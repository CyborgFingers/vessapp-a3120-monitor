<?php
/*******************************************************************************
 * Filename    : fancontrolt.php
 * Description : Fan Control settings editor for Promise Vess A3120 (Linux
 *               port of the FanControlPatch editor). The editor works in
 *               percentages; what gets WRITTEN follows promise-encld's
 *               semantics (encld_curve.c):
 *                 - calibrated zone -> Speedlevel saved as the percentage
 *                   (the daemon maps % -> PWM through FanCalibration.json);
 *                 - uncalibrated zone -> Speedlevel saved as raw PWM via the
 *                   same linear estimate the Windows patch used.
 *               promise-encld hot-reloads the file on mtime change.
 ******************************************************************************/
include 'tree.php';
include 'common.php';
include 'fanutil.php';

if (isset($_GET['ssid'])) SetSessionValue('ssid', $_GET['ssid']);
if (isset($_GET['ecid'])) SetSessionValue('ecid', $_GET['ecid']);
SyncSession();

if (isset($_POST['cancel'])) {
    header('Location: fancontrol.php?ssid='.$_SESSION['ssid'].'&ecid='.$_SESSION['ecid']);
    exit;
}

$server = getServer();
$subsystem = $server->getSubsystem(1);
$encls = $subsystem->getEnclosures($_SESSION['ecid']);
$pv = getUserPrivilege($subsystem);

$configPath = fanConfigPath();
$calib      = fanLoadCalibration();
$zonePct    = array('CpuFan' => fanCalibZoneValid($calib, 'CpuFan'),
                    'Blower' => fanCalibZoneValid($calib, 'Blower'));
$isCalibrated = $zonePct['CpuFan'] || $zonePct['Blower'];
$fanEnable    = fanLoadEnable();

// Helper: percentage -> stored Speedlevel for one zone.
// Calibrated zone: store the percentage itself (encld maps it via the same
// calibration). Uncalibrated: the Windows patch's linear PWM estimate.
function pctToStored($pct, $fanType, $zonePct) {
    $pct = max(1, min(100, (int)round($pct))); // 1% increments (min 1%, was 5%-snapped)
    if ($zonePct[$fanType]) {
        return $pct;
    }
    if ($fanType == 'CpuFan') {
        return (int)(50 + ($pct - 1) / 99 * 205);
    }
    return (int)(1 + ($pct - 1) / 99 * 254);
}

// Helper: stored Speedlevel -> display percentage for one zone.
function storedToPct($level, $fanType, $zonePct) {
    if ($zonePct[$fanType]) {
        return max(1, min(100, (int)$level));
    }
    if ($fanType == 'CpuFan') {
        return max(1, min(100, (int)round((($level - 50) / 205 * 99) + 1)));
    }
    return max(1, min(100, (int)round((($level - 1) / 254 * 99) + 1)));
}

