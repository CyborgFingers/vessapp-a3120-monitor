<?php

/*******************************************************************************
 * Filename    : rebuild.php
 * Description : islaVista background activity rebuild information.
 * Created on  : 06/15/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$server = getServer();
$subsystem = $server->getSubsystem();
$physicalDrives = $subsystem->getPhysicalDrives();

if (isset($_GET['pdid']))
  SetSessionValue('pdid', $_GET['pdid']);

/*
$physicalDrive = getPDFromId($physicalDrives, $_SESSION['pdid']);
$arrayId = $physicalDrive->pdi_ArrayId;
$arrayId &= 0xFF;
$seqNo = $physicalDrive->pdi_SequenceNo;
 */

$rebuilds = $subsystem->getRebuilds($_GET['act']);
foreach ($rebuilds as $rbd) {
  if ($rbd->rbpd_PDFlatId == $_SESSION['pdid']) {
    $rebuild = $rbd;
    break;
  }
}
if (isset($rebuild)) {
  $arrayId = $rebuild->rbpd_ArrayID;
  $seqNo = $rebuild->rbpd_SeqNumber;
}

$act = ($_GET['act'] == RBD_ACTION_PDM ? 'pdm' : ($_GET['act'] == RBD_ACTION_TRANSITION ? 'transition' : 'rebuild'));
if (isset($_GET['daid']))
  $file = htmlspecialchars($_SERVER['PHP_SELF']) . '?pdid=' . $_GET['pdid'] . '&act=' . $_GET['act'] . '&daid=' . $_GET['daid'];
else
  $file = htmlspecialchars($_SERVER['PHP_SELF']) . '?pdid=' . $_GET['pdid'] . '&act=' . $_GET['act'];
SetSessionValue('lastfile', $file);
SyncSession();

$note = isset($_GET['note']) ? $_GET['note'] : null;
$result = isset($_GET['result']) ? $_GET['result'] : 0;

if (isset($_POST['stop'])) {
  table_wait();
  $result = $subsystem->stopRebuild($_GET['act'], $arrayId, $seqNo);
  if ($result)
    redirectPage($file . '&result=' . $result . '&note=' . gresult($act . 'stopfail'));
  else
    redirectPage($file . '&note=' . gresult($act . 'stopok'));
  exit;
} else if (isset($_POST['pause'])) {
  table_wait();
  $result = $subsystem->pauseRebuild($_GET['act'], $arrayId, $seqNo);
  if ($result)
    redirectPage($file . '&result=' . $result . '&note=' . gresult($act . 'pausefail') . ':');
  else
    redirectPage($file . '&note=' . gresult($act . 'pauseok'));
  exit;
} else if (isset($_POST['resume'])) {
  table_wait();
  $result = $subsystem->resumeRebuild($_GET['act'], $arrayId, $seqNo);
  if ($result)
    redirectPage($file . '&result=' . $result . '&note=' . gresult($act . 'resumefail') . ':');
  else
    redirectPage($file . '&note=' . gresult($act . 'resumeok'));
  exit;
}
/*
if ($_GET['act'] == RBD_ACTION_RBD)
  $rebuilds = $subsystem->getRebuilds($_GET['act'], $arrayId, $seqNo);
else
  $rebuilds = $subsystem->getRebuilds($_GET['act']);
 */
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
table_title(gprop($act), null, $note, $result, $act);
table_tab_head();

if (isset($_GET['daid']))
  $tabTitle = tabTitleArray($subsystem, $_GET['daid']);
else
  $tabTitle = tabTitleSubsystem($subsystem);
table_tab($tabTitle);

table_head_fold(gprop($act . 'progress'), 'RB', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

if (isset($rebuild)) {
  table_row(gprop($act . 'status'), $rebuild->rbpd_State);
  table_row(gprop('diskarrayid'), $rebuild->rbpd_ArrayID);
  table_row(gprop('sourcepdseqnointhisda'), $rebuild->rbpd_SeqNumber);
  table_row(gprop('targetpdid'), $rebuild->rbpd_PDFlatId + 1);
  table_row_progress(gprop('progressonpd'), $rebuild->rbpd_PD_ProgressinPercentage);
  table_row(gprop('currentldid'), $rebuild->rbpd_LDFlatId);
  table_row_progress(gprop('progressoncurrentld'), $rebuild->rbpd_LD_ProgressinPercentage);
} else
  table_row_empty(gresult($act . 'notrunning'));

table_foot_fold();
table_tab_foot();

if (isset($rebuild))
  table_row_start(array(
  'start' => ($rebuild->rbpd_State == 'Stopped' ? 1 : 0), 'stop' => ($rebuild->rbpd_State == 'Stopped' ? 0 : 1),
  'pause' => ($rebuild->rbpd_State == 'Running' ? 1 : 0), 'resume' => ($rebuild->rbpd_State == 'Paused' ? 1 : 0)
));
page_foot();
refreshCurrentPage();
?>
  </table>
</form>

</body>
</html>
