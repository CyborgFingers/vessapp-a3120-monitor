<?php

/*******************************************************************************
 * Filename    : encl.php
 * Description : islaVista enclosure information.
 * Created on  : 08/28/03
 *
 * (C) Copyright Promise Technology Inc.,2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_GET['ssid']))
  SetSessionValue('ssid', $_GET['ssid']);
if (isset($_GET['ecid']))
  SetSessionValue('ecid', $_GET['ecid']);
SyncSession();

function isCclass3U($ctrlType, $enclType)
{
  if (!isCClassEncl($ctrlType, $enclType) && !_isCClassJbod($ctrlType))
    return false;
  if ($enclType == ENC_TYPE_VR1000Y_3U16BAY_S ||
    $enclType == ENC_TYPE_VR1000Y_3U16BAY_R)
    return true;
  else
    return false;
}

function getEnclosureInfo($enclIndex)
{
  global $topology;
  global $encls;
  global $enclSettings;
  global $enclrange;
  global $subsystem;

  $enclInfo = $encls[0];
  $enclInfo->enclIndex = $enclIndex;
  $enclInfo->_batteries = $subsystem->getBatteries($_SESSION['ecid']);

  if ($enclIndex == 0) {
    $enclInfo->isJBOD = false;
  } else {
    $enclInfo->isJBOD = true;
  }
  $enclInfo->frontendType = $subsystem->frontendType;
  $enclInfo->numberOfCtlPresent = $subsystem->ssi_NumberOfCtlPresent;
  if ($enclInfo->mpvs_EnclosureType == ENC_TYPE_J300S
    || $enclInfo->mpvs_EnclosureType == ENC_TYPE_E3X0_2U12BAY
    || $enclInfo->mpvs_EnclosureType == ENC_TYPE_E3X0_3U16BAY
    || _isCClassJbod($enclInfo->mpvs_CtrlType)) {
    $enclInfo->enclWarningTemp = $enclSettings->cs_TempThresholdWarning;
    $enclInfo->enclCriticalTemp = $enclSettings->cs_TempThresholdCritical;
    $enclInfo->ctrlWarningTemp = $enclSettings->cs_CtrlTempThresholdWarning;
    $enclInfo->ctrlCriticalTemp = $enclSettings->cs_CtrlTempThresholdCritical;
  } else {
    $enclInfo->enclWarningTemp = $enclInfo->_tempWarning;
    $enclInfo->enclCriticalTemp = $enclInfo->_tempCritical;
    $enclInfo->ctrlWarningTemp = $enclInfo->_ctrlTempWarning;
    $enclInfo->ctrlCriticalTemp = $enclInfo->_ctrlTempCritical;
  }
  // $enclInfo->maxCTRLWARN = $enclrange->maxCTRLWAR;
  // $enclInfo->maxENCLWARN = $enclrange->maxENCLWARN;
  $enclInfo->maxCTRLWARN = $enclInfo->ctrlWarningTemp;
  $enclInfo->maxENCLWARN = $enclInfo->enclWarningTemp;

  for ($i = 0; $i < count($enclInfo->_temps); $i++) {
    $enclInfo->_temps[$i] = getTempInfo($enclInfo, $i);
  }
  for ($i = 0; $i < count($enclInfo->_fans); $i++) {
    $enclInfo->_fans[$i] = getFanInfo($enclInfo, $i);
  }

  for ($i = 0; $i < count($enclInfo->_blowers); $i++) {
    $enclInfo->_blowers[$i] = getBlowerInfo($enclInfo, $i);
  }
  for ($i = 0; $i < count($enclInfo->_psus); $i++) {
    $enclInfo->_psus[$i] = getPSUInfo($enclInfo, $i);
  }

  if ($enclInfo->mpvs_EnclosureType == ENC_TYPE_J300S ||
    $enclInfo->mpvs_EnclosureType == ENC_TYPE_E3X0_2U12BAY ||
    $enclInfo->mpvs_EnclosureType == ENC_TYPE_E3X0_3U16BAY ||
    _isCClassCtrl($enclInfo->mpvs_CtrlType)) {
    $enclInfo->_ctrls = getCtrlsInfo($enclInfo);
  }
  return $enclInfo;
}

function getCtrlImagePath($enclInfo, $ctrl, $sasPortsCount)
{
  if ($ctrl->ci_DeviceID == 0)
    $side = 'L';
  else
    $side = 'R';
  if ($ctrl->_RaidState != 'Not Accessible')
    $state = 'Light';
  else
    $state = 'Shade';
  if ($enclInfo->mpvs_EnclosureType == ENC_TYPE_J300S ||
    $enclInfo->mpvs_EnclosureType == ENC_TYPE_E3X0_2U12BAY) {
    $model = '310';
  } else if ($enclInfo->mpvs_EnclosureType == ENC_TYPE_E3X0_3U16BAY) {
    $model = '610';
  } else
    return 'images/blank.gif';
  if ($enclInfo->frontendType == PI_FB_HOST_INTERFACE_FC)
    $path = 'enclosureImages/eClass/fc' . $side . 'CtrlE' . $model . $state . '.gif';
  else if ($enclInfo->frontendType == PI_FB_HOST_INTERFACE_SAS && $sasPortsCount == 2)
    $path = 'enclosureImages/eClass/sas2' . $side . 'CtrlE' . $model . $state . '.gif';
  else if ($enclInfo->frontendType == PI_FB_HOST_INTERFACE_SAS && $sasPortsCount == 4)
    $path = 'enclosureImages/eClass/sas4' . $side . 'CtrlE' . $model . $state . '.gif';
  else $path = 'images/blank.gif';
  return $path;
}

function getCtrlsInfo($enclInfo)
{
  if ($_SESSION['ecid'] == 0) {
    return getSubsystemCtrlsInfo($enclInfo);
  } else {
    return getJBODCtrlsInfo($enclInfo);
  }
}

function getSubsystemCtrlsInfo($enclInfo)
{
  global $controllers;
  global $subsystem;

  $ctrls = array();
  for ($i = 0; $i < count($controllers); $i++) {
    $ctrl = $controllers[$i];
    $portCount = count($subsystem->getsasport($ctrl->ci_DeviceID + 1));
    $ctrlId = $ctrl->ci_DeviceID;
    $ctrl->_tooltip = gprop('controller') . ' ' . ($ctrlId + 1);
    $ctrl->_id = $ctrl->ci_DeviceID;

    if ($ctrl->ci_ReadinessStatus == 'Active'
      || $ctrl->ci_ReadinessStatus == 'Standby'
      || $ctrl->_RaidState == 'Not Accessible') {
      if ($ctrl->_RaidState == 'Not Accessible') {
        $ctrl->_tooltip .= '<br>' . gprop('status') . ': ' . gprop('notaccessible');
      } else {
        $ctrl->_tooltip .= '<br>' . gprop('status') . ': ' . $ctrl->ci_ReadinessStatus;
      }
      $ctrl->_image = getCtrlImagePath($enclInfo, $ctrl, $portCount);
    } else {
      $ctrl->_tooltip .= '<br>' . gprop('status') . ': ' . gprop('notpresent');
      $ctrl->_image = 'images/blank.gif';
    }
  }
  $ctrls[] = $ctrl;
  return $ctrls;
}

function getJBODCtrlsInfo($enclInfo)
{
  global $topology;

  $ctrls = array();
  for ($i = 1; $i < count($topology); $i++) {
    if ($_SESSION['ecid'] == ($topology[$i]->enclosureID - 1)) {
      for ($j = 0; $j < count($topology[$i]->expanders); $j++) {
        $expander = $topology[$i]->expanders[$j];
        $ctrl->_id = $expander->expanderID - 1;
        if ($topology[$i]->expanders[$j]->numPorts == 0) {
          $ctrl->_image = 'images/blank.gif';
        } else {
          if ($ctrl->_id == 0)
            $position = 'left';
          else
            $position = 'right';
          if ($enclInfo->mpvs_CtrlType == CTRL_J300)
            $ctrl->_image = 'enclosureImages/eClass/J300s_' . $position . '_IOM.gif';
          else if ($enclInfo->mpvs_EnclosureType == ENC_TYPE_E3X0_3U16BAY)
            $ctrl->_image = 'enclosureImages/eClass/sas2CtrlJ610_' . $position . '.gif';
          else
            $ctrl->_image = 'enclosureImages/eClass/sas2CtrlJ310_' . $position . '.gif';
        }
        $ctrls[] = $ctrl;
      }
    }
  }
  return $ctrls;
}

function getTempInfo($enclInfo, $tempIndex)
{
  $temp = $enclInfo->_temps[$tempIndex];
  $temp->_isAvaiable = true;

  if ($temp->_location == 'Backplane') {
    $temp->_locationString = gprop('backplane');
    // $temp->_tempMaxWarn=$enclInfo->maxENCLWARN;
    $tempWarning = $enclInfo->enclWarningTemp;
    $tempCritical = $enclInfo->enclCriticalTemp;
  } else {
    $temp->_locationString = str_replace("Controller", gprop('controller'), $temp->_location);
    $temp->_locationString = str_replace("PSU", gprop('powersupply'), $temp->_locationString);
    // $temp->_tempMaxWarn=$enclInfo->maxCTRLWARN;
    $tempWarning = $enclInfo->ctrlWarningTemp;
    $tempCritical = $enclInfo->ctrlCriticalTemp;
  }
  if ($enclInfo->mpvs_EnclosureType == ENC_TYPE_J300S ||
    $enclInfo->mpvs_EnclosureType == ENC_TYPE_E3X0_2U12BAY ||
    $enclInfo->mpvs_EnclosureType == ENC_TYPE_E3X0_3U16BAY) {
    if ($temp->_temp == 0xEF) {
      $temp->_isAvaiable = false;
      $temp->_image = 'images/blank.gif';
      return $temp;
    }
  }
  if ($temp->_temp >= $tempCritical) {
    $temp->_status = 'critical';
    $temp->_image = 'enclosureImages/tmpbad.png';
  } else if ($temp->_temp >= $tempWarning) {
    $temp->_status = 'warning';
    $temp->_image = 'enclosureImages/tmpcritical.png';
  } else {
    $temp->_status = 'normal';
    $temp->_image = 'enclosureImages/tmpgood.png';
  }
  $temp->_tooltip = gprop('temperaturesensor') . ' ' . ($tempIndex + 1)
    . '<br>' . gprop('location') . ': ' . $temp->_locationString
    . '<br>' . gprop('currenttemperature') . ': ' . getTemperatureString($temp->_temp);
  return $temp;
}

function getFanInfo($enclInfo, $fanIndex)
{
  $fan = $enclInfo->_fans[$fanIndex];
  /* The A3120 reports its tach elements as fans (Fan 1 = CPU, Fan 2/3 = Cooling
   * Unit N, with the CU number in _psuid). Render those with the round blower
   * icon and a "Cooling Unit N Blower" / "CPU Fan" label so the back view + hover
   * match the vendor; a real PSU fan (no _location) keeps the psfan icon + PSU label. */
  $loc = isset($fan->_location) ? $fan->_location : '';
  if ($loc === 'Cooling Unit') {
    $iconbase = 'blower';
    $label = gprop('coolingunit') . ' ' . $fan->_psuid . ' ' . gprop('blower');
  } else if ($loc === 'CPU') {
    $iconbase = 'blower';
    $label = $fan->_location . ' ' . gprop('fan');
  } else {
    $iconbase = 'psfan';
    $label = gprop('powersupplyunit') . ' ' . $fan->_psuid . ' ' . gprop('fan') . ' ' . $fan->_fanidInPsu;
  }
  if ($fan->_status == 2) {
    $fan->_image = 'images/blank.gif';
    $fan->_isAvaiable = false;
  } else {
    $fan->_isAvaiable = true;
    if ($fan->_speed == 0)
      $image = $iconbase . 'stop';
    else if ($fan->_status != 0)
      $image = $iconbase . 'bad';
    else
      $image = $iconbase . 'good';
    $fan->_image = 'enclosureImages/' . $image . '.png';
  }
  $fan->_tooltip = $label
    . '<br>' . gprop('status') . ': ' . $fan->_statusStr
    . '<br>' . gprop('currentspeed') . ': ' . $fan->_speed . ' rpm';

  return $fan;
}

