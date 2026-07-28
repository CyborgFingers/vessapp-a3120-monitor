<?php

/*******************************************************************************
 * Filename    : ldrc.php
 * Description : islaVista logical drive redundancy check information.
 * Created on  : 06/28/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_GET['ldid']))
  SetSessionValue('ldid', $_GET['ldid']);
if (isset($_SESSION['daidflag']))
  unset($_SESSION['daidflag']);
if (isset($_GET['daid']))
  SetSessionValue('daidflag', $_GET['daid']);
if (isset($_SESSION['bgaflag']))
  unset($_SESSION['bgaflag']);
if (isset($_GET['bga']))
  SetSessionValue('bgaflag', $_GET['bga']);
$server = getServer();
$subsystem = $server->getSubsystem();

$note = null;
$result = 0;

$checks = $subsystem->getRedundancyChecks($_SESSION['ldid']);
if (isset($checks[0]))
  $check = $checks[0];

if (isset($_POST['start'])) {
  if (isset($check))
    $note = gresult('rcalreadystart');
  else {
    $file = getLogDrvBGAFile('ldrct.php');
    SyncSession();
    redirectPage($file);
    exit;
  }
} else if (isset($_POST['stop'])) {
  $result = $subsystem->stopRedundancyCheck($_SESSION['ldid']);
  if ($result == 0) {
    $note = gresult('rcstopok');
    $check->rcpd_State = 'Stopped';
    $noRefresh = 1;

    if (isset($_SESSION['daidflag']))
      header('Location: ldbga.php?note=' . $note . '&daid=' . $_SESSION['daidflag']);
    else if (isset($_SESSION['bgaflag']))
      header('Location: bga.php?tab=3&note=' . $note);
    else
      header('Location: ldbga.php?note=' . $note);

  } else
    $note = gresult('rcstopfail');
} else if (isset($_POST['pause'])) {
  $result = $subsystem->pauseRedundancyCheck($_SESSION['ldid']);
  if ($result == 0) {
    $note = gresult('rcpauseok');
    $checks = $subsystem->getRedundancyChecks($_SESSION['ldid']);
    $check = $checks[0];
  } else
    $note = gresult('rcpausefail');
} else if (isset($_POST['resume'])) {
  $result = $subsystem->resumeRedundancyCheck($_SESSION['ldid']);
  if ($result == 0) {
    $note = gresult('rcresumeok');
    $checks = $subsystem->getRedundancyChecks($_SESSION['ldid']);
    $check = $checks[0];
  } else
    $note = gresult('rcresumefail');
}
?>

<html>
<head>
<?php
page_head();
$file = getLogDrvBGAFile(htmlspecialchars($_SERVER['PHP_SELF']));
$_SESSION['lastfile'] = $file;
SyncSession();
?>
</head>

<body>
<?php $action_url = validateActionUrl($file); ?>
<form method=post action="<?php echo $action_url; ?>">
<?php form_csrf(); ?>
  <table width=100% border=1 cellspacing=0 cellpadding=2 align=center
    bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('redundancycheckonlogdrv') . ' ' . $_SESSION['ldid'], null, $note, $result);
table_tab_head();

if (isset($_GET['bga']))
  $tabTitle = tabTitleSubsystem($subsystem);
else
  $tabTitle = tabTitleLD($subsystem, isset($_GET['daid']) ? $_GET['daid'] : null);
table_tab($tabTitle);

table_head_fold(gprop('redundancycheckprogress'), 'LD');

if (isset($check)) {
  table_row(gprop('logicaldriveid'), $check->rcpd_LDFlatId);
  table_row(gprop('status'), $check->rcpd_State);
  table_row_progress(gprop('redundancycheckprogress'), $check->rcpd_LD_ProgressinPercentage);
} else
  table_row_empty(gresult('rccomplete'));

table_foot_fold();
table_tab_foot();

if (isset($check))
  table_row_start(array(
  'start' => ($check->rcpd_State == 'Stopped' ? 1 : 0), 'stop' => ($check->rcpd_State == 'Stopped' ? 0 : 1),
  'pause' => ($check->rcpd_State == 'Running' ? 1 : 0), 'resume' => ($check->rcpd_State == 'Paused' ? 1 : 0)
));

page_foot();

if (!isset($noRefresh))
  refreshCurrentPage();
?>

  </table>
</form>

</body>
</html>

