<?php

/*******************************************************************************
 * Filename    : migrate.php
 * Description : islaVista disk array migrate progress information.
 * Created on  : 01/12/04
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$server = getServer();
$subsystem = $server->getSubsystem();

$migrates = $subsystem->getArrayMigrates();
for ($i = 0; $i < count($migrates); $i++) {
  if ($migrates[$i]->mp_ArrayID == $_GET['daid']) {
    $migrate = $migrates[$i];
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
<form method=post>
  <table width=100% border=1 cellspacing=0 cellpadding=2 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('diskarraymigration'));
table_tab_head();

if (isset($_GET['bga']))
  $tabTitle = tabTitleSubsystem($subsystem);
else
  $tabTitle = tabTitleArray($subsystem, $_GET['daid']);
table_tab($tabTitle);

table_head_fold(gprop('migrationprogress'), 'DA');

if (isset($migrate)) {
  table_row(gprop('diskarrayid'), $migrate->mp_ArrayID, 40);
  table_row(gprop('state'), $migrate->mp_State);
  table_row_progress(gprop('completionpercentage'), $migrate->mp_OverallPercentage);
} else
  table_row_empty(gresult('migrationnotrunning'));

table_foot_fold();
table_tab_foot();

page_foot();
refreshCurrentPage(60);
?>

  </table>
</form>

</body>
</html>