function getBlower2Info($enclInfo, $blowerIndex)
{
  $blower = $enclInfo->_blowers[$blowerIndex];
  $blower2 = $enclInfo->_blowers[$blowerIndex + 1];
  $worseBlower = $blower;
  if ($enclInfo->mpvs_EnclosureType == ENC_TYPE_J300S ||
    $enclInfo->mpvs_EnclosureType == ENC_TYPE_E3X0_2U12BAY ||
    $enclInfo->mpvs_EnclosureType == ENC_TYPE_E3X0_3U16BAY) {
    $imageHeader = 'psfan';
  } else {
    $imageHeader = 'blower';
  }
  if ($blower->_status == 2 || $blower2->_status == 2) {
    $blower->_image = 'images/blank.gif';
    $blower->_isAvaiable = false;
  } else {
    $blower->_isAvaiable = true;
    if ($blower->_speed == 0 || $blower2->_speed == 0) {
      $image = $imageHeader . 'stop';
      if ($blower->_speed == 0) {
        $worseBlower = $blower;
      } else if ($blower2->_speed == 0) {
        $worseBlower = $blower2;
      }
    } else if ($blower->_status != 0 || $blower2->_status != 0) {
      $image = $imageHeader . 'bad';
      if ($blower->_status != 0) {
        $worseBlower = $blower;
      } else if ($blower2->_status != 0) {
        $worseBlower = $blower2;
      }
    } else
      $image = $imageHeader . 'good';
    $blower->_image = 'enclosureImages/' . $image . '.png';
  }

  $blower->_tooltip = gprop('coolingunit') . ' ' . ($blowerIndex + 1) . ' ' . ($blowerIndex + 2) . ' ' . gprop('blower')
    . '<br>' . gprop('blower') . ($blowerIndex + 1) . ' ' . gprop('status') . ': ' . $blower->_statusStr
    . '<br>' . gprop('blower') . ($blowerIndex + 1) . ' ' . gprop('currentspeed') . ': ' . $blower->_speed . ' rpm'
    . '<br>' . gprop('blower') . ($blowerIndex + 2) . ' ' . gprop('status') . ': ' . $blower2->_statusStr
    . '<br>' . gprop('blower') . ($blowerIndex + 2) . ' ' . gprop('currentspeed') . ': ' . $blower2->_speed . ' rpm';
  return $blower;
}

