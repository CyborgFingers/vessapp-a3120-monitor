<?php

/*******************************************************************************
 * Filename    : arrayc3.php
 * Description : islaVista disk array creation step 3.
 * Created on  : 06/01/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';
include 'ldcfg.inc';

if (isset($_POST['back'])) {
  header('Location: arrayc2.php');
  exit;
} else if (isset($_POST['cancel'])) {
  header('Location: arrays.php?tab=0&note=' . gresult('advancedconfigcancel'));
  exit;
}

$server = getServer();
$subsystem = $server->getSubsystem();
$physicalDrives = $subsystem->getPhysicalDrives();
$isSupportLUNAffinity = $subsystem->getLunAffinitySupport();
if (PI_FB_HOST_INTERFACE_NAS && ($subsystem->frontendType == PI_FB_HOST_INTERFACE_ISCSI))
  $isnas = 1;
else
  $isnas = 0;
$currentCtrlId = $subsystem->getCurrentCtrlId();
$ctrlId = $currentCtrlId->currentCtrlId;
$controller = $subsystem->getController($ctrlId);
$pds = $_SESSION['pds'];
$logicalDrives = $_SESSION['lds'];
$diskArray = $_SESSION['diskArray'];

$note = isset($_GET['note']) ? $_GET['note'] : null;
$result = isset($_GET['result']) ? $_GET['result'] : 0;
if (count($_POST)) {
  for ($i = 0; $i < count($logicalDrives ?? array()); $i++) {
    $logicalDrives[$i]->rpal_u32LDAttrib =
      getLogDrvWritePolicyFromValue($logicalDrives[$i]->_writePolicy) | getLogDrvReadPolicyFromValue($logicalDrives[$i]->_readPolicy);
    /*
    if (isset($logicalDrives[$i]->rpal_u32LDFlags) && $logicalDrives[$i]->rpal_u32LDFlags == "yes") {
      $logicalDrives[$i]->ldi_isNasLd = true;
      $logicalDrives[$i]->rpal_u32LDFlags = LD_NAS_USED;
    }
     */
    $logicalDrives[$i]->rpal_u32LDFlags = 0;
  }

  table_wait();

  $result = $subsystem->createDiskArray($diskArray, $pds, $logicalDrives);
  if ($result)
    redirectPage(htmlspecialchars($_SERVER['PHP_SELF']) . '?result=' . $result . '&note=' . gresult('createdafail'));
  else {
    $isForNas = false;
    /*
    for ($i = 0; $i < count($logicalDrives ?? array()); $i++) {
      $logicalDrive = $logicalDrives[$i];
      if (isset($logicalDrive->ldi_isNasLd) && $logicalDrive->ldi_isNasLd) {
        $isForNas = true;
        break;
      }
    }
    if ($isForNas)
      redirectPage('nas_filesysc.php?tab=1', 1);
     */
    redirectPage('arrays.php?tab=0&golmm=1&note=' . gresult('createdaok'), 1);
    SetSessionValue('golmmsession', '1');
  }
  exit;
}

$useSize = 0;
for ($i = 0; $i < count($logicalDrives ?? array()); $i++) {
  $logicalDrive = $logicalDrives[$i];
  $useSize += logSizeToPartitionSize(
    $logicalDrive->rpal_u64Size,
    count($pds ?? array()),
    $logicalDrive->rpal_u16RaidLevel,
    $logicalDrive->rpal_u16NoOfAxle,
    $logicalDrive->rpal_u32StripeSize * 1024
  );
}
$useSize *= count($pds ?? array());
?>

<html>
<head>
<?php
page_head();
?>
</head>

<body>
<form method=post>
  <?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('createda'), 'array_create', $note, $result);
table_tab_head();

$tabTitle = tabTitleArrays($subsystem);
table_tab($tabTitle);
$s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner');
table_head_fold(gprop('advstep3'), 'DA', null, null, $s);

table_row(gprop('diskarrayalias'), $diskArray->rpad_u8Name, 40);
table_row(gprop('numberofphysicaldrives'), count($pds ?? array()));
table_row(gprop('physicaldriveids'), getIDList($pds));

$pdsize = getPDConfiguableSize($physicalDrives, $pds);
table_row(gprop('configurablecapacity'), getStringSize($pdsize));
$freesize = BigIntMinus($pdsize, $useSize);
if ($freesize > (1024 * 1024))
  table_row(gprop('freecapacity'), getStringSize($freesize));
