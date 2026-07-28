<?php

/*******************************************************************************
 * Filename    : front.php
 * Description : islaVista enclosure front view.
 * Created on  : 09/10/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/

function showFrontImage($encls, $physicalDrives, $unplugPDs, $string = null, $s)
{
  global $subsystem;

  table_head_fold(gprop('enclosurefrontview'), 'FV', null, null, $s);

  echo '<tr  class=tableImageBackground><td align=center><div id=divFV><table width="770" align="center" border="0" cellpadding="0" cellspacing="10">';
  $divH = 0;

  foreach ($encls as $eid => $encl) {
    if ($encl->mpvs_EnclosureType == 0x50) {
      $enclImage = "enclosureFrontView.gif";
      echo '
                    <tr><td class="text3image" width="70" align="center">' . gprop('enclosure') . ' ' . ($eid + 1) . '</td><td>
                      <table onselectstart="return false" width="654" height="180" align="center" border="0" cellpadding="0" cellspacing="0">
                        <tr><td id=encl' . $eid . '  valign="top" align="left" class="imagePdydrvs" background="enclosureImages/' . $enclImage . '">
                    <div class="imagespdydrvs">
                        <div class="pdydrvsLeft">
                           <div style="margin-top:70px;">
                             <img id="ledsystem" src="enclosureImages/ledGray.gif" style="margin-left:55px">
                             <img id="ledfan" src="enclosureImages/ledGray.gif" style="margin-left:11px">
                             <img id="ledraid" src="enclosureImages/ledRed.gif" style="margin-left:16px">
                             <img id="ledpd1" src="enclosureImages/ledGray.gif" style="margin-left:12px">
                             <img id="ledpd2" src="enclosureImages/ledGray.gif" style="margin-left:11px">
                             <img id="ledpd3" src="enclosureImages/ledGray.gif" style="margin-left:8px">
                             <img id="ledpd4" src="enclosureImages/ledGray.gif" style="margin-left:10px">
                             <img id="ledpd5" src="enclosureImages/ledGray.gif" style="margin-left:9px">
                             <img id="ledpd6" src="enclosureImages/ledGray.gif" style="margin-left:9px">
                           </div>
                           <div style="margin-top:20px;">
                             <img id="ledpower" src="enclosureImages/ledBlue.gif" style="margin-left:55px">
                             <img id="ledrec" src="enclosureImages/ledRed.gif" style="margin-left:11px">
                             <img id="ledaccess" src="enclosureImages/ledGray.gif" style="margin-left:16px">
                             <img id="lednet1" src="enclosureImages/ledGray.gif" style="margin-left:12px">
                             <img id="lednet2" src="enclosureImages/ledGray.gif" style="margin-left:11px">
                             <img id="ledusb" src="enclosureImages/ledGray.gif" style="margin-left:66px">
                           </div>
                        </div>
                        <div class="pdydrvsRight"><ul>';
      for ($i = 0; $i < 6; $i += 2) {
        for ($j = 0; $j < 2; $j++) {
          echo '
                                    <li id=pdimg' . $encl->_flats[$i + $j] . '>
                                          <img border="0" src="images/blank.gif">
                                    </li>';
        }
      }
      echo '</ul></div>
                   </div></td></tr>
                  </table>
                 </td></tr>';
    } else if ($encl->mpvs_EnclosureType == 0x52) {
      $enclImage = "enclosureFrontView_3U16.gif";
      echo '
                    <tr><td class="text3image" width="70" align="center">' . gprop('enclosure') . ' ' . ($eid + 1) . '</td><td>
                      <table onselectstart="return false" width="654" height="180" align="center" border="0" cellpadding="0" cellspacing="0">
                        <tr><td id=encl' . $eid . '  valign="top" align="left" class="imagePdydrvs" background="enclosureImages/' . $enclImage . '">
                    <div class="imagespdydrvs3u"  style="font-size:0px;">
                        <div class="pdydrvsLeft3u">
                           <div style="margin-left:13px; width:9px; float:left">
                                <img id="ledusb" src="enclosureImages/ledGray.gif" style="margin-top:107px">
                           </div>
                           <div style="margin-left:120px; width:9px; float:left">
                             <img id="ledpd1" src="enclosureImages/ledGray.gif" style="margin-top:34.5px"><img id="actLedPd1" src="enclosureImages/ledGray.gif" style="margin-top:3px">
                             <img id="ledpd5" src="enclosureImages/ledGray.gif" style="margin-top:18px"><img id="actLedPd5" src="enclosureImages/ledGray.gif" style="margin-top:3px">
                             <img id="ledpd9" src="enclosureImages/ledGray.gif" style="margin-top:18px"><img id="actLedPd9" src="enclosureImages/ledGray.gif" style="margin-top:3px">
                             <img id="ledpd13" src="enclosureImages/ledGray.gif" style="margin-top:18px"><img id="actLedPd13" src="enclosureImages/ledGray.gif" style="margin-top:3px">
                           </div>
                           <div style="margin-left:119px; width:9px; float:left">
                             <img id="ledpd2" src="enclosureImages/ledGray.gif" style="margin-top:34.5px"><img id="actLedPd2" src="enclosureImages/ledGray.gif" style="margin-top:3px">
                             <img id="ledpd6" src="enclosureImages/ledGray.gif" style="margin-top:18px"><img id="actLedPd6" src="enclosureImages/ledGray.gif" style="margin-top:3px">
                             <img id="ledpd10" src="enclosureImages/ledGray.gif" style="margin-top:18px"><img id="actLedPd10" src="enclosureImages/ledGray.gif" style="margin-top:3px">
                             <img id="ledpd14" src="enclosureImages/ledGray.gif" style="margin-top:18px"><img id="actLedPd14" src="enclosureImages/ledGray.gif" style="margin-top:3px">
                           </div>
                           <div style="margin-left:119px; width:9px; float:left">
                             <img id="ledpd3" src="enclosureImages/ledGray.gif" style="margin-top:34.5px"><img id="actLedPd3" src="enclosureImages/ledGray.gif" style="margin-top:3px">
                             <img id="ledpd7" src="enclosureImages/ledGray.gif" style="margin-top:18px"><img id="actLedPd7" src="enclosureImages/ledGray.gif" style="margin-top:3px">
                             <img id="ledpd11" src="enclosureImages/ledGray.gif" style="margin-top:18px"><img id="actLedPd11" src="enclosureImages/ledGray.gif" style="margin-top:3px">
                             <img id="ledpd15" src="enclosureImages/ledGray.gif" style="margin-top:18px"><img id="actLedPd15" src="enclosureImages/ledGray.gif" style="margin-top:3px">
                           </div>
                           <div style="margin-left:119px; width:9px; float:left">
                             <img id="ledpd4" src="enclosureImages/ledGray.gif" style="margin-top:34.5px"><img id="actLedPd4" src="enclosureImages/ledGray.gif" style="margin-top:3px">
                             <img id="ledpd8" src="enclosureImages/ledGray.gif" style="margin-top:18px"><img id="actLedPd8" src="enclosureImages/ledGray.gif" style="margin-top:3px">
                             <img id="ledpd12" src="enclosureImages/ledGray.gif" style="margin-top:18px"><img id="actLedPd12" src="enclosureImages/ledGray.gif" style="margin-top:3px">
                             <img id="ledpd16" src="enclosureImages/ledGray.gif" style="margin-top:18px"><img id="actLedPd16" src="enclosureImages/ledGray.gif" style="margin-top:3px">
                           </div>
                           <div style="margin-left:7px; width:9px; float:left">
                                <img id="ledpower" src="enclosureImages/ledBlue.gif" style="margin-top:60px">
                                <img id="ledsystem" src="enclosureImages/ledGray.gif" style="margin-top:4px">
                                <img id="ledraid" src="enclosureImages/ledGray.gif" style="margin-top:4px">
                                <img id="ledaccess" src="enclosureImages/ledGray.gif" style="margin-top:24px">
                                <img id="ledrec" src="enclosureImages/ledGray.gif" style="margin-top:4px">
                                <img id="ledHeartbeat" src="enclosureImages/ledGray.gif" style="margin-top:4px">
                           </div>
                        </div>
                        <div class="pdydrvsRight3u" style="width:515px"><ul>';
      for ($i = 0; $i < 16; $i += 4) {
        for ($j = 0; $j < 4; $j++) {
          echo '
                                    <li id=pdimg' . $encl->_flats[$i + $j] . '>
                                          <img border="0" src="images/blank.gif">
                                    </li>';
        }
      }
      echo '</ul></div>
                   </div></td></tr>
                  </table>
                 </td></tr>';
    } else if ($encl->mpvs_EnclosureType == 0x54) {
      $enclImage = "enclosureFrontView_2U8.png";
      echo '
                    <tr><td class="text3image" width="70" align="center">' . gprop('enclosure') . ' ' . ($eid + 1) . '</td><td>
                      <table onselectstart="return false" width="635" height="190" align="center" border="0" cellpadding="0" cellspacing="0">
                        <tr><td id=encl' . $eid . '  valign="top" align="left" class="imagePdydrvs" background="enclosureImages/' . $enclImage . '">
                    <div class="imagespdydrvs2u" >
                        <div class="pdydrvsup2u8">
                           <div style="margin-top:2px;">
                             <img id="ledpower" src="enclosureImages/ledBlue.gif" style="margin-left:31px">
                             <img id="ledsystem" src="enclosureImages/ledGray.gif" style="margin-left:0px">
                             <img id="ledfan" src="enclosureImages/ledGray.gif" style="margin-left:0px">
                             <img id="ledrec" src="enclosureImages/ledRed.gif" style="margin-left:0px">
                             <img id="lednet1" src="enclosureImages/ledGray.gif" style="margin-left:12px">
                             <img id="lednet2" src="enclosureImages/ledGray.gif" style="margin-left:0px">
                             <img id="ledraid" src="enclosureImages/ledRed.gif" style="margin-left:11px">
                             <img id="ledaccess" src="enclosureImages/ledGray.gif" style="margin-left:1px">
                             <img id="ledpd1" src="enclosureImages/ledGray.gif" style="margin-left:1px">
                             <img id="ledpd2" src="enclosureImages/ledGray.gif" style="margin-left:1px">
                             <img id="ledpd3" src="enclosureImages/ledGray.gif" style="margin-left:0px">
                             <img id="ledpd4" src="enclosureImages/ledGray.gif" style="margin-left:0px">
                             <img id="ledpd5" src="enclosureImages/ledGray.gif" style="margin-left:1px">
                             <img id="ledpd6" src="enclosureImages/ledGray.gif" style="margin-left:1px">
                             <img id="ledpd7" src="enclosureImages/ledGray.gif" style="margin-left:0px">
                             <img id="ledpd8" src="enclosureImages/ledGray.gif" style="margin-left:0px">
                             <img id="ledusb" src="enclosureImages/ledGray.gif" style="margin-left:0px">
                           </div>
                        </div>
                        <div class="pdydrvsdown2u8" style="width:580px"><ul>';
      for ($i = 0; $i < 8; $i += 4) {
        for ($j = 0; $j < 4; $j++) {
          echo '
                                    <li id=pdimg' . $encl->_flats[$i + $j] . '>
                                          <img border="0" src="images/blank.gif">
                                    </li>';
        }
      }

      echo '</ul></div>
                   </div></td></tr>
                  </table>
                 </td></tr>';
    } else if ($encl->mpvs_EnclosureType == 0x55) {
      $enclImage = "enclosureFrontView_2U4.png";
      echo '
                    <tr><td class="text3image" width="70" align="center">' . gprop('enclosure') . ' ' . ($eid + 1) . '</td><td>
                      <table onselectstart="return false" width="635" height="190" align="center" border="0" cellpadding="0" cellspacing="0">
                        <tr><td id=encl' . $eid . '  valign="top" align="left" class="imagePdydrvs" background="enclosureImages/' . $enclImage . '">
                    <div class="imagespdydrvs2u" >
                        <div class="pdydrvsup2u4">
                           <div style="margin-top:2px;">
                             <img id="ledpower" src="enclosureImages/ledBlue.gif" style="margin-left:31px">
                             <img id="ledsystem" src="enclosureImages/ledGray.gif" style="margin-left:0px">
                             <img id="ledfan" src="enclosureImages/ledGray.gif" style="margin-left:1px">
                             <img id="ledrec" src="enclosureImages/ledRed.gif" style="margin-left:0px">
                             <img id="lednet1" src="enclosureImages/ledGray.gif" style="margin-left:11px">
                             <img id="lednet2" src="enclosureImages/ledGray.gif" style="margin-left:0px">
                             <img id="ledraid" src="enclosureImages/ledRed.gif" style="margin-left:11px">
                             <img id="ledaccess" src="enclosureImages/ledGray.gif" style="margin-left:0px">
                             <img id="ledpd1" src="enclosureImages/ledGray.gif" style="margin-left:1px">
                             <img id="ledpd2" src="enclosureImages/ledGray.gif" style="margin-left:1px">
                             <img id="ledpd3" src="enclosureImages/ledGray.gif" style="margin-left:0px">
                             <img id="ledpd4" src="enclosureImages/ledGray.gif" style="margin-left:0px">
                             <img id="ledusb" src="enclosureImages/ledGray.gif" style="margin-left:43px">
                           </div>
                        </div>
                        <div class="pdydrvsdown2u4" style="width:580px"><ul>';
      for ($i = 0; $i < 4; $i += 4) {
        for ($j = 0; $j < 4; $j++) {
          echo '
                                    <li id=pdimg' . $encl->_flats[$i + $j] . '>
                                          <img border="0" src="images/blank.gif">
                                    </li>';
        }
      }

      echo '</ul></div>
                   </div></td></tr>
                  </table>
                 </td></tr>';
    } else if ($encl->mpvs_EnclosureType == 0x57 && $encl->mpvs_CtrlType != 0x80B) {
      $enclImage = "enclosureFrontView_1U4.png";
      echo '
                    <tr><td class="text3image" width="70" align="center">' . gprop('enclosure') . ' ' . ($eid + 1) . '</td><td>
                      <table onselectstart="return false" width="648" height="288" align="center" border="0" cellpadding="0" cellspacing="0">
                        <tr><td id=encl' . $eid . '  valign="top" align="left" class="imagePdydrvs" background="enclosureImages/' . $enclImage . '">
                    <div class="imagespdydrvs2u" >                       
                        <div class="pdydrvsdown2u4" style="width:580px"><ul>
                        <div style="margin-top:14px;">                         
                             <img id="lederror" src="enclosureImages/ledGray.gif" style="margin-left:442px">
                             <img id="ledraid" src="enclosureImages/ledRed.gif" style="margin-left:1px">
                             <img id="lednet1" src="enclosureImages/ledGray.gif" style="margin-left:1px">
                             <img id="lednet2" src="enclosureImages/ledGray.gif" style="margin-left:1px">
                        </div>';
      for ($i = 0; $i < 4; $i += 4) {
        for ($j = 0; $j < 4; $j++) {
          echo '
                                    <li id=pdimg' . $encl->_flats[$i + $j] . '>
                                          <img border="0" src="images/blank.gif">
                                    </li>';
        }
      }

      echo '</ul></div>
                   </div></td></tr>
                  </table>
                 </td></tr>';
    } else if ($encl->mpvs_EnclosureType == 0x57 && $encl->mpvs_CtrlType == 0x80B) {
      $enclImage = "enclosureFrontView_1U4_A3120.png";
      echo '
                    <tr><td class="text3image" width="70" align="center">' . gprop('enclosure') . ' ' . ($eid + 1) . '</td><td>
                      <table onselectstart="return false" width="635" height="120" align="center" border="0" cellpadding="0" cellspacing="0">
                        <tr><td id=encl' . $eid . '  valign="top" align="left" class="imagePdydrvs" background="enclosureImages/' . $enclImage . '">
                    <div class="imagespdydrvs1u" >                       
                        <div class="pdydrvsdown1u4" style="width:635px"><ul>
                        <div style="margin-top:45px;">                         
                             <img id="ledpower" src="enclosureImages/ledBlue.gif" style="margin-left:80px">
                             <img id="ledsystem" src="enclosureImages/ledGray.gif" style="margin-left:1px">
                             <img id="ledfan" src="enclosureImages/ledGray.gif" style="margin-left:0.5px">
                             <img id="ledrec" src="enclosureImages/ledRed.gif" style="margin-left:1px">
                             <img id="lednet1" src="enclosureImages/ledGray.gif" style="margin-left:12px">
                             <img id="lednet2" src="enclosureImages/ledGray.gif" style="margin-left:0px">
                             <img id="ledraid" src="enclosureImages/ledRed.gif" style="margin-left:11px">
                             <img id="ledaccess" src="enclosureImages/ledGray.gif" style="margin-left:0px">
                             <img id="ledpd1" src="enclosureImages/ledGray.gif" style="margin-left:0.5px">
                             <img id="ledpd2" src="enclosureImages/ledGray.gif" style="margin-left:0.5px">
                             <img id="ledpd3" src="enclosureImages/ledGray.gif" style="margin-left:0.5px">
                             <img id="ledpd4" src="enclosureImages/ledGray.gif" style="margin-left:0.5px">
                             <img id="ledusb" src="enclosureImages/ledGray.gif" style="margin-left:11px">
                        </div>';
      for ($i = 0; $i < 4; $i += 4) {
        for ($j = 0; $j < 4; $j++) {
          echo '
                                    <li id=pdimg' . $encl->_flats[$i + $j] . '>
                                          <img border="0" src="images/blank.gif">
                                    </li>';
        }
      }

      echo '</ul></div>
                   </div></td></tr>
                  </table>
                 </td></tr>';
    }
    $divH += 147;
    break;
  }
  echo '
          <tr height="2"><td></td></tr>';
  if ($string) {
    echo '
            <tr class=tableImageBackground ><td colspan="3" class="text2" align="center">' . $string . '
          </td></tr>';
  }
  echo '
        </table></div></td></tr>
      </table>
    </td>
  </tr>
</table>';


  ?>
<script>
    if (self.innerHeight)
        h=self.innerHeight;
    else if (document.body)
        h= (document.documentElement.clientHeight == 0) ? document.body.clientHeight : document.documentElement.clientHeight;



    if (<?= $divH ?>>h*0.6) {
        divFV.style.height=h*0.6;
        divFV.style.overflow='auto';
    }
</script>

<?
showFRULEDs($subsystem);
}
function isUnplugPD($physicalDrives, $pdid)
{
  for ($i = 0; $i < count($physicalDrives); $i++) {
    if ($pdid == $physicalDrives[$i]->pdi_FlatId) {
      return false;
    }
  }
  return true;
}
function getUnplugPDs($physicalDrives, $arrays)
{
  $unplugPDs = array();
  for ($i = 0; $i < count($arrays); $i++) {
    $array = $arrays[$i];
    if ($array->_opStatus == "Degraded") {
      for ($j = 0; $j < count($array->acd_PDs); $j++) {
        if (isUnplugPD($physicalDrives, $array->acd_PDs[$j]->pdd_PDFlatId)) {
          $unplugPD = new stdclass();
          $unplugPD->pdId = $array->acd_PDs[$j]->pdd_PDFlatId;
          $unplugPDs[] = $unplugPD;
        }
      }
    }
  }
  return $unplugPDs;
}



/* getLedFruInfo() is not served by the mdadm/SMBus backend, so the front-panel
 * LEDs are derived here from live health: fans (enclosure), array + physical-drive
 * op-status, and NIC link. Returns a FRU-LED-state object like the vendor's
 * getLedFruInfo. getStatusColor() is the canonical status->severity mapper. */
