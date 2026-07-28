<?php

/*******************************************************************************
 * Filename    : common.php
 * Description : islaVista common php functions.
 * Created on  : 04/30/03
 *
 * (C) Copyright Promise Technology Inc.,2020
 * All Rights Reserved
 ******************************************************************************/
include 'table.php';
include 'common2.php';

/* ---- webpam-monitor: read-only drive posture --------------------------------
 * This is the drive-health MONITOR build. Every page that WRITES to a drive or
 * array - create/delete/expand/migrate, spare assign/remove, initialize,
 * redundancy-check/rebuild starts, media patrol, and the drive-config settings
 * pages - is disabled here. The health backend has NO write path (no
 * promise-raid-helper is installed), so these would fail regardless; the guard
 * just redirects for a clean UX and blocks a hand-typed URL. Fans, front-panel
 * LEDs, enclosure, email/SNMP/webserver and user admin are NOT drive control
 * and are left fully functional. common.php is included at the top of every
 * page, so this runs before any page's POST handler. */
$GLOBALS['MONITOR_BLOCKED_PAGES'] = array(
  'arrayc.php', 'arrayc1.php', 'arrayc2.php', 'arrayc3.php', 'arraya.php', 'arrayd.php', 'arrayt.php',
  'logdrvc.php', 'logdrvc2.php', 'logdrvd.php', 'logdrvt.php',
  'migrate.php', 'migrate1.php', 'migrate2.php', 'migrate3.php',
  'sparec.php', 'spared.php', 'sparecheck.php', 'sparet.php',
  'ldinitt.php', 'ldrct.php', 'rebuildt.php', 'mpatrol.php',
  'phydrvt.php', 'phydrvst.php', 'bgat.php',
);
if (in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), $GLOBALS['MONITOR_BLOCKED_PAGES'], true)) {
  if (!headers_sent())
    header('Location: home.php?note=' . rawurlencode('Drive management is disabled in this monitor build'));
  exit;
}

/* webpam-monitor: is this the Vess A3120 chassis (fans, front-panel LEDs, hot-swap
 * bays)? The installer/container sets VESSAPP_ENCLOSURE=1 on A3120 hardware and 0
 * on a generic host (TrueNAS/Proxmox) - there the chassis pages + enclosure
 * front-view are hidden and drives are shown by device name. Unset -> present, so
 * the on-A3120 default is unchanged. */
function hasEnclosure()
{
  return getenv('VESSAPP_ENCLOSURE') !== '0';
}

/* getFreeFragments($perPdFreeSize, $logicalDrives) - the migrate wizard
 * (migrate2.php) expects this vendor global; it was never ported (the SCSI engine
 * supplied it), so on Linux the page fataled with an undefined function. Linux/LVM
 * has no fixed per-PD offset layout, so report a single trailing fragment: the free
 * space (per PD) after the existing logical drives. Shape matches
 * getDiskArrayFreeFragments: [{daff_u64Addr, daff_u64Size}], per-PD units. */
function getFreeFragments($perPdFreeSize, $logicalDrives)
{
  $addr = 0;
  if (is_array($logicalDrives)) {
    foreach ($logicalDrives as $ld) {
      if (isset($ld->cdd_ComponentSize)) $addr += $ld->cdd_ComponentSize;
      else if (isset($ld->cdd_Start))    $addr = max($addr, $ld->cdd_Start);
    }
  }
  if ($perPdFreeSize <= 0) return array();
  $f = new stdClass();
  $f->daff_u64Addr = $addr;
  $f->daff_u64Size = $perPdFreeSize;
  return array($f);
}

function SetSessionValue($key, $value)
{
  $_SESSION["SessionSync"] = true;
  $_SESSION[$key] = $value;
}
function SyncSession()
{
  if (isset($_SESSION["SessionSync"])
    && isset($_SESSION["sessionService"])
    && $_SESSION["SessionSync"] == true) {
    $server = getServer();
    $subsystem = $server->getSubsystem();
    $session = unserialize($_SESSION["sessionService"]);
    $subsystem->syncsessionvalue(session_id(), $session->sid);
    $_SESSION["SessionSync"] = false;
  }
}

function validateActionUrl($url)
{
  if (preg_match('/^\/[a-zA-Z0-9]+[.]php$/', $url)) {
    return $url;
  }
  else if (preg_match('/^\/[a-zA-Z0-9]+[.]php[?][^<>\/"]+$/', $url)) {
    return $url;
  }
  else {
    echo '<script>window.location.replace(\'https://'.$_SERVER['HTTP_HOST'].'/screen.php\');</script>';
    return '';
  }
}

function page_head()
{
  global $tab;
  if (isset($_GET['tab']))
    $tab = $_GET['tab'];
  if (isset($tab)) {
    $_SESSION['tab'] = $tab;
    setcookie('tab', $tab, null, null, null, true, true);
    $_COOKIE['tab'] = $tab;
  }
  if (!isset($_SESSION['tab'])) {
    if (isset($_COOKIE['tab'])) {
      $_SESSION['tab'] = $_COOKIE['tab'];
    } else {
      $_SESSION['tab'] = 0;
    }
  }

  $_SESSION['lastfile'] = $_SERVER['PHP_SELF'];
  $cssPostFix = "?time=" . time() . ".css";
  $jsPostFix = "?time=" . time() . ".js";
  echo '
  <link rel="stylesheet" href="css/main.css' . $cssPostFix . '" type="text/css">
  <link rel="stylesheet" href="css/help.css' . $cssPostFix . '" type="text/css">
  <link rel="stylesheet" href="css/text1.css' . $cssPostFix . '" type="text/css">
  <link rel="stylesheet" href="css/table.css' . $cssPostFix . '" type="text/css">
  <meta http-equiv="Cache-Control" Content="no-cache">
  <meta http-equiv="Pragma" Content="no-cache">
  <meta http-equiv="Expires" Content="0">
  <title></title>

  <script src="js/tip.js' . $jsPostFix . '"></script>
  <script src="js/myua.js'.$jsPostFix.'"></script>
  <script src="js/plib.js' . $jsPostFix . '"></script>
  <script src="js/jquery-3.6.0.min.js"></script>
  <script src="js/form.js"></script>';
}

function page_foot()
{
  global $tabTitle, $treeId;
  echo '<div id=overDiv style="position:absolute;visibility:hidden;z-index:1000;filter: progid:DXImageTransform.Microsoft.Shadow(color=#000000, Direction=135, Strength=4);box-shadow: 4px 4px 4px #000; -moz-box-shadow: 4px 4px 4px #000;-webkit-box-shadow: 4px 4px 4px #000; border: 1px solid #151515;"></div>';
  echo '
  <script>
    plib_initTab(' . count($tabTitle ?? array()) . ',' . $_SESSION['tab'] . ');
    setTimeout("treeClickOnTreeId(\'' . $treeId . '\')",200);
    focusSubmit();
  </script>';
}

