<?php

/*******************************************************************************
 * Filename    : event.php
 * Description : islaVista event view.
 * Created on  : 04/01/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'event0.php';

if (isset($_COOKIE['eventMax']))
  SetSessionValue('eventMax', $_COOKIE['eventMax']);
if (isset($_COOKIE['evsort']))
  SetSessionValue('evsort', $_COOKIE['evsort']);
if (isset($_COOKIE['reverse']))
  SetSessionValue('reverse', $_COOKIE['reverse']);

if (!isset($_SESSION['ssid']))
  SetSessionValue('ssid', 0);
if (isset($_GET['eventMax']))
  SetSessionValue('eventMax', $_GET['eventMax']);
if (!isset($_SESSION['eventMax']))
  SetSessionValue('eventMax', 20);

$server = getServer();
$subsystem = $server->getSubsystem();
$dt = $subsystem->getDateAndTime();

if ($subsystem == null) exit;
closeEvtIndicator();
$events = $subsystem->getLastEvents($_SESSION['eventMax'], $language);
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
SetSessionValue('evsort', 'seq');
setcookie('eventMax', $_SESSION['eventMax'], null, null, null, true, true);
setcookie('evsort', $_SESSION['evsort'], null, null, null, true, true);
setcookie('reverse', $_SESSION['reverse'], null, null, null, true, true);

if (isset($events))
  usort($events, 'cmp_' . $_SESSION['evsort']);
?>
<html>
<head>
<?php
$cssPostFix = "?time=" . time() . ".css";
$jsPostFix = "?time=" . time() . ".js";
echo '
  <link rel="stylesheet" href="css/text1.css' . $cssPostFix . '" type="text/css">
  <link rel="stylesheet" href="css/table.css' . $cssPostFix . '" type="text/css">
  <script src="js/jquery-3.6.0.min.js"></script>';
?>
<title></title>
</head>

<body class=eventBody>
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center class="tableBorder">
<?php
table_head(array('pad' => 2, 'space' => 1, 'border' => 0));
?>
    <tr class=tableHeader>
      <td width=50% class=text3><? echo $dt->_strTime; ?>
      </td>
      <td width=50% class=text4 align=right><?= gprop('maxeventsdisplay') ?>
<?php
for ($i = 5; $i < 35; $i += 5)
  $maxNum[] = array($i, $i);
table_select($maxNum, 'maxNum', $_SESSION['eventMax']);
?>
      </td>
    </tr>
<?php
table_foot();

table_head(array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
$columns = array('#', gprop('device'), gprop('eventid'), gprop('severity'), gprop('time'), gprop('description'));
$widths = array(7, 12, 10, 10, 20, 50);
$acts = array('seq', 'dev', 'evid', 'sev', 'time', 'desc');
table_header_cols_anchor($columns, $widths, $acts);

if (isset($events)) {
  foreach ($events as $key => $event) { /* each() removed in PHP 8 */
    $columns = array(
      $event->evt_SequenceNumber, $event->evt_DeviceId, $event->evt_VendorEventId,
      array($event->_Severity, null, getSeverityColor($event->evt_Severity)), $event->_Timestamp, $event->evt_Desc
    );
    table_row_cols($columns);
  }
} else
  table_row_empty(gresult('noevent'), count($columns));
table_foot();
?>
  </table>
</form>

<script>
  $('select[name=maxNum]').change(function(o) {
    console.log("reload with 'eventMax=" + o.target.value + "'")
    window.location.replace('?eventMax=' + o.target.value)
  })
</script>
</body>

</html>