function ledStateForStatus($status)
{
  $c = getStatusColor($status);
  if ($c == 'red') return FRU_LED_STATE_RED;
  if ($c == 'orange') return FRU_LED_STATE_AMBER;
  return FRU_LED_STATE_GREEN;                 /* '#262626' = healthy */
}

function computeLedFru($subsystem)
{
  $api = new stdClass();
  $sysFault = false;

  /* fans -> ledfan */
  $fanFault = false;
  $encls = $subsystem->getEnclosures(isset($_SESSION['ecid']) ? $_SESSION['ecid'] : 0);
  if (is_array($encls) && isset($encls[0]->_fans))
    foreach ($encls[0]->_fans as $f)
      if ((isset($f->_speed) && $f->_speed == 0) || (isset($f->_status) && $f->_status != 0 && $f->_status != 1))
        $fanFault = true;
  $api->fan_FruLedStatus = $fanFault ? FRU_LED_STATE_RED : FRU_LED_STATE_GREEN;
  if ($fanFault) $sysFault = true;

  /* arrays -> ledraid (green ok / amber degraded / red critical; off if no array) */
  $raid = FRU_LED_STATE_GREEN; $anyArray = false;
  $arrays = $subsystem->getDiskArrays();
  if (is_array($arrays))
    foreach ($arrays as $a) {
      $anyArray = true;
      $st = ledStateForStatus(isset($a->_opStatusEx) && $a->_opStatusEx ? $a->_opStatusEx : (isset($a->_opStatus) ? $a->_opStatus : ''));
      if ($st == FRU_LED_STATE_RED) { $raid = FRU_LED_STATE_RED; $sysFault = true; }
      else if ($st == FRU_LED_STATE_AMBER && $raid != FRU_LED_STATE_RED) { $raid = FRU_LED_STATE_AMBER; $sysFault = true; }
    }
  $api->raid_FruLedStatus = $anyArray ? $raid : FRU_LED_STATE_OFF;

  /* physical drives -> ledpdN (by slot: slot 0 = pd1); empty slot = off */
  $driveOffline = false;
  for ($i = 1; $i <= 16; $i++) $api->{'pd' . $i . '_FruLedStatus'} = FRU_LED_STATE_OFF;
  $pds = $subsystem->getPhysicalDrives();
  if (is_array($pds))
    foreach ($pds as $pd) {
      if (!isset($pd->pdi_SlotId)) continue;
      $n = (int)$pd->pdi_SlotId + 1;
      if ($n < 1 || $n > 16) continue;
      $st = ledStateForStatus(isset($pd->_opStatus) ? $pd->_opStatus : '');
      $api->{'pd' . $n . '_FruLedStatus'} = $st;
      if ($st != FRU_LED_STATE_GREEN) $sysFault = true;
      if ($st == FRU_LED_STATE_RED) $driveOffline = true;
    }

  /* The mdadm disk-array op-status can read "OK" while actually degraded, so a
   * failed member alone would leave the RAID LED green. If an array member drive
   * is offline (failed), raise the RAID LED to red. */
  if ($anyArray && $driveOffline)
    $api->raid_FruLedStatus = FRU_LED_STATE_RED;

  /* NICs -> lednet1/2 (physical interface operstate) */
  $up = array();
  foreach (glob('/sys/class/net/*') ?: array() as $p) {
    $n = basename($p);
    if (!preg_match('/^(en|eth|em)/', $n)) continue;
    $up[] = (trim(@file_get_contents($p . '/operstate')) == 'up');
  }
  $api->net1_FruLedStatus = (isset($up[0]) && $up[0]) ? FRU_LED_STATE_GREEN : FRU_LED_STATE_OFF;
  $api->net2_FruLedStatus = (isset($up[1]) && $up[1]) ? FRU_LED_STATE_GREEN : FRU_LED_STATE_OFF;

  /* system + fixed/derived LEDs */
  $api->system_FruLedStatus = $sysFault ? FRU_LED_STATE_RED : FRU_LED_STATE_GREEN;
  $api->globalSata_FruLedAcitvity = FRU_LED_STATE_GREEN;   /* SATA subsystem present (blue) */
  $api->heartbeat_FruLedStatus = FRU_LED_STATE_GREEN;
  $api->record_FruLedStatus = FRU_LED_STATE_OFF;           /* NVR record LED unmanaged -> off (was false red) */
  $api->usb_FruLedStatus = FRU_LED_STATE_OFF;
  $api->_lederror = ($sysFault ? FRU_LED_STATE_RED : FRU_LED_STATE_OFF);
  return $api;
}