function getStringFromArray($array)
{
  $string = $array[0];
  for ($i = 1; $i < count($array ?? array()); $i++)
    $string = $string . ',' . $array[$i];
  return $string;
}

function getStringOpStatusArray($status)
{
  $string = getStringOpStatus($status[0]);
  for ($i = 1; $i < count($status ?? array()); $i++)
    $string = $string . ',' . getStringOpStatus($status[$i]);
  return $string;
}

function getPDFromId($physicalDrives, $id)
{
  for ($i = 0; $i < count($physicalDrives ?? array()); $i++) {
    $physicalDrive = $physicalDrives[$i];
    if ($id == $physicalDrive->pdi_FlatId)
      return $physicalDrive;
  }
  return null;
}

function getDAFromId($diskArrays, $id)
{
  for ($i = 0; $i < count($diskArrays ?? array()); $i++) {
    $diskArray = $diskArrays[$i];
    if ($id == $diskArray->acd_ArrayID)
      return $diskArray;
    if ($id < $diskArray->acd_ArrayID)
      return null;
  }
  return null;
}

function isInArray($array, $id)
{
  if ($array == null)
    return false;
  // $array is a sorted array of id's
  for ($i = 0; $i < count($array ?? array()); $i++) {
    if ($id == $array[$i])
      return true;
  }
  return false;
}

function setInteger($value)
{
  settype($value, 'integer');
  return $value;
}

function double2int($double)
{
  return number_format($double, 0, '', '');
}

$sizeUnits = array('B', 'KB', 'MB', 'GB', 'TB');

function getSizeUnitIndex($unit)
{
  global $sizeUnits;
  for ($i = 0; $i < count($sizeUnits); $i++)
    if ($sizeUnits[$i] == $unit)
    return $i;
  return -1;
}

function fmtNumberUnit($num)
{
  $ret = preg_match('/([0-9]*)(\.*)([0-9]*)(\ *)([A-Za-z\/]*)/', $num, $regs);

  if ($ret > 1) {
    if ($regs[1])
      $fmtNum = number_format($regs[1]) . $regs[2] . $regs[3] . $regs[4] . $regs[5];
    else
      $fmtNum = $regs[0];
  } else
    $fmtNum = $num;

  return $fmtNum;
}

function fmtSize($size)
{
  $sizes = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB');
  $ext = $sizes[0];
  for ($i = 1; (($i < count($sizes)) && ($size >= 1024)); $i++) {
    $size = $size / 1024;
    $ext = $sizes[$i];
  }
  return round($size, 2) . $ext;
}

$SELECTED_CLASS = 'tableRowSelectCols';
$USED_COLOR = '#F9D5A0';
// $CONFIG_COLOR='#999999'; raid0 raid1 RAID 10 RAID 1E RAID 5 RAID 50 RAID 6
$CONFIG_COLOR = array('#D5C06F', '#68F059', '#87EFF8', '#6454EF', '#9A2EFE', '#EF54DF', '#58ACFA', '#F887DE', '#DE6565', '#FC8747');
$FREE_COLOR = '#1A1A1A';

function mod($x, $y)
{
  $i = floor($x / $y);
  return $x - $i * $y;
}

function getTime()
{
  return date("D, M j, Y H:i:s");
}

function getIDList($objs)
{
  $string = '';
  for ($i = 0; $i < count($objs ?? array()); $i++)
    $string .= ($objs[$i] + 1) . ' ';
  return $string;
}

function getPDConfiguableSize($physicalDrives, $pds)
{
  // calculate total configurable size
  if (count($pds ?? array()) == 0)
    return 0;
  $size = 9223372036854775808; // 0x8000000000000000
  for ($i = 0; $i < count($pds ?? array()); $i++) {
    $pd = getPDFromId($physicalDrives, $pds[$i]);
    $size = min($size, $pd->_configurableSize);
  }
  return double2int($size * count($pds ?? array()));
}

function getTabMenu($title, $url, $privilege, $minPrivilege, $tooltip = '', $tooltipType = 'v')
{
  return array(
    $title, $privilege >= $minPrivilege ? $url : null,
    $tooltip == '' ? '' : ($privilege >= $minPrivilege ? '' : gprop('notauthorized') . ' ' . gprop($tooltipType == 'n' ? 'for' : 'to')) . ' ' . $tooltip
  );
}
function getBRCTabMenu($title, $url, $privilege, $minPrivilege, $tooltip = '', $tooltipType = 'v')
{
  $server = getServer();
  $subsystem = $server->getSubsystem();
  $batteries = $subsystem->getBatteries(0);

  /*get the controller type*/
  $currentCtrlId = $subsystem->getCurrentCtrlId();
  $currentCtrl = $subsystem->getController($currentCtrlId->currentCtrlId);

  if (_isCClassCtrl($currentCtrl->ci_ProductID) && count($batteries ?? array()) == 0) {
    $isGray = 1;
    return array(
      $title, $privilege >= $minPrivilege ? $url : null,
      $tooltip == '' ? '' : ($privilege >= $minPrivilege ? '' : gprop('notauthorized') . ' ' . gprop($tooltipType == 'n' ? 'for' : 'to')) . ' ' . $tooltip, $isGray
    );
  } else {
    return array(
      $title, $privilege >= $minPrivilege ? $url : null,
      $tooltip == '' ? '' : ($privilege >= $minPrivilege ? '' : gprop('notauthorized') . ' ' . gprop($tooltipType == 'n' ? 'for' : 'to')) . ' ' . $tooltip
    );
  }
}
function tabTitleArrays($subsystem)
{
  global $treeId;
  $treeId = $_SESSION['ssid'] . 'das';
  $pv = getUserPrivilege($subsystem);
  $tabTitle = array(
    array(gprop('arraysinfotab'), 'arrays.php', gtooltip('arraysinfotab'))
  );
  return $tabTitle;
}

