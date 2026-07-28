<?php
/*******************************************************************************
 * Filename    : fancalibrate.php
 * Description : Fan Calibration status page for Promise Vess A3120 (Linux
 *               port of the FanControlPatch page). Reads the promise-hwmon
 *               FanCalibration.json (zones Blower/CpuFan: MinRPM/MaxRPM +
 *               PwmRpmMap) that promise-encld consumes; the 5%-step map is
 *               derived with the same nearest-RPM rule the daemon uses.
 ******************************************************************************/
include 'tree.php';
include 'common.php';
include 'fanutil.php';

if (isset($_GET['ssid'])) SetSessionValue('ssid', $_GET['ssid']);
if (isset($_GET['ecid'])) SetSessionValue('ecid', $_GET['ecid']);
SyncSession();

$server    = getServer();
$subsystem = $server->getSubsystem(1);
$encls     = $subsystem->getEnclosures($_SESSION['ecid']);

$calib   = fanLoadCalibration();
$zoneOk  = array('Blower' => fanCalibZoneValid($calib, 'Blower'),
                 'CpuFan' => fanCalibZoneValid($calib, 'CpuFan'));
$isCalibrated = $zoneOk['Blower'] || $zoneOk['CpuFan'];

$pv         = getUserPrivilege($subsystem);
$encldOk    = fanEncldStatus() === 1;
$calStatus  = fanCalibStatus();
$calRunning = ($calStatus && ($calStatus['state'] ?? '') === 'running');
$calMsg = ''; $calMsgColor = 'green';

// Handle "Run Fan Calibration": queue a request promise-encld picks up.
if (isset($_POST['runcal']) && $pv >= USER_GROUP_POWER && !$calRunning) {
    if (!$encldOk) {
        $calMsg = '&#10007; promise-encld is not running on this appliance - cannot calibrate.';
        $calMsgColor = 'red';
    } else if (fanRequestCalibration()) {
        $calMsg = '&#10003; Calibration started - the fans will ramp for ~2-3 minutes. '.
                  'Reload this page to see the result.';
        $calRunning = true;
    } else {
        $calMsg = '&#10007; Could not queue calibration (check '.
                  htmlspecialchars(fanCalibRequestPath()).' permissions).';
        $calMsgColor = 'red';
    }
}

$ssid = $_SESSION['ssid'];
$ecid = $_SESSION['ecid'];
?>
<html>
<head><?php page_head(); ?></head>
<body>
<form method=post>
<?php form_csrf(); ?>
<table width=100% border=0 cellspacing=0 cellpadding=0 align=center>
<?php
table_title('Fan Calibration', 'encl', null);
table_tab_head();
$tabTitle = tabTitleEncl($subsystem, $encls[0]);   // page_foot() counts the global
table_tab($tabTitle);
$s = array('pad'=>2,'space'=>1,'border'=>0,'class'=>'tableContentInner');

// Calibration Status
table_head_fold('Calibration Status', 'CALSTS', null, null, $s);
if ($isCalibrated) {
    table_row('Status', '<span style="color:green;font-weight:bold">&#10003; Calibrated</span>');
    if (isset($calib['CalibratedDate']))
        table_row('Calibrated Date', $calib['CalibratedDate']);
    if (isset($calib['CalibratedBy']))
        table_row('Calibrated By', $calib['CalibratedBy']);
    if ($zoneOk['Blower'])
        table_row('Blower RPM Range',
            $calib['Blower']['MinRPM'].' RPM (0%) &nbsp;&rarr;&nbsp; '.
            $calib['Blower']['MaxRPM'].' RPM (100%)');
    if ($zoneOk['CpuFan'])
        table_row('CPU Fan RPM Range',
            $calib['CpuFan']['MinRPM'].' RPM (0%) &nbsp;&rarr;&nbsp; '.
            $calib['CpuFan']['MaxRPM'].' RPM (100%)');
    table_row('Calibration File', fanCalibrationPath());
} else {
    table_row('Status', '<span style="color:orange;font-weight:bold">&#9888; Not Calibrated</span>');
    table_row('Calibration File', fanCalibrationPath() . ' <span style="color:#888">(absent or unusable)</span>');
    table_row('Note', 'Without calibration, promise-encld treats fan curve speed levels as raw PWM (0-255). '.
        'Calibration data is produced by the promise-hwmon calibration routine on the appliance.');
}
table_foot_fold();