function ledImg($state, $palette)
{
  switch ($state) {
    case FRU_LED_STATE_GREEN:       return 'enclosureImages/' . ($palette == 'blue' ? 'ledBlue' : 'ledGreen') . '.gif';
    case FRU_LED_STATE_RED:         return 'enclosureImages/ledRed.gif';
    case FRU_LED_STATE_AMBER:       return 'enclosureImages/ledYellow.gif';
    case FRU_LED_STATE_GREEN_BLINK: return 'enclosureImages/' . ($palette == 'blue' ? 'ledBlueFlash' : 'ledGreenFlash') . '.gif';
    case FRU_LED_STATE_RED_BLINK:   return 'enclosureImages/ledRedFlash.gif';
    case FRU_LED_STATE_AMBER_BLINK: return 'enclosureImages/ledYellowFlash.gif';
    case FRU_LED_STATE_OFF:         return 'enclosureImages/ledGray.gif';
  }
  return null;
}

/* [domId, api field, palette] - the LEDs showFRULEDs drives. Shared by the load
 * render and the live poll (ledLive.php) so both build the same {id: image} map. */
function ledFruFields()
{
  $f = array(
    array('lednet1', 'net1_FruLedStatus', 'green'), array('lednet2', 'net2_FruLedStatus', 'green'),
    array('lednet3', 'net3_FruLedStatus', 'green'), array('lednet4', 'net4_FruLedStatus', 'green'),
    array('ledusb', 'usb_FruLedStatus', 'green'), array('ledrec', 'record_FruLedStatus', 'green'),
    array('ledsystem', 'system_FruLedStatus', 'green'), array('ledraid', 'raid_FruLedStatus', 'green'),
    array('ledfan', 'fan_FruLedStatus', 'green'), array('ledaccess', 'globalSata_FruLedAcitvity', 'blue'),
    array('ledHeartbeat', 'heartbeat_FruLedStatus', 'green'), array('lederror', '_lederror', 'green'),
  );
  for ($i = 1; $i <= 16; $i++) $f[] = array('ledpd' . $i, 'pd' . $i . '_FruLedStatus', 'green');
  return $f;
}