function getBlowerInfo($enclInfo, $blowerIndex)
{
  $blower = $enclInfo->_blowers[$blowerIndex];
  if ($enclInfo->mpvs_EnclosureType == ENC_TYPE_J300S ||
    $enclInfo->mpvs_EnclosureType == ENC_TYPE_E3X0_2U12BAY ||
    $enclInfo->mpvs_EnclosureType == ENC_TYPE_E3X0_3U16BAY) {
    $imageHeader = 'psfan';
  } else {
    $imageHeader = 'blower';
  }
  if ($blower->_status == 2) {
    $blower->_image = 'images/blank.gif';
    $blower->_isAvaiable = false;
  } else {
    $blower->_isAvaiable = true;
    if ($blower->_speed == 0)
      $image = $imageHeader . 'stop';
    else if ($blower->_status != 0)
      $image = $imageHeader . 'bad';
    else
      $image = $imageHeader . 'good';
    $blower->_image = 'enclosureImages/' . $image . '.png';
  }
  $blower->_tooltip = gprop('coolingunit') . ' ' . ($blowerIndex + 1) . ' ' . gprop('blower')
    . '<br>' . gprop('status') . ': ' . $blower->_statusStr
    . '<br>' . gprop('currentspeed') . ': ' . $blower->_speed . ' rpm';
  return $blower;
}

function getPSUInfo($enclInfo, $psuIndex)
{
  $psu = $enclInfo->_psus[$psuIndex];

  if ($psu->_status == 0x20) {
    $psu->_image = "images/blank.gif";
    if ($enclInfo->mpvs_EnclosureType == ENC_TYPE_J300S ||
      $enclInfo->mpvs_EnclosureType == ENC_TYPE_E3X0_2U12BAY) {
      if ($psuIndex == 0)
        $psu->_image = "enclosureImages/eClass/J300dummyPSleft.gif";
      else if ($psuIndex == 1)
        $psu->_image = "enclosureImages/eClass/J300dummyPSright.gif";
    } else {
      $psu->_image = "enclosureImages/psbad.png";
    }

  } else if ($psu->_status != 0 && $psu->_status != 0x21) {
    $psu->_image = "enclosureImages/psbad.png";
  } else {
    $psu->_image = "enclosureImages/psgood.png";
  }
  $psu->_tooltip = gprop('powersupplyunit') . ' ' . ($psuIndex + 1)
    . '<br>' . gprop('status') . ': ' . $psu->_statusStr;
  return $psu;
}
/*
function getBatteryInfo($enclInfo, $batteryIndex)
{
  $battery = $enclInfo->_batteries[$batteryIndex];
  if ($enclInfo->_id != 0) {
    $battery->_image = 'images/blank.gif';
  } else {
    if ($battery->_state == 3)
      $battery->_image = 'enclosureImages/batterybad.gif';
    else
      $battery->_image = 'enclosureImages/batterygood.gif';
  }

  if (($enclInfo->mpvs_CtrlType == CTRL_J300)
    && ($enclInfo->_id == 0)
    || $enclInfo->mpvs_EnclosureType == ENC_TYPE_E3X0_2U12BAY
    || $enclInfo->mpvs_EnclosureType == ENC_TYPE_E3X0_3U16BAY) {
    $batteryid = $battery->rpbi_u16BatteryId;
  } else {
    $batteryid = $batteryIndex;
  }
  $battery->_tooltip = gprop('battery') . ' ' . ($batteryid + 1)
    . '<br>' . gprop('status') . ': ' . $battery->mbi_BatteryState
    . '<br>' . gprop('voltage') . ': ' . $battery->mbi_BatteryVoltage . 'mV'
    . '<br>' . gprop('temperature') . ': ' . getTemperatureString($battery->mbi_BatteryTemperature)
    . '<br>' . gprop('remainingcapacity') . ': ' . $battery->mbi_RemainingCapacity . '%';
  return $battery;

}
 */

$server = getServer();
$subsystem = $server->getSubsystem(1);
$controllers = $subsystem->getControllers();
$topology = $subsystem->getSASTopology();
// $imgpath = "http://".$_SERVER['HTTP_HOST'].":".$_SERVER['SERVER_PORT']."/";
$imgpath = "";
$ctrlImg = null;
$supportfru = $subsystem->supportfru($_SESSION['ecid']);
$encls = $subsystem->getEnclosures($_SESSION['ecid']);
$enclrange = $subsystem->getEnclosuresTemptRange($_SESSION['ecid']);
if (count($encls) != 0) {
  if ($encls[0]->mpvs_EnclosureType == ENC_TYPE_J300S || $encls[0]->mpvs_EnclosureType == ENC_TYPE_E3X0_2U12BAY || $encls[0]->mpvs_EnclosureType == ENC_TYPE_E3X0_3U16BAY
    || _isCClassJbod($encls[0]->mpvs_CtrlType)) {
    $enclSettings = $subsystem->getEnclosureSettings($_SESSION['ecid']);
  }
}

/* Live sensor refresh for the back-view hover: return {domId: tooltipHtml} JSON
 * built from the SAME getEnclosureInfo() the page renders, so the page's poller
 * can refresh each fan/temp tooltip in real time without a reload. Reuses the
 * page's own tooltip builders - no duplicated format - and a fresh request
 * re-reads getEnclosures() above, so the values are current. */
if (isset($_GET['live'])) {
  header('Content-Type: application/json');
  header('Cache-Control: no-store');
  $tips = array();
  if (count($encls) != 0) {
    $e = getEnclosureInfo($_SESSION['ecid']);
    foreach (array('_fans' => 'fan', '_blowers' => 'blower', '_temps' => 'temp', '_psus' => 'psu') as $arr => $pfx) {
      if (!isset($e->$arr)) continue;
      foreach ($e->$arr as $i => $o) {
        if (isset($o->_isAvaiable) && !$o->_isAvaiable) continue;
        if (isset($o->_tooltip)) $tips[$pfx . $i] = $o->_tooltip;
      }
    }
  }
  echo json_encode($tips);
  exit;
}

if (isset($_GET['locateEncl'])) {
  $subsystem->locateEnclosure($_SESSION['ecid']);
  header('Refresh: 60;url=encl.php?ssid=' . $_SESSION['ssid'] . 'ecid=' . $_SESSION['ecid'] . '&tab=0');
}
if (isset($_GET['locateCtrl'])) {
  if ($supportfru->supportCtrl) {
    $subsystem->locateFRU($_SESSION['ecid'], FRU_TYPE_MB, $_GET['locateCtrl']);
    header('Refresh: 60;url=encl.php?ssid=' . $_SESSION['ssid'] . 'ecid=' . $_SESSION['ecid'] . '&tab=0');
  } else
    header('Refresh: 0;url=encl.php?ssid=' . $_SESSION['ssid'] . 'ecid=' . $_SESSION['ecid'] . '&tab=0' . '&note=' . gresult('notsupportctrl'));
}
if (isset($_GET['locatePSU'])) {
  if ($supportfru->supportPSU) {
    $subsystem->locateFRU($_SESSION['ecid'], FRU_TYPE_PSU, $_GET['locatePSU']);
    header('Refresh: 60;url=encl.php?ssid=' . $_SESSION['ssid'] . 'ecid=' . $_SESSION['ecid'] . '&tab=0');
  } else
    header('Refresh: 0;url=encl.php?ssid=' . $_SESSION['ssid'] . 'ecid=' . $_SESSION['ecid'] . '&tab=0' . '&note=' . gresult('notsupportpsu'));
}
if (isset($_GET['locateCU'])) {
  if ($supportfru->supportCU) {
    $subsystem->locateFRU($_SESSION['ecid'], FRU_TYPE_FAN, $_GET['locateCU']);
    header('Refresh: 60;url=encl.php?ssid=' . $_SESSION['ssid'] . 'ecid=' . $_SESSION['ecid'] . '&tab=0');
  } else
    header('Refresh: 0;url=encl.php?ssid=' . $_SESSION['ssid'] . 'ecid=' . $_SESSION['ecid'] . '&tab=0' . '&note=' . gresult('notsupportcu'));
}
$note = isset($_GET['note']) ? $_GET['note'] : null;
if (count($encls) == 0)
  $note = gresult('nosep');

