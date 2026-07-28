<?php

/*******************************************************************************
 * Filename    : fru.php
 * Description : islaVista enclosure FRU information.
 * Created on  : 09/01/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$server = getServer();
$subsystem = $server->getSubsystem();
$frus = $subsystem->getFRUs($_SESSION['ecid']);
$encls = $subsystem->getEnclosures($_SESSION['ecid']);
function showFRU($fru, $header, $id)
{
  table_head_fold(
    $header . ' ID ' . ($fru->rpfi_u16FruId + 1),
    $id . $fru->rpfi_u16FruId
  );

  table_row(gprop('device'), $fru->rpfi_u16FruId + 1);
  table_row(gprop('serialnumber'), $fru->mfru_MfgSerialNo);
  // table_row(gprop('oemname'), $fru->mfru_OEMName);
  // table_row(gprop('oemmodel'), $fru->mfru_OEMModel);
  table_row(gprop('partnumber'), $fru->mfru_MfgPartNo);
  table_row(gprop('revisionnumber'), $fru->mfru_MfgRev);
  // table_row(gprop('manufacturingdate'), $fru->mfru_MfgDate);

  if ($fru->rpfi_u16FruType == FRU_TYPE_BP) {
    table_row(gprop('wwn'), $fru->mfru_WWN);
    table_row(gprop('pdsn'), $fru->mfru_ProductBarcodeSerialNo);
  }

  table_foot_fold();
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
<form method=post action="<?php echo $action_url; ?>">
  <table width=100% border=1 cellspacing=0 cellpadding=2 align=center
    bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('vpdofrus'), 'fru');
table_tab_head();

$tabTitle = tabTitleEncl($subsystem, $encls[0]);
table_tab($tabTitle);

for ($i = 0; $i < count($frus[FRU_TYPE_MB] ?? array()); $i++) {
  showFRU($frus[FRU_TYPE_MB][$i], gprop('controller'), 'MB');
  if ($i == 0)
    table_tab_foot();
}

for ($i = 0; $i < count($frus[FRU_TYPE_SEP] ?? array()); $i++)
  showFRU($frus[FRU_TYPE_SEP][$i], gprop('sep'), 'SEP');

for ($i = 0; $i < count($frus[FRU_TYPE_BBU] ?? array()); $i++)
  showFRU($frus[FRU_TYPE_BBU][$i], gprop('bbu'), 'BBU');

for ($i = 0; $i < count($frus[FRU_TYPE_FAN] ?? array()); $i++)
  showFRU($frus[FRU_TYPE_FAN][$i], gprop('coolingunit'), 'FAN');

for ($i = 0; $i < count($frus[FRU_TYPE_PSU] ?? array()); $i++)
  showFRU($frus[FRU_TYPE_PSU][$i], gprop('powersupplyunit'), 'PSU');

for ($i = 0; $i < count($frus[FRU_TYPE_BP] ?? array()); $i++)
  showFRU($frus[FRU_TYPE_BP][$i], gprop('backplane'), 'BP');

for ($i = 0; $i < count($frus[FRU_TYPE_DAUGHTERCARD] ?? array()); $i++)
  showFRU($frus[FRU_TYPE_DAUGHTERCARD][$i], gprop('daughtercard'), 'DT');

table_tab_foot();
page_foot();
?>

  </table>
</form>
</body>

</html>