// Predefined profiles (in percentages)
$profiles = array(
    'quiet' => array(
        'label' => 'Quiet - Minimal noise, suitable for cool environments',
        'CpuFanLevelList' => array(
            array('Lower'=>0,  'Upper'=>59,  'Speedlevel'=>15),
            array('Lower'=>60, 'Upper'=>69,  'Speedlevel'=>25),
            array('Lower'=>70, 'Upper'=>79,  'Speedlevel'=>40),
            array('Lower'=>80, 'Upper'=>89,  'Speedlevel'=>60),
            array('Lower'=>90, 'Upper'=>100, 'Speedlevel'=>100)
        ),
        'SysFanLevelList' => array(
            array('Lower'=>0,  'Upper'=>59,  'Speedlevel'=>15),
            array('Lower'=>60, 'Upper'=>69,  'Speedlevel'=>25),
            array('Lower'=>70, 'Upper'=>79,  'Speedlevel'=>40),
            array('Lower'=>80, 'Upper'=>89,  'Speedlevel'=>60),
            array('Lower'=>90, 'Upper'=>100, 'Speedlevel'=>100)
        )
    ),
    'normal' => array(
        'label' => 'Normal - Balanced noise and cooling (recommended)',
        'CpuFanLevelList' => array(
            array('Lower'=>0,  'Upper'=>59,  'Speedlevel'=>25),
            array('Lower'=>60, 'Upper'=>69,  'Speedlevel'=>40),
            array('Lower'=>70, 'Upper'=>79,  'Speedlevel'=>55),
            array('Lower'=>80, 'Upper'=>89,  'Speedlevel'=>75),
            array('Lower'=>90, 'Upper'=>100, 'Speedlevel'=>100)
        ),
        'SysFanLevelList' => array(
            array('Lower'=>0,  'Upper'=>59,  'Speedlevel'=>25),
            array('Lower'=>60, 'Upper'=>69,  'Speedlevel'=>40),
            array('Lower'=>70, 'Upper'=>79,  'Speedlevel'=>55),
            array('Lower'=>80, 'Upper'=>89,  'Speedlevel'=>75),
            array('Lower'=>90, 'Upper'=>100, 'Speedlevel'=>100)
        )
    ),
    'fullspeed' => array(
        'label' => 'Full Speed - Maximum cooling, high noise',
        'CpuFanLevelList' => array(
            array('Lower'=>0,  'Upper'=>59,  'Speedlevel'=>100),
            array('Lower'=>60, 'Upper'=>69,  'Speedlevel'=>100),
            array('Lower'=>70, 'Upper'=>79,  'Speedlevel'=>100),
            array('Lower'=>80, 'Upper'=>89,  'Speedlevel'=>100),
            array('Lower'=>90, 'Upper'=>100, 'Speedlevel'=>100)
        ),
        'SysFanLevelList' => array(
            array('Lower'=>0,  'Upper'=>59,  'Speedlevel'=>100),
            array('Lower'=>60, 'Upper'=>69,  'Speedlevel'=>100),
            array('Lower'=>70, 'Upper'=>79,  'Speedlevel'=>100),
            array('Lower'=>80, 'Upper'=>89,  'Speedlevel'=>100),
            array('Lower'=>90, 'Upper'=>100, 'Speedlevel'=>100)
        )
    ),
    'custom' => array(
        'label' => 'Custom - Manually configure fan curves below'
    )
);

// Load existing config and convert stored Speedlevels to display percentages
$config = null;
$rawConfig = fanLoadConfig(null);
if ($rawConfig) {
    $config = array('CpuFanLevelList' => array(), 'SysFanLevelList' => array());
    foreach ($rawConfig['CpuFanLevelList'] as $level) {
        $config['CpuFanLevelList'][] = array(
            'Lower'      => $level['Lower'],
            'Upper'      => $level['Upper'],
            'Speedlevel' => storedToPct($level['Speedlevel'], 'CpuFan', $zonePct)
        );
    }
    foreach ($rawConfig['SysFanLevelList'] as $level) {
        $config['SysFanLevelList'][] = array(
            'Lower'      => $level['Lower'],
            'Upper'      => $level['Upper'],
            'Speedlevel' => storedToPct($level['Speedlevel'], 'Blower', $zonePct)
        );
    }
}
if (!$config) {
    $config = $profiles['normal'];
    unset($config['label']);
}

// Detect current profile
function detectProfile($config, $profiles) {
    foreach ($profiles as $key => $profile) {
        if ($key == 'custom') continue;
        if ($config['CpuFanLevelList'] == $profile['CpuFanLevelList'] &&
            $config['SysFanLevelList'] == $profile['SysFanLevelList']) {
            return $key;
        }
    }
    return 'custom';
}
$currentProfile = detectProfile($config, $profiles);

$saveMsg   = '';
$saveMsgColor = 'green';