function showEnclImage($enclInfo, $s)
{
  table_head_fold(gprop('enclosurebackview'), 'BV', null, null, $s);
  echo getEnclImage($enclInfo);
  $beforclick = gprop('locateleavel');
  $clickwait = '';
  if (isset($_GET['locateCtrl']) || isset($_GET['locateEncl']) || isset($_GET['locatePSU']) || isset($_GET['locateCU'])) {
    if (isset($_GET['locateCtrl']))
      $clickwait = gwaitmsg('locatectrl');
    if (isset($_GET['locateEncl']))
      $clickwait = gwaitmsg('locateencl');
    if (isset($_GET['locatePSU']))
      $clickwait = gwaitmsg('locatepsu');
    if (isset($_GET['locateCU']))
      $clickwait = gwaitmsg('locatecu');
    echo '<tr class=tableImageBackground><td class=text2 align=center><label id=locatemsg>' . $clickwait . '</label></td></tr>';
  } else {
    /*
    if(!(_isCClassCtrl($enclInfo->mpvs_CtrlType) || _isCClassJbod($enclInfo->mpvs_CtrlType))) {
      echo '<tr class=tableImageBackground><td class=text2 align=center><label id=labWarn>'.$beforclick.'</label></td></tr>';
    }
     */
  }

  table_foot_fold();
}

