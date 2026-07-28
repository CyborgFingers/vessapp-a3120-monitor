<?php

/*******************************************************************************
 * Filename    : phydrvst.php
 * Description : islaVista physical drives settings.
 * Created on  : 09/21/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_POST['cancel'])) {
  header('Location: phydrvs.php?tab=0&note=' . gresult('pdsettingscancel'));
  exit;
}

$server = getServer();
$subsystem = $server->getSubsystem();
$supportDualCtrl = $subsystem->supportdualcontroller();
$currentCtrlId = $subsystem->getCurrentCtrlId();
$ctrlId = $currentCtrlId->currentCtrlId;
$controller = $subsystem->getController($ctrlId);
$phydrvs = $subsystem->getPhyDrvSettings();
$capa = $subsystem->getDeviceCapabilities();
if ($capa->SASDriveSupport) {
  $phydrvsas = $subsystem->getPhyDrvSettings(INVALID_PHYDRV_ID, PD_PAGE_SAS);
}
$note = null;
$result = 0;
$pv = getUserPrivilege($subsystem);
if ($pv < USER_GROUP_POWER)
  $note = galert('noauthtomodifysettings');
if (count($_POST)) {
  $phydrvs->pds1_WriteCachedEnable = isset($_POST['pds1_WriteCachedEnable']) ? 1 : 0;
  $phydrvs->pds1_RLASupportEnable = isset($_POST['pds1_RLASupportEnable']) ? 1 : 0;
  $phydrvs->pds1_TransferMode = $_POST['pds1_TransferMode'];
  $phydrvs->pds1_EnableCmdQueuing = isset($_POST['pds1_EnableCmdQueuing']) ? 1 : 0;
  $phydrvs->pds1_MediumErrorThreshold = isset($_POST['pds1_MediumErrorThreshold']) ? $_POST['pds1_MediumErrorThreshold'] : 0;
  $result = $subsystem->setPhyDrvSettings(-1, $phydrvs);

  if ($result == 0) {
    redirectPage('phydrvs.php?tab=0&note=' . gresult('pdsettingschangeok'));
    exit;
  } else
    $note = gresult('pdssettingschangefail');
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
<form method=post onSubmit="return validateForm()" action="<?php echo $action_url; ?>">
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center
    bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('phydrvsettingsforallphydrvs'), null, $note, $result);
table_tab_head();

$tabTitle = tabTitlePDs(isset($_GET['daid']) ? $_GET['daid'] : null);
table_tab($tabTitle);

table_head_fold(gprop('satasettings'), 'sata', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
table_row_checkbox(gprop('writecachedenabled'), $phydrvs->pds1_WriteCachedEnable, 'pds1_WriteCachedEnable');
table_row_checkbox(gprop('readlookaheadenabled'), $phydrvs->pds1_RLASupportEnable, 'pds1_RLASupportEnable');
if (!_isCClassCtrl($controller->ci_ProductID))
  table_row_checkbox(gprop('commandqueuingenabled'), $phydrvs->pds1_EnableCmdQueuing, 'pds1_EnableCmdQueuing');
$DMA = $subsystem->getPhyDrvDMAModeList();
for ($i = 0; $i < count($DMA); $i++)
  $mode[] = array($DMA[$i]->_mode, $DMA[$i]->_strMode);
table_row_combo(gprop('dmamode'), $mode, 'pds1_TransferMode', $phydrvs->pds1_TransferMode);
table_foot_fold();

table_tab_foot();
table_row_submit($pv < USER_GROUP_POWER ? array('submit' => 2) : null);
page_foot();
?>
  </table>
</form>
<script>

	function validateForm()
  {
    focusItem=null;
    elements=document.forms[0].elements;

    if (focusItem==null) {
      return true;
    }
    else {
      focusItem.focus();
    }
    return false;
  }

</script>
</body>
</html>
