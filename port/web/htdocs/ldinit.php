<?php

/*******************************************************************************
 * Filename    : ldinit.php
 * Description : islaVista logical drive initialization information.
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

$inits = $subsystem->getInitializations($_SESSION['ldid']);
if (isset($inits[0]))
  $init = $inits[0];

if (isset($_POST['start'])) {
  if (isset($init))
    $note = gresult('initializationalreadystart');
  else {
    $file = getLogDrvBGAFile('ldinitt.php');
    SyncSession();
    redirectPage($file);
    exit;
  }
} else if (isset($_POST['stop'])) {
  $result = $subsystem->stopInitialization($_SESSION['ldid']);
  if ($result == 0) {
    $note = gresult('initializationstopok');
    $init->ldi_LdiState = 'Stopped';
    $noRefresh = 1;
    if (isset($_SESSION['daidflag']))
      header('Location: ldbga.php?note=' . $note . '&daid=' . $_SESSION['daidflag']);
    else if (isset($_SESSION['bgaflag']))
      header('Location: bga.php?tab=3&note=' . $note);
    else
      header('Location: ldbga.php?note=' . $note);

  } else
    $note = gresult('initializationstopfail');
} else if (isset($_POST['pause'])) {
  $result = $subsystem->pauseInitialization($_SESSION['ldid']);
  if ($result == 0) {
    $note = gresult('initializationpauseok');

    $inits = $subsystem->getInitializations($_SESSION['ldid']);
    $init = $inits[0];
  } else
    $note = gresult('initializationpausefail');
} else if (isset($_POST['resume'])) {
  $result = $subsystem->resumeInitialization($_SESSION['ldid']);
  if ($result == 0) {
    $note = gresult('initializationresumeok');
    $inits = $subsystem->getInitializations($_SESSION['ldid']);
    $init = $inits[0];
  } else
    $note = gresult('initializationresumefail');
}
SyncSession();
?>

<html>
<head>
<?php
page_head();
$file = getLogDrvBGAFile(htmlspecialchars($_SERVER['PHP_SELF']));
$_SESSION['lastfile'] = $file;
?>
</head>

<body>
<?php $action_url = validateActionUrl($file); ?>
<form method=post action="<?php echo $action_url; ?>">
<?php form_csrf(); ?>
  <table width=100% border=1 cellspacing=0 cellpadding=2 align=center
    bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('initializationonlogdrv') . ' ' . $_SESSION['ldid'], null, $note, $result);
table_tab_head();

if (isset($_GET['bga']))
  $tabTitle = tabTitleSubsystem($subsystem);
else
  $tabTitle = tabTitleLD($subsystem, isset($_GET['daid']) ? $_GET['daid'] : null);
table_tab($tabTitle);

table_head_fold(gprop('initializationprogress'), 'LD');
if (isset($init)) {
  table_row(gprop('logicaldriveid'), $init->ldi_LdFlatId);
  table_row(gprop('state'), $init->ldi_LdiState);
  table_row_progress(gprop('initializationprogress'), $init->ldi_ProgressinPercentage);
} else
  table_row_empty(gresult('initializationcomplete'));

table_foot_fold();
table_tab_foot();

if (isset($init))
  table_row_start(array(
  'start' => ($init->ldi_LdiState == 'Stopped' ? 1 : 0), 'stop' => ($init->ldi_LdiState == 'Stopped' ? 0 : 1),
  'pause' => ($init->ldi_LdiState == 'Running' ? 1 : 0), 'resume' => ($init->ldi_LdiState == 'Paused' ? 1 : 0)
));

page_foot();

if (!isset($noRefresh))
  refreshCurrentPage();
?>
  </table>
</form>

</body>
</html>