function getEnclImage($enclInfo)
{
  // $imgpath = "http://".$_SERVER['HTTP_HOST'].":".$_SERVER['SERVER_PORT']."/";
  $imgpath = "";
  $enclImage = '';
  switch ($enclInfo->mpvs_EnclosureType) {
    case 0x50: // ENC_TYPE_VESSAPP_2U6BAY:
      $i = count($enclInfo->_psus);
      if ($i == 1) {
        $enclImage = '
              <tr><td>
                <table class="tableImageBackground" align="center" background="enclosureImages/enclosureBackView.png" border="0"cellpadding="0" cellspacing="10" >
                          <tr>
                    <td valign="top" width="640" height="250" class="imageEnc">
                            <div style="margin-left:550px;margin-top:-8px;">
                            <img id=blower0 style="height: 56px; position: absolute; left: 24px; top: 25px; " src="' . $imgpath . 'images/blank.gif">
                            <img style="height: 56px; position: absolute; left: 24px; top: 75px;" id=blower1 src="' . $imgpath . 'images/blank.gif">
                            <img style="height: 56px; position: absolute; left: 24px; top: 150px; " id=temp0 src="' . $imgpath . 'enclosureImages/tmpgood.png"></div>
                            <div style="margin-left:45px; margin-top:50px;">
                            <img id=psu0 src="' . $imgpath . 'images/blank.gif"></div>
                            </td>
                          </tr><tr class="tableImageBackground"><td align="center" class="text2">Mouse over an icon to show information.</td></tr>
                        </table>
                      </td></tr>';
      } else if ($i == 3) {
        $enclImage = '
              <tr><td>
                <table class="tableImageBackground" align="center" background="enclosureImages/enclosureBackView_triple.png" border="0"cellpadding="0" cellspacing="10" >
                          <tr>
                    <td valign="top" width="640" height="250" class="imageEnc">
                            <div style="margin-left:550px;margin-top:-8px;">
                            <img id=blower0 style="height: 56px; position: absolute; left: 46px; top: 33px;" src="' . $imgpath . 'images/blank.gif">
                            <img id=blower1 style="height: 56px; position: absolute; left: 46px; top: 84px;"  src="' . $imgpath . 'images/blank.gif">
                            <img id=temp0 style="height: 56px; position: absolute; left: 46px; top: 176px;"  src="' . $imgpath . 'enclosureImages/tmpgood.png"></div>
                            <div style="margin-top:10px;">
                            <img id=psu2 style="position:absolute; left:35px; top:82px;" src="' . $imgpath . 'enclosureImages/psgood.png">
                            <img id=psu1 style="position:absolute; left:35px; top:142px;" src="' . $imgpath . 'enclosureImages/psgood.png">
                            <img id=psu0 style="position:absolute; left:35px; top:202px;" src="' . $imgpath . 'enclosureImages/psgood.png"></div>
                            </td>
                          </tr><tr class="tableImageBackground"><td align="center" class="text2">Mouse over an icon to show information.</td></tr>
                        </table>
                      </td></tr>';
      }
      break;
    case 0x55: // ENC_TYPE_VESSAPP_2U4BAY:
      $i = count($enclInfo->_psus);
      if ($i == 1) {
        $enclImage = '
              <tr><td>
                <table class="tableImageBackground" align="center" background="enclosureImages/enclosureBackView_2U8.png" border="0"cellpadding="0" cellspacing="10" >
                          <tr>
                    <td valign="top" width="640" height="250" class="imageEnc">
                            <div style="margin-left:550px;margin-top:-8px;">
                            <img id=blower0 style="height: 56px; position: absolute; left: 24px; top: 175px; " src="' . $imgpath . 'images/blank.gif">
                            <img style="height: 56px; position: absolute; left: 24px; top: 100px; " id=temp0 src="' . $imgpath . 'enclosureImages/tmpgood.png"></div>
                            <div style="margin-left:45px; margin-top:60px;">
                            <img id=psu0 src="' . $imgpath . 'images/blank.gif"></div>
                            </td>
                          </tr><tr class="tableImageBackground"><td align="center" class="text2">Mouse over an icon to show information.</td></tr>
                        </table>
                      </td></tr>';
      } else if ($i == 3) {
        $enclImage = '
              <tr><td>
                <table class="tableImageBackground" align="center" background="enclosureImages/enclosureBackView_triple.png" border="0"cellpadding="0" cellspacing="10" >
                          <tr>
                    <td valign="top" width="640" height="250" class="imageEnc">
                            <div style="margin-left:550px;margin-top:-8px;">
                            <img id=blower0 style="height: 56px; position: absolute; left: 46px; top: 33px;" src="' . $imgpath . 'images/blank.gif">
                            <img id=blower1 style="height: 56px; position: absolute; left: 46px; top: 84px;"  src="' . $imgpath . 'images/blank.gif">
                            <img id=temp0 style="height: 56px; position: absolute; left: 46px; top: 176px;"  src="' . $imgpath . 'enclosureImages/tmpgood.png"></div>
                            <div style="margin-top:10px;">
                            <img id=psu2 style="position:absolute; left:35px; top:82px;" src="' . $imgpath . 'enclosureImages/psgood.png">
                            <img id=psu1 style="position:absolute; left:35px; top:142px;" src="' . $imgpath . 'enclosureImages/psgood.png">
                            <img id=psu0 style="position:absolute; left:35px; top:202px;" src="' . $imgpath . 'enclosureImages/psgood.png"></div>
                            </td>
                          </tr><tr class="tableImageBackground"><td align="center" class="text2">Mouse over an icon to show information.</td></tr>
                        </table>
                      </td></tr>';
      }
      break;
    case 0x54: // ENC_TYPE_VESSAPP_2U8BAY:
      $i = count($enclInfo->_psus);
      if ($i == 1) {
        $enclImage = '
              <tr><td>
                <table class="tableImageBackground" align="center" background="enclosureImages/enclosureBackView_2U8.png" border="0"cellpadding="0" cellspacing="10" >
                          <tr>
                    <td valign="top" width="640" height="250" class="imageEnc">
                            <div style="margin-left:550px;margin-top:-8px;">
                            <img id=blower0 style="height: 56px; position: absolute; left: 24px; top: 50px; " src="' . $imgpath . 'images/blank.gif">
                            <img id=blower1 style="height: 56px; position: absolute; left: 24px; top: 150px; " src="' . $imgpath . 'images/blank.gif">
                            <img id=blower2 style="height: 56px; position: absolute; left: 24px; top: 210px; " src="' . $imgpath . 'images/blank.gif">
                            <img style="height: 56px; position: absolute; left: 24px; top: 100px; " id=temp0 src="' . $imgpath . 'enclosureImages/tmpgood.png"></div>
                            <div style="margin-left:45px; margin-top:60px;">
                            <img id=psu0 src="' . $imgpath . 'images/blank.gif"></div>
                            </td>
                          </tr><tr class="tableImageBackground"><td align="center" class="text2">Mouse over an icon to show information.</td></tr>
                        </table>
                      </td></tr>';
      } else if ($i == 2) {
        $enclImage = '
              <tr><td>
                <table class="tableImageBackground" align="center" background="enclosureImages/enclosureBackView_2U8_RPSU.png" border="0"cellpadding="0" cellspacing="10" >
                          <tr>
                    <td valign="top" width="640" height="250" class="imageEnc">
                            <div style="margin-left:550px;margin-top:-8px;">
                            <img id=blower0 style="height: 56px; position: absolute; left: 24px; top: 50px; " src="' . $imgpath . 'images/blank.gif">
                            <img id=blower1 style="height: 56px; position: absolute; left: 24px; top: 150px; " src="' . $imgpath . 'images/blank.gif">
                            <img id=blower2 style="height: 56px; position: absolute; left: 24px; top: 200px; " src="' . $imgpath . 'images/blank.gif">
                            <img style="height: 56px; position: absolute; left: 24px; top: 100px; " id=temp0 src="' . $imgpath . 'enclosureImages/tmpgood.png"></div>
                            <div style="margin-top:10px;">
                            <img id=psu1 style="position:absolute; left:40px; top:112px;" src="' . $imgpath . 'enclosureImages/psgood.png">
                            <img id=psu0 style="position:absolute; left:40px; top:162px;" src="' . $imgpath . 'enclosureImages/psgood.png"></div>
                            </td>
                          </tr><tr class="tableImageBackground"><td align="center" class="text2">Mouse over an icon to show information.</td></tr>
                        </table>
                      </td></tr>';
      }
      break;
    case 0x57: // ENC_TYPE_VESSAPP_1U4BAY:
      $i = count($enclInfo->_psus);
      if ($i == 2) {
        $enclImage = '
              <tr><td>
                <table class="tableImageBackground" align="center" background="enclosureImages/enclosureBackView_1U4.png" border="0"cellpadding="0" cellspacing="10" >
                          <tr>
                    <td valign="top" width="640" height="299" class="imageEnc">
                            <div style="margin-left:550px;margin-top:-8px;">
                            <img id=temp0 style="height: 56px; position: absolute; left: 54px; top: 70px;"  src="' . $imgpath . 'enclosureImages/tmpgood.png"></div>
                            <div style="margin-top:10px;">
                            <img id=psu1 style="position:absolute; left:-32px; top:20px;" src="' . $imgpath . 'enclosureImages/psgood.png">
                            <img id=psu0 style="position:absolute; left:15px; top:20px;" src="' . $imgpath . 'enclosureImages/psgood.png"></div>
                            </td>
                          </tr><tr class="tableImageBackground"><td align="center" class="text2">Mouse over an icon to show information.</td></tr>
                        </table>
                      </td></tr>';
      }
      if ($enclInfo->mpvs_CtrlType == 0x80B && $i == 1) // ENC_TYPE_VESSAPP_1U4BAY_A3120:
      {
        $enclImage = '
              <tr><td>
                <table class="tableImageBackground" align="center" background="enclosureImages/enclosureBackView_1U4_A3120.png" border="0"cellpadding="0" cellspacing="10" >
                  <tr>
                    <td valign="top" width="540" height="145" class="imageEnc">
                      <div style="margin-left:550px;margin-top:-8px;">
                        <img id=fan0 style="height: 56px; position: absolute; left: -50px; top: 0px; " src="' . $imgpath . 'images/blank.gif">
                        <img id=blower0 style="height: 56px; position: absolute; left: -50px; top: 96px; " src="' . $imgpath . 'images/blank.gif">
                        <img id=blower1 style="height: 56px; position: absolute; left: -50px; top: 142px; " src="' . $imgpath . 'images/blank.gif">
                        <img style="height: 56px; position: absolute; left: -50px; top: 48px; " id=temp0 src="' . $imgpath . 'enclosureImages/tmpgood.png">
                      </div>
                      <div style="margin-left:-20px; margin-top:10px;">
                        <img id=psu0 src="' . $imgpath . 'images/blank.gif">
                      </div>
                    </td>
                  </tr>
                  <tr class="tableImageBackground"><td align="center" class="text2">Mouse over an icon to show information.</td></tr>
                </table>
              </td></tr>';
      }
      break;
    case 0x52: // ENC_TYPE_VESS_3U16BAY:
      $i = count($enclInfo->_psus);
      if ($i == 1) {
        $enclImage = '
              <tr><td>
                <table class="tableImageBackground" align="center" background="enclosureImages/enclosureBackView_3U16_signal.png" border="0"cellpadding="0" cellspacing="10" >
                          <tr>
                    <td valign="top" width="630" height="210" class="imageEnc">
                            <div style="margin-left:550px;margin-top:-8px;">
                            <img style="margin-top:58px; margin-left:18px; height:56px" id=temp0 src="' . $imgpath . 'enclosureImages/tmpgood.png">
                            <img id=blower0 style="margin-top:16px; margin-left:18px; height:56px"src="' . $imgpath . 'images/blank.gif"></div>
                            <div style="margin-top:10px;margin-left:17px">
                            <img id=psu0 src="' . $imgpath . 'images/blank.gif"></div>
                            </td>
                          </tr><tr class="tableImageBackground"><td align="center" class="text2">Mouse over an icon to show information.</td></tr>
                        </table>
                      </td></tr>';
      } else if ($i == 3) {
        $enclImage = '
              <tr><td>
                <table class="tableImageBackground" align="center" background="enclosureImages/enclosureBackView3U16_triple.png" border="0"cellpadding="0" cellspacing="10" >
                          <tr>
                    <td valign="top" width="630" height="210" class="imageEnc">
                            <div style="margin-left:550px;margin-top:-8px;">
                             <img style="margin-top:60px; margin-left:18px; height:56px" id=temp0 src="' . $imgpath . 'enclosureImages/tmpgood.png">
                            <img id=blower0 style="margin-top:16px; margin-left:18px; height:56px"src="' . $imgpath . 'images/blank.gif"></div>
                            <div style="margin-top:10px;">
                            <img id=psu2 style="position:absolute; left:15px; top:15px;" src="' . $imgpath . 'images/blank.gif">
                            <img id=psu1 style="position:absolute; left:15px; top:75px;" src="' . $imgpath . 'images/blank.gif">
                            <img id=psu0 style="position:absolute; left:15px; top:135px;" src="' . $imgpath . 'images/blank.gif"></div>
                            </td>
                          </tr><tr class="tableImageBackground"><td align="center" class="text2">Mouse over an icon to show information.</td></tr>
                        </table>
                      </td></tr>';
      }
      break;
  }

  return $enclImage;
}
function showEnclFold($enclInfo, $s)
{
  table_head_fold(gprop('enclosureinfo'), 'ENCL', null, null, $s);
  table_row(gprop('enclosureid'), $_SESSION['ecid'] + 1);
  table_row(gprop('enclosuretype'), $enclInfo->_EnclosureTypeString);
  /*
  if (!strpos($enclInfo->_EnclosureTypeString, '2U8BAY') && $enclInfo->mpvs_EnclosureType != ENC_TYPE_VR1000Y_1U4BAY_S) {
    table_row(gprop('sepfirmwareversion'),$enclInfo->mpvs_SepFwVerSion);
  }
  if (!(_isCClassCtrl($enclInfo->mpvs_CtrlType) || _iscclassjbod($enclInfo->mpvs_CtrlType))) {
    table_row(gprop('enclwarningtemp'), getTemperatureString($enclInfo->enclWarningTemp));
    table_row(gprop('enclcriticaltemp'), getTemperatureString($enclInfo->enclCriticalTemp));
  }
   */
  table_row(gprop('ctrlwarningtemp'), getTemperatureString($enclInfo->ctrlWarningTemp));
  table_row(gprop('ctrlcriticaltemp'), getTemperatureString($enclInfo->ctrlCriticalTemp));
  table_row(gprop('powerswitch'), getEnableString($enclInfo->es_u8PowerSwitch));
  table_row(gprop('maxnumofcontrollers'), $enclInfo->maxNumOfControllers);
  table_row(gprop('maxnumofphydrvslots'), $enclInfo->maxNumOfPhyDrvSlots);
  table_row(gprop('maxnumoffans'), $enclInfo->maxNumOfFans);
  table_row(gprop('maxnumofblowers'), $enclInfo->maxNumOfBlowers);
  table_row(gprop('maxnumoftempsensors'), $enclInfo->maxNumOfTempSensor);
  table_row(gprop('maxnumofpsus'), $enclInfo->maxNumPSU);
  // table_row(gprop('maxnumofbatteries'),$enclInfo->maxNumOfBatteries);
  table_row(gprop('maxnumofvoltagesensors'), $enclInfo->maxNumOfVoltageSensors);
  /*
  if (isCClassCtrl($enclInfo->mpvs_CtrlType) || iscclassjbod($enclInfo->mpvs_CtrlType)) {
    table_row(gprop('psutype'), $enclInfo->mpvs_PSUType);
  }
   */

  table_foot_fold();
}

