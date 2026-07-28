<?php

/*******************************************************************************
 * Filename    : controllert.php
 * Description : islaVista controller settings.
 * Created on  : 06/20/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

// Disable options about SMART feature on the VESS-A device.
$supporting_smart = false;

if (isset($_POST['cancel'])) {
  if (isset($_GET['clear']))
    $note = gprop('ctrlclear');
  else
    $note = gprop('controllersetting');

  header('Location: controller.php?tab=0&note=' . $note . gprop('cancelled'));
  exit;
}

$server = getServer();

$subsystem = $server->getSubsystem();
$supportDualCtrl = $subsystem->supportdualcontroller();
$controller = $subsystem->getController($_SESSION['ctid']);
$isSupportLUNAffinity = $subsystem->getLunAffinitySupport();
$ctrl = $subsystem->getControllerSettings($_SESSION['ctid']);
$spindowntype = array(gprop('alldrive'), gprop('spareonly'));
$note = null;
$result = 0;
$pv = getUserPrivilege($subsystem);
if ($pv < USER_GROUP_POWER)
  $note = galert('notauthorizedmodifycontroller');
if (count($_POST)) {
  if (isset($_GET['clear'])) {
    table_wait();
    $result = $subsystem->clearControllerWatermark($_SESSION['ctid']);
    if ($result == 0) {
      redirectPage('controller.php?tab=0&note=' . gresult('controllerclearok'));
      exit;
    } else
      $note = gresult('controllerclearfail');
  } else {
    $ctrl->_devID = $_SESSION['ctid'];
    $ctrl->cs_UserName = $_POST['cs_UserName'];
    $ctrl->cs_CoercionEnable = 0;
    if (isset($_POST['cs_CoercionEnable'])) {
      $ctrl->cs_CoercionEnable = 1;
      $ctrl->cs_CoercionMethod = $_POST['cs_CoercionMethod'];
    }

    if ($supporting_smart) {
      $ctrl->cs_SmartLogEnable = 0;
      if (isset($_POST['cs_SmartLogEnable'])) {
        $ctrl->cs_SmartLogEnable = 1;
        $ctrl->cs_SmartPollInterval = floor($_POST['cs_SmartPollInterval']) * 60;
      }
    }

    /*
    if (isset($_POST['cs_LUNAffinityEnable']))
      $ctrl->cs_LUNAffinityEnable = 1;
    else
      $ctrl->cs_LUNAffinityEnable = 0;

    if (isset($_POST['cs_AdaptiveWriteBackCacheEnable']))
      $ctrl->cs_AdaptiveWriteBackCacheEnable = 1;
    else
      $ctrl->cs_AdaptiveWriteBackCacheEnable = 0;
     */

    if (isset($_POST['cs_SyncCacheEnable']))
      $ctrl->cs_SyncCacheEnable = 1;
    else
      $ctrl->cs_SyncCacheEnable = 0;
    if (isset($_POST['cs_ForcedReadAheadEnable']))
      $ctrl->cs_ForcedReadAheadEnable = 1;
    else
      $ctrl->cs_ForcedReadAheadEnable = 0;

    /*
    if (isset($_POST['cs_CommonWWNNEnable']))
      $ctrl->cs_CommonWWNNEnable = 1;
    else
      $ctrl->cs_CommonWWNNEnable = 0;
    if (isset($_POST['cs_PortPowerCtrl']))
      $ctrl->cs_PortPowerCtrl = 1;
    else
      $ctrl->cs_PortPowerCtrl = 0;
    if (isset($_POST['cs_NCQDisable']))
      $ctrl->cs_NCQDisable = 0;
    else
      $ctrl->cs_NCQDisable = 1;
     */

    $ctrl->cs_WriteBackCacheFlushInterval = floor($_POST['cs_WriteBackCacheFlushInterval']);
    // $ctrl->cs_PreferedCacheLineSize=$_POST['cs_PreferedCacheLineSize'];
    // $ctrl->cs_EnclosurePollInterval=$_POST['cs_EnclosurePollInterval'];
    // $ctrl->cs_SpinDownType=$_POST['cs_SpinDownType'];
    $ctrl->cs_HDDPowerLevels = $_POST['cs_HDDPowerLevels'];
    if (isset($_POST['cs_HDDIdleTime']))
      $ctrl->cs_HDDIdleTime = $_POST['cs_HDDIdleTime'];
    if (isset($_POST['cs_HDDStandbyTime']))
      $ctrl->cs_HDDStandbyTime = $_POST['cs_HDDStandbyTime'];
    if (isset($_POST['cs_HDDStoppedTime']))
      $ctrl->cs_HDDStoppedTime = $_POST['cs_HDDStoppedTime'];
    /*pd temperature threshhold*/
    $ctrl->cs_PDTempThreshold = $_POST['cs_PDTempThreshold'];
    $ctrl->cs_PDCriTempThreshold = $_POST['cs_PDCriTempThreshold'];
    $result = $subsystem->setControllerSettings($_SESSION['ctid'], $ctrl);
    if ($result == 0) {
      redirectPage('controller.php?tab=0&note=' . gresult('controllersettingok'));
      exit;
    } else {
      $note = gresult('controllersettingfail');
    }

  }
}
?>
<html>

