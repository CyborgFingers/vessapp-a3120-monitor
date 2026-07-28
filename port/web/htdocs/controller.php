<?php

/*******************************************************************************
 * Filename    : controller.php
 * Description : islaVista controller information.
 * Created on  : 04/01/03
 *
 * (C) Copyright Promise Technology Inc.,2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_GET['ssid']))
  SetSessionValue('ssid', $_GET['ssid']);
if (isset($_GET['ctid']))
  SetSessionValue('ctid', $_GET['ctid']);
$server = getServer();
$subsystem = $server->getSubsystem();
$supportDualCtrl = $subsystem->supportdualcontroller();
$controller = $subsystem->getController($_SESSION['ctid']);
$controllers = $subsystem->getControllers();
if ($controller == null)
  redirectPage('controllers.php?tab=0', 1);
if (isset($_SESSION['ctrlstatus']))
  unset($_SESSION['ctrlstatus']);
if (isset($_GET['status'])) {
  SetSessionValue('ctrlstatus', $_GET['status']);
}
SyncSession();
$note = isset($_GET['note']) ? $_GET['note'] : null;
?>

<html>
<head>
<?php
page_head();
function tabTitleController2($ctrld)
{
  global $treeId;
  $treeId = $_SESSION['ssid'] . 'ctrl' . $_SESSION['ctid'];
  $tabTitle = array(array(gprop('ctrlinfotab'), null, gtooltip('ctrlinfotab')));
  return $tabTitle;
}
?>
</head>

<body>
<form method=post  >
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
if (isset($_GET['status'])) {
  table_title(gprop('controller') . ' ' . ($_SESSION['ctid'] + 1), 'controller', $note);
  $tabTitle = tabTitleController2($_SESSION['ctid']);
} else if (isset($controller->u32Ret) && ($controller->u32Ret != 0)) {
  table_title(gprop('controller') . ' ' . ($_SESSION['ctid'] + 1), 'controller', $note);
  $tabTitle = tabTitleController2($_SESSION['ctid']);
  $_SESSION['ctrlstatus'] = 'sync';
} else {
  if ($controller->ci_ReadinessStatus == 'Active' || $controller->ci_ReadinessStatus == 'Standby') {
    table_title(gprop('controller') . ' ' . ($_SESSION['ctid'] + 1), 'controller', $note);
    $tabTitle = tabTitleController($controller);
  } else if ($controller->ci_ReadinessStatus == 'Maintenance') {
    table_title(gprop('controller') . ' ' . ($_SESSION['ctid'] + 1), 'controller', $note);
    $tabTitle = tabTitleController2($_SESSION['ctid']);
    $_SESSION['ctrlstatus'] = 'maintenance';
  } else {
    table_title(gprop('controller') . ' ' . ($_SESSION['ctid'] + 1), 'controller', $note);
    $tabTitle = tabTitleController2($_SESSION['ctid']);
    if (count($controllers) == 2)
      $_SESSION['ctrlstatus'] = 'notaccessible';
    else if (count($controllers) == 1)
      $_SESSION['ctrlstatus'] = 'notpresent';
  }
}

table_tab_head();
table_tab($tabTitle);

if (isset($_SESSION['ctrlstatus'])) {
  if ($_SESSION['ctrlstatus'] == 'notpresent')
    $ctrlmsg = gresult('notpresentmsg');
  else if ($_SESSION['ctrlstatus'] == 'maintenance')
    $ctrlmsg = gresult('maintenancemsg');
  else if ($_SESSION['ctrlstatus'] == 'sync')
    $ctrlmsg = gerror($controller->u32Ret);
  else
    $ctrlmsg = gresult('notaccessiblemsg');

  table_head_fold(gprop('controllerinfo'), 'CT', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
  table_row_empty($ctrlmsg);
  table_foot_fold();
  table_tab_foot();
} else {
  if (isset($_SESSION['pegasus'])) {
    table_row(gprop('controllerid'), $controller->getProperty('ControllerId'));
    table_row(gprop('partnumber'), $controller->getProperty('PartNumber'));
    table_row(gprop('serialnumber'), $controller->getProperty('SerialNumber'));
    table_row(gprop('revisionnumber'), $controller->getProperty('Version'));
    table_row(gprop('wwn'), $controller->getProperty('WWN'));
    table_row(gprop('memorytype'), $controller->getProperty('MemoryType'));
    table_row(gprop('memecc'), $controller->getProperty('MemoryECCType'));
    table_row(gprop('memorysize'), $controller->getProperty('MemorySize') . ' MB');
    table_row(gprop('cachetype'), $controller->getProperty('CacheType'));
    table_row(gprop('cacheecc'), $controller->getProperty('CacheECCType'));
    table_row(gprop('cachesize'), $controller->getProperty('CacheSize') . ' MB');
    table_row(gprop('flashtype'), $controller->getProperty('FlashType'));
    table_row(gprop('flashsize'), $controller->getProperty('FlashSize') . ' MB');
    table_row(gprop('nvramtype'), $controller->getProperty('NVRAMType'));
    table_row(gprop('nvramsize'), $controller->getProperty('NVRAMSize') . ' MB');
    table_row(gprop('firmwareversion'), $controller->getProperty('FirmwareRevision'));
    table_row(gprop('firmwaredate'), $controller->getProperty('FirmwareBuildDate'));

    table_row(gprop('status'), getStringOpStatusArray($controller->getProperty('OperationalStatus')));
    table_row(gprop('pstatus'), getStringOpStatusArray($controller->getProperty('PartnerOpStatus')));
    table_row(gprop('amode'), $controller->getProperty('ActiveMode'));
    table_row(gprop('pamode'), $controller->getProperty('PartnerActiveMode'));

    $hours = $controller->getProperty('PowerOnHours');
    settype($hours, 'integer');
    table_row(gprop('powerontime'), $hours . ' Hour' . ($hours > 1 ? 's' : ''));

    table_row(gprop('numreset'), $controller->getProperty('TotalNumberOfResets'));
    table_row(gprop('numcmd'), $controller->getProperty('NumberOfCommands'));
    table_row(gprop('numfcmd'), $controller->getProperty('NumberOfFailedCommands'));
    table_row(gprop('numrcmd'), $controller->getProperty('NumberOfReads'));
    table_row(gprop('numwcmd'), $controller->getProperty('NumberOfWrites'));
  } else {
    table_head_fold(gprop('controllerinfo'), 'CT', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

    table_row(gprop('controllerid'), $controller->ci_DeviceID + 1, 40);
    table_row(gprop('alias'), preg_replace("/\s/", "&nbsp;", $controller->ci_UserName));
    table_row(gprop('status'), $controller->_RaidState, null, getStatusColor($controller->_RaidState));
    if ($supportDualCtrl->DualCtrlSupport)
      table_row(gprop('readinessstatus'), $controller->ci_ReadinessStatus);
    // leave two digits after point
    // $hours = round($controller->ci_Uptime / 36) / 100;
    // table_row(gprop('powerontime'), $hours . ' Hour' . ($hours > 1 ? 's' : ''));
    table_row(gprop('powerontime'), $controller->ci_Uptime);

    table_row(gprop('cacheusage'), $controller->ci_CacheUsagePercent);
    table_row(gprop('dirtycacheusage'), $controller->ci_DirtyCachePercent);

    table_row(gprop('partnumber'), $controller->ci_CustPartNo);
    table_row(gprop('serialnumber'), $controller->ci_CustSerialNo);
    table_row(gprop('hardwarerevision'), $controller->ci_CustRev);
    table_row(gprop('wwn'), $controller->ci_WWN);
    // table_row(gprop('manufacturingdate'),$controller->ci_MftDate);
    table_row(gprop('scsiprotocolsup'), $controller->ci_SCSIProtocolSupported);
    table_row(gprop('installPkgVersion'), $controller->ci1_InstallPkgVersion);
    table_row(gprop('installPkgBuildDate'), $controller->ci1_InstallPkgBuildDate);

    // table_row(gprop('bootloaderversion'),$controller->ci1_PriBootLoaderBuildNo);
    // table_row(gprop('bootloaderdate'),$controller->ci1_PriBootLoaderBuildDate);
    // table_row(gprop('firmwareversion'),$controller->ci1_PriFWBuildNo);
    // table_row(gprop('firmwaredate'),$controller->ci1_PriFWBuildDate);
    // table_row(gprop('softwareversion'),$controller->ci1_PriSWBuildNo);
    // table_row(gprop('softwaredate'),$controller->ci1_PriSWBuildDate);

    table_foot_fold();
    table_tab_foot();

    table_head_fold(gprop('advancedcontrollerinfo'), 'ADV', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

    table_row(gprop('memorytype'), $controller->ci2_MemoryType);
    table_row(gprop('memorysize'), $controller->ci2_MemorySize);
    // table_row(gprop('lunaffinity'),$controller->ci_LUNAffinityEnable);
    if ($supportDualCtrl->DualCtrlSupport)
      table_row(gprop('controllerrole'), $controller->ci_IsMaster);
    table_row(gprop('flashtype'), $controller->ci2_FlashType);
    table_row(gprop('flashsize'), $controller->ci2_FlashSize);
    // table_row(gprop('nvramtype'),$controller->ci2_NVRAMType);
    // table_row(gprop('nvramsize'),$controller->ci2_NVRAMSize);

    table_row(gprop('preferredcachelinesize'), $controller->ci_PreferedCacheLineSize . 'KB');
    table_row(gprop('cachelinesize'), $controller->ci_CurrentCacheLineSize . 'KB');

    table_row(gprop('coercion'), getEnableString($controller->ci_CoercionEnable));
    if ($controller->ci_CoercionEnable)
      table_row(gprop('coercionmethod'), getStringCtrlCoercionMethod($controller->ci_CoercionMethod));

    //// Hide controller SMART info - RB-265710, RB-265689
    // table_row(gprop('smart'), getEnableString($controller->ci_SmartLogEnable), 40);
    // if ($controller->ci_SmartLogEnable) {
    //   table_row(gprop('smartpollinginterval'), $controller->ci_SmartPollInterval . ' ' . gprop('minutes'));
    // }

    table_row(gprop('writebackcacheflushinterval'), $controller->ci_WriteBackCacheFlushInterval . ' ' . gprop('seconds'));
    table_row(gprop('enclprollinterval'), $controller->ci_EnclosurePollInterval . ' ' . gprop('seconds'));
    // table_row(gprop('adaptiveenable'),$controller->ci_AdaptiveWriteBackCacheEnable);
    table_row(gprop('hostcacheflushing'), $controller->ci_SyncCacheEnable);
    if ($supportDualCtrl->DualCtrlSupport) {
      // table_row(gprop('hostcacheflushing'),$controller->ci_SyncCacheEnable);
		  // table_row(gprop('forcedreadahead'),$controller->ci_ForcedReadAheadEnable);
      table_row(gprop('commonwwnn'), $controller->ci_CommonWWNNEnable);
    }
    table_row(gprop('forcedreadahead'), $controller->ci_ForcedReadAheadEnable);
    table_row(gprop('spindowntype'), $controller->ci_SpinDownType);
    table_row(gprop('hddpowerlevels'), $controller->ci_HDDPowerLevels);
    table_row(gprop('hddidletime'), $controller->ci_HDDIdleTime . ' ' . gprop('minutes'));
    table_row(gprop('hddstandbytime'), $controller->ci_HDDStandbyTime . ' ' . gprop('minutes'));
    table_row(gprop('hddstoppedtime'), $controller->ci_HDDStoppedTime . ' ' . gprop('minutes'));
    // table_row(gprop('portpowerctrl'),$controller->ci_PortPowerCtrl);
    table_row(gprop('pdtemperaturethreshold'), $controller->ci_PDTempThreshold . ' ' . '&ordm;C');
    table_row(gprop('pdcritemperaturethreshold'), $controller->ci_PDCriTempThreshold . ' ' . '&ordm;C');
	  // table_row(gprop('ncq'),$controller->ci_NCQDisable);
    table_foot_fold('ADV');
  }
}
page_foot();
?>
  </table>
</form>
</body>

</html>

<?php
if (isset($_SESSION['pegasus']))
  $server->logout();
?>

