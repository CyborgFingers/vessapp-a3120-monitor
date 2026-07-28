<?php

/*******************************************************************************
 * Filename    : enclt.php
 * Description : islaVista enclosure information.
 * Created on  : 09/02/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_POST['cancel'])) {
  header('Location: encl.php?tab=0&note=' . gresult('enclosuresettingcancel'));
  exit;
}
if (isset($_GET['ecid'])) {
  SetSessionValue('ecid', $_GET['ecid']);
  SyncSession();
}

$server = getServer();
$subsystem = $server->getSubsystem();
$enclrange = $subsystem->getEnclosuresTemptRange($_SESSION['ecid']);
$encl = $subsystem->getEnclosureSettings($_SESSION['ecid']);
$encls = $subsystem->getEnclosures($_SESSION['ecid']);
$note = null;
$result = 0;
$pv = getUserPrivilege($subsystem);
if ($pv < USER_GROUP_POWER)
  $note = gresult('noauthmodifysettings');
if (count($_POST)) {
  // $encl->cs_EnclosurePollInterval=$_POST['cs_EnclosurePollInterval'];
  if (isset($_POST['cs_TempThresholdWarning']))
    $encl->cs_TempThresholdWarning = $_POST['cs_TempThresholdWarning'];
  if (isset($_POST['cs_TempThresholdCritical']))
    $encl->cs_TempThresholdCritical = $_POST['cs_TempThresholdCritical'];
  if (isset($_POST['cs_CtrlTempThresholdWarning']))
    $encl->cs_CtrlTempThresholdWarning = $_POST['cs_CtrlTempThresholdWarning'];
  if (isset($_POST['cs_CtrlTempThresholdCritical']))
    $encl->cs_CtrlTempThresholdCritical = $_POST['cs_CtrlTempThresholdCritical'];
  $encl->es_u8PowerSwitch = 0;
  if (isset($_POST['es_u8PowerSwitch']))
    $encl->es_u8PowerSwitch = 1;
  $result = $subsystem->setEnclosureSettings($_SESSION['ecid'], $encl);
  if ($result == 0) {
    redirectPage('encl.php?tab=0&note=' . gresult('enclosurechangeok'));
  } else
    $note = gresult('enclosurechangefail');
}

?>
<html>

<head>
<?php
page_head();
?>
</head>

<body>
<form method=post onSubmit="return validateForm()"  >
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('enclosuresettings'), 'encl', $note, $result);
table_tab_head();

$tabTitle = tabTitleEncl($subsystem, $encls[0]);
table_tab($tabTitle);

table_head_fold(gprop('enclosuresettings'), 'EC', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
/*
table_row_edit(gprop('pollinginterval') . ' [15-255]', $encl->cs_EnclosurePollInterval, 'cs_EnclosurePollInterval', 20, 40, gprop('seconds'), 'msg_EnclosurePollInterval');
if (!(_isCClassCtrl($encls[0]->mpvs_CtrlType) || _isCClassJbod($encls[0]->mpvs_CtrlType))) {
  table_row_edit(gprop('enclwarningtemp') . ' [' . $enclrange->minENCLWARN . '-' . $enclrange->maxENCLWARN . ']', $encl->cs_TempThresholdWarning, 'cs_TempThresholdWarning', 20, 40, ' &ordm;C', 'msg_TempThresholdWarning');
  table_row_edit(gprop('enclcriticaltemp') . ' [' . $enclrange->minENCLCRI . '-' . $enclrange->maxENCLCRI . ']', $encl->cs_TempThresholdCritical, 'cs_TempThresholdCritical', 20, 40, ' &ordm;C', 'msg_TempThresholdCritical');
}
 */
table_row_edit(gprop('ctrlwarningtemp') . ' [' . $enclrange->minCTRLWAR . '-' . $enclrange->maxCTRLWAR . ']', $encl->cs_CtrlTempThresholdWarning, 'cs_CtrlTempThresholdWarning', 20, 40, ' &ordm;C', 'msg_CtrlTempThresholdWarning');
table_row_edit(gprop('ctrlcriticaltemp') . ' [' . $enclrange->minCTRLCRI . '-' . $enclrange->maxCTRLCRI . ']', $encl->cs_CtrlTempThresholdCritical, 'cs_CtrlTempThresholdCritical', 20, 40, ' &ordm;C', 'msg_CtrlTempThresholdCritical');
/*
table_row_checkbox(gprop('powerswitch'), $encls[0]->es_u8PowerSwitch, 'es_u8PowerSwitch');
if (isCClassCtrl($encls[0]->mpvs_CtrlType) || isCClassJbod($encls[0]->mpvs_CtrlType)) {
  $psuType = array(
    array("Single", "Single"), array("Redundant", "Redundant"), array("RedundantGreen", "RedundantGreen")
  );
  table_row_combo(gprop('psutype'), $psuType, 'cs_psuType', $encls[0]->mpvs_PSUType);
}
 */
table_foot_fold();
table_tab_foot();
table_row_submit(array('submit' => ($pv < USER_GROUP_POWER ? 2 : 1), 'cancelClick' => 'doCancel()'));
page_foot();
?>
  </table>
