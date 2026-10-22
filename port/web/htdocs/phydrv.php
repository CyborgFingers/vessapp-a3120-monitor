<?php

/*******************************************************************************
 * Filename    : phydrv.php
 * Description : islaVista physical drive info.
 * Created on  : 04/01/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';
include 'front.php';

if (isset($_GET['pdid']))
  SetSessionValue('pdid', $_GET['pdid']);

$server = getServer();
$subsystem = $server->getSubsystem();
$supportDualCtrl = $subsystem->supportdualcontroller();
if ($supportDualCtrl) {
  $phydrvsettings = $subsystem->getPhyDrvSettings();
  $capa = $subsystem->getDeviceCapabilities();
  if ($capa->SASDriveSupport) {
    $phydrvsassettings = $subsystem->getPhyDrvSettings(INVALID_PHYDRV_ID, PD_PAGE_SAS);
  }
}

$encls = $subsystem->getEnclosures();

$flats = 0;
foreach ($encls as $eid => $encl) {
  $numslots = count($encl->_slots);
  foreach ($encl->_slots as $sid => $slot) {
    $encls[$eid]->_flats[$sid] = $flats;
    $flats++;
  }
}

/*get the controller type*/
$currentCtrlId = $subsystem->getCurrentCtrlId();
$currentCtrl = $subsystem->getController($currentCtrlId->currentCtrlId);

$noencl = null;
if (count($encls) == 0)
  $noencl = gresult('nosep');
else
  $encl = $encls[0];
$physicalDrives = $subsystem->getPhysicalDrive($_SESSION['pdid']);

$physicalDrive = null;
if (isset($physicalDrives[0]))
  $physicalDrive = $physicalDrives[0];
if ($physicalDrive != null) {
  $encl = $encls[$physicalDrive->pdi_EnclosureId];
  unset($encls);
  $encls[$physicalDrive->pdi_EnclosureId] = $encl;
}



if ($physicalDrive && $physicalDrive->pdi_FlatId != $_SESSION['pdid']) {
  // PD might be missing
  $missings = $subsystem->getMissingPhyDrvs($_SESSION['pdid']);
  if ($missings[0]->pdi_FlatId = $_SESSION['pdid'])
    $physicalDrive = $missings[0];
}

$physicalDrives = $subsystem->getPhysicalDrives();
$diskArrays = $subsystem->getDiskArrays();
$unplugPDs = getUnplugPDs($physicalDrives, $diskArrays);
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
<form method=post  >
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
if (isset($_SESSION['pegasus']))
  table_title(gprop('physicaldrive') . ' ' . $physicalDrive->getProperty('DriveId'));
else {
  if ($physicalDrive == null)
    table_title(gprop('physicaldrive') . ' ' . ($_SESSION['pdid'] + 1), null, $note);
  else
    table_title(gprop('physicaldrive') . ' ' . ($physicalDrive->pdi_FlatId + 1), null, $note);
}
table_tab_head();

$tabTitle = tabTitlePD($subsystem, $physicalDrive);
table_tab($tabTitle);

