<?php

/*******************************************************************************
 * Filename    : subsystemt.php
 * Description : islaVista subsystem settings.
 * Created on  : 06/20/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$months = array(
  gprop('january'), gprop('february'), gprop('march'), gprop('april'), gprop('may'), gprop('june'),
  gprop('july'), gprop('august'), gprop('september'), gprop('october'), gprop('november'), gprop('december')
);

$server = getServer();
$flag = 1;
$subsystem = $server->getSubsystem($flag);

$note = null;
$result = 0;
$resultClique = 0;
$pv = getUserPrivilege($subsystem);
if ($pv < USER_GROUP_POWER)
  $note = galert('noauthmodifysubsystemsetting');
if (count($_POST)) {
  if (!isset($_GET['time'])) {
    if (!isset($settings))
      $settings = new stdclass();
    $settings->ss_UserName = $_POST['ss_UserName'];
    $result = $subsystem->setSubsystemSettings($settings);
    if (isset($_POST['cs_RedundancyType'])) {
      $cliqueSettings->cs_RedundancyType = $_POST['cs_RedundancyType'];
      if (!isset($_POST['cs_EnableDCM']) &&
        $cliqueSettings->cs_RedundancyType == CLRT_ACTIVE_ACTIVE) {
        $cliqueSettings->cs_RedundancyType = CLRT_ACTIVE_ACTIVE_DCM;
      }
      $resultClique = $subsystem->setcliquesettings($cliqueSettings);
    }
  } else {
    $dt->tm_year = $_POST['tm_year'] - 1900;
    $dt->tm_mon = $_POST['tm_mon'];
    $dt->tm_mday = $_POST['tm_mday'];
    $dt->tm_hour = $_POST['tm_hour'];
    $dt->tm_min = $_POST['tm_min'];
    $dt->tm_sec = $_POST['tm_sec'];
    $result = $subsystem->setDateAndTime($dt);
  }
  if (isset($_GET['time'])) {
    if ($result == 0) {
      $_SESSION['time'] = time();
      redirectPage('subsystem.php?tab=0&note=' . gresult('dateandtimechangeok'));
    } else
      $note = gresult('dateandtimesettingfail');
  } else {
    if (!isset($_POST['cs_RedundancyType'])) {
      if ($result == 0) {
        redirectPage('subsystem.php?tab=0&note=' . gresult('settingchangeok'));
        exit;
      } else
        $note = gresult('settingchangefail');
    } else {
      if ($result == 0 && $resultClique == 0) {
        redirectPage('subsystem.php?tab=0&note=' . gresult('settingchangeok'));
        exit;
      } else
        $note = gresult('settingchangefail');
    }
  }
}
$settings = $subsystem->getSubsystemSettings();
$cliqueSettings = $subsystem->getCliqueSettings();
$supportDualCtrl = $subsystem->supportdualcontroller();
$dt = $subsystem->getDateAndTime();
?>

<html>
<head>
<?php
page_head();
?>
</head>
<body>
<form method=post onSubmit="return validateForm()" >
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center>
<?php
table_title(gprop('subsystemsettings'), 'subsytem', $note, $result, isset($_GET['time']) ? 'time' : 'set');
table_tab_head();
$s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner');
$tabTitle = tabTitleSubsystem($subsystem);
table_tab($tabTitle);

if (isset($_GET['time']))
  table_head_fold(gprop('dateandtimesettings'), 'SS', null, null, $s);
else
  table_head_fold(gprop('subsystemsettings'), 'SS', null, null, $s);

table_row(gprop('subsystemipaddress'), $subsystem->_IP);
if (!isset($_GET['time'])) {
  table_row_edit(gprop('alias'), $settings->ss_UserName, 'ss_UserName', 47, 30, null, 'msg_UserName');
  if ($supportDualCtrl->DualCtrlSupport) {
    if ($supportDualCtrl->activestandbyValue)
      $redundancyType = array(
      array(CLRT_ACTIVE_ACTIVE, getstringredundancytype(CLRT_ACTIVE_ACTIVE)),
      array(CLRT_ACTIVE_STANDBY, getstringredundancytype(CLRT_ACTIVE_STANDBY))
    );
    else
      $redundancyType = array(
      array(CLRT_ACTIVE_ACTIVE, getstringredundancytype(CLRT_ACTIVE_ACTIVE))
    );

    if ($cliqueSettings->cs_RedundancyType == CLRT_ACTIVE_ACTIVE_DCM) {
      $rt = CLRT_ACTIVE_ACTIVE;
      $enableDCM = false;
    } else {
      $rt = $cliqueSettings->cs_RedundancyType;
      $enableDCM = true;
    }
    table_row_combo(gprop('redundancytype'), $redundancyType, 'cs_RedundancyType', $rt, true);
    table_row_checkbox(gprop('cachemirroring'), $enableDCM, 'cs_EnableDCM');
  }
} else {
  ?>
      <tr>
        <td class=tableRowCol1><span><?= gprop('subsystemdate') ?>
        </span></td>
        <td class=tableRowColOther><span>
<?php
for ($i = 0; $i < count($months); $i++)
  $sysMonths[] = array($i, $months[$i] . '&nbsp;&nbsp;');
table_select($sysMonths, 'tm_mon', $dt->tm_mon);

for ($i = 1; $i <= 31; $i++)
  $sysDays[] = array($i, $i . '&nbsp;&nbsp;');
table_select($sysDays, 'tm_mday', $dt->tm_mday);
?>
          <input maxlength=4 class=editField1 type=text name=tm_year value=<? echo ($dt->tm_year + 1900) ?>>
        </span></td>
      </tr>
      <tr>
        <td class=tableRowCol1><span><?= gprop('subsystemtime') ?>
        </span></td>
        <td class=tableRowColOther><span>
<?php
for ($i = 0; $i < 24; $i++)
  $sysHours[] = array($i, $i . space(10));
table_select($sysHours, 'tm_hour', $dt->tm_hour);

for ($i = 0; $i < 60; $i++)
  $sysMinutes[] = array($i, sprintf(':&nbsp;%02d', $i) . space(5));
table_select($sysMinutes, 'tm_min', $dt->tm_min);
table_select($sysMinutes, 'tm_sec', $dt->tm_sec);
?>
        </span></td>
      </tr>
<?php

}
table_foot_fold();
table_tab_foot();

table_row_submit($pv<USER_GROUP_POWER?array('submit'=>2,'cancelClick'=>'doCancel()'):array('cancelClick'=>'doCancel()'));
page_foot();
?>
  </table>
</form>

<script>
  elements=document.forms[0].elements;
  oY=elements['tm_year'];
  oM=elements['tm_mon'];
  oD=elements['tm_mday'];
  oRedundancyType=elements['cs_RedundancyType'];
  oEnableDCM=elements['cs_EnableDCM'];
  oldRedundancyType=elements['cs_RedundancyType']?elements['cs_RedundancyType'].value:'';
  
  
  if(oM){
    oM.onchange=adjDateSel;
    oD.onchange=adjDateSel;
    oY.onchange=adjDateSel;
    adjDateSel();
  }

  if(oRedundancyType && oEnableDCM)
  {
    oRedundancyType.onchange=adjRTSel;
    adjRTSel();
  }
  function adjDateSel()
  {
    adjDateObj(oY,oM,oD);
  }
  function adjRTSel()
  {
    if(oRedundancyType.value == <?= CLRT_ACTIVE_ACTIVE ?>)
    {
      oEnableDCM.disabled = false;
    }
    else
    {
      oEnableDCM.disabled = true;
      oEnableDCM.checked=false;
    }
      
  }

  function validateForm()
  {
    ss_UserName=elements['ss_UserName'];
    if(ss_UserName){
      if(!validateAlias(ss_UserName.value)){
        msg_UserName.innerHTML='<?= gjsalert('invalidalias') ?>';
        ss_UserName.focus();
        return false;
      }
      msg_UserName.innerHTML='';
      
      if( typeof(elements['cs_RedundancyType'])!='undefined' &&  elements['cs_RedundancyType'].disabled==false
        && oldRedundancyType!=elements['cs_RedundancyType'].value)
      alert("<?= gjsalert('redundancytypesettingwarn'); ?>");
    }
    return true;
  }
  function doCancel(){
    window.location.href = 'subsystem.php?tab=0&note=<?=gresult("settingcancel")?>';
  }
</script>
</body>
</html>

