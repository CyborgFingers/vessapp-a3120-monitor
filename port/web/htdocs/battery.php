<?php

/*******************************************************************************
 * Filename    : battery.php
 * Description : islaVista battery information.
 * Created on  : 10/09/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$server = getServer();
$subsystem = $server->getSubsystem();
$batteries = $subsystem->getBatteries($_SESSION['ecid']);
$encls = $subsystem->getEnclosures($_SESSION['ecid']);

$note = null;
if (isset($_GET['act']) && $_GET['act'] == 'recond')
  $note = gresult('batteryreconditionstartok');
else if (isset($_GET['cancel']) && $_GET['cancel'] == 'recond')
  $note = gresult('batteryreconditioncancel');
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
table_title(gprop('batteryinformation'), 'battery', $note);
table_tab_head();

$tabTitle = tabTitleEncl($subsystem, $encls[0]);
table_tab($tabTitle);

if (count($batteries) == 0) {
  table_head_fold(gprop('batteryinformation'), 'BT');
  table_row_empty(gresult('nobattery'), 2);
  table_foot_fold();
  table_tab_foot();
} else {
  for ($i = 0; $i < count($batteries); $i++) {
    $battery = $batteries[$i];
    table_head_fold(gprop('battery') . ' ' . ($battery->rpbi_u16BatteryId + 1) . ' ' . gprop('information'), 'BT' . $i);
    table_row(gprop('device'), ($battery->rpbi_u16BatteryId + 1));
    table_row(gprop('status'), $battery->mbi_BatteryState);
    table_row(gprop('batterychemistry'), $battery->mbi_DeviceChemistry);
    // table_row(gprop('manufacturingdate'), $battery->mbi_MfgDate);
    table_row(gprop('remainingcapacity'), $battery->mbi_RemainingCapacity . ' %');
    table_row(gprop('batterycelltype'), $battery->mbi_batterycelltype);
    table_row(gprop('batteryholdtime'), $battery->mbi_batteryholdtime);
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
}

page_foot();
?>
  </table>
</form>
</body>

</html>