// Handle POST save - convert percentages to stored Speedlevels before saving
if (isset($_POST['submit']) && $pv >= USER_GROUP_POWER) {
    $selectedProfile = isset($_POST['profile']) ? $_POST['profile'] : 'custom';
    // Fan enable/disable (independent of the curve profile; unchecked = disabled/nulled)
    $fanEnableOut = array();
    foreach (fanCandidates() as $fi => $fan) {
        $fanEnableOut[$fan['key']] = isset($_POST['fanen_'.$fi]);
    }
    $newConfigPct    = array('CpuFanLevelList' => array(), 'SysFanLevelList' => array());
    $newConfigOut    = array('CpuFanLevelList' => array(), 'SysFanLevelList' => array());
    $valid    = true;
    $errorMsg = '';

    if ($selectedProfile != 'custom' && isset($profiles[$selectedProfile])) {
        $newConfigPct = array(
            'CpuFanLevelList' => $profiles[$selectedProfile]['CpuFanLevelList'],
            'SysFanLevelList' => $profiles[$selectedProfile]['SysFanLevelList']
        );
    } else {
        // Parse custom percentage values
        $cpuCount = count($config['CpuFanLevelList']);
        for ($i = 0; $i < $cpuCount; $i++) {
            $lower = intval($_POST['cpu_lower_'.$i]);
            $upper = intval($_POST['cpu_upper_'.$i]);
            $pct   = intval($_POST['cpu_speed_'.$i]);
            if ($pct < 1 || $pct > 100)  { $valid=false; $errorMsg='CPU speed must be 1-100%'; break; }
            if ($upper <= $lower)          { $valid=false; $errorMsg='Upper temp must be greater than Lower'; break; }
            if ($lower < 0 || $upper > 100) { $valid=false; $errorMsg='Temperature must be between 0 and 100'; break; }
            $newConfigPct['CpuFanLevelList'][] = array('Lower'=>$lower,'Upper'=>$upper,'Speedlevel'=>$pct);
        }
        if ($valid) {
            $sysCount = count($config['SysFanLevelList']);
            for ($i = 0; $i < $sysCount; $i++) {
                $lower = intval($_POST['sys_lower_'.$i]);
                $upper = intval($_POST['sys_upper_'.$i]);
                $pct   = intval($_POST['sys_speed_'.$i]);
                if ($pct < 1 || $pct > 100) { $valid=false; $errorMsg='Blower speed must be 1-100%'; break; }
                if ($upper <= $lower)         { $valid=false; $errorMsg='Upper temp must be greater than Lower'; break; }
                if ($lower < 0 || $upper > 100) { $valid=false; $errorMsg='Temperature must be between 0 and 100'; break; }
                $newConfigPct['SysFanLevelList'][] = array('Lower'=>$lower,'Upper'=>$upper,'Speedlevel'=>$pct);
            }
        }
    }

    if ($valid) {
        // Convert percentages to the stored Speedlevel form per zone
        foreach ($newConfigPct['CpuFanLevelList'] as $level) {
            $newConfigOut['CpuFanLevelList'][] = array(
                'Lower'      => $level['Lower'],
                'Upper'      => $level['Upper'],
                'Speedlevel' => pctToStored($level['Speedlevel'], 'CpuFan', $zonePct)
            );
        }
        foreach ($newConfigPct['SysFanLevelList'] as $level) {
            $newConfigOut['SysFanLevelList'][] = array(
                'Lower'      => $level['Lower'],
                'Upper'      => $level['Upper'],
                'Speedlevel' => pctToStored($level['Speedlevel'], 'Blower', $zonePct)
            );
        }

        $newConfigOut['FanEnable'] = $fanEnableOut;
        $json = json_encode($newConfigOut, JSON_PRETTY_PRINT);
        // ponytail: plain locked write; promise-encld re-reads on mtime change
        // and its tolerant parser keeps prior curves on a torn read.
        if (@file_put_contents($configPath, $json, LOCK_EX) !== false) {
            $config         = $newConfigPct;
            $currentProfile = detectProfile($config, $profiles);
            $fanEnable      = $fanEnableOut;
            $saveMsg        = '&#10003; Settings saved. promise-encld applies them within a few seconds.';
            $saveMsgColor   = 'green';
            if (!$isCalibrated) {
                $saveMsg .= ' <span style="color:orange">&#9888; Note: no calibration found - saved as estimated raw PWM values.</span>';
            }
        } else {
            $saveMsg      = '&#10007; Error: could not write ' . $configPath;
            $saveMsgColor = 'red';
        }
    } else {
        $saveMsg      = '&#10007; Error: '.$errorMsg;
        $saveMsgColor = 'red';
    }
}

