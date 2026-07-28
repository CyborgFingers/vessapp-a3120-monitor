<?php

/*******************************************************************************
 * Filename    : bga.php
 * Description : islaVista background activity information.
 * Created on  : 04/01/03
 *
 * (C) Copyright Promise Technology Inc.,2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$server = getServer();
$subsystem = $server->getSubsystem();

$leftWidth = 40;
$note = isset($_GET['note']) ? $_GET['note'] : null;

if (!isset($_GET['daid'])) {
  $mp = $subsystem->getMediaPatrolStatus();
  $inits = $subsystem->getInitializations();
  $syncs = $subsystem->getSynchronizations();
  $rcs = $subsystem->getRedundancyChecks();
}

if (isset($_SESSION['rebuildDAID']))
  unset($_SESSION['rebuildDAID']);

$rbds = $subsystem->getRebuilds();
$mgs = $subsystem->getArrayMigrates();

$specific = 'empty';
if (isset($_GET['daid']))
  $specific = 'da';

?>
<html>

<head>
<?php
page_head();
function showBGARate($left, $rate, $leftWidth = null)
{
  table_row($left, gprop($rate <= 30 ? 'low' : ($rate <= 70 ? 'medium' : 'high')), $leftWidth);
}
?>
</head>

<body>
<form method=post  >
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('backgroundactivities'), null, $note, null, $specific);
table_tab_head();

if (isset($_GET['daid']))
  $tabTitle = tabTitleArray($subsystem, $_GET['daid']);
else
  $tabTitle = tabTitleSubsystem($subsystem);
table_tab($tabTitle);

table_head_fold(gprop('backgroundactivities'), 'BGA1', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
for ($i = 0; $i < count($rbds); $i++) {
  $r = $rbds[$i];
  if (isset($_GET['daid']) && ($_GET['daid'] != $r->rbpd_ArrayID)) continue;
  table_row_progress_href(
    (gprop('pd') . ($r->rbpd_PDFlatId + 1) . ' - ' . gprop($r->rbpd_Type == RBD_ACTION_PDM ? 'pdm' : ($r->rbpd_Type == RBD_ACTION_TRANSITION ? 'transition' : 'rebuild'))),
    'rebuild.php?pdid=' . $r->rbpd_PDFlatId . '&act=' . $r->rbpd_Type . (isset($_GET['daid']) ? ('&daid=' . $_GET['daid']) : ''),
    $r->rbpd_PD_ProgressinPercentage,
    $leftWidth
  );
  /*
  table_row_anchor(
    (gprop('pd') . ($r->rbpd_PDFlatId + 1) . ' - ' . gprop($r->rbpd_Type == RBD_ACTION_PDM ? 'pdm' : ($r->rbpd_Type == RBD_ACTION_TRANSITION ? 'transition' : 'rebuild'))),
    'rebuild.php?pdid=' . $r->rbpd_PDFlatId . '&act=' . $r->rbpd_Type . (isset($_GET['daid']) ? ('&daid=' . $_GET['daid']) : ''),
    $r->rbpd_State . ' - ' . $r->rbpd_PD_ProgressinPercentage . '%',
    $leftWidth
  );
   */
  $bgaRunning = 1;
}
for ($i = 0; $i < count($mgs); $i++) {
  $m = $mgs[$i];
  if (isset($_GET['daid']) && ($_GET['daid'] != $m->mp_ArrayID)) continue;
  table_row_progress_href(
    gprop('da') . $m->mp_ArrayID . ' - ' . gprop('migrationonda'),
    'migrate.php?daid=' . $m->mp_ArrayID . (isset($_GET['daid']) ? '' : '&bga'),
    $m->mp_OverallPercentage,
    $leftWidth
  );
  /*
  table_row_anchor(
    gprop('da') . $m->mp_ArrayID . ' - ' . gprop('migrationonda'),
    'migrate.php?daid=' . $m->mp_ArrayID . (isset($_GET['daid']) ? '' : '&bga'),
    $m->mp_State . ' - ' . $m->mp_OverallPercentage . '%',
    $leftWidth
  );
   */
  $bgaRunning = 1;
}
if (!isset($_GET['daid'])) {
  for ($i = 0; $i < count($inits); $i++) {
    $in = $inits[$i];
    table_row_progress_href(
      gprop('ld') . $in->ldi_LdFlatId . ' - ' . gprop('initializationonld'),
      'ldinit.php?bga&ldid=' . $in->ldi_LdFlatId,
      $in->ldi_ProgressinPercentage,
      $leftWidth
    );
    $bgaRunning = 1;
  }
  for ($i = 0; $i < count($syncs); $i++) {
    $s = $syncs[$i];
    table_row_progress_href(
      gprop('ld') . $s->bspd_LDFlatId . ' - ' . gprop('synchronizationonld'),
      'ldsync.php?bga&ldid=' . $s->bspd_LDFlatId,
      $s->bspd_LD_ProgressinPercentage,
      $leftWidth
    );
    /*
    table_row_anchor(
      gprop('ld') . $s->bspd_LDFlatId . ' - ' . gprop('synchronizationonld'),
      'ldsync.php?bga&ldid=' . $s->bspd_LDFlatId,
      $s->bspd_State . ' - ' . $s->bspd_LD_ProgressinPercentage . '%',
      $leftWidth
    );
     */
    $bgaRunning = 1;
  }
  for ($i = 0; $i < count($rcs); $i++) {
    $r = $rcs[$i];
    table_row_progress_href(
      gprop('ld') . $r->rcpd_LDFlatId . ' - ' . gprop('redundancycheckonld'),
      'ldrc.php?bga&ldid=' . $r->rcpd_LDFlatId,
      $r->rcpd_LD_ProgressinPercentage,
      $leftWidth
    );
    /*
    table_row_anchor(
      gprop('ld') . $r->rcpd_LDFlatId . ' - ' . gprop('redundancycheckonld'),
      'ldrc.php?bga&ldid=' . $r->rcpd_LDFlatId,
      $r->rcpd_State . ' - ' . $r->rcpd_LD_ProgressinPercentage . '%',
      $leftWidth
    );
     */
    $bgaRunning = 1;
  }
  if (isset($mp->mppd_State)) {
    $mp = $subsystem->getMediaPatrolStatus();
    table_row_progress_href(gprop('mediapatrol'), 'mpatrol.php', $mp->mppd_OverallPercentage, $leftWidth);
    /*
    table_row_anchor(gprop('mediapatrol'), 'mpatrol.php', $mp->mppd_State . ' - ' . $mp->mppd_OverallPercentage . '%', $leftWidth);
     */
    $bgaRunning = 1;
  }
}
if (!isset($bgaRunning))
  table_row_empty(gresult('nobgarunning'));
