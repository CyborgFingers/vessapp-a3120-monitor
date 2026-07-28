<?php

/*******************************************************************************
 * Filename    : logdrvt.php
 * Description : islaVista logical drive settings.
 * Created on  : 04/01/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';
include 'ldcfg.inc';

$server = getServer();
$subsystem = $server->getSubsystem();
$logicalDrives = $subsystem->getLogDrvSettings($_SESSION['ldid']);
$logicalDrive = $logicalDrives[0];
$bdisplay = true;

$ldinfo = $subsystem->getLogicalDrive($_SESSION['ldid']);
if ($ldinfo->ldi_isNasLd == "yes")
  $baliaschange = false;
else
  $baliaschange = true;

$isSupportLUNAffinity = $subsystem->getLunAffinitySupport();

$note = isset($_GET['note']) ? $_GET['note'] : null;
$result = isset($_GET['result']) ? $_GET['result'] : 0;
$pv = getUserPrivilege($subsystem);
if ($pv < USER_GROUP_POWER)
  $note = galert('noauthtomodifyldsetting');
if (count($_POST)) {
  // PI_FB_HOST_INTERFACE_NAS?$logicalDrive->lds_LDName='Volume':$logicalDrive->lds_LDName=$_POST['lds_LDName'];
  if ($baliaschange)
    $logicalDrive->lds_LDName = $_POST['lds_LDName'];
  $logicalDrive->lds_Attribs = getLogDrvWritePolicyFromValue(isset($_POST['_writePolicy']) ? $_POST['_writePolicy'] : 0) |
    getLogDrvReadPolicyFromValue($_POST['_readPolicy']);
  if (isset($_POST['lds_PreferredOwnerControllerId']))
    $logicalDrive->lds_PreferredOwnerControllerId = $_POST['lds_PreferredOwnerControllerId'];
  else
    $logicalDrive->lds_PreferredOwnerControllerId = 2;
  $lds[0] = $logicalDrive;
  table_wait();
  $result = $subsystem->setLogDrvSettings($lds);
  if ($result)
    redirectPage($_SERVER['PHP_SELF'] . '?result=' . $result . (isset($_GET['daid']) ? ('&daid=' . $_GET['daid']) : '') . '&note=' . gresult('changeldsettingfail'));
  else
    redirectPage('logdrv.php?tab=0' . (isset($_GET['daid']) ? ('&daid=' . $_GET['daid']) : '') . '&note=' . gresult('changeldsettingok'));
  exit;
}
?>
<html>
<head>
<?php
page_head();
ob_end_flush();
$file = $_SERVER['PHP_SELF'] . (isset($_GET['daid']) ? ('?daid=' . $_GET['daid']) : '');
$_SESSION['lastfile'] = $file;
?>
</head>

<body>
<?php $action_url = validateActionUrl($file); ?>
<form method=post action="<?php echo $action_url; ?>" onSubmit="return validateForm()">
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('logicaldrive') . ' ' . $logicalDrive->lds_LDFlatId, 'logicaldrive', $note, $result);
table_tab_head();

$tabTitle = tabTitleLD($subsystem, isset($_GET['daid']) ? $_GET['daid'] : null);
table_tab($tabTitle);

table_head_fold(gprop('logicaldrivesettings'), 'LD', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
if ($bdisplay) {
  if ($baliaschange)
    table_row_edit(gprop('alias'), $logicalDrive->lds_LDName, 'lds_LDName', 32, 30, null, 'msg_LDName');
  else
    table_row_edit(gprop('aliasnasonly'), $logicalDrive->lds_LDName, 'lds_LDName', 32, 30, null, 'msg_LDName', 1);

  table_row_combo(gprop('readpolicy'), $readPolicy, '_readPolicy', ($logicalDrive->lds_Attribs & 0x06) >> 0x01);
  table_row_combo(gprop('writepolicy'), $writePolicy, '_writePolicy', $logicalDrive->lds_Attribs & 0x01);
} else {
  if ($baliaschange)
    table_row_edit(gprop('alias'), $logicalDrive->lds_LDName, 'lds_LDName', 32, 30, null, 'msg_LDName', 'true');
  else
    table_row_edit(gprop('aliasnasonly'), $logicalDrive->lds_LDName, 'lds_LDName', 32, 30, null, 'msg_LDName', 1, 'true');

  table_row_combo(gprop('readpolicy'), $readPolicy, '_readPolicy', ($logicalDrive->lds_Attribs & 0x06) >> 0x01, 'true');
  table_row_combo(gprop('writepolicy'), $writePolicy, '_writePolicy', ($logicalDrive->lds_Attribs & 0x01), 'true');
}
if ($isSupportLUNAffinity->isLunAffinitySupport) {
  if ($ldinfo->logdrvAttribLaEnable)
    table_row_combo(gprop('preferredctrlid'), $PreferredOwnerControllerId, 'lds_PreferredOwnerControllerId', $logicalDrive->lds_PreferredOwnerControllerId);
  else {
    $PreferredOwnerControllerId = array(array(2, 'N/A'));
    table_row_combo(gprop('preferredctrlid'), $PreferredOwnerControllerId, 'lds_PreferredOwnerControllerId', $logicalDrive->lds_PreferredOwnerControllerId, 'true');
  }
}
table_foot_fold();
table_tab_foot();
if ($bdisplay)
  table_row_submit($pv<USER_GROUP_POWER?array('submit'=>2,'cancelClick'=>'doCancel()'):array('cancelClick'=>'doCancel()'));
else
  table_row_submit(array('submit'=>0,'reset'=>0,'cancel'=>1,'cancelClick'=>'doCancel()'));
page_foot();
?>
  </table>
</form>

<script>
  elements=document.forms[0].elements;
  var originalReadPolicy=elements['_readPolicy'].selectedIndex;
  ylib_addEvt(elements['reset'],'click',new Function('checkDisabled()'));
  ylib_addEvt(elements['_readPolicy'],'change',new Function('RPChanged()'));
  if(<?= $bdisplay ?>)
  RPChanged();

  function validateForm()
  {
    elements=document.forms[0].elements;
    lds_LDName=elements['lds_LDName'];
    if (!validateAlias(lds_LDName.value)) {
      msg_LDName.innerHTML='<?= gjsalert('invalidalias') ?>';
      lds_LDName.focus();
      return false;
    }
    msg_LDName.innerHTML='';
    return true;
  }
  function checkDisabled()
  { 
    if(originalReadPolicy==2)
      elements['_writePolicy'].disabled=true;
    else
      elements['_writePolicy'].disabled=false;
  }
  function doCancel() {
    window.location.href = 'logdrv.php?tab=0<?=isset($_GET["daid"])?"&daid=".$_GET["daid"]:''?>&note=<?=gresult('ldsettingcancel')?>';
  }
</script>

</body>
</html>

