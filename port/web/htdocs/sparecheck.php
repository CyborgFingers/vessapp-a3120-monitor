<?php

/*******************************************************************************
 * Filename    : sparecheck.php
 * Description : islaVista start spare check.
 * Created on  : 07/27/04
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

function getBGAFile($spid, $note)
{
      return (isset($_GET['spid']) ? "spare.php?tab=0&spid=$spid&note=$note" : "spares.php?note=$note");
}

if (isset($_POST['cancel'])) {
  header('Location: '.getBGAFile($_GET['spid'], gresult('startsparecheckcancel')));
  exit;
}

$note = null;
$result = 0;

$specific = 'empty';
if (!isset($_GET['spid']))
  $specific = 's';

$server = getServer();
$subsystem = $server->getSubsystem();

$spares = null;
if (isset($_GET['spid'])) {
  $spid = $_GET['spid'];
  $spares = $subsystem->getSpareDrive($spid);
} else {
  $spares = $subsystem->getSpareDrives();
}
$result = getLastError();

if ($result == PIERR_INACTIVE_SESSION) {
  errorOnInvalidSession();
}

if (count($_POST)) {
  if (!isset($spid))
    $spareid = $_POST['_devId'];
  else
    $spareid = $spares[0]->acd_ArrayID;
  if ($spareid != 0xFFFFFFFF)
    $result = $subsystem->startSpareCheck($spareid);
  else
    $result = $subsystem->startSpareCheck();
  if ($result == PIERR_NO_ERROR) {
    redirectPage(getBGAFile($spareid, gresult('startsparecheckok')));
    exit;
  } else
    $note = gresult('startsparecheckfail');
}
?>

<html>
<head>
<?php
page_head();
$file = getSpareDrvBGAFile(htmlspecialchars($_SERVER['PHP_SELF']));
$_SESSION['lastfile'] = $file;
?>
</head>

<body>
<?php $action_url = validateActionUrl($file); ?>
<form method=post action="<?php echo $action_url; ?>">
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('startsparecheck'), 'sparecheck', $note, $result, $specific);
table_tab_head();
$s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner');
if (isset($_GET['spid']))
  $tabTitle = tabTitleSpare();
else
  $tabTitle = tabTitleSpares($subsystem);

table_tab($tabTitle);

if ($result == PIERR_NO_ERROR) {
  table_head_fold(gprop('sparecheckparameters'), 'PD', null, null, $s);
  if (count($spares)) {
    if (isset($_GET['spid']))
      table_row(gprop('spareid'), '&nbsp;&nbsp;' . gprop('spare') . ' ' . $spares[0]->acd_ArrayID . ' - ' . gprop('pd') . ' ' . ($spares[0]->pdd_PDFlatId + 1));
    else {
      $spids[] = array(0xFFFFFFFF, 'All');
      for ($i = 0; $i < count($spares); $i++) {
        $spare = $spares[$i];
        if ($spare->_OpStatus != 'Dead') {
          $spids[] = array($spare->acd_ArrayID, gprop('spare') . ' ' . $spare->acd_ArrayID . ' - ' . gprop('pd') . ' ' . ($spare->pdd_PDFlatId + 1));
        }
      }
      table_row_combo(gprop('sparedrive'), $spids, '_devId', 0);
    }
  } else {
    table_row_empty(gresult('nosparetocheck'));
  }

  table_foot_fold();
  table_tab_foot();

  if (count($spares))
    table_row_submit();
} //NO_ERROR
else {
  table_head_fold(gprop('sparecheckparameters'), 'content', null, null, $s);
  table_row_empty(gerror('displaysparecheck'));
  table_foot_fold();
  table_tab_foot();
}
page_foot();
?>
  </table>
</form>
</body>
</html>
