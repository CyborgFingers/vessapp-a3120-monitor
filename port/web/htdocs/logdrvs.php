<?php

/*******************************************************************************
 * Filename    : logdrvs.php
 * Description : islaVista logical drives information.
 * Created on  : 04/01/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_GET['daid']))
  SetSessionValue('daid', $_GET['daid']);

$specific = 'empty';
if (!isset($_GET['daid']))
  $specific = 'summary';

$server = getServer();
$subsystem = $server->getSubsystem();
if (isset($_GET['daid']))
  $diskArray = $subsystem->getDiskArray($_SESSION['daid']);

if (PI_FB_HOST_INTERFACE_NAS && ($subsystem->frontendType == PI_FB_HOST_INTERFACE_ISCSI))
  $isnas = 1;
else
  $isnas = 0;
$lunAffinitySupport = $subsystem->getLunAffinitySupport();
$logicalDrives = $subsystem->getLogicalDrives();

$note = isset($_GET['note']) ? $_GET['note'] : null;
?>
<html>
<head>
<?php
page_head();
SetSessionValue('lastfile', htmlspecialchars($_SERVER['PHP_SELF']) . (isset($_GET['daid']) ? ('?daid=' . $_GET['daid']) : ''));
SyncSession();
?>
<script language="javascript" type="text/javascript">
function onLoad()
{
    <?
    if (isset($_GET['daid'])) {
      if (!isset($diskArray) || $diskArray == null || $diskArray->acd_ArrayID != $_GET['daid']) {
        ?>
    self.location.href="arrays.php?ssid=<?= $_GET['ssid'] ?>&tab=0";
    <?
  }
}
?>
    return;
}
</script>
</head>

<body onload="javascript:onLoad()">
<form method=post  >
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
if ($language == 'zh_CN' || $language == 'zh_TW') {
  table_title(gprop('logicaldrives') . (isset($_GET['daid']) ? (gprop('in') . gprop('diskarray') . ' ' . $_GET['daid']) : ''), 'logicaldrive', $note, null, $specific);
} else if ($language == 'en_US') {
  table_title(gprop('logicaldrives') . (isset($_GET['daid']) ? (' ' . gprop('in') . ' the ' . gprop('diskarray') . ' ' . $_GET['daid']) : ''), 'logicaldrive', $note, null, $specific);
} else
  table_title(gprop('logicaldrives') . (isset($_GET['daid']) ? (' ' . gprop('in') . ' ' . gprop('diskarray') . ' ' . $_GET['daid']) : ''), 'logicaldrive', $note, null, $specific);
table_tab_head();

$tabTitle = tabTitleLDs(isset($_GET['daid']) ? $_GET['daid'] : null);
table_tab($tabTitle);

for ($i = 0, $count = 0; $i < count($logicalDrives); $i++)
  if (!isset($_GET['daid']) || $logicalDrives[$i]->ldi_ArrayId == $_GET['daid'])
  $count++;

table_head_fold(gprop('logicaldrivelist') . ' (' . $count . ' ' . ($count > 1 ? gprop('logicaldrives') : gprop('logicaldrive')) . ')', 'LD', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
$aliastitle = $isnas ? gprop('aliasnas') : gprop('alias');
if ($lunAffinitySupport) {
  $columns = array(gprop('device'), $aliastitle, gprop('raidlevel'), gprop('capacity'), gprop('diskarrayid'), gprop('stripe'), gprop('sector'), gprop('preferredctrlid'), gprop('status'));
  $isnas ? array_push($columns, gprop("fornas")) : null;
  $isnas ? $widths = array(7, 17, 8, 12, 10, 8, 8, 14, 14, 8) : $widths = array(7, 14, 8, 14, 10, 8, 8, 17, 14);
} else {
  $columns = array(gprop('device'), $aliastitle, gprop('raidlevel'), gprop('capacity'), gprop('diskarrayid'), gprop('stripe'), gprop('sector'), gprop('status'));
  $isnas ? array_push($columns, gprop("fornas")) : null;
  $isnas ? $widths = array(10, 17, 14, 14, 10, 10, 10, 14, 6) : $widths = array(10, 14, 14, 14, 10, 10, 14, 14);
}


table_header_cols($columns, $widths);

for ($i = 0; $i < count($logicalDrives); $i++) {
  $logicalDrive = $logicalDrives[$i];
  if (isset($_GET['daid']) && $logicalDrive->ldi_ArrayId != $_GET['daid']) continue;
  $a = array('LD' . $logicalDrive->ldi_FlatId, 'logdrv.php?ldid=' . $logicalDrive->ldi_FlatId . (isset($_GET['daid']) ? ('&daid=' . $logicalDrive->ldi_ArrayId) : ''));
  if ($lunAffinitySupport) {
    $columns = array(
      $a, preg_replace("/\s/", "&nbsp;", $logicalDrive->ldi_LDName), $logicalDrive->ldi_RaidLevel, $logicalDrive->ldi_Size, $logicalDrive->ldi_ArrayId,
      $logicalDrive->ldi_StripeSize, $logicalDrive->ldi_SectorSize, $logicalDrive->ldi_PreferredOwnerControllerId, array($logicalDrive->_opStatus, null, getStatusColor($logicalDrive->_opStatus))
    );
    $isnas ? array_push($columns, gprop($logicalDrive->ldi_isNasLd)) : null;
  } else {
    $columns = array(
      $a, preg_replace("/\s/", "&nbsp;", $logicalDrive->ldi_LDName), $logicalDrive->ldi_RaidLevel, $logicalDrive->ldi_Size, $logicalDrive->ldi_ArrayId,
      $logicalDrive->ldi_StripeSize, $logicalDrive->ldi_SectorSize, array($logicalDrive->_opStatus, null, getStatusColor($logicalDrive->_opStatus))
    );
    $isnas ? array_push($columns, gprop($logicalDrive->ldi_isNasLd)) : null;
  }
  table_row_cols($columns);
}
if ($count == 0)
  table_row_empty(gresult('noldin' . (isset($_GET['daid']) ? 'da' : 'subsystem')), count($columns));

table_foot_fold();
table_tab_foot();
page_foot();
?>
  </table>
</form>
</body>
</html>
