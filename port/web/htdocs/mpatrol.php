<?php

/*******************************************************************************
 * Filename    : mpatrol.php
 * Description : islaVista media patrol information.
 * Created on  : 04/01/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$server = getServer();
$subsystem = $server->getSubsystem();

$note = null;
$result = 0;

if (isset($_SESSION['pegasus'])) {
  $client = $server->subsystems[$_SESSION['ssid']]->client;
  $instanceNames = $client->enumerateInstanceNames(constant("NAMESPACE"), "Promise_BackgroundActivityService");
  if ($instanceNames) {
    settype($inParam, 'array');
    $outParam[0] = CIMParamValue("State", 0, 'uint8');
    $outParam[1] = CIMParamValue("Percentage", 0, 'uint8');
    $outParam[] = CIMParamValue("StateDescription", '');
    $outParam[] = CIMParamValue("PhysicalDriveId", 0, 'uint16');
    $outParam[] = CIMParamValue("PhysicalDrivePercentage", 0, 'uint8');
    $value = $client->invokeMethod(constant("NAMESPACE"), $instanceNames[0], 'MediaPatrolProgress', $inParam, $outParam);
    if ($value)
      echo gresult('mediapatrolcmdprogressfail');
    $status = $outParam[0]->getValue();
  }
  if (isset($_POST['start']))
    $action = '1';
  else if (isset($_POST['stop']))
    $action = '2';
  else if (isset($_POST['pause']))
    $action = '3';
  else if (isset($_POST['resume']))
    $action = '4';
  if (isset($action)) {
    $inParam[] = CIMParamValue("Action", $action);
    settype($outParam, 'array');
    if ($instanceNames) {
      $value = $client->invokeMethod(constant("NAMESPACE"), $instanceNames[0], 'MediaPatrol', $inParam, $outParam);
      if ($value)
        $note = gresult('mediapatrolcmdfail');
    }
  }
} else {
  if (isset($_POST['start'])) {
    $result = $subsystem->startMediaPatrol();
    if ($result == 0)
      $note = gresult('mediapatrolstartok');
    else
      $note = gresult('mediapatrolstartfail');
  } else if (isset($_POST['stop'])) {
    $result = $subsystem->stopMediaPatrol();
    if ($result == 0)
      $note = gresult('mediapatrolstopok');
    else
      $note = gresult('mediapatrolstopfail');
  } else if (isset($_POST['pause'])) {
    $result = $subsystem->pauseMediaPatrol();
    if ($result == 0)
      $note = gresult('mediapatrolpauseok');
    else
      $note = gresult('mediapatrolpausefail');
  } else if (isset($_POST['resume'])) {
    $result = $subsystem->resumeMediaPatrol();
    if ($result == 0)
      $note = gresult('mediapatrolresumeok');
    else
      $note = gresult('mediapatrolresumefail');
  }
  $mp = $subsystem->getMediaPatrolStatus();
}
?>
<html>
<head>
<?php
page_head();
?>
</head>

<body>
<form method=post  >
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('mediapatrol'), 'mediapatrol', $note, $result);
table_tab_head();

$tabTitle = tabTitleSubsystem($subsystem);
table_tab($tabTitle);

table_head_fold(gprop('mediapatrolstatus'), 'MP', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

if (isset($mp->mppd_State)) {
  if (isset($_SESSION['pegasus']))
    table_row(gprop('mediapatrolstatus'), $status);
  else {
    table_row(gprop('mediapatrolstatus'), $mp->mppd_State);
    table_row_progress(gprop('mediapatroloverallprogress'), $mp->mppd_OverallPercentage);
    table_row(gprop('currentpdid'), $mp->mppd_PDFlatId + 1);
    table_row_progress(gprop('progressoncurrentpd'), $mp->mppd_ProgressinPercentage);
    table_row(gprop('numofpdcompleted'), $mp->mppd_CompletedPDCount);
    table_row(gprop('numofpdqueued'), $mp->mppd_QueuedPDCount);
    table_row(gprop('completedpdids'), $mp->completedpdids);
    table_row(gprop('queuedpdids'), $mp->queuedpdids);
  }
} else {
  $arrays = $subsystem->getDiskArrays();
  if (count($arrays)) $hasArray = 1;
  else {
    $spares = $subsystem->getSpareDrives();
    if (count($spares)) $hasArray = 1;
  }
  if (isset($hasArray))
    table_row_empty(gresult('mediapatrolnotrunning'));
  else
    table_row_empty(gresult('mediapatrolnoda'));
}
table_foot_fold();
table_tab_foot();

table_row_start(array(
  'start' => (((!isset($mp->mppd_State) && isset($hasArray)) || $mp->mppd_State == 'Stopped') ? 1 : 0),
  'stop' => ((!isset($mp->mppd_State) || $mp->mppd_State == 'Stopped') ? 0 : 1),
  'pause' => ((isset($mp->mppd_State) && $mp->mppd_State == 'Running') ? 1 : 0),
  'resume' => ((isset($mp->mppd_State) && $mp->mppd_State == 'Paused') ? 1 : 0)
));

page_foot();

refreshCurrentPage();
?>
    <input type=hidden name=defaultconfirm>
  </table>
</form>

<script>
  s=document.forms[0].stop;
  if(s)
    s.onclick=confirmStop;

  function getreturn(ret)
  {
    var alertmsg="<? echo gjsalert('errconfirm') ?>";
    if(ret==1){
      document.forms[0].defaultconfirm.name="stop";
      document.forms[0].submit();
    } 
    else if(ret=='cancelled')
      return false;
    else{
      alert(alertmsg);
      return false;
    }
  }

  function confirmStop()
  {
    if (document.getElementById('windowconfirm') != null)
      return false;
    showConfirm('<?= gjsconfirm('mediapatrolstop') ?>', getreturn);
    return false;
  }

</script>

</body>
</html>

<?php
if (isset($_SESSION['pegasus']))
  $server->logout();
?>