if ($physicalDrive == null) {
  table_head_fold(gprop('physicaldriveinfo'), 'PD', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
  table_row_empty(galert('nopdinfo'));
  table_foot_fold();
  table_tab_foot();
} else {
  $string = gprop('locatecurrentdrivedesc');
  // exit encl
  if (!isset($noencl))
    showFrontImage($encls, $physicalDrives, $unplugPDs, $string, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => ''));
  else {
    table_head_fold(gprop('enclosurefrontview'), 'FV', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
    table_row_empty($noencl);
    table_foot_fold();
  }
  table_tab_foot();

  if (isset($_GET['locate']))
    locatePhyDrv($subsystem, $physicalDrive);

  $leftWidth = 30;
  if (isset($_SESSION['pegasus'])) {
    table_row(gprop('physicaldriveid'), $physicalDrive->getProperty('DriveId'));
    table_row(gprop('diskarrayid'), $physicalDrive->getProperty('PoolId'));
    table_row(gprop('enclosureid'), $physicalDrive->getProperty('EnclosureId'));
    table_row(gprop('slotnumber'), $physicalDrive->getProperty('SlotNumber'));
    $blockSize = $physicalDrive->getProperty('BlockSize');
    table_row(gprop('physicaldrivecapacity'), getStringDriveSize($physicalDrive->getProperty('Size'), $blockSize));
    table_row(gprop('configurablecapacity'), getStringDriveSize($physicalDrive->getProperty('ConfigurableSize'), $blockSize));
    table_row(gprop('usedcapacity'), getStringDriveSize($physicalDrive->getProperty('UsedSize'), $blockSize));
    table_row(gprop('blocksize'), $blockSize);
    table_row(gprop('drivetype'), $physicalDrive->getProperty('Type'));
    table_row(gprop('model'), $physicalDrive->getProperty('Model'));
    // table_row('Configuration Status',getPhysicalDriveStatus($physicalDrive));
    table_row(gprop('opstatus'), getStringOpStatusArray($physicalDrive->getProperty('OperationalStatus')));
    table_row(gprop('serialnumber'), $physicalDrive->getProperty('SerialNumber'));
    table_row(gprop('firmwareversion'), $physicalDrive->getProperty('FirmwareRevision'));
    table_row(gprop('supportedprotocols'), getStringFromArray($physicalDrive->getProperty('SupportedProtocols')));
    table_row(gprop('readcount'), $physicalDrive->getProperty('NumberOfReads'));
    table_row(gprop('writecount'), $physicalDrive->getProperty('NumberOfWrites'));
    table_row(gprop('errorcount'), $physicalDrive->getProperty('NumberOfErrors'));
  } else {
    table_head_fold(gprop('physicaldriveinfo'), 'PD', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
    /* smartctl detail to fill the identity rows the vendor backend leaves blank */
    $dd = webpam_drivedetail(
      isset($physicalDrive->pdi_DriveSerialNo) ? $physicalDrive->pdi_DriveSerialNo : '',
      isset($physicalDrive->pdi_OsName) ? $physicalDrive->pdi_OsName : ''
    );
    table_row(gprop('physicaldriveid'), $physicalDrive->pdi_FlatId + 1, $leftWidth);
    if (!isset($physicalDrive->_missing)) {
      table_row(gprop('location'), gprop('enclosure') . ' ' . ($physicalDrive->pdi_EnclosureId + 1) . ' ' . gprop('slot') . ' ' . $physicalDrive->pdi_SlotId);

      table_row(gprop('alias'), preg_replace("/\s/", "&nbsp;", $physicalDrive->pdi_DriveAlias));
    }
    table_row(gprop('physicalcapacity'), $physicalDrive->pdi_PhysicalSize ?: ($dd['capacity'] ?? ''));
    if (!isset($physicalDrive->_missing)) {
      table_row(gprop('configurablecapacity'), $physicalDrive->pdi_ConfigurableSize);
      table_row(gprop('usedcapacity'), $physicalDrive->pdi_UsedSize);
      table_row(gprop('blocksize'), ($physicalDrive->pdi_BlockSize ?: ($dd['logical_block'] ?? '')) . ' Bytes');
      $opEx = $physicalDrive->_opStatusEx;
      if ($opEx === null || $opEx === '') {   /* vendor backend left it blank -> derive from SMART */
        $sm = $dd['smart'] ?? '';
        if ($sm === 'PASSED') { table_row(gprop('opstatus'), 'OK', null, 'green'); }
        else if ($sm === 'FAILED') { table_row(gprop('opstatus'), 'Failing (SMART)', null, 'red'); }
        else { table_row(gprop('opstatus'), $opEx); }
      } else
        table_row(gprop('opstatus'), $opEx, null, getStatusColor($physicalDrive->_opStatus));
    } else
      table_row(gprop('opstatus'), $physicalDrive->_opStatus, null, 'red');
    table_row(gprop('configurationstatus'), $physicalDrive->_cfgStatus);
    table_row(gprop('model'), $physicalDrive->pdi_DriveModelNo);
    if (!isset($physicalDrive->_missing)) {
      table_row(gprop('driveinterface'), $physicalDrive->pdi_DriveInterface ?: webpam_iface($dd));
      table_row(gprop('serialnumber'), $physicalDrive->pdi_DriveSerialNo ?: ($dd['serial'] ?? ''));
      table_row(gprop('firmwareversion'), $physicalDrive->pdi_DriveFirmwareVer ?: ($dd['firmware'] ?? ''));
      table_row(gprop('protocolversion'), $physicalDrive->pdi_ProtocolMajorVer ?: ($dd['sata_version'] ?? ($dd['ata_version'] ?? '')));
    }
    table_row(gprop('visibleto'), $physicalDrive->_visibleto);
    /* HD Sentinel normalised drive health (if installed; else these rows vanish
     * and the native SMART fields above stand alone). hdsentinel.com */
    $hds = webpam_hdsentinel(
      isset($physicalDrive->pdi_DriveSerialNo) ? $physicalDrive->pdi_DriveSerialNo : '',
      isset($physicalDrive->pdi_OsName) ? $physicalDrive->pdi_OsName : ''
    );
    if ($hds) {
      $hcolor = $hds['health'] < 50 ? 'red' : ($hds['health'] < 90 ? '#ffa800' : null);
      table_row('HD Sentinel Health', $hds['health'] . '%', null, $hcolor);
      table_row('HD Sentinel Temperature', $hds['temp'] . ' C');
      table_row('Power-on Time', number_format($hds['power_on_h']) . ' hours');
      table_row('Health Source', 'Hard Disk Sentinel (hdsentinel.com)');
    }
    table_foot_fold();

    if (!isset($physicalDrive->_missing) && isset($physicalDrive->showAD) && ($physicalDrive->showAD == 1)) {
      table_head_fold(gprop('advancedpdinfo'), 'ADV', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

      if ($physicalDrive->pdi_DriveType == BEIT_SATA) {
        table_row_support(gprop('writecache'), $physicalDrive->pdi1_WriteCacheSupport, getEnableString($physicalDrive->pdi1_WriteCachedEnable));
        table_row_support(gprop('readlookahead'), $physicalDrive->pdi1_RLASupport, getEnableString($physicalDrive->pdi1_RLASupportEnable));
        table_row(gprop('smartfeatureset'), getSupportString($physicalDrive->pdi1_SMARTFeatureSetSupport));
        table_row(gprop('smartselftest'), getSupportString($physicalDrive->pdi1_SMARTSelfTestSupport));
        table_row(gprop('smarterrorlogging'), getSupportString($physicalDrive->pdi1_SMARTErrorLoggingSupport));
        table_row(gprop('commandqueuingsupport'), $physicalDrive->pdi1_CmdQueuingSupportString);
        if ($physicalDrive->pdi1_CmdQueuingSupported) {
          table_row(gprop('commandqueuing'), getEnableString($physicalDrive->pdi1_CmdQueuingEnable));
          table_row(gprop('queuedepth'), $physicalDrive->pdi1_QueueDepth);
        }
        table_row(gprop('maxmultipledmamodesupported'), $physicalDrive->pdi1_MultiwordDMaSupport);
        // table_row(gprop('multipledmamode'),$physicalDrive->pdi1_MultipleDmaMode);
        table_row(gprop('maxultradmamodesupported'), $physicalDrive->pdi1_UltraDmaModeSupport);
        table_row(gprop('dmamode'), $physicalDrive->pdi1_DmaMode);
        // table_row(gprop('mediumerrorthreshold'),$phydrvsettings->pds1_MediumErrorThreshold);

        // if(_isCClassCtrl($currentCtrl->ci_ProductID))
        {
          table_row(gprop('drivetemperature'), $physicalDrive->pdi_DriveTemperature);
          table_row(gprop('ReferenceDriveTemperature'), $physicalDrive->pdi_ReferenceDriveTemperature);
          table_row(gprop('PowerSavingMode'), $physicalDrive->pdi_PowerSavingMode);
        }

      } else if ($physicalDrive->pdi_DriveType == BEIT_SAS) {
        table_row_support(gprop('writecache'), $physicalDrive->pdi3_WriteCacheSupport, getEnableString($physicalDrive->pdi3_WriteCachedEnable));
        table_row_support(gprop('readlookahead'), $physicalDrive->pdi3_ReadLookAheadCacheSupport, getEnableString($physicalDrive->pdi3_ReadLookAheadCacheEnable));
        table_row_support(gprop('readcache'), $physicalDrive->pdi3_ReadCacheSupport, getEnableString($physicalDrive->pdi3_ReadCacheEnable));
        // table_row_support(gprop('commandqueuing'),$physicalDrive->pdi3_CmdQueuingSupport,getEnableString($physicalDrive->pdi3_CmdQueuingEnable));
        table_row(gprop('commandqueuing'), getEnableString($physicalDrive->pdi3_CmdQueuingEnable));
        table_row(gprop('commandqueuingsupport'), getYesString($physicalDrive->pdi3_CmdQueuingSupport));

        table_row(gprop('worldwidename'), $physicalDrive->pdi3_WWN);
        table_row(gprop('ports'), $physicalDrive->pdi3_NumOfPorts);
        table_row(gprop('port1speed'), $physicalDrive->pdi3_PhySpeedNegotiated1);
        table_row(gprop('port1sasaddress'), $physicalDrive->pdi3_SasAddress1);
        table_row(gprop('port2speed'), $physicalDrive->pdi3_PhySpeedNegotiated2);
        table_row(gprop('port2sasaddress'), $physicalDrive->pdi3_SasAddress2);
        table_row(gprop('mediumerrorthreshold'), $phydrvsassettings->pds3_MediumErrorThreshold);


        // if(_isCClassCtrl($currentCtrl->ci_ProductID))
        {
          table_row(gprop('drivetemperature'), $physicalDrive->pdi_DriveTemperature);
          table_row(gprop('ReferenceDriveTemperature'), $physicalDrive->pdi_ReferenceDriveTemperature);
          table_row(gprop('PowerSavingMode'), $physicalDrive->pdi_PowerSavingMode);
        }
      }

      table_foot_fold('ADV');
    }

    if (!isset($physicalDrive->_missing))
      table_foot_fold();
  }
}

page_foot();
addFrontViewEvents($encls, $physicalDrives);
?>

  </table>
</form>

<script>
  highlightPD(<? echo $physicalDrive->pdi_FlatId ?>);
  //var lastid=<? echo $physicalDrive->pdi_FlatId ?>+1;
  for(var i=0;i<256;i++){
    var obj=ylib_getObj('pdimg'+i);
    if(!obj) continue;

    if(physicalDrives[i]){
      obj.style.cursor="hand";
      if(i==<? echo $physicalDrive->pdi_FlatId + 0 ?>)
        ylib_addEvt(obj,'click',new Function('locatePD('+i+')'));
      else
        ylib_addEvt(obj,'click',new Function('switchTo('+i+')'));
    }
  }

  function locatePD(i)
  {
    window.location.href='phydrv.php?pdid='+i+'&locate';
  }

  function switchTo(i)
  {
    window.location.href='phydrv.php?pdid='+i;
  }
</script>

</body>
</html>

<?php
if (isset($_SESSION['pegasus']))
  $server->logout();
?>
