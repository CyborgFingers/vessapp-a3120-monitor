<?php

/*******************************************************************************
 * Filename    : array.php
 * Description : islaVista disk array information.
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
if (isset($_GET['daid']))
  SetSessionValue('daid', $_GET['daid']);
if (isset($_SESSION['mountpoints']))
  unset($_SESSION['mountpoints']);
$golmmsession = 0;
if (isset($_SESSION['golmmsession'])) {
  $golmmsession = 1;
  unset($_SESSION['golmmsession']);
}
SyncSession();
$server = getServer();
$subsystem = $server->getSubsystem();
if (PI_FB_HOST_INTERFACE_NAS && ($subsystem->frontendType == PI_FB_HOST_INTERFACE_ISCSI))
  $isnas = 1;
else
  $isnas = 0;
$subsystem->getDeviceCapabilities();
$ctrl = $subsystem->getControllerSettings(0);
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
$note = isset($_GET['note']) ? $_GET['note'] : null;
$result = 0;
if (isset($_GET['accept'])) {
  $result = $subsystem->processIncompleteArray($_SESSION['daid']);
  if ($result == 0) {
    $note = gresult('arrayprocessok');
    refreshTreeFrame();
  } else
    $note = gresult('arrayprocessfail');
} elseif (isset($_GET['acceptmissingwatermark'])) {
  $result = $subsystem->processMissingWatermark($_SESSION['daid']);
  if ($result == 0) {
    $note = gresult('nvramprocessok');
    refreshTreeFrame();
  } else
    $note = gresult('nvramprocessfail');
}
$physicalDrives = $subsystem->getPhysicalDrives();
$diskArrays = $subsystem->getDiskArrays();
$unplugPDs = getUnplugPDs($physicalDrives, $diskArrays);
$diskArray = $subsystem->getDiskArray($_SESSION['daid']);
$spareDrives = $subsystem->getSpareDrives();
$logicalDrives = $diskArray->acd_LDs;
/*
$mountpointstr = array();
for ($i = 0; $i < count($logicalDrives); $i++) {
  if ($logicalDrives[$i]->ldd_isNasLd == 'yes') {
    $mountpointstr[] = $logicalDrives[$i]->ldd_LDName;
  }
}
SetSessionValue('mountpoints', $mountpointstr);
 */
SetSessionValue('EnableMigration', 1);
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html>

<head>
<?php
page_head();
if (isset($_GET['locate'])) {
  $note = gresult('locatearraymsg');
}
?>
<script language="javascript" type="text/javascript">
  function onLoad()
  {
    <? if (!isset($diskArray) || $diskArray == null || $diskArray->acd_ArrayID != $_GET['daid']) { ?>
    self.location.href="arrays.php?ssid=<?= $_GET['ssid'] ?>&tab=0";
    <?
  } ?>
	  return;
  }
</script>
</head>

<!--Disable jump to lmm.php or lmm2.php-->
<!--<body <?= (isset($_GET['golmm']) && $golmmsession && $ctrl->cs_LUNMasking) ? (($subsystem->frontendType == PI_FB_HOST_INTERFACE_SCSI) ? 'onload=golmm2()' : 'onload=golmm()') : '' ?> >-->
<body onload="javascript:onLoad()">
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center
    bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('diskarray') . ' ' . $diskArray->acd_ArrayID, 'diskArray', $note, $result);
table_tab_head();
$s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner');
$tabTitle = tabTitleArray($subsystem, $diskArray->acd_ArrayID);
table_tab($tabTitle);

$string = gprop('locatedadesc');
//exist encl
if (!isset($noencl))
  showFrontImage($encls, $physicalDrives, $unplugPDs, $string, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => ''));
else {
  table_head_fold(gprop('enclosurefrontview'), 'FV', null, null, $s);
  table_row_empty($noencl);
  table_foot_fold();
}
table_tab_foot();

if (isset($_GET['locate']))
  locateArray($subsystem, $diskArray);

// disk array info
table_head_fold(gprop('diskarrayinfo'), 'DA', null, null, $s);