function ledFruMap($subsystem)
{
  $api = computeLedFru($subsystem);
  $map = array();
  foreach (ledFruFields() as $fld) {
    list($id, $field, $pal) = $fld;
    if (!isset($api->$field)) continue;
    $img = ledImg($api->$field, $pal);
    if ($img !== null) $map[$id] = $img;
  }
  return $map;
}

function showFRULEDs($subsystem)
{
  $map = ledFruMap($subsystem);
  /* Apply the computed LED images now, then poll ledLive.php every 3s so the
     front-panel LEDs track drive/array/fan/NIC state live without a reload. */
  echo '<script>(function(m){'
     . 'function ap(mm){for(var k in mm){var e=document.getElementById(k);if(e)e.src=mm[k];}}'
     . 'ap(m);'
     . 'function p(){try{var x=new XMLHttpRequest();x.open("GET","ledLive.php?_="+(new Date()).getTime(),true);'
     . 'x.onreadystatechange=function(){if(x.readyState==4&&x.status==200){try{ap(JSON.parse(x.responseText));}catch(e){}}};x.send();}catch(e){}}'
     . 'setInterval(p,3000);'
     . '})(' . json_encode($map) . ');</script>';
}

function changeRedLed($id, $ledAPI, $led1, $led2)
{
  if (isset($ledAPI->$led1)) {
    if (isset($ledAPI->$led2)) {
      if (($ledAPI->$led1 == FRU_LED_STATE_GREEN) && ($ledAPI->$led2 == FRU_LED_STATE_GREEN)) {
        echo 'changeImage("' . $id . '","enclosureImages/ledGray.gif");';
      } else {
        echo 'changeImage("' . $id . '","enclosureImages/ledRed.gif");';
      }
    }
  }
}