function tabTitleArray($subsystem, $daid)
{
  global $treeId;
  $treeId = $_SESSION['ssid'] . 'da' . $_SESSION['daid'];
  $pv = getUserPrivilege($subsystem);
  $diskArray = $subsystem->getDiskArray($daid);
  $enableCreateLd = true;
  if (isset($diskArray->_opStatus)) {
    if ($diskArray->_opStatus == 'Incomplete'
      || $diskArray->_opStatus == 'Degraded'
      || $diskArray->_opStatus == 'Offline') {
      $enableCreateLd = false;
    }
  }

  $tabTitle = array();
  $tabTitle[] = array(gprop('arrayinfotab'), 'array.php', gtooltip('arrayinfotab'));
  if ($_SESSION["EnableMigration"] == 1) {
    $tabTitle[] = array(
      array(gprop('arraybgatab'), 'bga.php?daid=' . $daid)
    );
  } else {
    $tabTitle[] = array(
      array(gprop('arraybgatab'), 'bga.php?daid=' . $daid)
    );
  }
  // $tabTitle[] = getTabMenu(gprop('arraytransporttab'),'arrayt.php?act=transport',$pv,USER_GROUP_SUPER,gtooltip('arraytransporttab'));

  return $tabTitle;
}

function tabTitleSubsystem($subsystem)
{
  global $treeId;
  $treeId = 'subsys' . $_SESSION['ssid'];
  $pv = getUserPrivilege($subsystem);
  $tabTitle = array(
    array(gprop('subsysinfotab'), 'subsystem.php', gtooltip('subsysinfotab')),
    // array(
    array(gprop('subsyssettingstab'), 'subsystemt.php', gtooltip('subsyssettingstab')),
    // array(gprop('dateandtimesettingsmenu'),'subsystemt.php?time',gtooltip('dateandtimesettingsmenu')),
    // array(gprop('ntpmgmt'),'ntpt.php',gtooltip('ntpmgmt'))
    //   ),
    array(
      array(gprop('subsyseventtab'), 'events.php', gtooltip('subsyseventtab')),
      array(gprop('subsysruntimeeventsmenu'), 'events.php', gtooltip('subsysruntimeeventsmenu')),
      array(gprop('subsyseventsinnvrammenu'), 'events.php?nvram', gtooltip('subsyseventsinnvrammenu'))
    ),
    array(
      array(gprop('subsysbgatab'), 'bga.php', gtooltip('subsysbgatab'))
    ),
    array(
      array(gprop('subsysschedulertab'), 'schedules.php', gtooltip('subsysschedulertab'))
    ),
    getTabMenu(gprop('subsyslocktab'), 'lock.php', $pv, USER_GROUP_POWER, gtooltip('subsyslocktab'))
  );
  return $tabTitle;
}

function tabTitleController($controller)
{
  $watermark = strstr($controller->_RaidState, 'Orphan Watermark');
  global $treeId;
  $treeId = $_SESSION['ssid'] . 'ctrl' . $_SESSION['ctid'];
  $tabTitle = array(
    array(array(gprop('ctrlinfotab'), 'controller.php', gtooltip('ctrlinfotab')), array(gprop('ctrlstatisticsmenu'), 'ctrlStat.php', gtooltip('ctrlstatisticsmenu'))),
    array(gprop('ctrlsettingstab'), 'controllert.php', gtooltip('ctrlsettingstab'))
  );
  if ($watermark != false)
    $tabTitle[] = array(gprop('ctrlcleartab'), 'controllert.php?clear', gtooltip('ctrlcleartab'));
  return $tabTitle;
}

function tabTitleUps()
{
  global $treeId;
  $treeId = $_SESSION['ssid'] . 'ups' . $_SESSION['upsid'];
  $tabTitle = array(array(gprop('upstab'), 'ups.php', gtooltip('upstab')));
  return $tabTitle;
}

function tabTitleSpares($subsystem)
{
  global $treeId;
  $treeId = $_SESSION['ssid'] . 'sds';
  $pv = getUserPrivilege($subsystem);
  $tabTitle = array(
    array(gprop('sparesinfotab'), 'spares.php', gtooltip('sparesinfotab'))
  );
  return $tabTitle;
}

function tabTitleSpare()
{
  global $treeId;
  $treeId = $_SESSION['ssid'] . 'sd' . $_SESSION['spid'];
  $tabTitle = array(
    array(gprop('sparesinfotab'), 'spare.php', gtooltip('sparesinfotab'))
  );
  return $tabTitle;
}

function tabTitleLDs($daid)
{
  global $treeId;
  if ($daid == null) {
    $tabTitle = array(array(gprop('ldssummeryinfotab'), 'logdrvs.php', gtooltip('ldssummeryinfotab'))/*,
                       array(gprop('ldlunclonetab'),'ldlunclone.php',gtooltip('luncloneforldtab'))*/ );
    $treeId = $_SESSION['ssid'] . 'lds';
  } else {
    $tabTitle = array(array(gprop('ldsinfotab'), 'logdrvs.php?daid=' . $daid, gtooltip('ldsinfotab')));
    $treeId = $_SESSION['ssid'] . 'dalds' . $_SESSION['daid'];
  }
  return $tabTitle;
}