table_row(gprop('diskarrayid'), $diskArray->acd_ArrayID, 40);
table_row(gprop('alias'), preg_replace("/\s/", "&nbsp;", $diskArray->acd_ArrayName));
table_row(gprop('opstatus'), $diskArray->_opStatusEx, null, getStatusColor($diskArray->_opStatusEx));
table_row(gprop('totalphysicalcapacity'), $diskArray->_physicalSize);
table_row(gprop('configurablecapacity'), $diskArray->_cfgSize);
table_row(gprop('freecapacity'), $diskArray->_availableSize);
table_row(gprop('maxcontiguousfreecapacity'), $diskArray->_maxSupportedSize);
table_row(gprop('mediapatrol'), getEnableString($diskArray->acd_ArrayFlags & ARRAY_FLAG_MP_ENABLE));
table_row(gprop('drivehealthpolling'), getEnableString($diskArray->acd_ArrayFlags & ARRAY_FLAG_PDM_ENABLE));
table_row(gprop('PowerManagement'), $diskArray->acd_PowerManagement);
table_row(gprop('numberofphysicaldrives'), $diskArray->acd_NoOfPhysicalDrives);
table_row(gprop('numberoflogicaldrives'), $diskArray->acd_NoOfLogicalDrives);
table_row(gprop('availableraidlevels'), $diskArray->_raidLevels);

table_foot_fold();

// physical drive list
table_head_fold(gprop('pdsinda'), 'PDs', null, null, $s);

$columns = array(gprop('device'), gprop('model'), gprop('type'), gprop('configurablecapacity'), gprop('status'));
$widths = array(20, 30, 15, 15, 20);
table_header_cols($columns, $widths);

$PDs = $diskArray->acd_PDs;
for ($i = 0; $i < count($PDs); $i++) {
  $phyDrvs = $subsystem->getPhysicalDrive($PDs[$i]->pdd_PDFlatId);
  $a = array('PD' . ($PDs[$i]->pdd_PDFlatId + 1), 'phydrv.php?pdid=' . $PDs[$i]->pdd_PDFlatId);
  if (isset($phyDrvs[0])) {
    $physicalDrive = $phyDrvs[0];
    $columns = array(
      $a, $physicalDrive->pdi_DriveModelNo, isset($physicalDrive->pdi_DriveType) ? $physicalDrive->pdi_DriveTypeString : gprop('sata'),
      $PDs[$i]->pdd_PDConfigurableSize, array($PDs[$i]->pdd_PDFlags, null, getStatusColor($PDs[$i]->pdd_PDFlags))
    );
  } else
    $columns = array($a, gprop('unknown'), gprop('unknown'), $PDs[$i]->pdd_PDConfigurableSize, array($PDs[$i]->pdd_PDFlags, null, getStatusColor($PDs[$i]->pdd_PDFlags)));
  table_row_cols($columns);
}

$trPDs = $diskArray->_transitionPDs;
if (count($trPDs)) {
  table_header0(gprop('transitionpds'), count($columns));
  $columns = array(gprop('device'), gprop('model'), gprop('type'), gprop('configurablecapacity'), gprop('status'));
  $widths = array(20, 30, 15, 15, 20);
  table_header_cols($columns, $widths);
  for ($i = 0; $i < count($trPDs); $i++) {
    $a = array('PD' . ($trPDs[$i]->pdi_FlatId + 1), 'phydrv.php?pdid=' . $trPDs[$i]->pdi_FlatId);
    $columns = array(
      $a, $trPDs[$i]->pdi_DriveModelNo, $trPDs[$i]->pdi_DriveType, $trPDs[$i]->pdi_ConfigurableSize,
      array($trPDs[$i]->_opStatus, null, getStatusColor($trPDs[$i]->_opStatus))
    );
    table_row_cols($columns);
  }
}

table_foot_fold();

// logical drive list
table_head_fold(gprop('ldsinda'), 'LDs', null, null, $s);
$columns = array(gprop('device'), gprop('alias'), gprop('raidlevel'), gprop('capacity'), gprop('status'));
// $isnas ? array_push($columns, gprop("fornas")) : null;
// $isnas ? $widths = array(20, 30, 15, 15, 12, 8) : $widths = array(20, 30, 15, 15, 20);
table_header_cols($columns, $widths);

