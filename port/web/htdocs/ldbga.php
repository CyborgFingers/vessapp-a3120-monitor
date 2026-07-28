<?php

/*******************************************************************************
 * Filename    : ldbga.php
 * Description : islaVista logical drive bga info.
 * Created on  : 07/25/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$server = getServer();
$subsystem = $server->getSubsystem();

if (isset($_GET['ldid']))
  SetSessionValue('ldid', $_GET['ldid']);

$inits = $subsystem->getInitializations($_SESSION['ldid']);
$syncs = $subsystem->getSynchronizations($_SESSION['ldid']);
$rcs = $subsystem->getRedundancyChecks($_SESSION['ldid']);
$rbs = $subsystem->getRebuildByLd($_SESSION['ldid']);

$note = isset($_GET['note']) ? $_GET['note'] : null;
if (count($inits) || count($syncs) || count($rcs) || count($rbs))
  $bgaRunning = 1;
$leftWidth = 30;
?>

<html>
<head>
<?php
page_head();
$file = htmlspecialchars($_SERVER['PHP_SELF']) . (isset($_GET['daid']) ? ('?daid=' . $_GET['daid']) : '');
$_SESSION['lastfile'] = $file;
SyncSession();
?>
</head>

<body>
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('logicaldrive') . ' ' . $_SESSION['ldid'], 'bga', $note);
table_tab_head();

$tabTitle = tabTitleLD($subsystem, isset($_GET['daid']) ? $_GET['daid'] : null);
table_tab($tabTitle);

table_head_fold(gprop('backgroundactivities'), 'LD', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

if (isset($bgaRunning)) {
  if (count($inits))
    table_row_progress(gprop('initialization'), $inits[0]->ldi_ProgressinPercentage);

  if (count($syncs))
    table_row_progress(gprop('synchronization'), $syncs[0]->bspd_LD_ProgressinPercentage);

  if (count($rcs))
    table_row_progress(gprop('redundancycheck'), $rcs[0]->rcpd_LD_ProgressinPercentage);

  if (count($rbs))
    table_row_progress(gprop('rebuild'), $rbs[0]->rbpd_PD_ProgressinPercentage);
} else
  table_row_empty(gresult('nobgarunning'));

table_foot_fold();
table_tab_foot();

page_foot();

if (isset($bgaRunning))
  refreshCurrentPage(20);
?>
  </table>
</form>

</body>
</html>