table_foot_fold();

if (isset($_GET['daid']))
  table_tab_foot();
else {
  $currentCtrlId = $subsystem->getCurrentCtrlId();
  $ctrlId = $currentCtrlId->currentCtrlId;
  $controller = $subsystem->getController($ctrlId);
  table_head_fold(gprop('bgaparameters'), 'BGA', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

  showBGARate(gprop('rebuildrate'), $controller->ci_RebuildRate, $leftWidth);
  showBGARate(gprop('bgsynrate'), $controller->ci_BkSyncRate);
  showBGARate(gprop('ldinitializationrate'), $controller->ci_LDInitRate);
  showBGARate(gprop('redundancycheckrate'), $controller->ci_RedCheckRate);
  showBGARate(gprop('migrationrate'), $controller->ci_MigrationRate);
  showBGARate(gprop('pdmrate'), $controller->ci_PDMRate);
  showBGARate(gprop('transitionrate'), $controller->ci_TransitionRate);

  table_row(gprop('reassignedblockthreshold'), $controller->ci_ReassignedBlockThreshold . ' Blocks');
  table_row(gprop('errorblockthreshold'), $controller->ci_ErrorBlockThreshold . ' Blocks');

  table_row(gprop('mediapatrol'), getEnableString($controller->ci_MediaPatrolEnable));
  table_row(gprop('autorebuild'), getEnableString($controller->ci_AutoRebuildEnable));

  table_row(gprop('lastmediapatrolstarttime'), $controller->ci_MediaPatrolStartTime);
  table_row(gprop('lastmediapatrolstoptime'), $controller->ci_MediaPatrolStopTime);
  table_foot_fold();
  table_tab_foot();
}

page_foot();
?>
  </table>
</form>

<?php
if (isset($bgaRunning))
  refreshCurrentPage(20);
?>
</body>
</html>