function tabTitleLD($subsystem, $daid)
{
  global $treeId;
  $pv = getUserPrivilege($subsystem);
  $systype = $subsystem->frontendType;
  if ($daid == null) {
    $tabTitle = array(
      array(array(gprop('ldinfotab'), 'logdrv.php', gtooltip('ldinfotab')), array(gprop('ldstatisticsmenu'), 'logdrvStat.php', gtooltip('ldstatisticsmenu'))),
      array(
        array(gprop('ldbgatab'), 'ldbga.php', gtooltip('ldbgatab'))
      ),
      array(gprop('ldchecktabletab'), 'errtable.php', gtooltip('ldchecktabletab'))
    );
    /*
    array('LUN Map', 'lmmld.php?ldid=' . $_SESSION['ldid'] . '&act=lmm&tab=4', 'LMM'));
    array('LUN Map', (($systype == PI_FB_HOST_INTERFACE_SCSI) ? 'lmmld2.php' : 'lmmld.php') . '?ldid=' . $_SESSION['ldid'] . '&act=lmm&tab=4', 'LMM'));
    if (defined('PI_FB_HOST_INTERFACE_ISCSI') && ($systype == PI_FB_HOST_INTERFACE_ISCSI) ||
      defined('PI_FB_HOST_INTERFACE_FC') && ($systype == PI_FB_HOST_INTERFACE_FC) ||
      defined('PI_FB_HOST_INTERFACE_SAS') && ($systype == PI_FB_HOST_INTERFACE_SAS))
      $tabTitle[] = array(gprop('ldlunmaptab'), 'lmmld.php?ldid=' . $_SESSION['ldid'] . '&act=lmm&tab=4', gtooltip('ldlunmaptab'));
    else if (defined('PI_FB_HOST_INTERFACE_SCSI') && ($systype == PI_FB_HOST_INTERFACE_SCSI))
      $tabTitle[] = array(gprop('ldlunmaptab'), 'lmmld2.php?ldid=' . $_SESSION['ldid'] . '&act=lmm&tab=4', gtooltip('ldlunmaptab'));
    $tabTitle[] = array(gprop('ldlunclonetab'), 'ldclone.php?ldid=' . $_SESSION['ldid'] . '&act=lunclone&tab=4', gtooltip('ldlunclonetab'));
     */
    $treeId = $_SESSION['ssid'] . 'ld' . $_SESSION['ldid'];
  } else {
    $tabTitle = array(
      array(array(gprop('ldinfotab'), 'logdrv.php?daid=' . $daid, gtooltip('ldinfotab')), array(gprop('ldstatisticsmenu'), 'logdrvStat.php?daid=' . $daid, gtooltip('ldstatisticsmenu'))),
      array(
        array(gprop('ldbgatab'), 'ldbga.php?daid=' . $daid, gtooltip('ldbgatab'))
      ),
      array(gprop('ldchecktabletab'), 'errtable.php?daid=' . $daid, gtooltip('ldchecktabletab'))
    );
    /*
    // array('LUN Map','lmmld.php?daid='.$daid.'&act=lmm&tab=4','LMM'));
    // array('LUN Map',(($systype==PI_FB_HOST_INTERFACE_SCSI)?'lmmld2.php':'lmmld.php').'?daid='.$daid.'&.'ldid='.$_SESSION['ldid'].'&act=lmm&tab=4','LMM'));
    if (defined('PI_FB_HOST_INTERFACE_ISCSI') && ($systype == PI_FB_HOST_INTERFACE_ISCSI) ||
      defined('PI_FB_HOST_INTERFACE_FC') && ($systype == PI_FB_HOST_INTERFACE_FC) ||
      defined('PI_FB_HOST_INTERFACE_SAS') && ($systype == PI_FB_HOST_INTERFACE_SAS))
      $tabTitle[] = array(gprop('ldlunmaptab'), 'lmmld.php?daid=' . $daid . '&ldid=' . $_SESSION['ldid'] . '&act=lmm&tab=4', gtooltip('ldlunmaptab'));
    else if (defined('PI_FB_HOST_INTERFACE_SCSI') && ($systype == PI_FB_HOST_INTERFACE_SCSI))
      $tabTitle[] = array(gprop('ldlunmaptab'), 'lmmld2.php?daid=' . $daid . '&ldid=' . $_SESSION['ldid'] . '&act=lmm&tab=4', gtooltip('ldlunmaptab'));
    // $tabTitle[]=array(gprop('ldlunclonetab'),'ldclone.php?ldid='.$_SESSION['ldid'].'&act=lunclone&tab=4',gtooltip('ldlunclonetab'));
     */
    $treeId = $_SESSION['ssid'] . 'dald' . $_SESSION['ldid'];
  }
  return $tabTitle;
}

function tabTitlePDs($daid)
{
  global $treeId;
  if ($daid == null) {
    if ($_SESSION['ssid'] == 0)
      $tabTitle = array(
      array(gprop('pdsinfotab'), 'phydrvs.php', gtooltip('pdsinfotab'))
    );
    else
      $tabTitle = array(array(gprop('pdsinfotab'), 'phydrvs.php', gtooltip('pdsinfotab')));
    $treeId = $_SESSION['ssid'] . 'pds';
  } else {
    if ($_SESSION['ssid'] == 0)
      $tabTitle = array(
      array(gprop('pdinfotab'), 'phydrvs.php?daid=' . $daid, gtooltip('pdinfotab'))
    );
    else
      $tabTitle = array(
      array(gprop('pdinfotab'), 'phydrvs.php?daid=' . $daid, gtooltip('pdinfotab'))
    );
    $treeId = $_SESSION['ssid'] . 'dapds' . $_SESSION['daid'];
  }
  return $tabTitle;
}

function tabTitlePD($subsystem, $physicalDrive)
{
  global $treeId;
  $treeId = $_SESSION['ssid'] . 'pd' . $_SESSION['pdid'];
  $pv = getUserPrivilege($subsystem);
  $tabTitle[] = array(
    array(gprop('pdinfotab'), 'phydrv.php', gtooltip('pdinfotab')),
    array(gprop('pdstatisticsmenu'), 'phydrvStat.php', gtooltip('pdstatisticsmenu')),
    array('SMART','phydrvSmart.php','Physical Drive SMART Information'),
    array('SMART Log','phydrvSmartLog.php','Save Physical Drive SMART Log'));
  return $tabTitle;
}
function tabTitleEncls($subsystem)
{
  global $treeId;
  $treeId = $_SESSION['ssid'] . 'encls';
  /*
  if ($subsystem->ssi_JOBExpSSPresent == 0)
    $tabTitle = array(array(gprop('enclsinfotab'), 'encls.php', gtooltip('enclsinfotab')));
   */
  $tabTitle = array();
  $tabTitle[] = array(gprop('enclsinfotab'), 'encls.php', gtooltip('enclsinfotab'));
  $currentCtrlId = $subsystem->getCurrentCtrlId();
  $currentCtrl = $subsystem->getController($currentCtrlId->currentCtrlId);
  if (!_isCClassCtrl($currentCtrl->ci_ProductID)) {
    // ($tabTitle[] = array(gprop('enclssastopologytab'), 'sastopology.php', gtooltip('enclssastopologytab'));
  }

  return $tabTitle;
}
function tabTitleEncl($subsystem, $encl)
{
  global $treeId;
  $treeId = $_SESSION['ssid'] . 'encl' . $_SESSION['ecid'];
  // webpam-monitor: on a non-A3120 host there is no chassis to control - show only
  // the Information tab, drop Fan Control / Front Panel LEDs / Buzzer / Settings.
  if (!hasEnclosure())
    return array(array(gprop('enclinfotab'), 'encl.php', gtooltip('enclinfotab')));
  // $pv=getUserPrivilege($subsystem);
  // $currentCtrlId=$subsystem->getCurrentCtrlId();
  // $currentCtrl=$subsystem->getController($currentCtrlId->currentCtrlId);
  // $lcd = $subsystem->getLCD();
  if ($encl == null) {
    $tabTitle = array(array(gprop('enclinfotab'), 'encl.php', gtooltip('enclinfotab')));
  } else if ($encl->_id != 0) {
    $tabTitle = array(
      array(gprop('enclinfotab'), 'encl.php', gtooltip('enclinfotab')),
      array(gprop('enclsettingstab'), 'enclt.php', gtooltip('enclsettingstab')),
      array('Fan Control', 'fancontrol.php', 'Fan Control Settings'),
      array('Front Panel LEDs', 'frontpanel.php', 'Front Panel Network LED Assignment')
    );
    // array(gprop('enclfruvpdtab'),'fru.php',gtooltip('enclfruvpdtab')));
  } else {
    if ($encl->mpvs_EnclosureType == ENC_TYPE_J300S || $encl->mpvs_EnclosureType == ENC_TYPE_E3X0_2U12BAY) {
      $batterytitle = gprop('enclbatteriestab');
      $batterytitletab = gtooltip('enclbatteriestab');
    } else {
      $batterytitle = gprop('enclbatterytab');
      $batterytitletab = gtooltip('enclbatterytab');
    }
    $tabTitle = array(
      array(gprop('enclinfotab'), 'encl.php', gtooltip('enclinfotab')),
      array(gprop('enclsettingstab'), 'enclt.php', gtooltip('enclsettingstab')),
      array('Fan Control', 'fancontrol.php', 'Fan Control Settings'),
      array('Front Panel LEDs', 'frontpanel.php', 'Front Panel Network LED Assignment'),
      // array(gprop('enclfruvpdtab'),'fru.php',gtooltip('enclfruvpdtab')),
      // array(array($batterytitle,'battery.php',$batterytitletab),
      // getTabMenu(gprop('enclreconditionmenu'),'batteryt.php?deviceid=0',$pv,USER_GROUP_POWER,gtooltip('enclreconditionmenu'))),
      array(
        array(gprop('enclbuzzertab'), 'buzzer.php', gtooltip('enclbuzzertab')),
        array(gprop('enclbuzzersettingmenu'), 'buzzer.php?set', gtooltip('enclbuzzersettingmenu'))
      )
    );
    /*
    if ($lcd->ds_PresentStatus & LCD_PANEL_PRESENT)
      $tabTitle[] = array(gprop('lcdsettingstab'), 'lcdt.php', gtooltip('lcdsettingstab'));
     */
  }
  return $tabTitle;
}