function changeBlueLed($id, $ledAPI, $led)
{
  if (isset($ledAPI->$led)) {
    switch ($ledAPI->$led) {
      case FRU_LED_STATE_GREEN:
        echo 'changeImage("' . $id . '","enclosureImages/ledBlue.gif");';
        break;
      case FRU_LED_STATE_RED:
        echo 'changeImage("' . $id . '","enclosureImages/ledRed.gif");';
        break;
      case FRU_LED_STATE_AMBER:
        echo 'changeImage("' . $id . '","enclosureImages/ledYellow.gif");';
        break;
      case FRU_LED_STATE_GREEN_BLINK:
        echo 'changeImage("' . $id . '","enclosureImages/ledBlueFlash.gif");';
        break;
      case FRU_LED_STATE_RED_BLINK:
        echo 'changeImage("' . $id . '","enclosureImages/ledRedFlash.gif");';
        break;
      case FRU_LED_STATE_AMBER_BLINK:
        echo 'changeImage("' . $id . '","enclosureImages/ledYellowFlash.gif");';
        break;
      case FRU_LED_STATE_OFF:
        echo 'changeImage("' . $id . '","enclosureImages/ledGray.gif");';
        break;
      default:
        break;
    }
  }
}

function changeGreenLed($id, $ledAPI, $led)
{
  if (isset($ledAPI->$led)) {
    switch ($ledAPI->$led) {
      case FRU_LED_STATE_GREEN:
        echo 'changeImage("' . $id . '","enclosureImages/ledGreen.gif");';
        break;
      case FRU_LED_STATE_RED:
        echo 'changeImage("' . $id . '","enclosureImages/ledRed.gif");';
        break;
      case FRU_LED_STATE_AMBER:
        echo 'changeImage("' . $id . '","enclosureImages/ledYellow.gif");';
        break;
      case FRU_LED_STATE_GREEN_BLINK:
        echo 'changeImage("' . $id . '","enclosureImages/ledGreenFlash.gif");';
        break;
      case FRU_LED_STATE_RED_BLINK:
        echo 'changeImage("' . $id . '","enclosureImages/ledRedFlash.gif");';
        break;
      case FRU_LED_STATE_AMBER_BLINK:
        echo 'changeImage("' . $id . '","enclosureImages/ledYellowFlash.gif");';
        break;
      case FRU_LED_STATE_OFF:
        echo 'changeImage("' . $id . '","enclosureImages/ledGray.gif");';
        break;
      default:
        break;
    }
  }
}



