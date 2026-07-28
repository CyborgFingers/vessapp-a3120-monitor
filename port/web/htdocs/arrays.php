<?php

/*******************************************************************************
 * Filename    : arrays.php
 * Description : islaVista disk array list.
 * Created on  : 04/01/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_GET['ssid']))
  SetSessionValue('ssid', $_GET['ssid']);

$server = getServer();
$subsystem = $server->getSubsystem();
$ctrl = $subsystem->getControllerSettings(0);
$diskArrays = $subsystem->getDiskArrays();

$golmmsession = 0;
if (isset($_SESSION['golmmsession'])) {
  $golmmsession = 1;
  unset($_SESSION['golmmsession']);
}
$note = isset($_GET['note']) ? $_GET['note'] : null;
SyncSession();
?>
<html>

<head>
<?php
page_head();
?>
</head>
<!--Disable jump to lmm.php-->
<!--<body<?= (isset($_GET['golmm']) && $golmmsession && $ctrl->cs_LUNMasking) ? ' onload=golmm()' : '' ?>>-->
<body>
<form method=post  >
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('diskarrays'), 'diskArray', $note);
table_tab_head();

$tabTitle = tabTitleArrays($subsystem);
table_tab($tabTitle);

table_head_fold(gprop('diskarraylist'), 'DA', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

$columns = array(gprop('device'), gprop('alias'), gprop('status'), gprop('configurablecapacity'), gprop('freecapacity'));
$widths = array(20, 20, 20, 20, 20);

table_header_cols($columns, $widths);

if (count($diskArrays) == 0)
  table_row_empty(gresult('nodainsubsystem'), count($columns));
else {
  for ($i = 0; $i < count($diskArrays); $i++) {
    $diskArray = $diskArrays[$i];
    $a = array('DA' . $diskArray->acd_ArrayID, 'array.php?daid=' . $diskArray->acd_ArrayID);
    $columns = array(
      $a, preg_replace("/\s/", "&nbsp;", $diskArray->acd_ArrayName), array($diskArray->_opStatus, null, getStatusColor($diskArray->_opStatus)),
      $diskArray->_cfgSize, $diskArray->_availableSize
    );
    table_row_cols($columns);
  }
}

table_foot_fold();
table_tab_foot();

page_foot();
if ($subsystem->frontendType == PI_FB_HOST_INTERFACE_SCSI)
  $lmmflag = 'true';
else
  $lmmflag = 'false';

?>
  </table>
</form>

<script>
  function golmm()
  {
    if(window.confirm('<?= gjsresult('createdaokeditlum') ?>'))
    {
      str='<?= $lmmflag ?>';
      if(str=='true')
        document.location.href='lmm2.php?lmmid=0&tab=0';
      else
        document.location.href='lmm.php?tab=1';
    }
  }
</script>
</body>

</html>
