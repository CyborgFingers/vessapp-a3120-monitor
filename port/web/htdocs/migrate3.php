<?php

/*******************************************************************************
 * Filename    : migrate3.php
 * Description : islaVista disk array migration step 3.
 * Created on  : 01/03/04
 *
 * (C) Copyright Promise Technology Inc.,2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_POST['back'])) {
  header('Location: migrate2.php');
  exit;
} else if (isset($_POST['cancel'])) {
  header('Location: array.php?tab=0&note=' . gresult('migrationcancel'));
  exit;
}
function LocateLD($ldId, $lds)
{
  for ($i = 0; is_array($lds) && $i < count($lds); $i++) {
    if ($ldId == $lds[$i]->ldd_LDFlatID) {
      return $lds[$i];
    }
  }
  return null;
}
$server = getServer();
$subsystem = $server->getSubsystem();
$physicalDrives = $subsystem->getPhysicalDrives();

$logicalDrives = $_SESSION['lds'] ?? array();   // PHP 8: count(null) is a fatal TypeError
$diskArray = $subsystem->getDiskArray($_SESSION['daid'] ?? 0);
$PDs = $diskArray->acd_PDs ?? array();
$LDs = $diskArray->acd_LDs ?? array();

$pds = $_SESSION['pds'] ?? array();
for ($i = 0; $i < count($PDs); $i++)
  $pds[] = $PDs[$i]->pdd_PDFlatId;

$pdsize = getPDConfiguableSize($physicalDrives, $pds);

$note = null;
$result = 0;
if (count($_POST)) {
  $pds1 = array();
  if (isset($_SESSION['pds']))
    $pds1 = $_SESSION['pds'];
  $migrateLDs = array();
  for ($i = 0; $i < count($logicalDrives); $i++) {
    $logicalDrive = $logicalDrives[$i];
    $orgLD = LocateLD($logicalDrive->rpal_u16LdId, $LDs);
    if ($orgLD != null) {
      if ($logicalDrive->_oce != 0 ||
        $logicalDrive->rpal_u16RaidLevel != $orgLD->_raidLevel) {
        $migrateLDs[] = $logicalDrive;
      }
    }
  }
  $result = $subsystem->migrateDiskArray($diskArray->acd_ArrayID, $pds1, $migrateLDs);
  if ($result == 0) {
    redirectPage('array.php?tab=0&note=' . gresult('migrationstartok'));
    exit;
  } else
    $note = gresult('migrationstartfail');
}

$useSize = 0;
for ($i = 0; $i < count($logicalDrives); $i++)
  $useSize += logSizeToPartitionSizeNoRounding(
  $logicalDrives[$i]->rpal_u64Size,
  count($pds),
  $logicalDrives[$i]->rpal_u16RaidLevel,
  $logicalDrives[$i]->rpal_u16NoOfAxle
);
$useSize *= count($pds);
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
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center
    bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('diskarraymigration'), '', $note, $result);
table_tab_head();

$tabTitle = tabTitleArray($subsystem, $_SESSION['daid']);
table_tab($tabTitle);

table_head_fold(gprop('migrationstep3'), 'MG', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

table_row(gprop('diskarrayid'), $diskArray->acd_ArrayID, 40);
table_row(gprop('diskarrayalias'), $diskArray->acd_ArrayName);
table_row(gprop('numofpds'), count($pds));
table_row(gprop('physicaldriveids'), getIDList($pds));
table_row(gprop('configurablecapacity'), getStringSize($pdsize));
table_row(gprop('freecapacity'), getStringSize($pdsize - $useSize));
table_row(gprop('mediapatrol'), getEnableString($diskArray->acd_ArrayFlags & ARRAY_FLAG_MP_ENABLE));
table_row(gprop('pdm'), getEnableString($diskArray->acd_ArrayFlags & ARRAY_FLAG_PDM_ENABLE));

table_foot_fold();

table_head_fold(gprop('logicaldrives'), 'LD', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

$columns = array(gprop('logicaldriveid'), gprop('alias'), gprop('raidlevel'), gprop('capacity'), gprop('stripe'), gprop('sector'));
$widths = array(15, 20, 20, 15, 15, 15);
table_header_cols($columns, $widths);

for ($i = 0; $i < count($logicalDrives); $i++) {
  $logicalDrive = $logicalDrives[$i];
  $raid = getStringRAIDLevel($logicalDrive->rpal_u16RaidLevel);
  if ($logicalDrive->rpal_u16RaidLevel == RAID30 || $logicalDrive->rpal_u16RaidLevel == RAID50 || $logicalDrive->rpal_u16RaidLevel == RAID51 || $logicalDrive->rpal_u16RaidLevel == RAID55)
    $raid .= (': ' . $logicalDrive->rpal_u16NoOfAxle . ' axles');
  $columns = array(
    $logicalDrive->rpal_u16LdId, $logicalDrive->rpal_u8Name, $raid, $logicalDrive->_u64SizeDisplay . $sizeUnits[$logicalDrive->rpal_u64Size1],
    $logicalDrive->rpal_u32StripeSize . 'KB', getStringLogDrvSectorSize($logicalDrive->rpal_u8SectorSizeCode)
  );
  table_row_cols($columns);
}

table_foot_fold();
table_tab_foot();

table_row_submit2(array('reset' => 0, 'back' => 1, 'submitClick' => 'validateSubmit()'));
page_foot();
?>
  </table>
</form>

<script>
  function getreturn(ret)
  {
    var alertmsg="<? echo gjsalert('errconfirm') ?>";
    if(ret==1){
      document.forms[0].submit();
    }
    else if(ret=='cancelled')
      return false;
    else{
      alert(alertmsg);
      return false;
    }
  }

  function validateSubmit()
  {
    if (document.getElementById('windowconfirm') != null)
      return false;
    var string='<?= gjsconfirm('migrationstart') ?>';
    showConfirm(string, getreturn);
    return false;
  }
</script>

</body>
</html>