function flashLED($slot)
{
  // if ($slot<1||$slot>15)
  //   return;
  echo '
        <script>
            flashImage("ledpd' . ($slot) . '");
        </script>';
}

function stopFlashLater()
{
  echo '
        <script>
          setTimeout("stopFlash()", 60000); // flash for 1 minute
        </script>';
}

function flashArray($subsystem, $array)
{
  // find PDs in the array
  $PDs = $array->acd_PDs;
  for ($i = 0; $i < count($PDs); $i++) {
    $pds = $subsystem->getPhysicalDrive($PDs[$i]->pdd_PDFlatId);
    if (isset($pds[0])) {
      $pd = $pds[0];
      $multipdid = $PDs[$i]->pdd_PDFlatId + 1;
      if (isset($multipdid))
        flashLED($multipdid);
    }
  }

  stopFlashLater();
}

function locateArray($subsystem, $array)
{
  $subsystem->locateArrayInEnclosure(0, $array->acd_ArrayID);
  flashArray($subsystem, $array);
}

function locateLogDrv($subsystem, $logicalDrive)
{
  $subsystem->locateLogDrvInEnclosure(0, $logicalDrive->ldi_FlatId);
  $array = $subsystem->getDiskArray($logicalDrive->ldi_ArrayId);
  flashArray($subsystem, $array);
}

