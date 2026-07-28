<?php

/*******************************************************************************
 * Filename    : daemon.php
 * Description : islaVista daemon script to check if there is new events.
 * Created on  : 08/20/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_SESSION['redirect'])) {
  unset($_SESSION['redirect']);
  exit;
}
if (!isset($_SESSION['sessionService']))
  {
    echo '
    <script>
      top.location.reload();
    </script>';
    exit;
  }
if (!isset($_SESSION['processing']) || $_SESSION['processing'] == 1)
  refreshCurrentPage(2);
else {
  if (isset($_COOKIE['eventLast']))
    SetSessionValue('eventLast', $_COOKIE['eventLast']);
  if (isset($_COOKIE['eventLast1']))
    SetSessionValue('eventLast1', $_COOKIE['eventLast1']);
  if (isset($_COOKIE['evtView']))
    SetSessionValue('evtView', $_COOKIE['evtView']);
  $server = getServer();
  $subsystem = $server->getSubsystem();

  $events = $subsystem->getLastEvents(1, $language);
  $result = getLastError();
  if ($result == PIERR_INACTIVE_SESSION) {
    errorOnInvalidSession();
  }
  $seqNum = count($events) ? $events[0]->evt_SequenceNumber : 0;

  $oldSeqNum = $_SESSION['eventLast'];
  SetSessionValue('eventLast', $seqNum);
  if (!isset($_SESSION['eventLast1']))
    SetSessionValue('eventLast1', $seqNum);

  if (!isset($_SESSION['evtView']))
    SetSessionValue('evtView', $oldSeqNum);

  ?>
<html>

<head>
<script src="js/tip.js"></script>
<script src="js/myua.js"></script>
<script src="js/plib.js"></script>
<script language="javascript">
  function changeLedImage(id,src){
    var obj = top.basefrm.document.getElementById(id);
    if(obj){
      obj.src = src;
    }
  }
</script>
</head>

<body>
<div id=overDiv style="position:absolute;visibility:hidden;z-index:1000;">
</div>
<script src="js/tip.js"></script>
<?php
  function isBuzzerSounding()
  {
    global $subsystem;
    $sounding = 0;

    $buzzers = $subsystem->getBuzzers();
    $result = getLastError();
    if ($result == PIERR_INACTIVE_SESSION) {
      errorOnInvalidSession();
    }
    for ($i = 0; $i < count($buzzers); $i++) {
      if ($buzzers[$i]->bs_Sounding) {
        $sounding = $buzzers[$i]->bs_Sounding;
        break;
      }
    }
    return $sounding;
  }

  function refreshBuzzerIcon($buzzerSounding)
  {
    echo '
          <script>
          var frm=top.headfrm;
          if(frm){
              var buzzerSounding = ' . $buzzerSounding . ';
              var buzzerIcon = frm.document.getElementById("alarm");
              if(buzzerSounding)
              {
                  if(buzzerIcon) ylib_show(buzzerIcon);
              }
              else
              {
                  if(buzzerIcon) ylib_hide(buzzerIcon);
              }
          }

          </script>';
    SetSessionValue('buzzersounding', $buzzerSounding);
  }
  function eventNotice($event)
  {
    $evtCount = $event->evt_SequenceNumber - $_SESSION['evtView'];
    if ($evtCount == 0) return;
    if ($evtCount < 0) $evtCount = 1;
    if (isset($event->evt_SequenceNumber)) {
      echo '
        <script>
        var frm=top.headfrm;
        if(frm) {
          var overDiv=frm.document.getElementById("overDiv");
          if (overDiv&&' . $evtCount . '&&(!frm.over||!frm.over.eventLast||frm.over.eventLast!=' . $event->evt_SequenceNumber . ')) {
            var string="Last Event: ' . $event->evt_SequenceNumber . ', ' . $event->evt_DeviceId . ', ' . $event->_Severity . ', ' . $event->_Timestamp . '";
            frm.overlib(string,STICKY,CAPTION,"' . $evtCount . ' New Events",FIXX,100,FIXY,30,BGCOLOR,"#FF9700");
            string+="<br>' . $event->evt_Desc . '";
            frm.overlib(string,STICKY,CAPTION,"' . $evtCount . ' New Event' . ($evtCount > 1 ? 's' : '') . '",
              FIXX,(frm.document.body.offsetWidth-overDiv.offsetWidth-16),FIXY,30,BGCOLOR,"#FFFFCC");
            frm.over.eventLast=' . $event->evt_SequenceNumber . ';
          }
        }
        </script>';
    }
  }

  function isEventRelatedTree($events)
  {
    for ($i = 0; $i < count($events); $i++) {
      $class = $events[$i]->ei_ClassId; // it is a number
      $eventid = intval($events[$i]->evt_defaultEventId, 16); // convert string(0x00000000) to number
      switch ($class) {
        case EVT_CLASS_PHYSICAL_DISK:
          if ($eventid != EVT_CODE_PD_CHANGED) return true;
          break;
        case EVT_CLASS_LOGICAL_DRIVE:
          return true;
          break;
        case EVT_CLASS_DISK_ARRAY:
          return true;
          break;
        case EVT_CLASS_REBUILD:
          return true;
          break;
        case EVT_CLASS_CONTROLLER:
          if (
            $eventid != EVT_CODE_CONTROLLER_OPAS_START
            && $eventid != EVT_CODE_CONTROLLER_OPAS_END
            && $eventid != EVT_CODE_CONTROLLER_OPAS_END_WITH_ERROR
            && $eventid != EVT_CODE_CONTROLLER_OPAS_END_NO_CONFIRM
            && $eventid != EVT_CODE_CONTROLLER_OPAS_END_FREE_SPACE_SMALL
          ) {
            // error_log('refresh on controller event ' . $eventid);
            return true;
          } else {
            // error_log('no refresh on event ' . $eventid);
          }
          break;
      }
    }
    return false;
  }

  function isEventRelatedEncl($events)
  {
    for ($i = 0; $i < count($events); $i++) {
      $class = $events[$i]->ei_ClassId;
      if ($class == EVT_CLASS_BATTERY ||
        $class == EVT_CLASS_COOLING_DEVICE ||
        $class == EVT_CLASS_BBU ||
        $class == EVT_CLASS_SEP ||
        $class == EVT_CLASS_PSU ||
        $class == EVT_CLASS_BLOWER)
        return true;
    }
    return false;
  }

  echo 'seqNum=' . $seqNum . ', old=' . $oldSeqNum;
  if ($seqNum != $oldSeqNum) {
    $events = $subsystem->getLastEvents($seqNum - $oldSeqNum, $_COOKIE['language']);
    if (isEventRelatedTree($events)) {
      refreshTreeFrame();
      refreshBaseFrame();
    }
  }

  if ($_SESSION['eventLast1'] != $seqNum) {
    if (isset($_SESSION['lastfile'])) {
      if (strstr($_SESSION['lastfile'], 'events.php')) {
        refreshBaseFrame();
      }
      else if (isEventRelatedEncl($events)) {
        if (in_array($_SESSION['lastfile'], array('/encl.php', '/phydrv.php', '/phydrvs.php', '/array.php', '/logdrv.php', '/spare.php'))) {
          refreshBaseFrame(); // to redraw front/back view
        }
      }
    }
    if (isset($_SESSION['eventShow']) && $_SESSION['eventShow']) {
      refreshEventFrame();
    }
    SetSessionValue('eventLast1', $seqNum);
  }

  if ((isset($_SESSION['lastfile']) && strstr($_SESSION['lastfile'], 'events.php')) || (isset($_SESSION['eventShow']) && $_SESSION['eventShow'])) {
  }
  else
    eventNotice($events[0]);

  $buzzerSounding = isBuzzerSounding();
  if (!isset($_SESSION['buzzersounding']) ||
    $_SESSION['buzzersounding'] != $buzzerSounding) {
    refreshBuzzerIcon($buzzerSounding);
  }

  refreshCurrentPage();
  setcookie('eventLast', $_SESSION['eventLast'], null, null, null, true, true);
  setcookie('eventLast1', $_SESSION['eventLast1'], null, null, null, true, true);
  setcookie('evtView', $_SESSION['evtView'], null, null, null, true, true);
?>
</body>

</html>
<?php

  function showFRULEDs()
  {
    global $subsystem;
    $ledapi = $subsystem->getLedFruInfo();

    echo '<script>';

    changeBlueLed("lednet1", $ledapi, "net1_FruLedStatus");
    changeBlueLed("lednet2", $ledapi, "net2_FruLedStatus");
    changeBlueLed("lednet3", $ledapi, "net3_FruLedStatus");
    changeBlueLed("lednet4", $ledapi, "net4_FruLedStatus");
    changeGreenLed("ledusb", $ledapi, "usb_FruLedStatus");
    changeGreenLed("ledrec", $ledapi, "record_FruLedStatus");
    changeGreenLed("ledsystem", $ledapi, "system_FruLedStatus");
    changeGreenLed("ledraid", $ledapi, "raid_FruLedStatus");
    changeGreenLed("ledfan", $ledapi, "fan_FruLedStatus");
    changeBlueLed("ledaccess", $ledapi, "globalSata_FruLedAcitvity");
    changeGreenLed("ledHeartbeat", $ledapi, "heartbeat_FruLedStatus");
    changeRedLed("lederror", $ledapi, "system_FruLedStatus", "raid_FruLedStatus");

    for ($i = 1; $i <= 16; $i++) {
      $str1 = "pd" . $i . "_FruLedStatus";
      $str2 = "pd" . $i . "_FruLedActivity";
      changeGreenLed("ledpd" . $i, $ledapi, $str1);
      changeBlueLed("actLedPd" . $i, $ledapi, $str2);
    }


    echo '</script>';
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
          echo 'changeLedImage("' . $id . '","enclosureImages/ledBlue.gif");';
          break;
        case FRU_LED_STATE_RED:
          echo 'changeLedImage("' . $id . '","enclosureImages/ledRed.gif");';
          break;
        case FRU_LED_STATE_AMBER:
          echo 'changeLedImage("' . $id . '","enclosureImages/ledYellow.gif");';
          break;
        case FRU_LED_STATE_GREEN_BLINK:
          echo 'changeLedImage("' . $id . '","enclosureImages/ledBlueFlash.gif");';
          break;
        case FRU_LED_STATE_RED_BLINK:
          echo 'changeLedImage("' . $id . '","enclosureImages/ledRedFlash.gif");';
          break;
        case FRU_LED_STATE_AMBER_BLINK:
          echo 'changeLedImage("' . $id . '","enclosureImages/ledYellowFlash.gif");';
          break;
        case FRU_LED_STATE_OFF:
          echo 'changeLedImage("' . $id . '","enclosureImages/ledGray.gif");';
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
          echo 'changeLedImage("' . $id . '","enclosureImages/ledGreen.gif");';
          break;
        case FRU_LED_STATE_RED:
          echo 'changeLedImage("' . $id . '","enclosureImages/ledRed.gif");';
          break;
        case FRU_LED_STATE_AMBER:
          echo 'changeLedImage("' . $id . '","enclosureImages/ledYellow.gif");';
          break;
        case FRU_LED_STATE_GREEN_BLINK:
          echo 'changeLedImage("' . $id . '","enclosureImages/ledGreenFlash.gif");';
          break;
        case FRU_LED_STATE_RED_BLINK:
          echo 'changeLedImage("' . $id . '","enclosureImages/ledRedFlash.gif");';
          break;
        case FRU_LED_STATE_AMBER_BLINK:
          echo 'changeLedImage("' . $id . '","enclosureImages/ledYellowFlash.gif");';
          break;
        case FRU_LED_STATE_OFF:
          echo 'changeLedImage("' . $id . '","enclosureImages/ledGray.gif");';
          break;
        default:
          break;
      }
    }
  }

  showFRULEDs();
}
?>