function tabTitleNet($subsystem)
{
  global $treeId;
  $treeId = $_SESSION['ssid'] . 'net';
  $pv = getUserPrivilege($subsystem);
  $supportDualCtrl = $subsystem->supportdualcontroller();
  $curfrontendtype = $subsystem->getCurfrontendtype();
  $currentCtrlId = $subsystem->getCurrentCtrlId();
  $currentCtrl = $subsystem->getController($currentCtrlId->currentCtrlId);
  $supportMx10i = $subsystem->supportMx10i();
  $supportVlan = $subsystem->supportVlan($currentCtrl->ci_ProductID);
  $linkaggrs = $subsystem->getiscsilinkaggr(-1, -1, $currentCtrlId->currentCtrlId);
  if (defined('PI_FB_HOST_INTERFACE_ISCSI') && ($curfrontendtype->curfrontendtype == PI_FB_HOST_INTERFACE_ISCSI)) {
    $tabTitle = array(
      // array(gprop('networkmgmtmanagementporttab'),'net.php?act=ether',gtooltip('networkmgmtmanagementporttab')),
      array(gprop('networkmgmtmanagementporttab'), 'net.php?act=ether', gtooltip('networkmgmtmanagementporttab')),
      array(gprop('linkaggrtab'), 'linkaggregation.php', gtooltip('linkaggrtab'))
    );

  } else if (defined('PI_FB_HOST_INTERFACE_FC') && ($curfrontendtype->curfrontendtype == PI_FB_HOST_INTERFACE_FC) ||
    defined('PI_FB_HOST_INTERFACE_SAS') && ($curfrontendtype->curfrontendtype == PI_FB_HOST_INTERFACE_SAS)) {
    /*
    $tabTitle = array(array(gprop('networkmgmtmanagementporttab'), 'net.php?act=ether', gtooltip('networkmgmtmanagementporttab')));
    $tabTitle = array(
      array(
        array(gprop('networkmgmtmanagementporttab'), 'net.php?act=ether', gtooltip('networkmgmtmanagementporttab')),
        getTabMenu(gprop('netmaintaintenancemenu'), 'netmaintenance.php', $pv, USER_GROUP_POWER, gtooltip('netmaintenancemenutab'))
      )
    );
     */
    if ($supportDualCtrl->DualCtrlSupport)
      $tabTitle = array(
      array(gprop('networkmgmtmanagementporttab'), 'net.php?act=ether', gtooltip('networkmgmtmanagementporttab')), array(gprop('netmaintaintenancemenu'), 'netmaintenance.php?act=ether', gtooltip('netmaintenancemenutab'))
    );
    else
      $tabTitle = array(array(gprop('networkmgmtmanagementporttab'), 'net.php?act=ether', gtooltip('networkmgmtmanagementporttab')));

  } else if (defined('PI_FB_HOST_INTERFACE_SCSI') && ($curfrontendtype->curfrontendtype == PI_FB_HOST_INTERFACE_SCSI)) {
    $tabTitle = array(array(gprop('networkmgmtmanagementporttab'), 'net.php?act=ether', gtooltip('networkmgmtmanagementporttab')));;
  } else {
    $tabTitle = array(array(gprop('networkmgmtmanagementporttab'), 'net.php?act=ether', gtooltip('networkmgmtmanagementporttab')));;
  }
  return $tabTitle;
}
function tabTitleStorage($subsystem)
{
  global $treeId;
  $treeId = $_SESSION['ssid'] . 'storage';
  $pv = getUserPrivilege($subsystem);
  if (defined('PI_FB_HOST_INTERFACE_SAS') && ($subsystem->frontendType == PI_FB_HOST_INTERFACE_SAS) ||
    defined('PI_FB_HOST_INTERFACE_FC') && ($subsystem->frontendType == PI_FB_HOST_INTERFACE_FC) ||
    defined('PI_FB_HOST_INTERFACE_ISCSI') && ($subsystem->frontendType == PI_FB_HOST_INTERFACE_ISCSI))
    $tabTitle = array(
    array(
      array(gprop('lmminitiatorstab'), 'initiator.php', gtooltip('lmminitiatorstab')),
      getTabMenu(gprop('lmmaddinitiatormenu'), 'initiatora.php', $pv, USER_GROUP_POWER, gtooltip('lmmaddinitiatormenu')),
      getTabMenu(gprop('lmmdeleteinitiatorsmenu'), 'initiatord.php', $pv, USER_GROUP_POWER, gtooltip('lmmdeleteinitiatorsmenu'))
    ),
    array(
      array(gprop('lmmlunmaptab'), 'lmms.php', gtooltip('lmmlunmaptab')),
      getTabMenu(gprop('lmmaddlunmapmenu'), 'lmm.php', $pv, USER_GROUP_POWER, gtooltip('lmmaddlunmapmenu'))
    )
  );

  else if (defined('PI_FB_HOST_INTERFACE_SCSI') && ($subsystem->frontendType == PI_FB_HOST_INTERFACE_SCSI))
    $tabTitle = array(
    array(
      array(gprop('lmmlunmaptab'), 'lmms2.php', gtooltip('lmmlunmaptab')),
      getTabMenu(gprop('lmmeditlunmapmenu'), 'lmm2.php?lmmid=0', $pv, USER_GROUP_POWER, gtooltip('lmmeditlunmapmenu'))
    )
  );
  else
    $tabTitle = array(array(gprop('lmminitiatorstab'), 'initiator.php', gtooltip('lmminitiatorstab')));
  return $tabTitle;
}
function tabTitleService()
{
  return array(array(gprop('swmgmtservicetab'), 'services.php'));
}
/* iSCSI SLP settings tab (slp/slpi/slpt). The A3120 is DAS-only (no iSCSI
 * target); a single SLP tab keeps these pages rendering rather than fataling on
 * an undefined helper. */