function locatePhyDrv($subsystem, $physicalDrive)
{
  if ($physicalDrive == null)
    return;
  $subsystem->locatePhyDrvInEnclosure(0, $physicalDrive->pdi_FlatId);
  $multislotid = $physicalDrive->pdi_FlatId + 1;
  // flashLED($physicalDrive->pdi_SlotId);
  flashLED($multislotid);
  stopFlashLater();
}

function addFrontViewEvents($encls, $physicalDrives)
{
  $pdcount = count($physicalDrives);
  echo '
      <script>
          var physicalDrives=new Array(256);';
  $pdflats = array();
  for ($i = 0; $i < count($physicalDrives); $i++) {
    $physicalDrive = $physicalDrives[$i];
    $pdflats[$physicalDrive->pdi_FlatId] = 1;
    echo '
        var physicalDrive=new Array(6);
        physicalDrive[0]=' . $physicalDrive->pdi_FlatId . ';
        physicalDrive[1]="' . $physicalDrive->pdi_DriveModelNo . '";
        physicalDrive[2]="' . $physicalDrive->pdi_ConfigurableSize . '";
        physicalDrive[3]="' . ((isset($physicalDrive->pdi_EnclosureId) && isset($physicalDrive->pdi_SlotId)) ? ('Encl ' . ($physicalDrive->pdi_EnclosureId + 1) . ' Slot ' . $physicalDrive->pdi_SlotId) : 'Unknown') . '";
        physicalDrive[4]="' . $physicalDrive->_opStatus . '";
        physicalDrive[5]="' . $physicalDrive->_cfgStatus . '";
        physicalDrives[' . (isset($physicalDrive->pdi_SlotId) ? (int)$physicalDrive->pdi_SlotId : $physicalDrive->pdi_FlatId) . ']=physicalDrive;';
  }
  echo '
      var flats=new Array(256);';
  echo '
      for (var i=0; i<256; i++) {
        var obj=ylib_getObj("pdimg" + i);
        if (!obj) continue;
        //obj.style.border="2px solid #8C8C8C";
        tooltip(i);
      }';
  echo '
      for (var i=1; i<=8; i++) {
        var obj=ylib_getObj("ledpd" + i);
        if (!obj) continue;
        tooltippd(i-1);
      }';
  echo '
        tooltipstatues()';
  echo '
      </script>';
}
?>
<script>
  function flashImage(id)
  {
    var obj=ylib_getObj(id);
    if (!obj)
      return;

    obj.origin=obj.src;
    var src=obj.src;
    src=src.substr(0, src.lastIndexOf('.'));
    src += 'Flash.gif';
    obj.src=src;
  }

  function stopFlash()
  {
    var i;
    var obj;
    for (i=0; i <= 15; i++){
      obj=ylib_getObj('ledb' + i);
      if (obj)
        if (obj.origin)
          obj.src=obj.origin;
    }
    window.location.href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>";
  }

  function showTooltip(id,str)
  {
    o=ylib_getObj(id);
    ylib_addEvt(o,'mouseover',new Function("return overlib('"+str+"',HAUTO)"));
    ylib_addEvt(o,'mouseout',new Function("return nd()"));
  }

  function tooltip(i)
  {
    if (!physicalDrives[i])
      showTooltip("pdimg" + i,'<?= gprop('device') ?> '+(i+1)+': no drive');
    else {
      var physicalDrive=physicalDrives[i];
      showTooltip("pdimg" + i,'<?= gprop('device') ?>: '+(physicalDrive[0]+1)+'<br>'+
              '<?= gprop('model') ?>: '+physicalDrive[1]+'<br>'+
              '<?= gprop('configurablecapacity') ?>: '+physicalDrive[2]+'<br>'+
              '<?= gprop('location') ?>: '+physicalDrive[3]+'<br>'+
              '<?= gprop('opstatus') ?>: '+physicalDrive[4]+'<br>'+
              '<?= gprop('configurationstatus') ?>: '+physicalDrive[5]);
    }
  }

  function tooltippd(i)
  {
    if (!physicalDrives[i])
      showTooltip("ledpd" + (i+1),'<?= gprop('device') ?> '+(i+1)+': no drive');
    else {
      var physicalDrive=physicalDrives[i];
      showTooltip("ledpd" + (i+1),'<?= gprop('device') ?>: '+(physicalDrive[0]+1)+'<br>'+
              '<?= gprop('opstatus') ?>: '+physicalDrive[4]);
    }
  }

  function tooltipstatues()
  {
    showTooltip("ledpower","<?= gprop('ledpower') ?>");
    showTooltip("ledsystem","<?= gprop('ledsystem') ?>");
    showTooltip("ledrec","<?= gprop('ledrec') ?>");
    showTooltip("ledfan","<?= gprop('ledfan') ?>");
    showTooltip("ledraid","<?= gprop('ledraid') ?>");
    showTooltip("ledaccess","<?= gprop('ledaccess') ?>");
    showTooltip("lednet1","<?= gprop('lednet1') ?>");
    showTooltip("lednet2","<?= gprop('lednet2') ?>");
    showTooltip("lednet3", "<?= gprop('lednet3') ?>");
    showTooltip("lednet4", "<?= gprop('lednet4') ?>");
    showTooltip("ledusb","<?= gprop('ledusb') ?>");
    showTooltip("ledencl", "<?= gprop('ledencl') ?>");
    showTooltip("ledHeartbeat", "<?= gprop('ledHeartbeat') ?>");
    showTooltip("lederror", "<?= gprop('lederror') ?>");
  }

  function unselectAllPDs()
  {
    for (var i=0; i<256; i++) {
      var obj=ylib_getObj('pdimg' + i);
      if (obj) {
        obj.style.backgroundImage='';
      };
    }

    for (var i=0; i<256; i++) {
      var obj=ylib_getObj('pd' + i);
      if (obj)
        obj.style.backgroundImage='';
    }
  }

  function highlightPD(i)
  {
    var obj=ylib_getObj('pdimg' + i);
    if (obj) {
      obj.style.backgroundImage='url(enclosureImages/drive_enable.gif)';
    }

  }

  function overPD(i)
  {
    var obj=ylib_getObj('pdimg' + i);
    if (obj) {
      obj.style.backgroundImage='url(enclosureImages/drive_hover.gif)';
    }
    obj=ylib_getObj('pd' + i);
    if (obj)
      obj.style.backgroundImage='url(images/select.gif)';

  }

  function outPD(i)
  {
    var obj=ylib_getObj('pdimg' + i);
    if (obj) {
      obj.style.backgroundImage='';
    }

    obj=ylib_getObj('pd' + i);
    if (obj)
      obj.style.backgroundImage='';
  }

  function overArray(i)
  {
    var obj=ylib_getObj('pdimg' + i);
    if (obj) {
      obj.style.backgroundImage='url(enclosureImages/drive_hover.gif)';
    }
  }

  function outArray(i)
  {
    var obj=ylib_getObj('pdimg' + i);
    if (obj) {
      obj.style.backgroundImage='url(enclosureImages/drive_enable.gif)';;
    }
  }
</script>