function showPSUFold($enclInfo, $s)
{
  $psus = $enclInfo->_psus;

  table_head_fold(gprop('powersupplyunits'), 'PSU', null, null, $s);
  $columns = array(gprop('deviceid'), gprop('status'));
  $widths = array(30, 70);
  table_header_cols($columns, $widths);
  for ($i = 0; $i < count($psus); $i++) {
    $columns = array(gprop('powersupply') . ' ' . $psus[$i]->_psuid, $psus[$i]->_statusStr);
    table_row_cols($columns);
  }
  table_foot_fold();
}

function showFanFold($enclInfo, $s)
{
  $fans = $enclInfo->_fans;

  table_head_fold(gprop('fans'), 'FANS', null, null, $s);
  $columns = array(gprop('deviceid'), gprop('location'), gprop('status'), gprop('healthythreshold'), gprop('currentspeed'));
  $widths = array(20, 20, 20, 20, 20);
  table_header_cols($columns, $widths);
  for ($i = 0; $i < (count($fans)); $i++) {
    if ($fans[$i]->_location == 'PSU') {
      $fans[$i]->_location = gprop('powersupply');
    }
    $columns = array(
      gprop('fan') . ' ' . ($fans[$i]->_fanid),
      $fans[$i]->_location . ' ' . $fans[$i]->_psuid, $fans[$i]->_statusStr,
      '> ' . $fans[$i]->_speedThreshold . ' RPM',
      $fans[$i]->_speed . ' RPM'
    );
    table_row_cols($columns);
  }
  table_foot_fold();
}

function showBlowerFold($enclInfo, $s)
{
  $blowers = $enclInfo->_blowers;

  table_head_fold(gprop('blowers'), 'BLOW', null, null, $s);
  $columns = array(gprop('deviceid'), gprop('location'), gprop('status'), gprop('healthythreshold'), gprop('currentspeed'));
  $widths = array(20, 20, 20, 20, 20);
  table_header_cols($columns, $widths);
  for ($i = 0; $i < (count($blowers)); $i++) {
    $columns = array(
      gprop('fan') . ' ' . ($blowers[$i]->_blowerid),
      gprop('coolingunit') . ' ' . $blowers[$i]->_cuid, $blowers[$i]->_statusStr,
      '> ' . $blowers[$i]->_speedThreshold . ' RPM',
      $blowers[$i]->_speed . ' RPM'
    );
    table_row_cols($columns);
  }
  table_foot_fold();
}

function showTempFold($enclInfo, $s)
{
  $temps = $enclInfo->_temps;

  table_head_fold(gprop('temperaturesensors'), 'TEMP', null, null, $s);
  $columns = array(gprop('deviceid'), gprop('location'), gprop('healthythreshold'), gprop('currenttemperature'));
  $widths = array(25, 25, 25, 25);
  table_header_cols($columns, $widths);
      
  // temperature
  $temps = $enclInfo->_temps;
  for ($i = 0; $i < count($temps); $i++) {
    $temp = $temps[$i];
    if (!$temp->_isAvaiable) {
      $columns = array(
        gprop('sensor') . ' ' . ($i + 1),
        gprop('sensornotpresent'),
        gprop('na'),
        gprop('na')
      );
      table_row_cols($columns, 0, 0, true);
      continue;
    }
    if ($temp->_status == 'critical') {
      $color = 'red';
    } else if ($temp->_status == 'warning') {
      $color = 'orange';
    } else {
      $color = '#262626';
    }
    $columns = array(
      gprop('sensor') . ' ' . ($i + 1),
      $temp->_locationString,
      '<' . getTemperatureString($temp->_healthtemp),
      array(getTemperatureString($temp->_temp), null, $color)
    );
    table_row_cols($columns, 0, 0, true);
  }
  table_foot_fold();
}