function tabTitleISCSI($subsystem)
{
  return array(array(gprop('slp'), 'slp.php'));
}
function getScheduleRecurrency($schedule)
{
  if ($schedule->s_u8Recurrency == SCHEDULE_RECURRENCY_DAILY)
    $recurrency = gprop('daily');
  else if ($schedule->s_u8Recurrency == SCHEDULE_RECURRENCY_WEEKLY)
    $recurrency = gprop('weekly');
  else
    $recurrency = gprop('monthly');

  return $recurrency;
}

function getScheduleStartTime($schedule)
{
  $hour = (int)($schedule->s_u16StartTime / 60);
  $minute = $schedule->s_u16StartTime - (int)($schedule->s_u16StartTime / 60) * 60;
  if ($hour < 10)
    $hour = '0' . $hour;
  if ($minute < 10)
    $minute = '0' . $minute;
  $time = $hour . ':' . $minute . ' ' . ($schedule->s_u8MonthStart + 1) . '/' . $schedule->s_u8DayStart . '/' . ($schedule->s_u16YearStart + 1900);
  return $time;
}

function getScheduleString($type)
{
  // Comment out the BGAS_TYPE_CLONE in the php page by Brooke Yang
  // return ($type == BGAS_TYPE_MP ? gprop('mediapatrol') : ($type == BGAS_TYPE_RC ? gprop('redundancycheck') : ($type == BGAS_TYPE_SC ? gprop('sparecheck') : ($type == BGAS_TYPE_BRC ? gprop('batteryreconditioning') : ($type == BGAS_TYPE_PS_ON ? gprop('poweron') : ($type == BGAS_TYPE_PS_OFF ? gprop('poweroff') : ($type == BGAS_TYPE_CLONE ? gprop('ldlunclonetab') : gprop('unknown'))))))));
  return ($type == BGAS_TYPE_MP ? gprop('mediapatrol') : ($type == BGAS_TYPE_RC ? gprop('redundancycheck') : ($type == BGAS_TYPE_SC ? gprop('sparecheck') : ($type == BGAS_TYPE_BRC ? gprop('batteryreconditioning') : ($type == BGAS_TYPE_PS_ON ? gprop('poweron') : ($type == BGAS_TYPE_PS_OFF ? gprop('poweroff') : gprop('unknown')))))));
}

function getSpareAssociatedArrays($subsystem, $spare)
{
  $daids = $spare->acd_NoOfArraysAssociated;
  $daString = '';
  if (count($daids ?? array()) == 0)
    return 'N/A';

  for ($j = 0; $j < count($daids ?? array()); $j++) {
    $array = $subsystem->getDiskArray($daids[$j]);
    $daString .= ('ID' . $daids[$j] . ':' . $array->acd_ArrayName);
    if ($j != count($daids ?? array()) - 1)
      $daString .= ',';
  }
  return $daString;
}

function getTemperatureString($c)
{
  $f = floor($c * 9 / 5 + 32);
  return $c . '&ordm;C / ' . $f . '&ordm;F';
}

function getEnableString($n)
{
  return $n ? gprop('enabled') : gprop('disabled');
}

function getSupportString($n)
{
  return $n ? gprop('supported') : gprop('notsupported');
}
function getNotSupportString()
{
  return getSupportString(0);
}
function getYesString($n)
{
  return $n ? gprop('yes') : gprop('no');
}
function getStatusColor($status)
{
  if (strstr($status, 'Dead')) return 'red';
  if (strstr($status, 'Offline')) return 'red';
  if (strstr($status, 'Incomplete')) return 'red';
  if (strstr($status, 'Critical')) return 'orange';
  if (strstr($status, 'Rebuild')) return 'orange';
  if (strstr($status, 'Degraded')) return 'orange';
  return '#262626';
}

function refreshTreeFrame()
{
  echo '
  <script>
    if(top.treefrm)
      top.treefrm.location.href="treeframe.php?refresh";
  </script>';
}
function refreshEventFrame()
{
  echo '
  <script>
    top.eventfrm.location.href="event.php";
  </script>';
}

function refreshCurrentPage($seconds = 10)
{
  if (isset($_SERVER['QUERY_STRING'])) {
    $query_string = $_SERVER['QUERY_STRING'];
    if (!preg_match('/refreshthis=1/', $query_string))
      $query_string .= "&refreshthis=1";
  } else
    $query_string = 'refreshthis=1';
  echo '
  <meta http-equiv=refresh content="' . $seconds . '; URL=' . $_SERVER['PHP_SELF'] . '?' . $query_string . '">';
}

function redirectPage($url, $refreshTree = 0)
{
  if ($refreshTree) refreshTreeFrame();
  echo '<script>document.location.href="' . $url . '"</script>';
}

function getLogDrvBGAFile($file)
{
  if (isset($_GET['ldid']))
    $file .= '?ldid=' . $_GET['ldid'] . (isset($_GET['daid']) ? ('&daid=' . $_GET['daid']) : '') . (isset($_GET['bga']) ? '&bga' : '');
  else if (isset($_GET['bga']))
    $file .= '?bga';
  return $file;
}

function getSpareDrvBGAFile($file)
{
  if (isset($_GET['spid']))
    $file .= '?spid=' . $_GET['spid'] . (isset($_GET['bga']) ? '&bga' : '');
  else if (isset($_GET['bga']))
    $file .= '?bga';
  return $file;
}