$logicalDrives = $diskArray->acd_LDs;
for ($i = 0; $i < count($logicalDrives); $i++) {
  $logicalDrive = $logicalDrives[$i];
  $a = array('LD' . $logicalDrive->ldd_LDFlatID, 'logdrv.php?ldid=' . $logicalDrive->ldd_LDFlatID . '&daid=' . $diskArray->acd_ArrayID);
  $columns = array(
    $a, $logicalDrive->ldd_LDName, $logicalDrive->ldd_RaidLevel, $logicalDrive->ldd_LDSizeInBlocks,
    array($logicalDrive->ldd_LDFlags, null, getStatusColor($logicalDrive->ldd_LDFlags))
  );
  $isnas ? array_push($columns, gprop($logicalDrive->ldd_isNasLd)) : null;
  table_row_cols($columns);
}

if (count($logicalDrives) == 0)
  table_row_empty(gresult('noldsinda'), count($columns));

table_foot_fold();

table_head_fold(gprop('sparesinda'), 'SD', null, null, $s);
$columns = array(gprop('device'), gprop('physicaldriveid'), gprop('revertible'), gprop('type'), gprop('capacity'), gprop('status'));
$widths = array(20, 20, 10, 15, 15, 20);
table_header_cols($columns, $widths);

$sparesinarray = 0;
for ($i = 0; $i < count($spareDrives); $i++) {
  $spareDrive = $spareDrives[$i];
  if ($spareDrive->acd_ArrayAttributes == 'Dedicated' && !in_array($_SESSION['daid'], $spareDrive->acd_NoOfArraysAssociated))
    continue;

  // if ($diskArray->_cfgSize_str > $spareDrive->pdd_PDConfigurableSize_str)
    // continue;

  $sparesinarray++;
  $a = array('Spare ' . $spareDrive->acd_ArrayID, 'spare.php?spid=' . $spareDrive->acd_ArrayID);
  $columns = array(
    $a, $spareDrive->pdd_PDFlatId + 1,
    $spareDrive->acd_ArrayFlags ? 'Yes' : 'No',
    $spareDrive->acd_ArrayAttributes, $spareDrive->pdd_PDConfigurableSize, $spareDrive->_OpStatus
  );
  table_row_cols($columns);
}
if ($sparesinarray == 0)
  table_row_empty(gresult('nospareinarray'), count($columns));

table_foot_fold();

page_foot();

addFrontViewEvents($encls, $physicalDrives);
?>

  </table>
</form>

<script>
  function getreturn(ret)
  {
    var alertmsg="<? echo gjsalert('errconfirm') ?>";
    if(ret==1)
    {
      document.location="array.php?accept";
    } else if (ret=='cancelled')
    {
    } else {
      alert(alertmsg);
    }
  }

<?php
  if ($diskArray->_opStatus == 'Incomplete') {
    if (strstr($diskArray->_opStatusEx, 'Missing PD')) {
      ?>
      var string='<?= gjsalert('missingpd') ?>';
      showConfirm(string,getreturn);
<?php

    } else if (strstr($diskArray->_opStatusEx, 'Missing Watermark')) {
      ?>
      ret=confirm("<?= gjsalert('missingwatermark') ?>");
      if (ret)
        document.location="array.php?acceptmissingwatermark";
<?php

    }
  }

  for ($i = 0; $i < count($PDs); $i++) {
    ?>
    highlightPD(<? echo $PDs[$i]->pdd_PDFlatId ?>);
    var obj=ylib_getObj('pdimg<? echo $PDs[$i]->pdd_PDFlatId ?>');
    ylib_addEvt(obj,'click',new Function('locateArray()'));
    if(obj){
      obj.style.cursor="hand";
      ylib_addEvt(obj,'mouseover',new Function('overArray(<? echo $PDs[$i]->pdd_PDFlatId ?>)'));
      ylib_addEvt(obj,'mouseout',new Function('outArray(<? echo $PDs[$i]->pdd_PDFlatId ?>)'));
    }
<?php

  }
?>
  function locateArray()
  {
    window.location.href='array.php?locate';
  }

  function golmm()
  {
    if(window.confirm('<?= gjsresult('createldokeditlum') ?>'))
      document.location.href='lmm.php?tab=1';
  }

	function golmm2()
	{
		if(window.confirm('<?= gjsresult('createldokeditlum') ?>'))
			document.location.href='lmm2.php?lmmid=0&tab=0';
	}
</script>
</body>

</html>
