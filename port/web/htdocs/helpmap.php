<?php

/*******************************************************************************
 * Filename    : helpmap.php
 * Description : islaVista php file name conversion to help html files
 * Created on  : 12/20/04
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

function getFileName($srcfile, $specific)
{
  $filemap = array(
    // contact.html
    // EnclosureProblemHelp.html
    // CachePolicyHelp.html
    // CapacityCoercionHelp.html
    // connectionProblemHelp.html
    // FaqsHelp.html
    // FRUHelp.html
    // HelpHelp.html
    // HelpMenu.html
    // LanguageHelp.html
    // LD_DAStatusHelp.html
    // LDDegradedHelp.html
    // LDOfflineHelp.html
    // LogoutHelp.html
    // RAIDconvert.html
    // RAIDlevels.html
    // SectorSizeHelp.html
    // StorageNetworkHelp.html
    // StripeSizeHelp.html
    // TechBackHelp.html
    // TroubleShootingHelp.html
    // UiHelp.html
    // ViewHelp.html
    // YellowRedHelp.html
    'admin' => 'AdministrativeToolsHelp.html',
    'phydrvt_clear' => 'PhysicalDriveClearHelp.html',
    'controller' => 'ControllerInfoHelp.html',
    'ctrlStat.php' => 'ControllerInfoHelp.html', // user same help
    'controllert_set' => 'ControllerSettingsHelp.html',
    'controllert_clear' => 'ControllerClearHelp.html',
    'controllers' => 'ControllersInfoHelp.html',
    'bga_da' => 'DiskArrayBackgroundHelp.html',
    'logdrvc' => 'DiskArrayCreateLDHelp.html',
    'logdrvc2' => 'DiskArrayCreateLDHelp.html', // user same help
    'logdrvd' => 'DiskArrayDeleteLDHelp.html',
    'array' => 'DiskArrayInfoHelp.html',
    'migrate' => 'BackgroundActivityHelp.html',
    'migrate1' => 'DiskArrayMigrationHelp.html', // user same help
    'migrate2' => 'DiskArrayMigrationHelp.html', // user same help
    'migrate3' => 'DiskArrayMigrationHelp.html', // user same help
    'arrayc1' => 'DiskArraysCreateAdvHelp.html',
    'arrayc2' => 'DiskArraysCreateAdvHelp.html', // user same help
    'arrayc3' => 'DiskArraysCreateAdvHelp.html', // user same help
    'arraya_auto' => 'DiskArraysCreateAutoHelp.html',
    'arraya_expr' => 'DiskArraysCreateExpHelp.html',
    'arrayc' => 'DiskArraysCreateHelp.html',
    'arrayd' => 'DiskArraysDeleteHelp.html',
    'arrayt_setting' => 'DiskArraySettingsHelp.html',
    'arrays' => 'DiskArraysInfoHelp.html',
    'arrayt_transport' => 'DiskArrayTransportHelp.html',
    'email' => 'EmailHelp.html',
    'battery' => 'EnclosureBatteryHelp.html',
    'batteryt' => 'EnclosureBatteryHelp.html', // user same help
    'buzzer' => 'EnclosureBuzzerHelp.html',
    'fru' => 'EnclosureFRUVPDHelp.html',
    'encl' => 'EnclosureInfoHelp.html',
    'enclt' => 'EnclosureSettingsHelp.html',
    'encls' => 'EnclosuresInfoHelp.html',
    'lcdt' => 'EnclosureLCDHelp.html',
    'about' => 'HelpMenu.html',
    'ldinit' => 'BackgroundActivityHelp.html',
    'ldinitt' => 'InitializationHelp.html', // user same help
    'chap' => 'iSCSIMgtCHAPHelp.html',
    'chapc' => 'iSCSIMgtCHAPHelp.html',  // user same help
    'chapd' => 'iSCSIMgtCHAPHelp.html',  // user same help
    'iNode' => 'iSCSIMgtiSCSINodeHelp.html',
    'iNodet' => 'iSCSIMgtiSCSINodeHelp.html', // user same help
    'isns' => 'iSCSIMgtiSNSHelp.html',
    'isnsn' => 'iSCSIMgtiSNSHelp.html', // user same help
    'isnst' => 'iSCSIMgtiSNSHelp.html', // user same help
    'ping' => 'iSCSIMgtPingHelp.html',
    'iPort' => 'iSCSIMgtPortsHelp.html',
    'iPortal' => 'iSCSIMgtPortalHelp.html',
    'iSession' => 'iSCSIMgtSessionsHelp.html',
    'iSessionStat' => 'iSCSIMgtSessionStatisticsHelp.html',
    'slpi' => 'iSCSIMgtSLPHelp.html',
    'slpt' => 'iSCSIMgtSLPHelp.html', // user same help
    'ldbga' => 'LogicalDriveBackgroundHelp.html',
    'errtable' => 'LogicalDriveCheckTableHelp.html',
    'logdrv' => 'LogicalDriveInfoHelp.html',
    'logdrvt' => 'LogicalDriveSettingsHelp.html',
    'logdrvStat' => 'LogicalDriveStatHelp.html',
    'logdrvs' => 'LogicalDrivesInfoHelp.html',
    'lmmld2' => 'LogicalDriveLUNMapHelp.html',
    'lmmld' => 'LogicalDriveLUNMapHelp.html',
    'logdrvs_summary' => 'LogicalDriveSummaryInfoHelp.html',
    'mpatrol' => 'MediaPatrolHelp.html',
    'net_ether' => 'NetworkMgtPortHelp.html',
    'net_iscsi' => 'NetworkMgtISCSIHelp.html',
    'nett_ether' => 'NetworkMgtPortConfigHelp.html',
    'nett_iscsi' => 'NetworkMgtISCSIPortHelp.html',
    'vlan' => 'NetworkMgtVLANHelp.html',
    'vlant' => 'NetworkMgtVLANsettingHelp.html',
    'vlanc' => 'NetworkMgtVLANcreateHelp.html',
    'vland' => 'NetworkMgtVLANdeleteHelp.html',
    'linkaggregation' => 'NetworkMgtLinkAgHelp.html',
    'linkaggregationt' => 'NetworkMgtLinkAgSettingHelp.html',
    'linkaggregationc' => 'NetworkMgtLinkAgCreateHelp.html',
    'linkaggregationd' => 'NetworkMgtLinkAgDeleteHelp.html',
    'rebuildt_pdm' => 'PDMHelp.html',
    'phydrvt_offline' => 'PhysicalDriveForceOfflineHelp.html',
    'phydrv' => 'PhysicalDriveInfoHelp.html',
    'phydrvStat' => 'PhysicalDriveInfoStatsHelp.html', // user same help
    'phydrvs' => 'PhysicalDrivesInfoHelp.html',
    'phydrvt' => 'PhysicalDriveSettingsHelp.html',
    'phydrvst' => 'PhysicalDrivesSettingsHelp.html',
    'ldrct' => 'RCHelp.html',
    'ldrc' => 'BackgroundActivityHelp.html',
    'rebuildt' => 'RebuildHelp.html',
    'restore' => 'RestoreDefaultsHelp.html',
    'snmp' => 'SNMPHelp.html',
    'export' => 'SoftwareMgtExportHelp.html',
    'flashnew' => 'SoftwareMgtFirmwareUpdateHelp.html',
    'uploadnew' => 'SoftwareMgtFirmwareUpdateHelp.html', // user same help
    'import' => 'SoftwareMgtImportHelp.html',
    'services' => 'SoftwareMgtServiceHelp.html',
    'spare' => 'SpareDriveInfoHelp.html',
    'sparec' => 'SpareDrivesCreateHelp.html',
    'spared' => 'SpareDrivesDeleteHelp.html',
    'sparet' => 'SpareDriveSettingsHelp.html',
    'spares' => 'SpareDrivesInfoHelp.html',
    'sparecheck' => 'SpareDriveSpareCheckHelp.html',
    'sparecheck_s' => 'SpareDrivesSpareCheckHelp.html',
    'initiator' => 'StorageServicesInitiatorsHelp.html',
    'initiatora' => 'StorageServicesInitiatorsHelp.html', // user same help
    'initiatord' => 'StorageServicesInitiatorsHelp.html', // user same help
    'lmm' => 'StorageServicesLUNMapHelp.html',
    'lmms' => 'StorageServicesLUNMapHelp.html', // user same help
    'bga' => 'SubsystemBackgroundHelp.html',
    'bgat' => 'SubsystemBackgroundHelp.html', // user same help
    'events' => 'SubsystemEventHelp.html',
    'subsystem' => 'SubsystemInfoHelp.html',
    'lock' => 'SubsystemLockHelp.html',
    'schedules' => 'SubsystemSchedulerHelp.html',
    'schedule' => 'SubsystemSchedulerHelp.html', // user same help
    'scheduled' => 'SubsystemSchedulerHelp.html', // user same help
    'subsystemt_set' => 'SubsystemSettingsHelp.html',
    'subsystemt_time' => 'SubsystemDateHelp.html',
    'subsystems' => 'SubsystemsInfoHelp.html',
    'ldsync' => 'SynchronizationHelp.html',
    'telnet' => 'TelnetHelp.html',
    'ssh' => 'SshHelp.html',
    'rebuildt_transition' => 'TransitionHelp.html',
    'rebuild_transition' => 'BackgroundActivityHelp.html',
    'rebuild_pdm' => 'BackgroundActivityHelp.html',
    'rebuild_rebuild' => 'BackgroundActivityHelp.html',
    'userc' => 'UserMgtCreateHelp.html',
    'userd' => 'UserMgtDeleteHelp.html',
    'evtsubscribe' => 'UserMgtEventSubscriptionHelp.html',
    'users' => 'UserMgtInfoHelp.html',
    'userp' => 'UserMgtPasswordHelp.html',
    'session' => 'UserMgtSessionHelp.html',
    'usert' => 'UserMgtSettingsHelp.html',
    'webserver' => 'WebServerHelp.html',
    'slp' => 'SLPHelp.html',
    'cim' => 'CIMHelp.html',
    'netsend' => 'NetSendHelp.html',
    'clearStat' => 'ClearStatisticsHelp.html',
    'ctrlStat' => 'ControllerInfoStatsHelp.html',
    'fcInitiator' => 'FCMgtInitiatorHelp.html',
    'fcDevice' => 'FCMgtLoggedInDeviceHelp.html',
    'fcNode' => 'FCMgtNodeHelp.html',
    'fcPort' => 'FCMgtPortHelp.html',
    'fcPortt' => 'FCMgtPortSettingsHelp.html',
    'fcSFP' => 'FCMgtSFPHelp.html',
    'fcStats' => 'FCMgtStatisticHelp.html',
    'iPortStat' => 'iSCSIMgtPortStatsHelp.html',
    'pPort' => 'SCSIMgtPortsHelp.html',
    'pPortt' => 'SCSIMgtPortsSettingsHelp.html',
    'pTarget' => 'SCSIMgtTargetsHelp.html',
    'lmms2' => 'StorageServicesLUNMapHelp.html',
    'lmm2' => 'StorageServicesLUNMapHelp.html',
    'shutdown' => 'ShutdownHelp.html',


    'netmaintenancet' => 'NetworkMgtMaintModeConfigHelp.html',
    'netmaintenance' => 'NetworkMgtMaintModeHelp.html',
    // 'shutdown'=>   	    'NetworkMgtPortConfigHelp.html',
    // 'shutdown'=>   	    'NetworkMgtPortHelp.html',
    'sasPortList' => 'SASmgtInfoHelp.html',
    'sasPortt' => 'SASmgtPortSettingHelp.html',
    'sasStats' => 'SASmgtPortStatHelp.html',
    'sasInitiator' => 'SASmgtPortInitiatorsHelp.html',
    'sastopology' => 'EnclosuresTopologyHelp.html',
    'imgversions' => 'SoftwareMgtImageVersionHelp.html',
    'perfStats' => 'PerformMonitorHelp.html',
    'perfStatsForRW' => 'PerformMonitorHelp.html',
    'ntpt' => 'SubsystemNTPHelp.html',
    'upss' => 'UPSInfoHelp.html',
    'upst' => 'UPSSettingsHelp.html',
    'ups' => 'UPSUPSHelp.html',
	  // Mount point conflict in nas
    'mountpoints' => 'mountpoints.html',
    //stats
    //---------------------------------------
    //nas
    'nas_useri' => 'nas_useri.html',
    'nas_usercp' => 'nas_usercp.html',
    'nas_userc' => 'nas_userc.html',
    'nas_userd' => 'nas_userd.html',

    'nas_groupi' => 'nas_groupi.html',
    'nas_groupgm' => 'nas_groupgm.html',
    'nas_groupc' => 'nas_groupc.html',
    'nas_groupd' => 'nas_groupd.html',

    'nas_protoinfo' => 'nas_protoinfo.html',
    'nas_protowin' => 'nas_protowin.html',
    'nas_protounix' => 'nas_protounix.html',
    'nas_protoftp' => 'nas_protoftp.html',

    'nas_sharei' => 'nas_sharei.html',
    'nas_sharem' => 'nas_sharem.html',
    'nas_sharec' => 'nas_sharec.html',
    'nas_sharer' => 'nas_sharer.html',

    'nas_shareset' => 'nas_shareset.html',
    'nas_sharesetnfs' => 'nas_sharesetnfs.html',

    'nas_filesys' => 'nas_filesys.html',
    'nas_filesysc' => 'nas_filesysc.html',
    'nas_filesysd' => 'nas_filesysd.html',

    'nas_repinfo' => 'nas_repinfo.html',
    'nas_repset' => 'nas_repset.html',
    'prevtlogd' => 'WinSysLogHelp.html',
    'phydrvSmart' => 'SmartInfoHelp.html',
    'phydrvSmartLog' => 'SmartLogHelp.html'
  );

  $file = basename($srcfile, '.php');

  if ($file == 'bga') {
    if ($specific == 'da')
      $file = 'bga_da';
    else if ($specific == 'subsys')
      $file = 'bga_subsys';
    else if ($specific == 'ld')
      $file = 'bga_ld';
  } else if ($file == 'arraya') {
    if ($specific == 'auto')
      $file = 'arraya_auto';
    else if ($specific == 'expr')
      $file = 'arraya_expr';
  } else if ($file == 'arrayt') {
    if ($specific == 'transport')
      $file = 'arrayt_transport';
    else if ($specific == 'setting')
      $file = 'arrayt_setting';
  } else if ($file == 'logdrvs') {
    if ($specific == 'summary')
      $file = 'logdrvs_summary';
  } else if ($file == 'net' || $file == 'nett') {
    if ($specific == 'ether')
      $file .= '_ether';
    else if ($specific == 'iscsi')
      $file .= '_iscsi';
  } else if ($file == 'rebuildt') {
    if ((int)$specific == RBD_ACTION_PDM)
      $file = 'rebuildt_pdm';
    else if ((int)$specific == RBD_ACTION_TRANSITION)
      $file = 'rebuildt_transition';
  } else if ($file == 'phydrvt') {
    if ($specific == 'offline')
      $file = 'phydrvt_offline';
    else if ($specific == 'clear')
      $file = 'phydrvt_clear';
  } else if ($file == 'sparecheck') {
    if ($specific == 's')
      $file = 'sparecheck_s';
  } else if ($file == 'subsystemt') {
    if ($specific == 'time')
      $file = 'subsystemt_time';
    else if ($specific == 'set')
      $file = 'subsystemt_set';
  } else if ($file == 'rebuild') {
    if ($specific == 'pdm')
      $file = 'rebuild_pdm';
    else if ($specific == 'transition')
      $file = 'rebuild_transition';
    else if ($specific == 'rebuild')
      $file = 'rebuild_rebuild';
  } else if ($file == 'controllert') {
    if ($specific == 'set')
      $file = 'controllert_set';
    else if ($specific == 'clear')
      $file = 'controllert_clear';
  }
  $file = $filemap[$file];
  return $file;
}
?>