function showVoltageFold($enclInfo, $s)
{
  $volts = $enclInfo->_volts;
  table_head_fold(gprop('voltagesensors'), 'VOLT', null, null, $s);
  $columns = array(gprop('deviceid'), gprop('location'), gprop('sensortype'), gprop('healthythreshold'), gprop('currentvoltage'));
  $widths = array(20, 20, 20, 20, 20);
  table_header_cols($columns, $widths);
  for ($i = 0; $i < count($volts); $i++) {
    $volt = $volts[$i];
    $voltLocStr = str_replace('Controller', gprop('controller'), $volt->_location);
    $voltLocStr = str_replace('PSU', gprop('powersupply'), $voltLocStr);
    $columns = array(
      gprop('sensor') . ' ' . ($i + 1),
      $voltLocStr,
      $volt->_sensorType,
      $volt->_healthyThreshold,
      $volt->_volt
    );
    table_row_cols($columns);
  }
  table_foot_fold();
}
?>
<html>

<head>
<?php
page_head();
?>
</head>

<body>
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center
    bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('enclosureinfo'), 'encl', $note);
table_tab_head();
$s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner');
$s1 = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => '');
if (empty($encls)) {
  $tabTitle = tabTitleEncl($subsystem, null);
  table_tab($tabTitle);
  table_head_fold(gprop('enclosureinfo'), 'ENCL', null, null, $s);
  table_row_empty(gresult('notaccessiblemsg'));
  table_foot_fold();
  table_tab_foot();
} else {
  $enclInfor = getEnclosureInfo($_SESSION['ecid']);
  $tabTitle = tabTitleEncl($subsystem, $encls[0]);
  table_tab($tabTitle);
  showEnclImage($enclInfor, $s1);
  table_tab_foot();

  showEnclFold($enclInfor, $s);
  showPSUFold($enclInfor, $s);
  if (!(_isCClassCtrl($enclInfor->mpvs_CtrlType) || _iscclassjbod($enclInfor->mpvs_CtrlType))) {
    if (count($enclInfor->_fans)) {
      showFanFold($enclInfor, $s);
    }
  }
  showBlowerFold($enclInfor, $s);
  showTempFold($enclInfor, $s);
  showVoltageFold($enclInfor, $s);
}
page_foot();
?>
  </table>
</form>
<script>
    
<?php

if (substr(CClassCtrlType($enclInfor->mpvs_CtrlType), -2, 1) == 1) {
  ?>      
    document.getElementById("blower0").parentNode.style.marginLeft = "230px";
<?php

}
?>
  function moveOut(){
    var labelid= document.getElementById("labWarn");
    if ( typeof(labelid)!="undefined"&&labelid != null )
      labelid.innerHTML = "<?= gprop('locateleavel') ?>";
  }

  function clickPSUInfo(psuid)
	{
		var PSUImgSrc;
		var indexPSUImgSrc=null;
		var indexVPDImgSrc=null;
		if(psuid==1){
			PSUImgSrc= document.getElementById("psu0").src;
			indexPSUImgSrc=PSUImgSrc.indexOf("blank");
		} else if(psuid==2){
			PSUImgSrc= document.getElementById("psu1").src;
			indexPSUImgSrc=PSUImgSrc.indexOf("blank");
		} else if(psuid==3){
			PSUImgSrc= document.getElementById("psuvpd0").src;
			indexVPDImgSrc=PSUImgSrc.indexOf("blank");
		} else if(psuid==4){
			PSUImgSrc= document.getElementById("psuvpd1").src;	
			indexVPDImgSrc=PSUImgSrc.indexOf("blank");
		}

		if(indexPSUImgSrc!=null && indexPSUImgSrc.valueOf()!="-1")
      return false;
		if(indexVPDImgSrc!=null && indexVPDImgSrc.valueOf()=="-1")
      return false;
    var labelid= document.getElementById("labWarn");
		if ( typeof(labelid)!="undefined"&&labelid != null )
			labelid.innerHTML = "<?= gprop('locatepsu') ?>";
  }

  function clickCUInfo(cuid)
	{
		var CUImgSrc;
		if(cuid==1)
      CUImgSrc= document.getElementById("blower1").src;
		else
		  CUImgSrc= document.getElementById("blower0").src;
		var indexCUImgSrc=CUImgSrc.indexOf("blank");
		if(indexCUImgSrc.valueOf()!="-1")
      return false;
		var labelid= document.getElementById("labWarn");
		if ( typeof(labelid)!="undefined"&&labelid != null )
			labelid.innerHTML = "<?= gprop('locatecu') ?>";
  }

  function clickCtrlInfo(ctrlid)
	{
	
<?php
    //locate controller function not support jbod
if (!$enclInfor->isJBOD) {
  ?>
		  var ctrlImgSrc;
		  if(ctrlid==1)
		    ctrlImgSrc= document.getElementById("ctrl1").src;
      else
		    ctrlImgSrc= document.getElementById("ctrl0").src;
		  var indexCtrlImgSrc=ctrlImgSrc.indexOf("blank");
		  var indexCtrlImgSrcShade=ctrlImgSrc.indexOf("Shade");
		  if(indexCtrlImgSrc.valueOf()!="-1" || indexCtrlImgSrcShade.valueOf()!="-1")
			  return false;

		var labelid= document.getElementById("labWarn");
		if ( typeof(labelid)!="undefined"&&labelid != null )
			labelid.innerHTML = "<?= gprop('locatectrl') ?>";
<?php

}
?>
  }     

  function clickEnclInfo()
  {
    var labelid= document.getElementById("labWarn");
    if ( typeof(labelid)!="undefined"&&labelid != null )
      labelid.innerHTML = "<?= gprop('locateenclosure') ?>";
  } 

	function clickCtrlAction(ctrlid)
	{
<?php
    //locate controller function not support jbod
if (!$enclInfor->isJBOD) {
  ?>
      var ctrlImgSrc;
      if(ctrlid==1)
        ctrlImgSrc= document.getElementById("ctrl1").src;
      else
        ctrlImgSrc= document.getElementById("ctrl0").src;
      var indexCtrlImgSrc=ctrlImgSrc.indexOf("blank");
      var indexCtrlImgSrcShade=ctrlImgSrc.indexOf("Shade");
      if(indexCtrlImgSrc.valueOf()!="-1" || indexCtrlImgSrcShade.valueOf()!="-1")		
        return false;
		
		  location.href=location.href+"&locateCtrl="+ctrlid;
<?php

}
?>
	}

	function clickPSUAction(psuid)
	{
		var PSUImgSrc;
		var indexPSUImgSrc=null;
		var indexVPDImgSrc=null;
		if(psuid==1){
			PSUImgSrc= document.getElementById("psu0").src;
			indexPSUImgSrc=PSUImgSrc.indexOf("blank");
		} else if(psuid==2){
			PSUImgSrc= document.getElementById("psu1").src;
			indexPSUImgSrc=PSUImgSrc.indexOf("blank");
		} else if(psuid==3){
			PSUImgSrc= document.getElementById("psuvpd0").src;
			indexVPDImgSrc=PSUImgSrc.indexOf("blank");
		} else if(psuid==4){
			PSUImgSrc= document.getElementById("psuvpd1").src;	
			indexVPDImgSrc=PSUImgSrc.indexOf("blank");
		}

		if(indexPSUImgSrc!=null && indexPSUImgSrc.valueOf()!="-1")
			 return false;
		if(indexVPDImgSrc!=null && indexVPDImgSrc.valueOf()=="-1")
			 return false;
		if(psuid==1 || psuid==3)	
			psuid=0;
		else
			psuid=1;
		location.href=location.href+"&locatePSU="+psuid;
	}

	function clickCUAction(cuid)
	{
		var CUImgSrc;
		if(cuid==1)
      CUImgSrc= document.getElementById("blower1").src;
		else
		  CUImgSrc= document.getElementById("blower0").src;
		var indexCUImgSrc=CUImgSrc.indexOf("blank");
		
		if(indexCUImgSrc.valueOf()!="-1")
      return false;
		
		location.href=location.href+"&locateCU="+cuid;
	}

  function showTooltip(id,str)
  {
    o=ylib_getObj(id);
    if(!window.__tip)window.__tip={};
    window.__tip[id]=str;                  /* seed with the page-load tooltip */
    ylib_addEvt(o,'mouseover',function(){window.__hoverId=id;return overlib(window.__tip[id],HAUTO);});
    ylib_addEvt(o,'mouseout',function(){window.__hoverId=null;return nd();});
  }

  /* Live sensor hover: poll encl.php?live for current tooltips so the next hover
     shows fresh values, and if an icon is hovered right now, re-fire overlib so
     the open popup updates in real time (no page reload, no separate bar). */
  function __pollTips()
  {
    try{
      var x=new XMLHttpRequest();
      x.open('GET','encl.php?live=1&_='+(new Date()).getTime(),true);
      x.onreadystatechange=function(){
        if(x.readyState!=4||x.status!=200)return;
        var d;try{d=JSON.parse(x.responseText);}catch(e){return;}
        if(!window.__tip)window.__tip={};
        for(var k in d)window.__tip[k]=d[k];
        if(window.__hoverId&&d[window.__hoverId])overlib(window.__tip[window.__hoverId],HAUTO);
      };
      x.send();
    }catch(e){}
  }
  setInterval(__pollTips,3000);

