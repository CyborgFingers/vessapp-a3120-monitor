<?/* replaced: 5 skipped: 1 modules: bga, common */ ?>

<?php

/*******************************************************************************
 * Filename    : ldsync.php
 * Description : islaVista logical drive synchronization information.
 * Created on  : 06/28/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_GET['ldid']))
  SetSessionValue('ldid', $_GET['ldid']);

$server = getServer();
$subsystem = $server->getSubsystem();

$syncs = $subsystem->getSynchronizations($_SESSION['ldid']);
if (isset($syncs[0]))
  $sync = $syncs[0];
?>

<html>
<head>
<?php
page_head();
$file = getLogDrvBGAFile(htmlspecialchars($_SERVER['PHP_SELF']));
SetSessionValue('lastfile', $file);
SyncSession();
?>
</head>

<body>
<?php $action_url = validateActionUrl($file); ?>
<form method=post action="<?php echo $action_url; ?>">
  <table width=100% border=1 cellspacing=0 cellpadding=2 align=center
    bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('synchronizationonlogdrv') . ' ' . $_SESSION['ldid']);
table_tab_head();

if (isset($_GET['bga']))
  $tabTitle = tabTitleSubsystem($subsystem);
else
  $tabTitle = tabTitleLD($subsystem, isset($_GET['daid']) ? $_GET['daid'] : null);
table_tab($tabTitle);

table_head_fold(gprop('synchronizationprogress'), 'LD');
if (isset($sync)) {
  table_row(gprop('logicaldriveid'), $sync->bspd_LDFlatId);
  table_row(gprop('status'), $sync->bspd_State);
  table_row_progress(gprop('synchronizationprogress'), $sync->bspd_LD_ProgressinPercentage);
} else
  table_row_empty(gresult('synccomplete'));

table_foot_fold();
table_tab_foot();
page_foot();

if (!isset($noRefresh))
  refreshCurrentPage();
?>

  </table>
</form>

</body>
</html>

