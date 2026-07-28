<?php

/*******************************************************************************
 * Filename    : slp.php
 * Description : islaVista start/stop/configure slp_sa setting.
 * Created on  : 11/05/04
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$server = getServer();
$subsystem = $server->getSubsystem();
$note = null;
$result = null;

$daemons = $subsystem->getAllDaemons();
$result = $daemons->errorcode;
if ($result == PIERR_INACTIVE_SESSION) {
  errorOnInvalidSession();
}
if ($result == PIERR_NO_ERROR) {
  $daemons = $daemons->daemonlist;
  for ($i = 0; $i < count($daemons ?? array()); $i++) {
    $daemon = $daemons[$i];
    if ($daemon->daemonname == DAEMON_SLP)
      break;
  }
}
?>

<html>

<head>
<?php
page_head();
?>
</head>

<body>
<?php
if (isset($_POST['start']) || isset($_POST['stop']) || isset($_POST['restart'])) {
  if (isset($_POST['start'])) {
    $daemon->daemonstatus = DAEMON_STARTED;
    $note = gresult('slpserviceagentstart');
  } else if (isset($_POST['stop'])) {
    $daemon->daemonstatus = DAEMON_STOPPED;
    $note = gresult('slpserviceagentstop');
  } else if (isset($_POST['restart'])) {
    $daemon->daemonstatus = DAEMON_RESTART;
    $note = gresult('slpserviceagentrestart');
  }
  $setresult = $subsystem->startStopDaemon($daemon);
  if ($setresult != PIERR_NO_ERROR)
    $note = gresult('slpservicenotstartorstop');
  else {
    if ($daemon->daemonstatus == DAEMON_RESTART || $daemon->daemonstatus == DAEMON_STARTED)
      $daemon->daemonstatus = 'Started';
    else
      $daemon->daemonstatus = 'Stopped';
  }
}
if (isset($_POST['submit'])) {
  $setresult = PIERR_NO_ERROR;
  if (isset($_POST['submit'])) {
    if ($_POST['startuptype'] == "Auto")
      $daemon->startuptype = DAEMON_STARTTYPE_AUTO;
    else if ($_POST['startuptype'] == "Manual")
      $daemon->startuptype = DAEMON_STARTTYPE_MANUAL;
    $setresult = $subsystem->setDaemonStartupType($daemon);
  }
  if ($setresult == PIERR_NO_ERROR) {
    if ($daemon->startuptype == DAEMON_STARTTYPE_AUTO)
      $daemon->startuptype = 'Automatic';
    else
      $daemon->startuptype = 'Manual';
    $note = gresult('slpservicesettingok');
  } else
    $note = gresult('slpservicesettingfail');
}

?>
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center
    bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
if (isset($setresult) && $setresult != PIERR_NO_ERROR)
  table_title(gprop('slpserviceagentsetting'), 'slp_sa', $note, $setresult);
else
  table_title(gprop('slpserviceagentsetting'), 'slp_sa', $note, $result);

table_tab_head();
$tabTitle = tabTitleAdminTool($subsystem);
table_tab($tabTitle);
$s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner');
if ($result == PIERR_NO_ERROR) {
  $privilege = getUserPrivilege($subsystem);
  table_swmgt($daemon, $privilege, $s);
  if ($privilege >= USER_GROUP_SUPER)
    table_row_submit();
} //NO_ERROR
else {
  table_head_fold(gprop('slpserviceagentsetting'), 'content', null, null, $s);
  table_row_empty(gerror('slpdisplay'));
  table_foot_fold();
  table_tab_foot();
}
page_foot();
?>

  </table>
</form>
<script>
  function validateStop()
  {
    var ret = confirm('<?= gjsalert('stopservice') ?>');
    if (!ret)
      return false;
    return true;
  }
  
  function validateRestart()
  {
    return true;
  }
</script>
</body>
</html>
