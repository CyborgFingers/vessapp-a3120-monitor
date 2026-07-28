<?php

/*******************************************************************************
 * Filename    : ldrct.php
 * Description : islaVista start logical drive redundancy check.
 * Created on  : 06/29/03
 *
 * (C) Copyright Promise Technology Inc.,2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

function getBGAFile($note)
{
  return (isset($_GET['ldid']) ? 'ldbga.php' : 'bga.php') . '?note=' . $note . (isset($_GET['daid']) ? ('&daid=' . $_GET['daid']) : '');
}

if (isset($_POST['cancel'])) {
  header('Location: ' . getBGAFile(gresult('rcstartcancel')));
  exit;
}

$server = getServer();
$subsystem = $server->getSubsystem();

$note = null;
$result = 0;
if (isset($_GET['ldid'])) {
  $ldid = $_GET['ldid'];
  $logicalDrive = $subsystem->getLogicalDrive($ldid);
  if ($logicalDrive->ldi_RaidLevel == 'RAID0' || $logicalDrive->ldi_RaidLevel == 'JBOD') {
    $na = 1;
    $note1 = gresult('rcnold');
  }
}

if (count($_POST)) {
  if (!isset($ldid))
    $ldid = $_POST['_devId'];

  $result = $subsystem->startRedundancyCheck($ldid, (isset($_POST['rpr_bAutoFix']) ? true : false), (isset($_POST['rpr_bPauseOnError']) ? true : false));
  if ($result == 0) {
    redirectPage(getBGAFile(gresult('rcstartok')));
    exit;
  } else
    $note = gresult('rcstartfail');
}

if (!isset($_GET['ldid'])) {
  $logicalDrives = $subsystem->getLogicalDrives();
  for ($i = 0; $i < count($logicalDrives); $i++) {
    $logicalDrive = $logicalDrives[$i];
    if ($logicalDrive->ldi_RaidLevel != 'RAID0' && $logicalDrive->ldi_RaidLevel != 'JBOD')
      $ldids[] = array($logicalDrive->ldi_FlatId, 'ID' . $logicalDrive->ldi_FlatId . '-' . $logicalDrive->ldi_RaidLevel);
  }
  if (!isset($ldids)) {
    $na = 1;
    $note1 = gresult('rcnolds');
  }
}
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
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('startredundancycheck'), 'rcheck', $note, $result);
table_tab_head();

if (isset($_GET['bga']))
  $tabTitle = tabTitleSubsystem($subsystem);
else
  $tabTitle = tabTitleLD($subsystem, isset($_GET['daid']) ? $_GET['daid'] : null);
table_tab($tabTitle);

table_head_fold(gprop('redundancycheckparameters'), 'RC', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
if (isset($na))
  table_row_empty($note1);
else {
  if (isset($_GET['ldid']))
    table_row(gprop('logicaldriveid'), '&nbsp;&nbsp;' . $_GET['ldid']);
  else
    table_row_combo(gprop('redundantlogicaldrive'), $ldids, '_devId', isset($ldid) ? $ldid : 0);

  table_row_checkbox(gprop('autofix'), 0, 'rpr_bAutoFix');
  table_row_checkbox(gprop('pauseonerrors'), 0, 'rpr_bPauseOnError');
}

table_foot_fold();
table_tab_foot();

if (!isset($na))
  table_row_submit();
page_foot();
?>
  </table>
</form>

</body>
</html>
