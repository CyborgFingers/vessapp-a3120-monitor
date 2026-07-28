<?php

/*******************************************************************************
 * Filename    : spare.php
 * Description : islaVista spare drive info.
 * Created on  : 09/04/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';
include 'front.php';

if (isset($_GET['ssid']))
  SetSessionValue('ssid', $_GET['ssid']);
if (isset($_GET['spid']))
  SetSessionValue('spid', $_GET['spid']);

$server = getServer();
$subsystem = $server->getSubsystem();
$encls = $subsystem->getEnclosures();
$noencl = null;
if (count($encls) == 0)
  $noencl = gresult('nosep');
$flats = 0;
foreach ($encls as $eid => $encl) {
  $numslots = count($encl->_slots);
  foreach ($encl->_slots as $sid => $slot) {
    $encls[$eid]->_flats[$sid] = $flats;
    $flats++;
  }
}

$spares = $subsystem->getSpareDrive($_SESSION['spid']);
$result = getLastError();
if ($result == PIERR_NO_ERROR) {
  if (count($spares))
    $spare = $spares[0];
  $physicalDrives = $subsystem->getPhysicalDrive($spare->pdd_PDFlatId);
  $result = getLastError();
}

if ($result == PIERR_NO_ERROR) {
  if (count($physicalDrives))
    $physicalDrive = $physicalDrives[0];
  else
    $result = PIERR_PHYDRV_NOT_FOUND;
}

if ($result == PIERR_NO_ERROR) {
  $physicalDrives = $subsystem->getPhysicalDrives();
  $result = getLastError();
}
if ($result == PIERR_NO_ERROR) {
  $diskArrays = $subsystem->getDiskArrays();
  $unplugPDs = getUnplugPDs($physicalDrives, $diskArrays);
}


if ($result == PIERR_INACTIVE_SESSION) {
  errorOnInvalidSession();
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

<body>
<form method=post>
<table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('sparedrive'), 'spare', $note, $result);
table_tab_head();

$tabTitle = tabTitleSpare();
table_tab($tabTitle);
if ($result == PIERR_NO_ERROR) {
  $string = null;
  if (isset($physicalDrive))
    $string = gprop('locatesparedesc');
  //exist encl
  if (!isset($noencl))
    showFrontImage($encls, $physicalDrives, $unplugPDs, $string, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => ''));
  else {
    table_head_fold(gprop('enclosurefrontview'), 'FV');
    table_row_empty($noencl);
    table_foot_fold();
  }
  table_tab_foot();

  if (isset($physicalDrive) && isset($_GET['locate']))
    locatePhyDrv($subsystem, $physicalDrive);

  table_head_fold(gprop('sparedriveinfo'), 'SD', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

  if (!isset($physicalDrive))
    table_row_empty(galert('sparedrivenotpresent'));
  else {
    table_row(gprop('spareid'), $spare->acd_ArrayID);
    table_row(gprop('physicaldriveid'), $physicalDrive->pdi_FlatId + 1);
    if ($spare) {
      table_row(gprop('sparetype'), $spare->acd_ArrayAttributes);
      if ($spare->acd_ArrayAttributes == 'Dedicated')
        table_row(gprop('dedicatedtodiskarray'), getSpareAssociatedArrays($subsystem, $spare));
      table_row(gprop('revertiblespare'), $spare->acd_ArrayFlags ? 'Yes' : 'No');
      $sparechecks = $subsystem->getSpareCheckProgress($spare->acd_ArrayID);
      $result = getLastError();
    }
    table_row(gprop('opstatus'), $physicalDrive->_opStatusEx, null, getStatusColor($physicalDrive->_opStatusEx));
    if ($spare) {
      if ($result == PIERR_NO_ERROR)
        $state = $sparechecks[0]->state;
      else
        $state = gresult('getstatusfail');
      table_row(gprop('sparecheckstatus'), $state);
    }
    table_row(gprop('physicalcapacity'), $physicalDrive->pdi_PhysicalSize);
    table_row(gprop('configurablecapacity'), $physicalDrive->pdi_ConfigurableSize);
    table_row(gprop('blocksize'), $physicalDrive->pdi_BlockSize . ' Bytes');
    table_row(gprop('driveinterface'), $physicalDrive->pdi_DriveInterface);
    table_row(gprop('model'), $physicalDrive->pdi_DriveModelNo);
    table_row(gprop('location'), gprop('enclosure') . ' ' . ($physicalDrive->pdi_EnclosureId + 1) . ' ' . gprop('slot') . ' ' . $physicalDrive->pdi_SlotId);
    table_row(gprop('configurationstatus'), $physicalDrive->_cfgStatus);
    table_row(gprop('serialnumber'), $physicalDrive->pdi_DriveSerialNo);
    table_row(gprop('firmwareversion'), $physicalDrive->pdi_DriveFirmwareVer);
  }

  table_foot_fold();
} //NO_ERROR
else {
  table_head_fold(gprop('sparedriveinfo'), 'content');
  table_row_empty(gerror('displayspareinfo'));
  table_foot_fold();
  table_tab_foot();
}
page_foot();
if ($result == PIERR_NO_ERROR)
  addFrontViewEvents($encls, $physicalDrives);
?>
  </table>
</form>

<script>
<?php
if (isset($physicalDrive)) {
?>
  var i=<? echo $physicalDrive->pdi_FlatId ?>;
  highlightPD(i);
  var obj=ylib_getObj('pdimg'+i);
  ylib_addEvt(obj,'click',new Function('locatePD('+i+')'));
  obj.style.cursor="hand";

  function locatePD(i)
  {
    window.location.href='spare.php?pdid='+i+'&locate';
  }
<?php

}
?>
</script>

</body>
</html>
