<?php
/*******************************************************************************
 * Filename    : fancontrol.php
 * Description : Fan Control status page for Promise Vess A3120 (Linux port of
 *               the FanControlPatch page: the Windows FanMonitoringService/
 *               FanMonitor.log checks became promise-encld + its fanconfig).
 ******************************************************************************/
include 'tree.php';
include 'common.php';
include 'fanutil.php';

if (isset($_GET['ssid'])) SetSessionValue('ssid', $_GET['ssid']);
if (isset($_GET['ecid'])) SetSessionValue('ecid', $_GET['ecid']);
SyncSession();

$server = getServer();
$subsystem = $server->getSubsystem(1);
$encls = $subsystem->getEnclosures($_SESSION['ecid']);
$note = isset($_GET['note']) ? $_GET['note'] : null;

$config = fanLoadConfig(fanDefaultConfig());
$calib  = fanLoadCalibration();
$cpuPct = fanCalibZoneValid($calib, 'CpuFan');
$sysPct = fanCalibZoneValid($calib, 'Blower');
?>
<html>
<head><?php page_head(); ?></head>
<body>
<form method=post>
<?php form_csrf(); ?>
<table width=100% border=0 cellspacing=0 cellpadding=0 align=center>
<?php
table_title('Fan Control', 'encl', $note);
table_tab_head();
$tabTitle = tabTitleEncl($subsystem, $encls[0]);
table_tab($tabTitle);
$s = array('pad'=>2,'space'=>1,'border'=>0,'class'=>'tableContentInner');

// Fan control daemon status
table_head_fold('Fan Control Service', 'FANSVC', null, null, $s);
table_row('Service Status', fanEncldStatusHtml());
table_row('Configuration File', fanConfigPath() .
  (@file_exists(fanConfigPath()) ? '' : ' <span style="color:#888">(absent - daemon built-in defaults)</span>'));
table_row('Calibration', ($cpuPct || $sysPct)
  ? '<span style="color:green">Calibrated - speed levels are percentages of the measured RPM range</span>'
  : '<span style="color:#888">Not calibrated - speed levels are raw PWM (0-255)</span>');

// Current temperatures straight from the enclosure (same source as encl.php)
$temps = $encls[0]->_temps;
for ($i = 0; $i < count($temps); $i++) {
  $temp = $temps[$i];
  if (!$temp->_isAvaiable)
    continue;
  table_row($temp->_locationString . ' Temperature', getTemperatureString($temp->_temp));
}
table_foot_fold();

// Curve tables
$unit = 'Speed Level';
$cols = array('Temp Lower (&deg;C)', 'Temp Upper (&deg;C)', $unit);
$widths = array(33, 33, 34);

table_head_fold('CPU Fan Curve' . ($cpuPct ? ' (%)' : ' (raw PWM 0-255)'), 'CPUFAN', null, null, $s);
table_header_cols($cols, $widths);
foreach ($config['CpuFanLevelList'] as $level) {
  table_row_cols(array($level['Lower'].'&deg;C', $level['Upper'].'&deg;C',
    $level['Speedlevel'] . ($cpuPct ? '%' : '')));
}
table_foot_fold();

table_head_fold('Blower / System Fan Curve' . ($sysPct ? ' (%)' : ' (raw PWM 0-255)'), 'SYSFAN', null, null, $s);
table_header_cols($cols, $widths);
foreach ($config['SysFanLevelList'] as $level) {
  table_row_cols(array($level['Lower'].'&deg;C', $level['Upper'].'&deg;C',
    $level['Speedlevel'] . ($sysPct ? '%' : '')));
}
table_foot_fold();

table_tab_foot();
echo '<table border=0 cellspacing=2 cellpadding=0 align=center><tr>' .
  '<td><input class=input-p type=button value="Edit Fan Curves" onclick="location.href=\'fancontrolt.php?ssid=' . $_SESSION['ssid'] . '&ecid=' . $_SESSION['ecid'] . '\'"></td>' .
  '<td style="padding-left:10px"><input class=input-p type=button value="Fan Calibration" onclick="location.href=\'fancalibrate.php?ssid=' . $_SESSION['ssid'] . '&ecid=' . $_SESSION['ecid'] . '\'"></td>' .
  '</tr></table>';
page_foot();
?>
</table>
</form>
</body>
</html>
