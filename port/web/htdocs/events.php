<?php

/*******************************************************************************
 * Filename    : events.php
 * Description : islaVista event log view.
 * Created on  : 09/09/03
 *
 * (C) Copyright Promise Technology Inc.,2020
 * All Rights Reserved
 ******************************************************************************/
include 'event0.php';

/* System Events source for the TrueNAS monitor: the vendor RAID-engine event
 * stream (getEvents) is absent on a controller-less box, so we render the real
 * events collected by the root `promise-alerts` helper into /run/promise/
 * alerts.json - TrueNAS alert.list + enclosure sensor faults + syslog tail -
 * mapped onto the same event fields the table below already renders. */
function webpam_system_events()
{
  $events = array();
  @date_default_timezone_set('Pacific/Auckland');   /* show alert times in NZ local */
  $raw = @file_get_contents('/run/promise/alerts.json');
  if ($raw === false) return $events;
  $data = json_decode($raw, true);
  if (!is_array($data) || empty($data['events'])) return $events;
  $sevnum = array('critical' => 7, 'warning' => 3, 'info' => 2);
  $sevstr = array('critical' => 'Critical', 'warning' => 'Warning', 'info' => 'Information');
  $seq = count($data['events']);
  foreach ($data['events'] as $e) {
    $sev = isset($e['severity']) ? $e['severity'] : 'info';
    $t = isset($e['time']) ? intval($e['time']) : 0;
    $o = new stdClass();
    $o->evt_SequenceNumber = $seq--;
    $o->evt_DeviceId = isset($e['source']) ? $e['source'] : '-';
    $o->evt_VendorEventId = isset($e['id']) ? $e['id'] : '';
    $o->evt_Severity = isset($sevnum[$sev]) ? $sevnum[$sev] : 2;
    $o->_Severity = isset($sevstr[$sev]) ? $sevstr[$sev] : 'Information';
    $o->evt_Timestamp = $t;
    $o->_Timestamp = $t ? date('Y/m/d H:i:s', $t) : '-';
    $o->evt_Desc = isset($e['desc']) ? $e['desc'] : '';
    $o->evt_defaultEventId = '';   /* used by saveEvents() export */
    $o->evt_specInfo = '';
    $events[] = $o;
  }
  return $events;
}

$server = getServer();
$subsystem = $server->getSubsystem();
$dt = $subsystem->getDateAndTime();
closeEvtIndicator();

$location = EVENT_LOCATION_RAM;
$title = gprop('runtimeevents');
if (isset($_GET['nvram'])) {
  $location = EVENT_LOCATION_NVRAM;
  $title = gprop('nvramevents');
} else if (isset($_GET['mdd'])) {
  $location = EVENT_LOCATION_MDD;
  $title = gprop('mddevents');
} else {
  ?>
<script>
    top.headfrm.location.href="header.php?refresh";
</script>
<?php

}

$file = htmlspecialchars($_SERVER['PHP_SELF']) . (isset($_GET['nvram']) ? '?nvram' : (isset($_GET['mdd']) ? '?mdd' : '?ram'));
$_SESSION['lastfile'] = $file;

$note = isset($_GET['note']) ? $_GET['note'] : null;
$result = isset($_GET['result']) ? $_GET['result'] : 0;
if (!isset($_POST['save'])) {   /* clearing system alerts / syslog is not ours to do */
  // do not reverse or sort after clearing events
  if (!isset($_SESSION['reverse']))
    SetSessionValue('reverse', 1);
  if (isset($_GET['evsort'])) {
    if (isset($_SESSION['evsort']) && $_SESSION['evsort'] == $_GET['evsort'])
      SetSessionValue('reverse', $_SESSION['reverse'] ? 0 : 1);
    else
      SetSessionValue('reverse', 0);
    SetSessionValue('evsort', $_GET['evsort']);
  } else if (!isset($_SESSION['evsort']))
  // reverse by time in default
  SetSessionValue('evsort', 'time');
}
// SyncSession();


$getAllEvents = 1;
$events = webpam_system_events();

$bHasEvent = 0;
if (count($events))
  $bHasEvent = 1;

if ($getAllEvents) {
  if (count($events))
    usort($events, 'cmp_' . $_SESSION['evsort']);
}

