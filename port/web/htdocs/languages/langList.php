<?php

/*******************************************************************************
 * Filenamecase 'case ': langList.php':
 * Description : islaVista localization languages charset and image list
 * Created oncase ': 11/02/04
 * CVS Version : $Id: langList.php,v 1.20.6.8 2010/01/21 07:41:58 JudithLi Exp $
 *
 * (C) Copyright Promise Technology Inc.,2004
 * All Rights Reserved
 ******************************************************************************/

$charset_array=array(
'en_US'=>'iso-8859-1',
'fr_FR'=>'iso-8859-1',
'de_DE'=>'iso-8859-1',
'it_IT'=>'iso-8859-1',
'ja_JP'=>'shift-jis',
'ko_KR'=>'euc-kr',
'zh_CN'=>'gb2312',
'zh_TW'=>'big5',
'es_ES'=>'iso-8859-1',
'pt_PT'=>'utf-8',
'tr_TR'=>'utf-8',
'ar_AR'=>'utf-8',
'ru_RU'=>'windows-1251',
);

$language_array=array(
'en_US'=>'English',
'fr_FR'=>'Fran&#231;ais',
'de_DE'=>'Deutsch',
'it_IT'=>'Italiano',
'ja_JP'=>'&#26085;&#26412;&#35486;',
'ko_KR'=>'&#54620;&#44397;&#50612;',
'zh_CN'=>'&#31616;&#20307;&#20013;&#25991;',
'zh_TW'=>'&#32321;&#39636;&#20013;&#25991;',
'es_ES'=>'Espa&#241;ol',
'ru_RU'=>'&#1056;&#1091;&#1089;&#1089;&#1050;&#1048;&#1049;',
'pt_PT'=>'Portugu&#234;s',
'tr_TR'=>'T&#252;rk',
'ar_AR'=>'&#1575;&#1604;&#1593;&#1585;&#1576;&#1610;&#1577;'
);

function file2mod($filename)
{
	switch($filename) {
	case 'admin':
	case 'nasadmin':
	case 'clearStat':
	case 'restore':
	case 'shutdown1':
	case 'shutdown2':
	case 'shutdown':
	case 'prevtlogd':
        case 'perfStats':
        case 'perfStatsForRW':
		return 'admintools';
	case 'arraya':
	case 'arrayc1':
	case 'arrayc2':
	case 'arrayc3':
	case 'arrayc':
	case 'arrayd':
	case 'array':
	case 'arrays':
	case 'arrayt':
		return 'diskarray';
	case 'bga':
	case 'bgat':
	case 'ldbga':
	case 'ldinit':
	case 'ldinitt':
	case 'ldrc':
	case 'ldrct':
	case 'ldsync':
	case 'migrate1':
	case 'migrate2':
	case 'migrate3':
	case 'migrate':
	case 'mpatrol':
	case 'rebuild':
	case 'rebuildt':
		return 'bga';
	case 'confirm':
	case 'contact':
	case 'controller':
	case 'controllers':
	case 'controllert':
	case 'ctrlStat':
	case 'ups':
	case 'upst':
	case 'upss':
        case 'about':
		return 'controller';
	case 'battery':
	case 'batteryt':
	case 'buzzer':
	case 'lcdt':
	case 'encl':
	case 'encls':
	case 'enclt':
	case 'fru':
	case 'sastopology':
	case 'sastopology':
       case 'Front':
		return 'enclosure';
	case 'fcDevice':
	case 'fcInitiator':
	case 'fcNode':
	case 'fcPort':
	case 'fcPortt':
	case 'fcSFP':
	case 'fcStats':
		return 'fcmanagement';
	case 'sasPortList':
	case 'sasPort':
	case 'sasPortt':
	case 'sasStats':
	case 'sasInitiator':
		return 'sasmanagement';

	case 'header':
	case 'index':
		return 'header';
	case 'home':
		return 'home';
	case 'chapc':
	case 'chapd':
	case 'chap':
	case 'iNode':
	case 'iNodet':
	case 'iPort':
	case 'iPortStat':
	case 'iPortt':
	case 'iSession':
	case 'iSessionStat':
	case 'isnsn':
	case 'isns':
	case 'isnst':
	case 'ping':
	case 'slpi':
	case 'slpt':
		return 'iscsimanagement';
	case 'initiator':
	case 'initiatora':
	case 'initiatord':
	case 'lmm2':
	case 'lmm':
	case 'lmms2':
	case 'lmms':
	case 'lmmld':
	case 'lmmld2':
	case 'lmmldcfg':
		return 'storage';
	case 'errtable':
	case 'ldcfg':
	case 'logdrvc2':
	case 'logdrvc':
	case 'logdrvd':
	case 'logdrv':
	case 'logdrvs':
	case 'logdrvStat':
	case 'logdrvt':
	case 'mountpoints':
	case 'ldlunclone':
	case 'ldclone':
		return 'logicaldrive';
	case 'net':
	case 'nett':
	case 'netmaintenance':
	case 'netmaintenancet':
	case 'netstatistic':
	case 'netstatistics':
	case 'vlan':     
	case 'vlanc':    
	case 'vlant': 
 	case 'vland' :
	case 'linkaggregation'  :
	case 'linkaggregationc' :
	case 'linkaggregationd' : 
	case 'linkaggregationt' :
	case 'linkaggregationtcclass' : 
		return 'networkmanagement';
	case 'phydrv':
	case 'phydrvs':
	case 'phydrvStat':
	case 'phydrvst':
	case 'phydrvt':
		return 'physicaldrive';
	case 'pPort':
	case 'pPortt':
	case 'pTarget':
    case 'iPortal':     
		return 'scsimanagement';
	case 'sparecheck':
	case 'sparec':
	case 'spared':
	case 'spare':
	case 'spares':
	case 'sparet':
		return 'sparedrive';
	case 'event0':
	case 'event':
	case 'events':
	case 'lock':
	case 'scheduled':
	case 'schedule':
	case 'schedules':
	case 'ntpt':
	case 'subsystem':
	case 'subsystems':
	case 'subsystemt':
		return 'subsystem';
	case 'tcp':
	case 'tcpt':
	case 'treeframe':
		return 'tree';
	case 'evtsubscribe':
	case 'session':
	case 'tree':
	case 'userc':
	case 'userd':
	case 'userp':
	case 'indexp':
	case 'users':
	case 'usert':
		return 'usermanagement';
	case 'cim':
	case 'download':
	case 'email':
	case 'export':
	case 'flash':
	case 'flashnew':
	case 'imgversions':
	case 'import':
	case 'services':
	case 'servicet':
	case 'snmp':
	case 'telnet':
	case 'ssh':
	case 'bonjour':
	case 'upload':
	case 'uploadnew':
	case 'webserver':
	case 'netsend':
	case 'slp':

		return 'swsoftware';
	}
	return false;
}

?>