// Build profile JS data (percentages)
$profilesJson = '{';
foreach ($profiles as $key => $profile) {
    if ($key == 'custom') continue;
    $profilesJson .= '"'.$key.'":'.json_encode($profile).',';
}
$profilesJson = rtrim($profilesJson, ',').'}';

// Build the 5%-step RPM lookup for display from the PwmRpmMap calibration
$calibJson = '{}';
if ($isCalibrated) {
    $lut = array();
    foreach (array('Blower', 'CpuFan') as $zone) {
        if (!$zonePct[$zone]) continue;
        $lut[$zone] = array();
        for ($pct = 1; $pct <= 100; $pct += 1) {
            $ent = fanPctEntry($calib, $zone, $pct);
            if ($ent) $lut[$zone][strval($pct)] = $ent;
        }
    }
    $calibJson = json_encode($lut);
}
?>
<html>
<head>
<?php page_head(); ?>
<style>
#customCurves { display: <?php echo ($currentProfile=='custom'?'block':'none'); ?>; }
.rpm-hint { color:#888; font-size:11px; margin-left:4px; }
</style>
</head>
<body>
<form method=post name=fanform onsubmit="return validateFanForm()">
<?php form_csrf(); ?>
<input type=hidden name=submit value=1>
<table width=100% border=0 cellspacing=0 cellpadding=0 align=center>
<?php
table_title('Fan Control Settings', 'encl', null);
table_tab_head();
$tabTitle = tabTitleEncl($subsystem, $encls[0]);
table_tab($tabTitle);
$s = array('pad'=>2,'space'=>1,'border'=>0,'class'=>'tableContentInner');

// Calibration warning if not calibrated
if (!$isCalibrated) {
    table_head_fold('Calibration Status', 'CALWARN', null, null, $s);
    table_row('Warning', '<span style="color:orange;font-weight:bold">&#9888; Fans have not been calibrated. '.
        'Percentage values are saved as estimated raw PWM. '.
        '<a href="fancalibrate.php?ssid='.$_SESSION['ssid'].'&ecid='.$_SESSION['ecid'].'">See Fan Calibration</a> for details.</span>');
    table_foot_fold();
}

// Profile selector
table_head_fold('Fan Speed Profile', 'PROFILE', null, null, $s);
echo '<tr><td class=tableRowColOther colspan=2 style="padding:10px">';
echo '<b>Select Profile:</b>&nbsp;&nbsp;';
echo '<select name="profile" id="profileSelect" class=input-p onchange="profileChanged(this.value)" style="width:380px;padding:3px">';
foreach ($profiles as $key => $profile) {
    $sel = ($currentProfile == $key) ? ' selected' : '';
    echo '<option value="'.$key.'"'.$sel.'>'.$profile['label'].'</option>';
}
echo '</select>';
echo '</td></tr>';
table_foot_fold();

// Cooling-unit enable/disable (null a slot that isn't fitted on this chassis)
table_head_fold('Cooling Units', 'FANEN', null, null, $s);
echo '<tr><td class=tableRowColOther colspan=2 style="padding:10px">';
echo '<small style="color:#888">Disable a cooling unit that is not fitted on this chassis - it is removed from the enclosure view and raises no alarm. An <b>enabled</b> unit reading 0 RPM is treated as a fault.</small><br><br>';
foreach (fanCandidates() as $fi => $fan) {
    $ck  = fanIsEnabled($fanEnable, $fan['key']) ? ' checked' : '';
    $dis = ($pv >= USER_GROUP_POWER) ? '' : ' disabled';
    echo '<label style="display:inline-block;margin-right:24px;padding:3px">'.
         '<input type=checkbox name="fanen_'.$fi.'" value=1'.$ck.$dis.'> '.
         htmlspecialchars($fan['label']).'</label>';
}
echo '</td></tr>';
table_foot_fold();

// Custom curves
echo '<tr><td colspan=2><div id="customCurves">';

// CPU Fan Curve
table_head_fold('CPU Fan Curve', 'CPUFAN', null, null, $s);
foreach ($config['CpuFanLevelList'] as $i => $level) {
    table_row('Band '.($i+1),
        'Lower: <input type=number name="cpu_lower_'.$i.'" value="'.$level['Lower'].'" min=0 max=100 size=4 class=input-p id="cpu_lower_'.$i.'"> &deg;C &nbsp;'.
        'Upper: <input type=number name="cpu_upper_'.$i.'" value="'.$level['Upper'].'" min=0 max=100 size=4 class=input-p id="cpu_upper_'.$i.'"> &deg;C &nbsp;'.
        'Speed: <input type=number name="cpu_speed_'.$i.'" value="'.$level['Speedlevel'].'" min=1 max=100 step=1 size=4 class=input-p id="cpu_speed_'.$i.'">% '.
        '<span class=rpm-hint id="cpu_rpm_'.$i.'"></span>'
    );
}
table_foot_fold();

// Blower Fan Curve
table_head_fold('Blower / System Fan Curve', 'SYSFAN', null, null, $s);
foreach ($config['SysFanLevelList'] as $i => $level) {
    table_row('Band '.($i+1),
        'Lower: <input type=number name="sys_lower_'.$i.'" value="'.$level['Lower'].'" min=0 max=100 size=4 class=input-p id="sys_lower_'.$i.'"> &deg;C &nbsp;'.
        'Upper: <input type=number name="sys_upper_'.$i.'" value="'.$level['Upper'].'" min=0 max=100 size=4 class=input-p id="sys_upper_'.$i.'"> &deg;C &nbsp;'.
        'Speed: <input type=number name="sys_speed_'.$i.'" value="'.$level['Speedlevel'].'" min=1 max=100 step=1 size=4 class=input-p id="sys_speed_'.$i.'">% '.
        '<span class=rpm-hint id="sys_rpm_'.$i.'"></span>'
    );
}
table_row('Note', '<small style="color:#888">Speed as percentage of fan range. 1%=minimum, 100%=maximum speed.<br>'.
    ($isCalibrated ? 'RPM estimates shown based on calibration data.' : 'No calibration data - percentages are stored as estimated raw PWM.').
    '</small>');
table_foot_fold();

echo '</div></td></tr>';
table_tab_foot();
?>

<!-- Save button row -->
<tr>
  <td colspan=2 align=center style="padding:10px">
    <?php if ($pv >= USER_GROUP_POWER): ?>
    <input class=input-p type=submit value="Save Settings">
    &nbsp;&nbsp;
    <input class=input-p type=button value="Cancel" onclick="doCancel()">
    &nbsp;&nbsp;
    <input class=input-p type=button value="Fan Calibration" onclick="location.href='fancalibrate.php?ssid=<?=$_SESSION['ssid']?>&ecid=<?=$_SESSION['ecid']?>'">
    <?php if ($saveMsg): ?>
    &nbsp;&nbsp;<span style="color:<?php echo $saveMsgColor; ?>;font-weight:bold"><?php echo $saveMsg; ?></span>
    <?php endif; ?>
    <?php else: ?>
    <span style="color:red">Insufficient privilege to modify fan settings.</span>
    <?php endif; ?>
  </td>
</tr>

</table>
<?php page_foot(); ?>
</form>

<script>
var profiles  = <?php echo $profilesJson; ?>;
var calib     = <?php echo $calibJson; ?>;
var cpuCount  = <?php echo count($config['CpuFanLevelList']); ?>;
var sysCount  = <?php echo count($config['SysFanLevelList']); ?>;

// Show RPM hint next to speed input (plain text)
function getRpmHint(pct, fanType) {
    if (!calib || !calib[fanType]) return '';
    var key = String(Math.round(pct));
    if (calib[fanType][key]) {
        return '~' + calib[fanType][key].RPM + ' RPM';
    }
    return '';
}

function updateRpmHints() {
    for (var i = 0; i < cpuCount; i++) {
        var el = document.getElementById('cpu_speed_'+i);
        var hint = document.getElementById('cpu_rpm_'+i);
        if (el && hint) hint.textContent = getRpmHint(parseInt(el.value), 'CpuFan');
    }
    for (var i = 0; i < sysCount; i++) {
        var el = document.getElementById('sys_speed_'+i);
        var hint = document.getElementById('sys_rpm_'+i);
        if (el && hint) hint.textContent = getRpmHint(parseInt(el.value), 'Blower');
    }
}

function profileChanged(val) {
    var customDiv = document.getElementById('customCurves');
    if (val === 'custom') {
        customDiv.style.display = 'block';
    } else {
        customDiv.style.display = 'none';
        if (profiles[val]) {
            var cpu = profiles[val].CpuFanLevelList;
            var sys = profiles[val].SysFanLevelList;
            for (var i = 0; i < cpu.length; i++) {
                document.getElementById('cpu_lower_'+i).value = cpu[i].Lower;
                document.getElementById('cpu_upper_'+i).value = cpu[i].Upper;
                document.getElementById('cpu_speed_'+i).value = cpu[i].Speedlevel;
            }
            for (var i = 0; i < sys.length; i++) {
                document.getElementById('sys_lower_'+i).value = sys[i].Lower;
                document.getElementById('sys_upper_'+i).value = sys[i].Upper;
                document.getElementById('sys_speed_'+i).value = sys[i].Speedlevel;
            }
            updateRpmHints();
        }
    }
}

function validateFanForm() {
    var profile = document.getElementById('profileSelect').value;
    if (profile !== 'custom') return true;
    var els = document.forms[0].elements;
    for (var i = 0; i < els.length; i++) {
        if (els[i].name && els[i].name.indexOf('_speed_') >= 0) {
            var v = parseInt(els[i].value);
            if (isNaN(v) || v < 1 || v > 100) {
                alert('Speed must be between 1% and 100%');
                els[i].focus(); return false;
            }
        }
        if (els[i].name && (els[i].name.indexOf('_lower_') >= 0 || els[i].name.indexOf('_upper_') >= 0)) {
            var v = parseInt(els[i].value);
            if (isNaN(v) || v < 0 || v > 100) {
                alert('Temperature must be between 0 and 100');
                els[i].focus(); return false;
            }
        }
    }
    return true;
}

function doCancel() {
    window.location.href = 'fancontrol.php?ssid=<?=$_SESSION['ssid']?>&ecid=<?=$_SESSION['ecid']?>';
}

// Attach change handlers to speed inputs
window.onload = function() {
    for (var i = 0; i < cpuCount; i++) {
        (function(idx) {
            var el = document.getElementById('cpu_speed_'+idx);
            if (el) el.onchange = function() { updateRpmHints(); };
        })(i);
    }
    for (var i = 0; i < sysCount; i++) {
        (function(idx) {
            var el = document.getElementById('sys_speed_'+idx);
            if (el) el.onchange = function() { updateRpmHints(); };
        })(i);
    }
    updateRpmHints();
};
</script>
</body>
</html>