function saveEvents($events, $filename)
{
    global $dt;

  $handle = fopen($filename, "w+");
  if (!$handle) return false;
  fwrite($handle, 'Event List at ' . $dt->_strTime);
  $str = sprintf(
    "\n%-8s%-16s%s\t%-12s%-24s%-12s%-68s\t%s\n",
    '#',
    'Device',
    'Event ID',
    'Severity',
    'Time',
    'Default ID',
    'Specific Data',
    'Description'
  );
  fwrite($handle, $str);

  foreach ($events as $key => $event) { /* each() removed in PHP 8 */
    $tmp = sprintf("%d", $event->evt_SequenceNumber);
    $str = sprintf(
      "%-8s%-16s%s\t%-12s%-24s%-12s%-68s\t%s\n",
      $tmp,
      $event->evt_DeviceId,
      $event->evt_VendorEventId,
      $event->_Severity,
      $event->_Timestamp,
      $event->evt_defaultEventId,
      $event->evt_specInfo,
      $event->evt_Desc
    );
    fwrite($handle, $str);
  }
  fclose($handle);
  return true;
}

function outputEvents($events)
{  
  if (PHP_OS == "WINNT") {
      $logfile = apache_getenv('TMP') . '\\eventlog';
  } else {
      $logfile = '/tmp/eventlog';
  }

  if (!saveEvents($events, $logfile)) {
      return false;
  }

  if (PHP_OS == "WINNT") {
      header('Location: download.php?file=evtlog');
  } else {
      header('Location: download.php?file=' . $logfile);
  }

  exit(); // !!! [RB-261322] just terminate PHP script here, because it has send redirection response to web client.
  return true;
}

if (isset($_POST['save']))
  $note = outputEvents($events) ? gresult('eventlogsaveok') : gresult('eventlogsavefail');
?>
<html>

<head>
<?php
page_head();
?>
</head>

<body>
<?php $action_url = validateActionUrl($file); ?>
<form method=post action="<?php echo $action_url; ?>">
  <?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title($title, 'event', $note, $result);
table_tab_head();
$tabTitle = tabTitleSubsystem($subsystem);
table_tab($tabTitle);

table_head_fold_list(gprop('eventlist') . ' - ' . $dt->_strTime, 'EV', array('pad' => 0, 'space' => 0, 'border' => 0, 'class' => 'tableContentInner'));
// table_head(array('pad'=>2,'space'=>1,'border'=>0));

$columns = array('#', gprop('device'), gprop('eventid'), gprop('severity'), gprop('time'), gprop('description'));
$widths = array(7, 12, 10, 10, 20, 50);
$acts = array('seq', 'dev', 'evid', 'sev', 'time', 'desc');
table_list_head();
if ($getAllEvents) {
  table_header_cols_anchor($columns, $widths, $acts, 'listh');
} else
  table_header_cols($columns, $widths);
  // table_foot();

if ($bHasEvent == 0)
  table_row_empty(gresult('noeventin' . (isset($_GET['nvram']) ? 'nvram' : (isset($_GET['mdd']) ? 'mdd' : 'ram'))));
else {
  $i = 0;
  foreach ($events as $key => $event) { /* each() removed in PHP 8 */
    $columns = array(
      $event->evt_SequenceNumber, $event->evt_DeviceId, $event->evt_VendorEventId,
      array($event->_Severity, null, getSeverityColor($event->evt_Severity)), $event->_Timestamp, $event->evt_Desc
    );
    table_row_cols($columns, $widths, 'listb' . $i);
    $i++;
  }
}
table_list_foot();
table_foot_fold();
table_tab_foot();

if ($bHasEvent) {
  $pv = getUserPrivilege($subsystem);
  echo '
      <table border=0 cellspacing=2 cellpadding=0 align=center>
        <tr>
          <td>
            <input class=input-p type=submit value="' . gjsprop('saveeventlog') . '" name=save ' . ($pv > USER_GROUP_POWER ? '' : 'disabled title="' . gjsalert('noauthsaveeventlog') . '"') . '>
          </td>
        </tr>
      </table>';
}

page_foot();
?>
    <input type=hidden name=defaultconfirm>

  </table>
</form>

<script>
  adjustListDivHeight(170);
<?php
if ($bHasEvent) {
  ?>
  function getreturn(ret)
  {
	  var alertmsg="<? echo gjsalert('errconfirm') ?>";
    if(ret==1)
   	{
      document.forms[0].defaultconfirm.name="clear";
      document.forms[0].submit();
    } 
    else if(ret=='cancelled')
      return false;
    else{
      alert(alertmsg);
      return false;
    }
  }

  function validate()
  {
    if (document.getElementById('windowconfirm') != null)
      return false;
    var string='<?= gjsconfirm('deleteeventin' . ($location == EVENT_LOCATION_NVRAM ? 'nvram' : ($location == EVENT_LOCATION_MDD ? 'mdd' : 'ram'))) ?>';
    showConfirm(string, getreturn);
    return false;
  
  }
<?php

}
?>
</script>
</body>

</html>
