<?php

/*******************************************************************************
 * Filename    : phydrvStat.php
 * Description : islaVista physical drive statistics.
 * Created on  : 03/25/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_GET['pdid']))
  SetSessionValue('pdid', $_GET['pdid']);

$server = getServer();
$subsystem = $server->getSubsystem();
$supportDualCtrl = $subsystem->supportdualcontroller();
$physicalDrives = $subsystem->getPhysicalDrive($_SESSION['pdid']);
$physicalDrive = $physicalDrives[0];
$phydrvs = $subsystem->getPhyDrvsStatistics($_SESSION['pdid']);
SyncSession();
?>

<html>
<head>
<?php
page_head();
?>
</head>

<body>
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('physicaldrivestatistics'));
table_tab_head();

$tabTitle = tabTitlePD($subsystem, $physicalDrive);
table_tab($tabTitle);

table_head_fold(gprop('physicaldrive') . ' ' . ($_SESSION['pdid'] + 1) . ' ' . gprop('statistics'), 'PD', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

if (count($phydrvs) == 0)
  table_row_empty(galert('nopdinfo'));
else {
  $phydrv = $phydrvs[0];
  table_row(gprop('datatransferred'), $phydrv->spd_BlocksTransferred);
  table_row(gprop('readdatatransferred'), $phydrv->spd_ReadBlocksTransferred);
  table_row(gprop('writedatatransferred'), $phydrv->spd_WriteBlocksTransferred);

  table_row(gprop('errors'), fmtNumberUnit($phydrv->spd_Errors));
  table_row(gprop('noreadwriteerrors'), fmtNumberUnit($phydrv->spd_NonRWErrors));
  table_row(gprop('readerrors'), fmtNumberUnit($phydrv->spd_ReadErrors));
  table_row(gprop('writeerrors'), fmtNumberUnit($phydrv->spd_WriteErrors));

  table_row(gprop('iorequest'), fmtNumberUnit($phydrv->spd_IOReq));
  table_row(gprop('nonreadwriteio'), fmtNumberUnit($phydrv->spd_NonRWReq));
  table_row(gprop('readiorequest'), fmtNumberUnit($phydrv->spd_ReadIOReq));
  table_row(gprop('writeiorequest'), fmtNumberUnit($phydrv->spd_WriteIOReq));

  table_row(gprop('statisticsstarttime'), $phydrv->spd_statsStartTime);
  table_row(gprop('statisticscollecttime'), $phydrv->spd_statsCollectionTime);
}
table_foot_fold();
table_tab_foot();
page_foot();
?>
  </table>
</form>

</body>
</html>
