<?php

/*******************************************************************************
 * Filename    : ctrlStat.php
 * Description : islaVista controller statistics.
 * Created on  : 03/25/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_GET['ldid']))
  SetSessionValue('ldid', $_GET['ldid']);
SyncSession();

$server = getServer();
$subsystem = $server->getSubsystem();
$logdrvs = $subsystem->getLogDrvsStatistics($_SESSION['ldid']);
?>

<html>
<head>
<?php
page_head();
$fileparam = isset($_GET['daid']) ? ('?daid=' . $_GET['daid']) : '';
?>
</head>

<body>
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url.$fileparam; ?>">
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('logicaldrivestatistics'));
table_tab_head();

$tabTitle = tabTitleLD($subsystem, isset($_GET['daid']) ? $_GET['daid'] : null);
table_tab($tabTitle);

table_head_fold(gprop('logicaldrive') . ' ' . $_SESSION['ldid'] . ' ' . gprop('statistics'), 'LD', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

if (count($logdrvs) == 0)
  table_row_empty(galert('noldstatics'));
else {
  $logdrv = $logdrvs[0];
  table_row(gprop('datatransferred'), $logdrv->sld_BlocksTransferred);
  table_row(gprop('readdatatransferred'), $logdrv->sld_ReadBlocksTransferred);
  table_row(gprop('writedatatransferred'), $logdrv->sld_WriteBlocksTransferred);

  table_row(gprop('errors'), fmtNumberUnit($logdrv->sld_Errors));
  table_row(gprop('noreadwriteerrors'), fmtNumberUnit($logdrv->sld_NonRWErrors));
  table_row(gprop('readerrors'), fmtNumberUnit($logdrv->sld_ReadErrors));
  table_row(gprop('writeerrors'), fmtNumberUnit($logdrv->sld_WriteErrors));

  table_row(gprop('iorequest'), fmtNumberUnit($logdrv->sld_IOReq));
  table_row(gprop('nonreadwriteio'), fmtNumberUnit($logdrv->sld_NonRWReq));
  table_row(gprop('readiorequest'), fmtNumberUnit($logdrv->sld_ReadIOReq));
  table_row(gprop('writeiorequest'), fmtNumberUnit($logdrv->sld_WriteIOReq));

  table_row(gprop('statisticsstarttime'), $logdrv->sld_statsStartTime);
  table_row(gprop('statisticscollecttime'), $logdrv->sld_statsCollectionTime);
}
table_foot_fold();
table_tab_foot();
page_foot();
?>
  </table>
</form>

</body>
</html>
