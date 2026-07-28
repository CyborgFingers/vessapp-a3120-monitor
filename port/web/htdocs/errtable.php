<?php

/*******************************************************************************
 * Filename    : errtable.php
 * Description : islaVista error table for logical drive
 * Created on  : 09/24/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$server = getServer();
$subsystem = $server->getSubsystem();

$errtablerct = $subsystem->getErrTable($_SESSION['ldid'], RCT_ENTRY);
$result = $errtablerct->errorcode;
if ($result == PIERR_INACTIVE_SESSION) {
  errorOnInvalidSession();
}
if ($result == PIERR_NO_ERROR) {
  $errtablewct = $subsystem->getErrTable($_SESSION['ldid'], WCT_ENTRY);
  $result = $errtablewct->errorcode;
  if ($result == PIERR_INACTIVE_SESSION) {
    errorOnInvalidSession();
  }
}
if ($result == PIERR_NO_ERROR) {
  $errtableibt = $subsystem->getErrTable($_SESSION['ldid'], IBT_ENTRY);
  $result = $errtableibt->errorcode;
  if ($result == PIERR_INACTIVE_SESSION) {
    errorOnInvalidSession();
  }
}
?>
<html>

<head>
<?php
page_head();

$file = htmlspecialchars($_SERVER['PHP_SELF']);
if (isset($_GET['daid']))
  $file = $file . '?daid=' . $_GET['daid'];
$_SESSION['lastfile'] = $file;

function table_header_cols_anchor($columns, $widths, $acts)
{
  echo '
        <tr class=tableRowHeader>';

  for ($i = 0; $i < count($columns); $i++) {
    echo '
            <td class=text4b width=' . $widths[$i] . '%>' . $columns[$i] . '</a>
            </td>';
  }

  echo '
        </tr>';
}
?>
</head>

<body>
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center>
<?php
table_title(gprop('logicaldrive') . ' ' . $_SESSION['ldid'], 'logicaldrive', null, $result);
table_tab_head();

$tabTitle = tabTitleLD($subsystem, isset($_GET['daid']) ? $_GET['daid'] : null);
table_tab($tabTitle);
if ($result == PIERR_NO_ERROR) {
  table_head_fold(gprop('choosechecktabletype'), 'checktable', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
  ?>
    <tr>
          <td width=15% class=tableRowCol1 ><span><?= gprop('checktabletype') ?></span></td>
          <td width=85% class=tableRowColOther><span>
          <input type=radio
<?php
if (isset($_GET['all']) || (!isset($_GET['rct']) &&
  !isset($_GET['wct']) && !isset($_GET['ibt'])))
  echo ' checked ';
?>
             name=errortype value=ALL
              onClick="window.self.location.href='?all'">
              <?= gprop('all') ?>&nbsp;&nbsp;
            <input type=radio
<?php
if (isset($_GET['rct']))
  echo ' checked ';
?>
             name=errortype value=RCT
              onClick="window.self.location.href='?rct'">
              <?= gprop('readchecktable') ?>&nbsp;&nbsp;
            <input type=radio
<?php
if (isset($_GET['wct']))
  echo ' checked ';
?>
            name=errortype value=WCT
              onClick="window.self.location.href='?wct'">
              <?= gprop('writechecktable') ?>&nbsp;&nbsp;
            <input type=radio
<?php
if (isset($_GET['ibt']))
  echo ' checked ';
?>
            name=errortype value=IBT
              onClick="window.self.location.href='?ibt'">
              <?= gprop('inconsistentblocktable') ?>
            <input type=hidden value=RCT name=errtype>
          </span></td>
        </tr>
<?php
table_foot_fold();
table_head(array('class' => 'tableBorder'));
table_head(array('pad' => 0, 'space' => 1, 'border' => 0));

$columns = array('#', gprop('tabletype'), gprop('startlogicalblockaddress'), gprop('count'));
$widths = array(15, 25, 40, 20);
table_header(gprop('checktable'), count($columns));
table_head(array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
table_header_cols($columns, $widths);

$all = 0;
if (!isset($_GET['rct']) && !isset($_GET['wct']) && !isset($_GET['ibt']))
  $all = 1;

$nextIndex = 0;
if (isset($_GET['rct']) || $all) {
  for ($i = 0; $i < $errtablerct->entrynum; $i++) {
    $columns = array($i, gprop('readchecktable'), $errtablerct->entrys[$i]->entry, $errtablerct->entrys[$i]->count);

    table_row_cols($columns);
  }

  $nextIndex = $i;
}

if (isset($_GET['wct']) || $all) {
  for ($i = 0; $i < $errtablewct->entrynum; $i++) {
    if ($all)
      $columns = array(
      $i + $nextIndex, gprop('writechecktable'), $errtablewct->entrys[$i]->entry,
      $errtablewct->entrys[$i]->count
    );
    else
      $columns = array(
      $i, gprop('writechecktable'), $errtablewct->entrys[$i]->entry,
      $errtablewct->entrys[$i]->count
    );

    table_row_cols($columns);
  }

  $nextIndex += $i;
}

if (isset($_GET['ibt']) || $all) {
  for ($i = 0; $i < $errtableibt->entrynum; $i++) {
    if ($all)
      $columns = array(
      $i + $nextIndex, gprop('inconsistentblocktable'),
      $errtableibt->entrys[$i]->entry, $errtableibt->entrys[$i]->count
    );
    else
      $columns = array(
      $i, gprop('inconsistentblocktable'), $errtableibt->entrys[$i]->entry,
      $errtableibt->entrys[$i]->count
    );

    table_row_cols($columns);
  }

  $nextIndex += $i;
}

if (($all && $nextIndex == 0) || (isset($_GET['ibt']) && $errtableibt->entrynum == 0) || (isset($_GET['wct']) && $errtablewct->entrynum == 0) || (isset($_GET['rct']) && $errtablerct->entrynum == 0)) {
  table_row_empty(gresult('noentries'), count($columns));
}
table_foot();
table_foot();
table_foot();
} else {
  table_foot_fold();
  table_head(array('class' => 'tableBorder'));
  table_head(array('pad' => 0, 'space' => 1, 'border' => 0));
  table_row_empty(gerror('displaychecktable'));
  table_foot();
  table_foot();
  table_foot();
}

table_tab_foot();
page_foot();
?>
  </table>
</form>
</body>

</html>