function tabTitleUsers()
{
  global $treeId;
  $treeId = $_SESSION['ssid'] . 'user';

  $tabTitle = array(
    array(gprop('usermgmtinfotab'), 'users.php', gtooltip('usermgmtinfotab')),
    array(gprop('usermgmtsettingstab'), 'usert.php', gtooltip('usermgmtsettingstab')),
    array(gprop('usermgmteventsubscriptiontab'), 'evtsubscribe.php', gtooltip('usermgmteventsubscriptiontab')),
    array(gprop('usermgmtpasswordtab'), 'userp.php', gtooltip('usermgmtpasswordtab')),
    array(gprop('usermgmtcreatetab'), 'userc.php', gtooltip('usermgmtcreatetab')),
    array(gprop('usermgmtdeletetab'), 'userd.php', gtooltip('usermgmtdeletetab')),
    array(gprop('usermgmtsessiontab'), 'session.php', gtooltip('usermgmtsessiontab')),
  );

  return $tabTitle;
}

function tabTitleUser()
{
  global $treeId;
  $treeId = $_SESSION['ssid'] . 'user';

  $tabTitle = array(
    array(gprop('usermgmtinfotab'), 'users.php', gtooltip('usermgmtinfotab')),
    array(gprop('usermgmtsettingstab'), 'usert.php', gtooltip('usermgmtsettingstab')),
    array(gprop('usermgmteventsubscriptiontab'), 'evtsubscribe.php', gtooltip('usermgmteventsubscriptiontab')),
    array(gprop('usermgmtpasswordtab'), 'userp.php', gtooltip('usermgmtpasswordtab'))
  );

  return $tabTitle;
}

function tabTitleAdminTool($subsystem)
{
  global $treeId;
  $treeId = $_SESSION['ssid'] . 'sw';
  $pv = getUserPrivilege($subsystem);
  $supportDualCtrl = $subsystem->supportdualcontroller();
  $tabTitle = array(
    array(gprop('swmgmtservicetab'), 'services.php', gtooltip('swmgmtservicetab')),
    getTabMenu(gprop('swmgmtexporttab'), 'export.php', $pv, USER_GROUP_SUPER, gtooltip('swmgmtexporttab')),
    getTabMenu(gprop('swmgmtimporttab'), 'import.php', $pv, USER_GROUP_SUPER, gtooltip('swmgmtimporttab')),
  /*
    array(
      array(gprop('swmgmtfwupdatetab'), 'flashnew.php', gtooltip('swmgmtfwupdatetab')),
      getTabMenu(gprop('swmgmtdownloadfromtftpmenu'), 'uploadnew.php?tftp', $pv, USER_GROUP_SUPER, gtooltip('swmgmtdownloadfromtftpmenu')),
      getTabMenu(gprop('swmgmtdownloadfromlocal'), 'uploadnew.php', $pv, USER_GROUP_SUPER, gtooltip('swmgmtdownloadfromlocal'))
    )
   */
  );
  // if ($supportDualCtrl->DualCtrlSupport) {
  //    $tabTitle[] = array(gprop('imageversion'), 'imgversions.php', gtooltip('imageversion'));
  // }
  return $tabTitle;
}
function BigIntCompute($a, $b, $oper)
{
  $a = strval($a);
  $b = strval($b);
  $result = '';
  $remains = 0;
  $maxLength = (strlen($a) > strlen($b) ? strlen($a) : strlen($b));
  if (strlen($a) < 13 && strlen($b) < 13) {
    return $a - $b;
  }
  for ($i = 0; $i < $maxLength; $i++) {
    $posA = strlen($a) - $i - 1;
    $posB = strlen($b) - $i - 1;
    if ($posA >= 0)
      $numA = $a[$posA];
    else
      $numA = 0;
    if ($posB >= 0)
      $numB = $b[$posB];
    else
      $numB = 0;
    $tempResult = $numA + $oper * $numB;
    $tempResult += $remains;
    if ($tempResult < 0) {
      $result = ($tempResult + 10) . $result;
      $remains = -1;
    } else if ($tempResult < 10) {
      $result = $tempResult . $result;
      $remains = 0;
    } else {
      $result = ($tempResult % 10) . $result;
      $remains = 1;
    }
  }
  if ($remains != 0) {
    $result = $remains . $result;
  }
  return $result;
}
function BigIntAdd($a, $b)
{
  return BigIntCompute($a, $b, 1);
}
function BigIntMinus($a, $b)
{
  return BigIntCompute($a, $b, -1);
}
function LoadImg($method, $path)
{
  $imgpath = 'http://' . $_SERVER['HTTP_HOST'] . ':' . $_SERVER['SERVER_PORT'] . '/' . $path;
  echo ' ' . $method . '="' . $imgpath . '" ';
}
function RestartService($serviceName)
{
  echo "
    <iframe src='restart.php?service=" . $serviceName . "' style='visibility:hidden'>

    </iframe>
    ";
}
function _isCClassCtrl($ctrlType)
{
  if (($ctrlType >> 8) == (PT_VR1000_SERIES_SUBSYSTEM >> 8)) {
    return 1;
  }
  return 0;
}
function _isCClassJbod($ctrlType)
{
  if (($ctrlType >> 8) == (PT_VJ1000_SERIES_SUBSYSTEM >> 8)) {
    return 1;
  }
  return 0;
}

/* ---------------------------------------------------------------------------
 * RAID helpers the vendor php_i2.dll registered as GLOBAL PHP functions
 * (strings "getsupportedraidlevels", "getstringraidlevel", "logsizetopartition
 * size", ... in the DLL). The create wizard (arrayc2/arrayc3/ldcfg.inc,
 * logdrvc*) calls them; recreated here in PHP for the Linux port.
 * ------------------------------------------------------------------------- */

/* Levels the engine offers for a member count (mirrors the mdadm backend:
 * RAID-ENGINE-MDADM-PLAN.md P2 - 1 drive RAID0; 2 +RAID1; >=3 +RAID5;
 * >=4 +RAID6/RAID10). */
function getSupportedRAIDLevels($pdCount)
{
  $levels = array();
  if ($pdCount >= 1) $levels[] = RAID0;
  if ($pdCount >= 2) $levels[] = RAID1;
  if ($pdCount >= 3) $levels[] = RAID5;
  if ($pdCount >= 4) {
    $levels[] = RAID6;
    $levels[] = RAID10;
  }
  return $levels;
}

