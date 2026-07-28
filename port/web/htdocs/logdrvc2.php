<?php

/*******************************************************************************
 * Filename    : logdrvc2.php
 * Description : islaVista create logical drives confirmation.
 * Created on  : 08/26/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';
include 'ldcfg.inc';

if (isset($_POST['back'])) {
  header('Location: logdrvc.php');
  exit;
} else if (isset($_POST['cancel'])) {
  session_start();
  header('Location: array.php?tab=0&daid=' . $_SESSION['daid'] . '&note=' . gresult('createldcancel'));
  exit;
}

$server = getServer();
$subsystem = $server->getSubsystem();
if (PI_FB_HOST_INTERFACE_NAS && ($subsystem->frontendType == PI_FB_HOST_INTERFACE_ISCSI))
  $isnas = 1;
else
  $isnas = 0;

$physicalDrives = $subsystem->getPhysicalDrives();
$isSupportLUNAffinity = $subsystem->getLunAffinitySupport();
$currentCtrlId = $subsystem->getCurrentCtrlId();
$ctrlId = $currentCtrlId->currentCtrlId;
$controller = $subsystem->getController($ctrlId);
$logicalDrives = $_SESSION['lds'];
$diskArray = $subsystem->getDiskArray($_SESSION['daid']);
$PDs = $diskArray->acd_PDs;
$LDs = $diskArray->acd_LDs;

for ($i = 0; $i < count($PDs); $i++)
  $pds[] = $PDs[$i]->pdd_PDFlatId;
$pdsize = getPDConfiguableSize($physicalDrives, $pds);

$note = isset($_GET['note']) ? $_GET['note'] : null;
$result = isset($_GET['result']) ? $_GET['result'] : 0;

$freeFrags = $subsystem->getDiskArrayFreeFragments($_SESSION['daid']);
$fragmentEndAddr = null;
for ($i = 0; $i < count($freeFrags); $i++) {
  $fragmentEndAddr[$i] = $freeFrags[$i]->daff_u64Addr + $freeFrags[$i]->daff_u64Size - 1;
}

if (count($_POST)) {
  for ($i = 0; $i < count($logicalDrives); $i++) {
    $logicalDrives[$i]->rpal_u32LDAttrib = getLogDrvWritePolicyFromValue($logicalDrives[$i]->_writePolicy) |
      getLogDrvReadPolicyFromValue($logicalDrives[$i]->_readPolicy);
    $logicalDrives[$i]->rpal_u64FragmentEndAddr = $fragmentEndAddr[$logicalDrives[$i]->ffindex];
    if (isset($logicalDrives[$i]->rpal_u32LDFlags) && $logicalDrives[$i]->rpal_u32LDFlags == "yes") {
      $logicalDrives[$i]->ldi_isNasLd = true;
      $logicalDrives[$i]->rpal_u32LDFlags = LD_NAS_USED;
    } else
      $logicalDrives[$i]->rpal_u32LDFlags = 0;

  }

  table_wait();
  $result = $subsystem->addLogicalDrives($diskArray->acd_ArrayID, $logicalDrives);
  if ($result)
    redirectPage(htmlspecialchars($_SERVER['PHP_SELF']) . '?result=' . $result . '&note=' . gresult('createldsfail'));
  else {
    $isForNas = false;
    for ($i = 0; $i < count($logicalDrives); $i++) {
      $logicalDrive = $logicalDrives[$i];
      if (isset($logicalDrive->ldi_isNasLd) && $logicalDrive->ldi_isNasLd) {
        $isForNas = true;
        break;
      }
    }
    SetSessionValue('golmmsession', '1');
    if ($isForNas)
      redirectPage('nas_filesysc.php?tab=1', 1);

    else
      redirectPage('array.php?tab=0&golmm=1&note=' . gresult('createldok') . '&daid=' . $_SESSION['daid'], 1);
  }
  exit;
}

$useSize = 0;
// for($i=0;$i<count($LDs);$i++)
//   $useSize+=logSizeToPartitionSize($LDs[$i]->_LDSize,count($pds),$LDs[$i]->_raidLevel,$LDs[$i]->ldd_LDNoOfAxles);
for ($i = 0; $i < count($logicalDrives); $i++)
  $useSize += logSizeToPartitionSize(
  $logicalDrives[$i]->rpal_u64Size,
  count($pds),
  $logicalDrives[$i]->rpal_u16RaidLevel,
  $logicalDrives[$i]->rpal_u16NoOfAxle,
  $logicalDrives[$i]->rpal_u32StripeSize * 1024
);
$originalLogicDrives = $diskArray->acd_LDs;
for ($i = 0; $i < count($originalLogicDrives); $i++) {
  $logicalDrive = $originalLogicDrives[$i];
  $useSize += logSizeToPartitionSize(
    $logicalDrive->_LDSize,
    count($pds),
    $logicalDrive->_raidLevel,
    $logicalDrive->ldd_LDNoOfAxles,
    $logicalDrive->ldd_StripeSizeInBlocks * 512
  );
}
$useSize *= count($pds);
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
table_title(gprop('createlogicaldrives'), 'array_create', $note, $result);
table_tab_head();

$tabTitle = tabTitleArray($subsystem, $_SESSION['daid']);
table_tab($tabTitle);
$s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner');
table_head_fold(gprop('createlogicaldrivessummary'), 'DA', null, null, $s);

table_row(gprop('diskarrayid'), $diskArray->acd_ArrayID, 40);
table_row(gprop('diskarrayalias'), $diskArray->acd_ArrayName);
table_row(gprop('numberofphysicaldrives'), count($pds));
table_row(gprop('physicaldriveids'), getIDList($pds));
table_row(gprop('configurablecapacity'), getStringSize($pdsize));
table_row(gprop('freecapacity'), getStringSize($pdsize - $useSize));
table_row(gprop('mediapatrol'), getEnableString($diskArray->acd_ArrayFlags & ARRAY_FLAG_MP_ENABLE));
table_row(gprop('pdm'), getEnableString($diskArray->acd_ArrayFlags & ARRAY_FLAG_PDM_ENABLE));

table_foot_fold();

table_head_fold(gprop('newlogicaldrives'), 'LD', null, null, $s);
$columns = null;
$widths = null;
$aliastitle = gprop('alias');
if ($isnas)
  $aliastitle = gprop('aliasnas');

if ($isSupportLUNAffinity->isLunAffinitySupport && ($controller->ci_LUNAffinityEnable == 'Enabled')) {
  $columns = array('#', $aliastitle, gprop('raidlevel'), gprop('capacity'), gprop('stripe'), gprop('sector'), gprop('readpolicy'), gprop('writepolicy'), gprop('preferredctrlid'));
  $isnas ? array_push($columns, gprop('fornas')) : null;
  $isnas ? $widths = array(6, 17, 10, 10, 8, 8, 10, 10, 16, 8) : $widths = array(6, 12, 10, 12, 10, 10, 12, 12, 16);


} else {
  $columns = array('#', $aliastitle, gprop('raidlevel'), gprop('capacity'), gprop('stripe'), gprop('sector'), gprop('readpolicy'), gprop('writepolicy'));
  $isnas ? array_push($columns, gprop('fornas')) : null;
  $isnas ? $widths = array(6, 17, 12, 12, 10, 10, 10, 10, 10) : $widths = array(10, 14, 14, 14, 10, 10, 10, 10);
}

table_header_cols($columns, $widths);

for ($i = 0; $i < count($logicalDrives); $i++) {
  $logicalDrive = $logicalDrives[$i];
  $raid = getStringRAIDLevel($logicalDrive->rpal_u16RaidLevel);
  if ($logicalDrive->rpal_u16RaidLevel == RAID30 || $logicalDrive->rpal_u16RaidLevel == RAID50 || $logicalDrive->rpal_u16RaidLevel == RAID51 || $logicalDrive->rpal_u16RaidLevel == RAID55 || $logicalDrive->rpal_u16RaidLevel == RAID60)
    $raid .= (': ' . $logicalDrive->rpal_u16NoOfAxle . ' axles');
  if ($isSupportLUNAffinity->isLunAffinitySupport && ($controller->ci_LUNAffinityEnable == 'Enabled')) {
    $columns = array(
      $i + 1, $logicalDrive->rpal_u8Name, $raid, $logicalDrive->_u64SizeDisplay . $logicalDrive->_strSizeUnit, $logicalDrive->rpal_u32StripeSize . 'KB',
      getStringLogDrvSectorSize($logicalDrive->rpal_u8SectorSizeCode),
      getStringLogDrvReadPolicyValue($logicalDrive->_readPolicy),
      getStringLogDrvWritePolicy(getLogDrvWritePolicyFromValue($logicalDrive->_writePolicy) | getLogDrvReadPolicyFromValue($logicalDrive->_readPolicy)),
      getLunAffinityValue($logicalDrive->rpal_u16PreferredCtrlId)
    );
    $isnas ? array_push($columns, gprop($logicalDrive->rpal_u32LDFlags)) : null;
  } else {
    $columns = array(
      $i + 1, $logicalDrive->rpal_u8Name, $raid, $logicalDrive->_u64SizeDisplay . $logicalDrive->_strSizeUnit, $logicalDrive->rpal_u32StripeSize . 'KB',
      getStringLogDrvSectorSize($logicalDrive->rpal_u8SectorSizeCode),
      getStringLogDrvReadPolicyValue($logicalDrive->_readPolicy),
      getStringLogDrvWritePolicy(getLogDrvWritePolicyFromValue($logicalDrive->_writePolicy) | getLogDrvReadPolicyFromValue($logicalDrive->_readPolicy))
    );
    $isnas ? array_push($columns, gprop($logicalDrive->rpal_u32LDFlags)) : null;
  }
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
