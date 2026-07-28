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

if (isset($_GET['ctid']))
  SetSessionValue('ctid', $_GET['ctid']);

$server = getServer();
$subsystem = $server->getSubsystem();
$controller = $subsystem->getController($_SESSION['ctid']);
$ctrls = $subsystem->getControllersStatistics($_SESSION['ctid']);
$ctrl = $ctrls[0];
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
table_title(gprop('controllerstatistics'));
table_tab_head();

$tabTitle = tabTitleController($controller);
table_tab($tabTitle);

table_head_fold(gprop('controller') . ' ' . ($_SESSION['ctid'] + 1) . ' ' . gprop('statistics'), 'CT', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

table_row(gprop('datatransferred'), $ctrl->scntr_BlocksTransferred);
table_row(gprop('readdatatransferred'), $ctrl->scntr_ReadBlocksTransferred);
table_row(gprop('writedatatransferred'), $ctrl->scntr_WriteBlocksTransferred);


table_row(gprop('errors'), fmtNumberUnit($ctrl->scntr_Errors));
table_row(gprop('noreadwriteerrors'), fmtNumberUnit($ctrl->scntr_NonRWErrors));
table_row(gprop('readerrors'), fmtNumberUnit($ctrl->scntr_ReadErrors));
table_row(gprop('writeerrors'), fmtNumberUnit($ctrl->scntr_WriteErrors));

table_row(gprop('iorequest'), fmtNumberUnit($ctrl->scntr_IOReq));
table_row(gprop('nonreadwriteio'), fmtNumberUnit($ctrl->scntr_NonRWReq));
table_row(gprop('readiorequest'), fmtNumberUnit($ctrl->scntr_ReadIOReq));
table_row(gprop('writeiorequest'), fmtNumberUnit($ctrl->scntr_WriteIOReq));

table_row(gprop('statisticsstarttime'), $ctrl->scntr_statsStartTime);
table_row(gprop('statisticscollecttime'), $ctrl->scntr_statsCollectionTime);

table_foot_fold();
table_tab_foot();
page_foot();
?>
  </table>
</form>
</body>

</html>