function getStringRAIDLevel($level)
{
  switch ((int)$level) {
    case RAID0:  return 'RAID0';
    case RAID1:  return 'RAID1';
    case RAID3:  return 'RAID3';
    case RAID5:  return 'RAID5';
    case RAID6:  return 'RAID6';
    case RAID10: return 'RAID10';
    case RAID30: return 'RAID30';
    case RAID50: return 'RAID50';
    case RAID51: return 'RAID51';
    case RAID55: return 'RAID55';
    case RAID60: return 'RAID60';
    case JBOD:   return 'JBOD';
    default:     return 'Unknown';
  }
}

function getStringLogDrvSectorSize($code)
{
  switch ((int)$code) {
    case 2: return '1 KB';
    case 3: return '2 KB';
    case 4: return '4 KB';
    default: return '512 Bytes';
  }
}

/* LD attrib cache-policy bits (I2BAPI_LDCACHEPOLICY_* decode): bit0 =
 * WriteBack, bit1 = ReadAhead, bit2 = NoCache. Display-only on the Linux
 * engine (no controller cache). */
function getLogDrvWritePolicyFromValue($v)
{
  return ((int)$v) ? 1 : 0;
}
function getLogDrvReadPolicyFromValue($v)
{
  if ((int)$v == 0) return 4;      /* NoCache  */
  if ((int)$v == 1) return 2;      /* ReadAhead */
  return 0;                        /* ReadCache */
}
function getStringLogDrvWritePolicyValue($v)
{
  return ((int)$v) ? 'WriteBack' : 'WriteThru';
}
function getStringLogDrvReadPolicyValue($v)
{
  if ((int)$v == 0) return 'NoCache';
  if ((int)$v == 1) return 'ReadAhead';
  return 'ReadCache';
}
function getStringLogDrvWritePolicy($attrib)
{
  if (((int)$attrib) & 4) return 'WriteThru';
  return (((int)$attrib) & 1) ? 'WriteBack' : 'WriteThru';
}
/* Read-policy string from the LD attribute bitfield (mirror of
 * getStringLogDrvWritePolicy; bit1 = ReadAhead, bit2 = NoCache per the layout
 * documented above). */
function getStringLogDrvReadPolicy($attrib)
{
  if (((int)$attrib) & 4) return 'NoCache';
  return (((int)$attrib) & 2) ? 'ReadAhead' : 'ReadCache';
}
/* Human-readable capacity for a block count (blocks * blockSize bytes); reuses
 * fmtSize. Used by the PD / LD detail pages. */
function getStringDriveSize($blocks, $blockSize)
{
  return fmtSize((double)$blocks * (double)$blockSize);
}
/* User-privilege / controller coercion-method labels for the user + controller
 * detail pages. Exact enum values are an on-device CALIBRATE point (display
 * only; standard Promise WebPAM ordering). */
function getStringUserPrivilege($groupId)
{
  switch ((int)$groupId) {       /* USER_GROUP_* values from php_i2.dll */
    case 1:  return 'View';          /* USER_GROUP_USER */
    case 2:  return 'Maintenance';   /* USER_GROUP_MAINTENANCE */
    case 3:  return 'Power User';    /* USER_GROUP_POWER */
    case 4:  return 'Administrator'; /* USER_GROUP_SUPER */
    default: return 'User';
  }
}
function getStringCtrlCoercionMethod($method)
{
  switch ((int)$method) {
    case 0:  return 'GBTruncate';
    case 1:  return '10GBTruncate';
    case 2:  return 'GrpRounding';
    default: return 'None';
  }
}
/* Current (effective) write policy from the LD attribute bitfield. No controller
 * cache / BBU on the Linux engine, so current == configured. */
function getStringLogDrvCurrentWritePolicyValue($attrib)
{
  return getStringLogDrvWritePolicy($attrib);
}
/* Single operational-status code -> label (getStringOpStatusArray joins these).
 * Values are a CALIBRATE point (display only; standard Promise op-status order). */
function getStringOpStatus($status)
{
  switch ((int)$status) {
    case 0:  return 'OK';
    case 1:  return 'Rebuilding';
    case 2:  return 'Synchronizing';
    case 3:  return 'Initializing';
    case 4:  return 'Migrating';
    case 5:  return 'PDM';
    case 6:  return 'Transition';
    case 7:  return 'Degraded';
    case 8:  return 'Offline';
    default: return 'Unknown';
  }
}
/* Controller redundancy type -> label (subsystemt.php); matches the CLRT_*
 * constants (0 Active-Standby / 1 Active-Active / 2 Active-Active DCM). */
function getStringRedundancyType($type)
{
  switch ((int)$type) {           /* CLRT_* values from php_i2.dll */
    case 1:  return 'Active-Active';       /* CLRT_ACTIVE_ACTIVE */
    case 2:  return 'Active-Standby';      /* CLRT_ACTIVE_STANDBY */
    case 3:  return 'Active-Active (DCM)'; /* CLRT_ACTIVE_ACTIVE_DCM */
    default: return 'None';
  }
}

/* Per-PD partition bytes for an LD (PHP mirror of ldcfg.inc's JS
 * getPartitionSize + its stripe rounding; arrayc3.php multiplies the result
 * by the member count). */
function logSizeToPartitionSize($ldSize, $pdCount, $raidLevel, $axles, $stripeBytes)
{
  if ($pdCount < 1) return 0;
  switch ((int)$raidLevel) {
    case RAID1:
    case RAID10:
    case RAID01: $size = $ldSize * 2 / $pdCount; break;
    case RAID3:
    case RAID5:  $size = $ldSize / max(1, $pdCount - 1); break;
    case RAID30:
    case RAID50: $size = $ldSize / max(1, $pdCount - $axles); break;
    case RAID51: $size = $ldSize / max(1, $pdCount / 2 - 1); break;
    case RAID6:  $size = $ldSize / max(1, $pdCount - 2); break;
    case RAID60: $size = $ldSize / max(1, $pdCount - 2 * $axles); break;
    default:     $size = $ldSize / $pdCount; break;
  }
  if ($stripeBytes > 0 && fmod($size, $stripeBytes))
    $size = ceil(($size + $stripeBytes) / $stripeBytes) * $stripeBytes;
  return $size;
}

// migrate3's free-capacity display wants the unrounded partition size:
// stripeBytes=0 skips the stripe-rounding step above.
function logSizeToPartitionSizeNoRounding($ldSize, $pdCount, $raidLevel, $axles)
{
  return logSizeToPartitionSize($ldSize, $pdCount, $raidLevel, $axles, 0);
}

function getStringSize($size)
{
  return fmtSize($size);
}
?>
