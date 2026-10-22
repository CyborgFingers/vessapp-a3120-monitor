<?php

/*******************************************************************************
 * Filename    : phydrvs.php
 * Description : islaVista physical drives information.
 * Created on  : 04/01/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';
include 'front.php';

if (isset($_GET['ssid']))
  SetSessionValue('ssid', $_GET['ssid']);

SyncSession();
$server = getServer();
$subsystem = $server->getSubsystem();
$encls = $subsystem->getEnclosures();

$flats = 0;
foreach ($encls as $eid => $encl) {
  $numslots = count($encl->_slots);
  foreach ($encl->_slots as $sid => $slot) {
    $encls[$eid]->_flats[$sid] = $flats;
    $flats++;
  }
}

foreach ($encls as $eid => $encl)
  if ($eid != $_SESSION['ssid'])
  unset($encls[$eid]);

$noencl = null;
if (count($encls) == 0)
  $noencl = gresult('nosep');
$physicalDrives = $subsystem->getPhysicalDrives();
$diskArrays = $subsystem->getDiskArrays();
$unplugPDs = getUnplugPDs($physicalDrives, $diskArrays);
if (isset($_GET['daid']))
  $diskArray = $subsystem->getDiskArray($_GET['daid']);

function onePD($physicalDrive)
{
  if ($physicalDrive == null) return;
  $a = array('PD' . ($physicalDrive->pdi_FlatId + 1), 'phydrv.php?pdid=' . $physicalDrive->pdi_FlatId);
  $hds = webpam_hdsentinel($physicalDrive->pdi_DriveSerialNo ?? '', $physicalDrive->pdi_OsName ?? '');
  $hcell = $hds
    ? array($hds['health'] . '%', null, ($hds['health'] < 50 ? 'red' : ($hds['health'] < 90 ? '#ffa800' : null)))
    : '-';
  $columns = array(
    $a, $physicalDrive->pdi_DriveModelNo, $physicalDrive->pdi_DriveTypeString, $physicalDrive->pdi_ConfigurableSize,
    isset($physicalDrive->pdi_SlotId)
      ? ('Encl ' . ($physicalDrive->pdi_EnclosureId + 1) . ' Slot ' . $physicalDrive->pdi_SlotId)
      : ('Internal' . (isset($physicalDrive->pdi_OsName) && $physicalDrive->pdi_OsName !== '' ? (' (' . $physicalDrive->pdi_OsName . ')') : '')),
    array($physicalDrive->_opStatus, null, getStatusColor($physicalDrive->_opStatus)), $physicalDrive->_cfgStatus, $hcell
  );
  table_row_cols($columns, null, 'pd' . (isset($physicalDrive->pdi_SlotId) ? (int)$physicalDrive->pdi_SlotId : $physicalDrive->pdi_FlatId));
}

$note = isset($_GET['note']) ? $_GET['note'] : null;
?>

<html>
<head>
<?php
page_head();
if (isset($_GET['locate'])) {
  $note = gresult('locatedrivemsg');
}

?>
</head>

<body>
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center
    bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('physicaldrives'), 'physicaldrive', $note);
table_tab_head();

$tabTitle = tabTitlePDs(isset($_GET['daid']) ? $_GET['daid'] : null);
table_tab($tabTitle);

//exist encl
if (!isset($noencl))
  showFrontImage($encls, $physicalDrives, $unplugPDs, count($physicalDrives) ? gprop('locatelddesc') : null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => ''));
else {
  table_head_fold(gprop('enclosurefrontview'), 'FV', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
  table_row_empty($noencl);
  table_foot_fold();
}

table_tab_foot();

table_head_fold(gprop('physicaldrivelist'), 'PD', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
$columns = array(gprop('device'), gprop('model'), gprop('type'), gprop('configurablecapacity'), gprop('location'), gprop('opstatus'), gprop('configurationstatus'), 'Health');
$widths = array(10, 18, 7, 14, 12, 14, 16, 9);
table_header_cols($columns, $widths);

if (isset($_SESSION['pegasus'])) {
  for ($i = 0; $i < count($physicalDrives); $i++) {
    $physicalDrive = $physicalDrives[$i];
    $status = $physicalDrive->getProperty('OperationalStatus');
    $columns = array(
      $physicalDrive->getProperty('DriveId'), $physicalDrive->getProperty('Model'),
      $physicalDrive->getProperty('Size'), getStringOpStatus($status[0])
    );
    table_row_cols($columns);
  }
} else {
  if (isset($diskArray)) {
    $PDs = $diskArray->acd_PDs;
    for ($i = 0; $i < count($PDs); $i++)
      onePD(getPDFromId($physicalDrives, $PDs[$i]->pdd_PDFlatId));
    if (count($PDs) == 0)
      table_row_empty(gresult('nopdsinda'), count($columns));
  } else {
    $pdCount = 0;
    for ($i = 0; $i < count($physicalDrives); $i++) {
      if (!isset($physicalDrives[$i]->pdi_EnclosureId))
        continue;
      if ($physicalDrives[$i]->pdi_EnclosureId != $_SESSION['ssid'])
        continue;
      onePD($physicalDrives[$i]);
      $pdCount++;
    }
    if ($pdCount == 0)
      table_row_empty(gresult('nopdsinsubsystem'), count($columns));
  }
}

table_foot_fold();

if (isset($_GET['locate']))
  locatePhyDrv($subsystem, getPDFromId($physicalDrives, $_GET['locate']));

page_foot();
addFrontViewEvents($encls, $physicalDrives);
?>
  </table>
</form>

<script>
  for (var i=0;i<256;i++) {
    var obj=ylib_getObj('pdimg'+i);
    if (!obj) continue;
    if (physicalDrives[i]) {
      obj.style.cursor="hand";
      ylib_addEvt(obj,'mouseover',new Function('overPD('+i+')'));
      ylib_addEvt(obj,'mouseout',new Function('outPD('+i+')'));
      ylib_addEvt(obj,'click',new Function('locatePD('+i+')'));
      ylib_addEvt(ylib_getObj('pd'+i),'mouseover',new Function('overPD('+i+')'));
      ylib_addEvt(ylib_getObj('pd'+i),'mouseout',new Function('outPD('+i+')'));
    }
  }

  function locatePD(i)
  {
    window.location.href='phydrvs.php?locate='+i;
  }
</script>

</body>
</html>

<?php
if (isset($_SESSION['pegasus']))
  $server->logout();
?>