<head>
<?php
page_head();
?>
</head>

<body>
<form method=post onsubmit="return validateForm()">
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
$helpAct = 'set';
if (isset($_GET['clear']))
  $helpAct = 'clear';
else
  $helpAct = 'set';

table_title(gprop('controllersettings'), 'controller', $note, $result, $helpAct);
table_tab_head();

$tabTitle = tabTitleController($controller);
table_tab($tabTitle);

if (isset($_GET['clear'])) {
  table_head_fold(gprop('ctrlclear'), 'CT', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
  table_row_empty(galert('ctrlwillclear'));
} else {
  table_head_fold(gprop('controllersettings'), 'CT', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
  // table_row(gprop('controllerid'),'&nbsp;&nbsp;'.($ctrl->cs_DeviceID+1));

  table_row(gprop('controllerid'), '&nbsp;&nbsp;' . ($_SESSION['ctid'] + 1));
  table_row_edit(gprop('alias'), $ctrl->cs_UserName, 'cs_UserName', 48, 30, null, 'msg_UserName');
  /*
  if ($isSupportLUNAffinity->isLunAffinitySupport) {
    table_row_checkbox(gprop('lunaffinityenabled'), $ctrl->cs_LUNAffinityEnable, 'cs_LUNAffinityEnable');
  }
   */
  if ($supporting_smart) {
    table_row_checkbox(gprop('smartlogenabled'), $ctrl->cs_SmartLogEnable, 'cs_SmartLogEnable');
    table_row_edit(gprop('smartpollinginterval') . ' [1-1440]', floor($ctrl->cs_SmartPollInterval / 60), 'cs_SmartPollInterval', 48, 30, gprop('minutes'), 'msg_SmartPollInterval');
  }

  table_row_checkbox(gprop('coercionenabled'), $ctrl->cs_CoercionEnable, 'cs_CoercionEnable');
  for ($i = 0; $i < 4; $i++)
    $method[$i] = array($i, getStringCtrlCoercionMethod($i));
  table_row_combo(gprop('coercionmethod'), $method, 'cs_CoercionMethod', $ctrl->cs_CoercionMethod);
  table_row_edit(gprop('writebackcacheflushinterval') . ' [1-12]', $ctrl->cs_WriteBackCacheFlushInterval, 'cs_WriteBackCacheFlushInterval', 48, 30, gprop('seconds'), 'msg_WriteBackCacheFlushInterval');
  $preferedCacheLineSizes = array(array(8, '8KB'), array(64, '64KB'));
  // table_row_combo(gprop('preferredcachelinesize'),$preferedCacheLineSizes,'cs_PreferedCacheLineSize',$ctrl->cs_PreferedCacheLineSize);
  // table_row_edit(gprop('enclprollinterval').' [15-255]',$ctrl->cs_EnclosurePollInterval,'cs_EnclosurePollInterval',20,30,gprop('seconds'),'msg_EnclosurePollInterval');
  // table_row_checkbox(gprop('adaptiveenable'),$ctrl->cs_AdaptiveWriteBackCacheEnable,'cs_AdaptiveWriteBackCacheEnable');
  // for($i=0;$i<2;$i++)
    // $type[$i]=array($i+1,$spindowntype[$i]);
  // table_row_combo(gprop('spindowntype'),$type,'cs_SpinDownType',$ctrl->cs_SpinDownType);
  for ($i = 0; $i < 4; $i++)
    $level[$i] = array($i, $i);

  table_row_combo(gprop('hddpowerlevels'), $level, 'cs_HDDPowerLevels', $ctrl->cs_HDDPowerLevels);

  // table_row_edit(gprop('hddpowerlevels').' [0,1,3]',$ctrl->cs_HDDPowerLevels,'cs_HDDPowerLevels',48,30,null,'msg_HDDPowerLevels');

  table_row_edit(gprop('hddidletime') . ' [0, 1-1440]', $ctrl->cs_HDDIdleTime, 'cs_HDDIdleTime', 48, 30, gprop('minutes'), 'msg_HDDIdleTime');
  table_row_edit(gprop('hddstandbytime') . ' [0, 1-1440]', $ctrl->cs_HDDStandbyTime, 'cs_HDDStandbyTime', 48, 30, gprop('minutes'), 'msg_HDDStandbyTime');
  table_row_edit(gprop('hddstoppedtime') . ' [0, 10-1440]', $ctrl->cs_HDDStoppedTime, 'cs_HDDStoppedTime', 48, 30, gprop('minutes'), 'msg_HDDStoppedTime');
  table_row_checkbox(gprop('hostcacheflushing'), $ctrl->cs_SyncCacheEnable, 'cs_SyncCacheEnable');
  /*
  if ($ctrl->cs_NCQDisable)
    $ncqenable = 0;
  else
    $ncqenable = 1;
  table_row_checkbox(gprop('ncq'), $ncqenable, 'cs_NCQDisable');
  */

  if ($supportDualCtrl->DualCtrlSupport) {
    // table_row_checkbox(gprop('hostcacheflushing'),$ctrl->cs_SyncCacheEnable,'cs_SyncCacheEnable');
    // table_row_checkbox(gprop('forcedreadahead'),$ctrl->cs_ForcedReadAheadEnable,'cs_ForcedReadAheadEnable');
    table_row_checkbox(gprop('commonwwnn'), $ctrl->cs_CommonWWNNEnable, 'cs_CommonWWNNEnable');
  }
  table_row_checkbox(gprop('forcedreadahead'), $ctrl->cs_ForcedReadAheadEnable, 'cs_ForcedReadAheadEnable');
  // table_row_checkbox(gprop('portpowerctrl'),$ctrl->cs_PortPowerCtrl,'cs_PortPowerCtrl');
  table_row_edit(gprop('pdtemperaturethreshold') . ' [' . CS_PD_TEMP_THRESHOLD_MIN . '-' . CS_PD_TEMP_THRESHOLD_MAX . ']', $ctrl->cs_PDTempThreshold, 'cs_PDTempThreshold', 48, 30, '&ordm;C', 'msg_PDTempThreshold');
  table_row_edit(gprop('pdcritemperaturethreshold') . ' [' . CS_PD_CRI_TEMP_THRESHOLD_MIN . '-' . CS_PD_CRI_TEMP_THRESHOLD_MAX . ']', $ctrl->cs_PDCriTempThreshold, 'cs_PDCriTempThreshold', 48, 30, '&ordm;C', 'msg_PDCriTempThreshold');
}

table_foot_fold();
table_tab_foot();

if (isset($_GET['clear']))
  table_row_submit(array('reset' => 0));
else
  table_row_submit($pv < USER_GROUP_POWER ? array('submit' => 2, 'resetClick' => 'resetChange()','cancelClick'=>'doCancel()') : array('resetClick' => 'resetChange()','cancelClick'=>'doCancel()'));


page_foot();
?>
  </table>
</form>

<script>
  elements=document.forms[0].elements;
  ylib_addEvt(elements['cs_CoercionEnable'],'click',new Function('coersionChanged()'));
  coersionChanged();

<?PHP if ($supporting_smart) { ?>
  ylib_addEvt(elements['cs_SmartLogEnable'],'click',new Function('smartlogChanged()'));
  smartlogChanged();
<?PHP 
} ?>

  /*oldNCQDisable=<? echo $ncqenable ?>;*/
  
  onpowerLchange=elements['cs_HDDPowerLevels'];
  idletime=elements['cs_HDDIdleTime'];
  standby=elements['cs_HDDStandbyTime'];
  stoptime=elements['cs_HDDStoppedTime'];
  onpowerLchange.onclick=adjlevelSel;
  adjlevelSel();

  function adjlevelSel()
  {
    if(onpowerLchange.value == 0){
      idletime.disabled = true;
      standby.disabled = true;
      stoptime.disabled = true;
    } else if(onpowerLchange.value == 1){
      idletime.disabled = false;
      standby.disabled = true;
      stoptime.disabled = true;
    } else if(onpowerLchange.value == 2){
      idletime.disabled = false;
      standby.disabled = false;
      stoptime.disabled = true;
    } else if(onpowerLchange.value == 3){
      idletime.disabled = false;
      standby.disabled = false;
      stoptime.disabled = false;
    }
    idletime.style.background=idletime.disabled?'#dadada':'#ffffff';
    standby.style.background=standby.disabled?'#dadada':'#ffffff';
    stoptime.style.background=stoptime.disabled?'#dadada':'#ffffff';
  }

  function coersionChanged()
  {
    cs_CoercionEnable = elements['cs_CoercionEnable'];  
    cs_CoercionMethod=elements['cs_CoercionMethod'];        
    if(cs_CoercionMethod==null)
      return;      
    if(cs_CoercionEnable.checked){
        cs_CoercionMethod.disabled= !(elements['cs_CoercionEnable'].checked);
        cs_CoercionMethod.style.background = "";
        cs_CoercionMethod.style.color = "";
    } else if(!cs_CoercionEnable.checked){
        cs_CoercionMethod.disabled= !(elements['cs_CoercionEnable'].checked);
    }
  }

<?PHP if ($supporting_smart) { ?>
  function smartlogChanged()
  {
    cs_SmartPollInterval=cs_SmartPollInterval=elements['cs_SmartPollInterval'];
    if(cs_SmartPollInterval==null)
      return;
    else
      cs_SmartPollInterval.disabled=!(elements['cs_SmartLogEnable'].checked);
  }
<?PHP 
} ?>

  function resetChange()
  {	
<?PHP if ($supporting_smart) { ?>
		cs_SmartPollInterval=elements['cs_SmartPollInterval'];
		if(cs_SmartPollInterval.disabled==1)
			cs_SmartPollInterval.disabled=0;
<?PHP 
} ?>

		cs_CoercionMethod=cs_CoercionMethod=elements['cs_CoercionMethod'];
		if(cs_CoercionMethod.disabled==1)
			cs_CoercionMethod.disabled=0;
	}
  function doCancel() {
    window.location.href = 'controller.php?tab=0&note=<?=gprop('cancelled')?>'
  }
  function validateForm()
  {
    focusItem=null;
    elements=document.forms[0].elements;
    cs_UserName=elements['cs_UserName'];
    
    if(cs_UserName==null)
      return true;

    if (!validateAlias(cs_UserName.value)) {
      msg_UserName.innerHTML='<?= gjsalert('invalidalias') ?>';
      focusItem=cs_UserName;
    } else
      msg_UserName.innerHTML='';
<?PHP if ($supporting_smart) { ?>
      cs_SmartPollInterval=elements['cs_SmartPollInterval'];
    if (cs_SmartPollInterval&&!cs_SmartPollInterval.disabled) {
      if (!validateNumber(cs_SmartPollInterval.value)
          ||cs_SmartPollInterval.value<1||cs_SmartPollInterval.value>1440) {
        msg_SmartPollInterval.innerHTML='<?= gjsalert('invalidpollinginterval') ?>';
        focusItem=cs_SmartPollInterval;
      } else
        msg_SmartPollInterval.innerHTML='';
    }
<?PHP 
} ?>

    cs_WriteBackCacheFlushInterval=elements['cs_WriteBackCacheFlushInterval'];
    if(cs_WriteBackCacheFlushInterval||!cs_WriteBackCacheFlushInterval.disabled) {
      if (!validateNumber(cs_WriteBackCacheFlushInterval.value)
          ||cs_WriteBackCacheFlushInterval.value<1||cs_WriteBackCacheFlushInterval.value>12) {
        msg_WriteBackCacheFlushInterval.innerHTML='<?= gjsalert('invalidflushinterval') ?>';
        focusItem=cs_WriteBackCacheFlushInterval;
      }
      else
        msg_WriteBackCacheFlushInterval.innerHTML='';
    }

    /*add
    cs_EnclosurePollInterval=elements['cs_EnclosurePollInterval'];
    if(validateNumber(cs_EnclosurePollInterval.value)==false||cs_EnclosurePollInterval.value<15||cs_EnclosurePollInterval.value>255) {
      msg_EnclosurePollInterval.innerHTML='<?= gjsalert('invalidpollingintervalrange') ?>';
      focusItem=cs_EnclosurePollInterval;
    } else
      msg_EnclosurePollInterval.innerHTML='';

    cs_HDDPowerLevels=elements['cs_HDDPowerLevels'];
    if(validateNumber(cs_HDDPowerLevels.value)==false||cs_HDDPowerLevels.value!=0&&cs_HDDPowerLevels.value!=1&&cs_HDDPowerLevels.value!=3) {
      msg_HDDPowerLevels.innerHTML='<?= gjsalert('invalidhddpowerlevels') ?>';
      focusItem=cs_HDDPowerLevels;
    } else
      msg_HDDPowerLevels.innerHTML=''*/;

    cs_HDDStandbyTime=elements['cs_HDDStandbyTime'];
    if (cs_HDDStandbyTime&&!cs_HDDStandbyTime.disabled) {
      if (!validateNumber(cs_HDDStandbyTime.value)
          ||cs_HDDStandbyTime.value<0||cs_HDDStandbyTime.value>1440 ) {
        msg_HDDStandbyTime.innerHTML='<?= gjsalert('invalidstandbytime') ?>';
        focusItem=cs_HDDStandbyTime;
      } else
        msg_HDDStandbyTime.innerHTML='';
    }
    cs_HDDIdleTime=elements['cs_HDDIdleTime'];
    if (cs_HDDIdleTime&&!cs_HDDIdleTime.disabled) {
      if (!validateNumber(cs_HDDIdleTime.value)
          ||cs_HDDIdleTime.value<0||cs_HDDIdleTime.value>1440) {
        msg_HDDIdleTime.innerHTML='<?= gjsalert('invalidhddidletime') ?>';
        focusItem=cs_HDDIdleTime;
      } else
        msg_HDDIdleTime.innerHTML='';
    }
    cs_HDDStoppedTime=elements['cs_HDDStoppedTime'];
    if (cs_HDDStoppedTime&&!cs_HDDStoppedTime.disabled) {
      if (!validateNumber(cs_HDDStoppedTime.value)
          ||(cs_HDDStoppedTime.value<10 && cs_HDDStoppedTime.value>0)
          ||cs_HDDStoppedTime.value<0||cs_HDDStoppedTime.value>1440) {
        msg_HDDStoppedTime.innerHTML='<?= gjsalert('invalidhddstoppedtime') ?>';
        focusItem=cs_HDDStoppedTime;
      } else
        msg_HDDStoppedTime.innerHTML='';
    }
    /*pd temperature threshold*/
    cs_PDTempThreshold = elements['cs_PDTempThreshold'];
    if(cs_PDTempThreshold){
      if(!validateNumber(cs_PDTempThreshold.value) || cs_PDTempThreshold.value<50 ||cs_PDTempThreshold.value>55 ){
        msg_PDTempThreshold.innerHTML='<?= gjsalert("invalidpdtempthreshold") ?>';
        focusItem=cs_PDTempThreshold;
      } else
        msg_PDTempThreshold.innerHTML='';
    }
    cs_PDCriTempThreshold = elements['cs_PDCriTempThreshold'];
    if(cs_PDCriTempThreshold){
      if(!validateNumber(cs_PDCriTempThreshold.value) || cs_PDCriTempThreshold.value<58 ||cs_PDCriTempThreshold.value>65 ){
        msg_PDCriTempThreshold.innerHTML='<?= gjsalert("invalidpdcritempthreshold") ?>';
        focusItem=cs_PDCriTempThreshold;
      } else
        msg_PDCriTempThreshold.innerHTML='';
    }    

    if (focusItem!=null) {
      focusItem.focus();
      return false;
    }

    return true;
  }
</script>
</body>

</html>