else
  table_row(gprop('freecapacity'), 0);
table_row(gprop('mediapatrol'), getEnableString($diskArray->rpad_bMediaPatrlEnable));
table_row(gprop('pdm'), getEnableString($diskArray->rpad_bPDMEnable));
table_row(gprop('PowerManagement'), getEnableString($diskArray->rpad_bPowerManagement));
table_foot_fold();

table_head_fold(gprop('newlogicaldrives'), 'LD', null, null, $s);
$columns = null;
$widths = null;
$aliastitle = gprop('alias');
if ($isnas)
  $aliastitle = gprop('aliasnas');
/*
if ($isSupportLUNAffinity->isLunAffinitySupport && ($controller->ci_LUNAffinityEnable = 'Enabled')) {
  $columns = array('#', $aliastitle, gprop('raidlevel'), gprop('capacity'), gprop('stripe'), gprop('sector'), gprop('readpolicy'), gprop('writepolicy'), gprop('preferredctrlid'));
  $isnas ? array_push($columns, gprop('fornas')) : null;

  $isnas ? $widths = array(6, 17, 10, 10, 8, 8, 10, 10, 16, 8) : $widths = array(6, 12, 10, 12, 10, 10, 12, 12, 16);
}
 */
$columns = array('#', $aliastitle, gprop('raidlevel'), gprop('capacity'), gprop('stripe'), gprop('sector'), gprop('readpolicy'), gprop('writepolicy'));
$isnas ? array_push($columns, gprop('fornas')) : null;
$isnas ? $widths = array(6, 17, 12, 12, 10, 10, 10, 10, 10) : $widths = array(10, 14, 14, 14, 10, 10, 10, 10);

table_header_cols($columns, $widths);

for ($i = 0; $i < count($logicalDrives ?? array()); $i++) {
  $logicalDrive = $logicalDrives[$i];
  $raid = getStringRAIDLevel($logicalDrive->rpal_u16RaidLevel);
  if ($logicalDrive->rpal_u16RaidLevel == RAID30 || $logicalDrive->rpal_u16RaidLevel == RAID50 || $logicalDrive->rpal_u16RaidLevel == RAID51 || $logicalDrive->rpal_u16RaidLevel == RAID55 || $logicalDrive->rpal_u16RaidLevel == RAID60)
    $raid .= (': ' . $logicalDrive->rpal_u16NoOfAxle . ' axles');
  if ($isSupportLUNAffinity->isLunAffinitySupport && ($controller->ci_LUNAffinityEnable = 'Enabled')) {
    $columns = array(
      $i + 1, $logicalDrive->rpal_u8Name, $raid, $logicalDrive->_u64SizeDisplay . $logicalDrive->_strSizeUnit, $logicalDrive->rpal_u32StripeSize . 'KB',
      getStringLogDrvSectorSize($logicalDrive->rpal_u8SectorSizeCode),
      getStringLogDrvReadPolicyValue($logicalDrive->_readPolicy),
      getStringLogDrvWritePolicy(getLogDrvWritePolicyFromValue($logicalDrive->_writePolicy) | getLogDrvReadPolicyFromValue($logicalDrive->_readPolicy)),
      getLunAffinityValue($logicalDrive->rpal_u16PreferredCtrlId)
    );
  } else {
    $columns = array(
      $i + 1, $logicalDrive->rpal_u8Name, $raid, $logicalDrive->_u64SizeDisplay . $logicalDrive->_strSizeUnit, $logicalDrive->rpal_u32StripeSize . 'KB',
      getStringLogDrvSectorSize($logicalDrive->rpal_u8SectorSizeCode),
      getStringLogDrvReadPolicyValue($logicalDrive->_readPolicy),
      getStringLogDrvWritePolicy(getLogDrvWritePolicyFromValue($logicalDrive->_writePolicy) | getLogDrvReadPolicyFromValue($logicalDrive->_readPolicy))
    );
  }
  $isnas ? array_push($columns, gprop($logicalDrive->rpal_u32LDFlags)) : null;
  table_row_cols($columns);
}

table_foot_fold();
table_tab_foot();

table_row_submit(array('reset' => 0, 'back' => 1));
page_foot();
?>
  </table>
</form>

</body>
</html>