// Run / re-run calibration
table_head_fold('Run Calibration', 'CALRUN', null, null, $s);
if ($calRunning) {
    table_row('Status', '<span style="color:orange;font-weight:bold">&#8635; Calibration in progress</span> '.
        '&mdash; the fans are ramping 5%&nbsp;&rarr;&nbsp;100%; this takes ~2-3 minutes. '.
        '<a href="fancalibrate.php?ssid='.$ssid.'&ecid='.$ecid.'">Reload</a> to check the result.');
} else {
    table_row('Fan Daemon', fanEncldStatusHtml());
    if ($pv >= USER_GROUP_POWER) {
        $disabled = $encldOk ? '' : ' disabled';
        table_row('Calibrate',
            '<input class=input-p type=submit name="runcal" value="Run Fan Calibration"'.$disabled.
            ' onclick="return confirm(\'Calibration ramps every fan from 5% to 100% over ~2-3 minutes. Continue?\')">'.
            '<br><small style="color:#888">Measures each fan\'s PWM&nbsp;&rarr;&nbsp;RPM curve so the percentage '.
            'fan curves map to real speeds. Run once per chassis, or after fitting a different fan (any brand).</small>');
    } else {
        table_row('Calibrate', '<span style="color:#888">Requires power-user privilege.</span>');
    }
    if ($calMsg)
        table_row('Result', '<span style="color:'.$calMsgColor.';font-weight:bold">'.$calMsg.'</span>');
    if ($calStatus && isset($calStatus['state']) && $calStatus['state'] !== 'running')
        table_row('Last Run', htmlspecialchars($calStatus['state']).
            (isset($calStatus['ts']) ? ' at '.date('Y-m-d H:i:s', (int)$calStatus['ts']) : ''));
}
table_foot_fold();

// Calibration map derived exactly the way the daemon maps % -> PWM
if ($isCalibrated) {
    table_head_fold('Calibration Map', 'CALMAP', null, null, $s);
    echo '<tr><td class=tableRowColOther colspan=2 style="padding:10px">';
    echo '<table width=100% border=0 cellspacing=2 cellpadding=3>';
    echo '<tr class="tableHeaderCols"><th>%</th>';
    echo '<th>Blower PWM</th><th>Blower RPM</th>';
    echo '<th>CPU Fan PWM</th><th>CPU Fan RPM</th></tr>';
    for ($pct = 5; $pct <= 100; $pct += 5) {
        $b = fanPctEntry($calib, 'Blower', $pct);
        $c = fanPctEntry($calib, 'CpuFan', $pct);
        echo '<tr class="tableRowColOther"><td align=center><b>'.$pct.'%</b></td>';
        echo '<td align=center>'.($b ? $b['PWM'] : '-').'</td><td align=center>'.($b ? $b['RPM'].' RPM' : '-').'</td>';
        echo '<td align=center>'.($c ? $c['PWM'] : '-').'</td><td align=center>'.($c ? $c['RPM'].' RPM' : '-').'</td></tr>';
    }
    echo '</table></td></tr>';
    table_foot_fold();
}

table_tab_foot();
echo '<tr><td colspan=2 align=center style="padding:10px">';
echo '<input class=input-p type=button value="Back to Fan Control" ';
echo 'onclick="location.href=\'fancontrol.php?ssid='.$ssid.'&ecid='.$ecid.'\'">';
echo '</td></tr>';
page_foot();
?>
</table>
</form>
</body>
</html>
