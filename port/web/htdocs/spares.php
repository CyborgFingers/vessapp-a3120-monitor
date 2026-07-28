<?php

/*******************************************************************************
 * Filename    : spares.php
 * Description : islaVista spare drive list.
 * Created on  : 06/01/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_GET['ssid']))
  SetSessionValue('ssid', $_GET['ssid']);

SyncSession();
$server = getServer();
$subsystem = $server->getSubsystem();
$spareDrives = $subsystem->getSpareDrives();
$result = getLastError();
if ($result == PIERR_INACTIVE_SESSION) {
  errorOnInvalidSession();
}

$note = isset($_GET['note']) ? $_GET['note'] : null;
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
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center
    bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('sparedrives'), 'sparedrive', $note, $result);
table_tab_head();
$s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner');
$tabTitle = tabTitleSpares($subsystem);
table_tab($tabTitle);
if ($result == PIERR_NO_ERROR) {
  table_head_fold(gprop('sparedrivelist'), 'SD', null, null, $s);

  $columns = array(
    gprop('id'), gprop('opstatus'), gprop('physicaldriveid'), gprop('capacity'), gprop('revertible'), gprop('type'),
    gprop('dedicatedtoarray')
  );
  $widths = array(10, 17, 12, 10, 10, 11, 20);
  table_header_cols($columns, $widths);

  if (count($spareDrives) == 0)
    table_row_empty(gresult('nospareinsubsystem'), count($columns));
  else {
    for ($i = 0; $i < count($spareDrives); $i++) {
      $spareDrive = $spareDrives[$i];

      $sparechecks = $subsystem->getSpareCheckProgress($spareDrive->acd_ArrayID);
      $result = getLastError();
      if ($result == PIERR_NO_ERROR) {
        $state = $sparechecks[0]->state;
      } else
        $state = gresult('getstatusfail');

      $daString = getSpareAssociatedArrays($subsystem, $spareDrive);
      $a = array('Spare ' . $spareDrive->acd_ArrayID, 'spare.php?spid=' . $spareDrive->acd_ArrayID);
      $columns = array(
        $a, $spareDrive->_OpStatus, $spareDrive->pdd_PDFlatId + 1,
        $spareDrive->pdd_PDConfigurableSize, $spareDrive->acd_ArrayFlags ? 'Yes' : 'No',
        $spareDrive->acd_ArrayAttributes, $daString
      );
      table_row_cols($columns);
    }
  }
} else {
  table_head_fold(gprop('sparedrivelist'), 'content', null, null, $s);
  table_row_empty(gerror('displaysparelist'));
  // table_foot_fold();
}
table_foot_fold();
table_tab_foot();
page_foot();
?>
  </table>
</form>

</body>
</html>