</form>

<script>
  maxENCLWARN=<? echo $enclrange->maxENCLWARN ?>;
  maxENCLCRI=<? echo $enclrange->maxENCLCRI ?>;
  maxCTRLCRI=<? echo $enclrange->maxCTRLCRI ?>;
  maxCTRLWAR=<? echo $enclrange->maxCTRLWAR ?>;
  minENCLWARN=<? echo $enclrange->minENCLWARN ?>;
  minENCLCRI=<? echo $enclrange->minENCLCRI ?>;
  minCTRLCRI=<? echo $enclrange->minCTRLCRI ?>;
  minCTRLWAR=<? echo $enclrange->minCTRLWAR ?>;

  // msg_EnclosurePollInterval.innerHTML='<?= gjsalert('pollingintervalappliestoall') ?>';

  function validateForm()
  {
    elements=document.forms[0].elements;
    // cs_EnclosurePollInterval=elements['cs_EnclosurePollInterval'];
    cs_TempThresholdWarning=elements['cs_TempThresholdWarning'];
    cs_TempThresholdCritical=elements['cs_TempThresholdCritical'];
    cs_CtrlTempThresholdWarning=elements['cs_CtrlTempThresholdWarning'];
    cs_CtrlTempThresholdCritical=elements['cs_CtrlTempThresholdCritical'];
    focusItem=null;
    
    if(cs_CtrlTempThresholdCritical){
      if(validateNumber(cs_CtrlTempThresholdCritical.value)==false||cs_CtrlTempThresholdCritical.value<minCTRLCRI||cs_CtrlTempThresholdCritical.value>maxCTRLCRI) {
        msg_CtrlTempThresholdCritical.innerHTML='<?= gjsalert('controllercriticaltemp') ?>'+' '+minCTRLCRI+' - '+maxCTRLCRI;
        focusItem=cs_CtrlTempThresholdCritical;
      } else if(Math.floor(cs_CtrlTempThresholdWarning.value)>=Math.floor(cs_CtrlTempThresholdCritical.value)){
        msg_CtrlTempThresholdCritical.innerHTML='<?= gjsalert('invalidcriticaltempthreshold') ?>';
        focusItem=cs_CtrlTempThresholdCritical;
      } else
        msg_CtrlTempThresholdCritical.innerHTML='';
    }
    
    if(cs_CtrlTempThresholdWarning){
      if(validateNumber(cs_CtrlTempThresholdWarning.value)==false||cs_CtrlTempThresholdWarning.value<minCTRLWAR||cs_CtrlTempThresholdWarning.value>maxCTRLWAR) {
        msg_CtrlTempThresholdWarning.innerHTML='<?= gjsalert('controllerwarningtemp') ?>'+' '+minCTRLWAR+' - '+maxCTRLWAR;
        focusItem=cs_CtrlTempThresholdWarning;
      } else
        msg_CtrlTempThresholdWarning.innerHTML='';
    }

    if(cs_TempThresholdCritical){
      if(validateNumber(cs_TempThresholdCritical.value)==false||cs_TempThresholdCritical.value<minENCLCRI||cs_TempThresholdCritical.value>maxENCLCRI) {
        msg_TempThresholdCritical.innerHTML='<?= gjsalert('enclosurecriticaltemp') ?>'+' '+minENCLCRI+' - '+maxENCLCRI;
        focusItem=cs_TempThresholdCritical;
      } else if(Math.floor(cs_TempThresholdWarning.value)>=Math.floor(cs_TempThresholdCritical.value)){
        msg_TempThresholdCritical.innerHTML='<?= gjsalert('invalidcriticaltempthreshold') ?>';
        focusItem=cs_TempThresholdCritical;
      } else
        msg_TempThresholdCritical.innerHTML='';
    }
    
    if(cs_TempThresholdWarning){
      if(validateNumber(cs_TempThresholdWarning.value)==false||cs_TempThresholdWarning.value<minENCLWARN||cs_TempThresholdWarning.value>maxENCLWARN) {
        msg_TempThresholdWarning.innerHTML='<?= gjsalert('enclosurewarningtemp') ?>'+' '+minENCLWARN+' - '+maxENCLWARN;
        focusItem=cs_TempThresholdWarning;
      } else
        msg_TempThresholdWarning.innerHTML='';
    }
    /*
    if(validateNumber(cs_EnclosurePollInterval.value)==false||cs_EnclosurePollInterval.value<15||cs_EnclosurePollInterval.value>255) {
      msg_EnclosurePollInterval.innerHTML='<?= gjsalert('validpollingintervalrange') ?>';
      focusItem=cs_EnclosurePollInterval;
    } else
      msg_EnclosurePollInterval.innerHTML='';
    */
    if (focusItem!=null) {
      focusItem.focus();
      return false;
    }

    return true;
  }

  function doCancel() {
    window.location.href = 'encl.php?tab=0&note=<?=gresult('enclosuresettingcancel')?>'
  }
</script>

</body>
</html>
