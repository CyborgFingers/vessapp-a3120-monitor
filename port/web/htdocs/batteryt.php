<?php

/*******************************************************************************
 * Filename    : batteryt.php
 * Description : islaVista start battery reconditioning.
 * Created on  : 09/01/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_POST['cancel'])) {
  header('Location: battery.php?cancel=recond');
  exit;
}

$server = getServer();
$subsystem = $server->getSubsystem();
$encls = $subsystem->getEnclosures($_SESSION['ecid']);
$supportDualCtrl = $subsystem->supportdualcontroller();
$note = null;
$result = 0;
if (count($_POST)) {
  $id = 0;
  if (isset($_POST['deviceid']))
    $id = $_POST['deviceid'];
  $result = $subsystem->startBatteryRecond($_SESSION['ecid'], $id);
  if ($result == 0) {
    redirectPage('battery.php?act=recond');
    exit;
  } else
    $note = gresult('batteryreconditionstartfail');

}
$battery = null;
$batterygetid = 0;
if (isset($_GET['deviceid'])) {
  $batterygetid = $_GET['deviceid'];
  $batteries = $subsystem->getBatteries($_SESSION['ecid'], $_GET['deviceid']);
  if (isset($batteries[0]))
    $battery = $batteries[0];
  else
    $battery = null;
}
$totalbattery = $subsystem->getBatteries($_SESSION['ecid']);
?>

<html>
<head>
<?php
page_head();
function batteryInfo($battery)
{
  table_head_fold(gprop('startbatteryrecondition'), 'BTD');
  table_row(gprop('device'), ($battery->rpbi_u16BatteryId + 1));
  table_row(gprop('status'), $battery->mbi_BatteryState);
  table_row(gprop('batteryname'), $battery->mbi_DeviceName);
  table_row(gprop('batterychemistry'), $battery->mbi_DeviceChemistry);
  table_row(gprop('manufacturer'), $battery->mbi_MfgName);
  // table_row(gprop('manufacturingdate'), $battery->mbi_MfgDate);
  table_row(gprop('remainingcapacity'), $battery->mbi_RemainingCapacity . ' %');
  table_row(gprop('serialnumber'), $battery->mbi_SerialNum);
  table_row(
    gprop('temperaturethresholddischarge'),
    getTemperatureString($battery->mbi_TempThreshholdDischarge)
  );
  table_row(
    gprop('temperaturethresholdcharge'),
    getTemperatureString($battery->mbi_TempthresholdCharge)
  );
  table_row(
    gprop('batterytemperature'),
    getTemperatureString($battery->mbi_BatteryTemperature)
  );
  table_row(gprop('cyclecount'), $battery->mbi_CycleCount);
  table_row(gprop('voltage'), $battery->mbi_BatteryVoltage . ' mV');
  table_row(gprop('batterycurrent'), $battery->mbi_BatteryCurrent . ' mA');
  table_foot_fold();
  table_tab_foot();
}
?>
</head>

<body>
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
<?php form_csrf(); ?>
  <table width=100% border=1 cellspacing=0 cellpadding=2 align=center
    bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('batteryrecondition'), 'battery', $note, $result);
table_tab_head();

$tabTitle = tabTitleEncl($subsystem, $encls[0]);
table_tab($tabTitle);

if (count($totalbattery) == 0) {
  table_head_fold(gprop('startbatteryrecondition'), 'BT');
  table_row_empty(gresult('nobattery'), 2);
  table_foot_fold();
  table_tab_foot();
} else {
  /*
  for ($i = 0; $i < count($totalbattery); $i++)
    $batteryId[] = array($totalbattery[$i]->rpbi_u16BatteryId, $totalbattery[$i]->rpbi_u16BatteryId + 1);
   */
  if ($supportDualCtrl->DualCtrlSupport) {
    $batteryId = array(array(0, 1), array(1, 2));
  } else {
    $batteryId = array(array(0, 1));
  }
  table_head_fold(gprop('battery'), 'BT');
  table_row_combo(gprop('deviceid'), $batteryId, 'deviceid', $batterygetid);
  table_foot_fold();
  table_tab_foot();

  if ($battery != null)
    batteryInfo($battery);
  else {
    table_head_fold(gprop('startbatteryrecondition'), 'BT');
    table_row_empty(gresult('nobattery'), 2);
    table_foot_fold();
    table_tab_foot();
  }
  if ($battery != null && $battery->mbi_BatteryState != 'Malfunction' && $battery->mbi_BatteryState != 'Recondition')
    table_row_submit(array('reset' => 0));
}

page_foot();
?>
  </table>
</form>

<script type="text/javascript">
  elements=document.forms[0].elements;
  ylib_addEvt(elements['deviceid'],'change',new Function('deviceChanged()'));
  function deviceChanged()
  {
    window.location.href='batteryt.php?deviceid='+elements['deviceid'].value;
  }
</script>
</body>

</html>