<?php
function changeImage($id, $path)
{
  echo '
      changeImage("' . $id . '","' . $path . '");';
}

function showTooltip($id, $str)
{
  echo '
      showTooltip("' . $id . '","' . $str . '");';
}

function changePSUIcon($enclInfo)
{
  for ($i = 0; $i < count($enclInfo->_psus); $i++) {
    if (!isset($enclInfo->_psus[$i]))
      continue;
    $psu = $enclInfo->_psus[$i];
    if ($psu->_status == 0x20 && ($enclInfo->mpvs_EnclosureType == ENC_TYPE_J300S ||
      $enclInfo->mpvs_EnclosureType == ENC_TYPE_E3X0_2U12BAY)) {
      $id = 'psuvpd' . $i;
      changeImage($id, $psu->_image);
    } else {
      $id = 'psu' . $i;
      showTooltip($id, $psu->_tooltip);
      changeImage($id, $psu->_image);
    }
  }
}

function changeFanIcon($enclInfo)
{
  for ($i = 0; $i < count($enclInfo->_fans); $i++) {
    if (!isset($enclInfo->_fans[$i]))
      continue;
    $fan = $enclInfo->_fans[$i];
    $id = 'fan' . $i;
    changeImage($id, $fan->_image);
    if (!isset($fan->_isAvaiable)
      || $fan->_isAvaiable)
      showTooltip($id, $fan->_tooltip);
  }
}

function changeBlowerIcon($enclInfo)
{
  if ($enclInfo->mpvs_EnclosureType != ENC_TYPE_VR1000Y_1U4BAY_S) {
    for ($i = 0; $i < count($enclInfo->_blowers); $i += 1) {
      if (!isset($enclInfo->_blowers[$i]))
        continue;
      $blower = $enclInfo->_blowers[$i];
      $id = 'blower' . ($i / 1);
      if (!isset($blower->_isAvaiable)
        || $blower->_isAvaiable)
        showTooltip($id, $blower->_tooltip);
      changeImage($id, $blower->_image);
    }
  } else {
    for ($i = 0; $i < count($enclInfo->_blowers); $i++) {
      if (!isset($enclInfo->_blowers[$i]))
        continue;
      $blower = $enclInfo->_blowers[$i];
      $id = 'blower' . $i;
      if (!isset($blower->_isAvaiable)
        || $blower->_isAvaiable)
        showTooltip($id, $blower->_tooltip);
      changeImage($id, $blower->_image);
    }
  }
}
/*
function changeBatteryIcon($enclInfo)
{
  if ($_SESSION['ecid'] != 0)
    return;
  for ($i = 0; $i < count($enclInfo->_batteries); $i++) {
    if (!isset($enclInfo->_batteries[$i]))
      continue;
    $battery = $enclInfo->_batteries[$i];
    if ($enclInfo->mpvs_CtrlType == CTRL_J300
      && ($enclInfo->_id == 0)
      || $enclInfo->mpvs_EnclosureType == ENC_TYPE_E3X0_2U12BAY
      || $enclInfo->mpvs_EnclosureType == ENC_TYPE_E3X0_3U16BAY) {
      $id = 'battery' . $battery->rpbi_u16BatteryId;
      if (!isset($enclInfo->_ctrls[$battery->rpbi_u16BatteryId])
        || !isset($enclInfo->_ctrls[$battery->rpbi_u16BatteryId]->_image)
        || $enclInfo->_ctrls[$battery->rpbi_u16BatteryId]->_image == 'images/blank.gif') {
        continue;
      }
    } else {
      $id = 'battery' . $i;
    }
    showTooltip($id, $battery->_tooltip);
    changeImage($id, $battery->_image);
  }
}
 */

function changeTempIcon($enclInfo)
{
  for ($i = 0; $i < count($enclInfo->_temps); $i++) {
    if (!isset($enclInfo->_temps[$i]))
      continue;
    $temp = $enclInfo->_temps[$i];
    $id = 'temp' . $i;
    if (!isset($temp->_isAvaiable)
      || $temp->_isAvaiable)
      showTooltip($id, $temp->_tooltip);
    changeImage($id, $temp->_image);
  }
}

function changeCtrlIcon($enclInfo)
{
  for ($i = 0; $i < count($enclInfo->_ctrls); $i++) {
    $ctrl = $enclInfo->_ctrls[$i];
    $id = 'ctrl' . $ctrl->_id;
    if (isset($ctrl->_tooltip))
      showTooltip($id, $ctrl->_tooltip);
    changeImage($id, $ctrl->_image);
  }
}

if (isset($enclInfor)) {

  if ($enclInfor->mpvs_EnclosureType == ENC_TYPE_J300S ||
    $enclInfor->mpvs_EnclosureType == ENC_TYPE_E3X0_2U12BAY ||
    $enclInfor->mpvs_EnclosureType == ENC_TYPE_E3X0_3U16BAY) {
    changeCtrlIcon($enclInfor);
  }
  changePSUIcon($enclInfor);
  changeFanIcon($enclInfor);
  changeBlowerIcon($enclInfor);
  // changeBatteryIcon($enclInfor);
  changeTempIcon($enclInfor);
}

?>
</script>
</body>

</html>
