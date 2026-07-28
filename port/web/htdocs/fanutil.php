<?php
/*******************************************************************************
 * Filename    : fanutil.php
 * Description : shared helpers for the fan-control pages (Linux port of the
 *               FanControlPatch pages). Paths + semantics match promise-encld
 *               (encld_curve.c / promise_encld.c):
 *                 - fanconfig.json = PROMISE_FANCONFIG (default
 *                   /etc/promise/fanconfig.json on a WebPAM install);
 *                 - calibration = PROMISE_CALIBRATION (the promise-hwmon
 *                   FanCalibration.json, zones Blower/CpuFan with
 *                   MinRPM/MaxRPM + PwmRpmMap);
 *                 - with a usable calibration zone, fanconfig Speedlevels are
 *                   PERCENTAGES of the calibrated RPM range; without one they
 *                   are raw PWM (0-255).
 *               promise-encld hot-reloads fanconfig.json on mtime change, so
 *               saves apply within one daemon tick (~5s) - no restart.
 ******************************************************************************/

function fanConfigPath()
{
  $p = getenv('PROMISE_FANCONFIG');
  return $p ? $p : '/etc/promise/fanconfig.json';
}

function fanCalibrationPath()
{
  $p = getenv('PROMISE_CALIBRATION');
  return $p ? $p : '/var/lib/promise-hwmon/data/FanCalibration.json';
}

/* Sibling files of the calibration file that promise-encld watches: the UI
 * touches calibrate.request; the daemon writes calibrate.status while it runs. */
function fanCalibRequestPath() { return dirname(fanCalibrationPath()) . '/calibrate.request'; }
function fanCalibStatusPath()  { return dirname(fanCalibrationPath()) . '/calibrate.status'; }

/* Queue a calibration sweep for promise-encld (picked up within one poll tick).
 * Returns true if the request file was written. */
function fanRequestCalibration()
{
  return @file_put_contents(fanCalibRequestPath(), strval(time()), LOCK_EX) !== false;
}

/* Decoded calibrate.status, or null. {state:running|done|aborted|error, ts:N} */
function fanCalibStatus()
{
  $p = fanCalibStatusPath();
  if (!@is_file($p)) return null;
  $j = json_decode(@file_get_contents($p), true);
  return is_array($j) ? $j : null;
}

/* Decoded FanCalibration.json, or null when absent/unparseable. */
function fanLoadCalibration()
{
  $path = fanCalibrationPath();
  if (!@file_exists($path))
    return null;
  $c = json_decode(@file_get_contents($path), true);
  return is_array($c) ? $c : null;
}

/* Usable zone per the encld_curve rules: MinRPM < MaxRPM + PwmRpmMap. */
function fanCalibZoneValid($calib, $zone)
{
  return $calib !== null
    && isset($calib[$zone]['MinRPM'], $calib[$zone]['MaxRPM'])
    && $calib[$zone]['MaxRPM'] > $calib[$zone]['MinRPM']
    && !empty($calib[$zone]['PwmRpmMap']);
}

/* encld_pct_to_pwm: target RPM = MinRPM + pct% of the range -> the nearest
 * PwmRpmMap entry (first wins on ties). array(PWM=>, RPM=>) or null. */
function fanPctEntry($calib, $zone, $pct)
{
  if (!fanCalibZoneValid($calib, $zone))
    return null;
  $z = $calib[$zone];
  $target = $z['MinRPM'] + ($pct / 100.0) * ($z['MaxRPM'] - $z['MinRPM']);
  $best = null;
  $bestd = -1;
  foreach ($z['PwmRpmMap'] as $ent) {
    if (!isset($ent['PWM'], $ent['RPM']))
      continue;
    $d = abs($ent['RPM'] - $target);
    if ($bestd < 0 || $d < $bestd) {
      $bestd = $d;
      $best = array('PWM' => (int)$ent['PWM'], 'RPM' => (int)$ent['RPM']);
    }
  }
  return $best;
}

/* fanconfig.json curves, or $default when absent/unparseable. */
function fanLoadConfig($default)
{
  $path = fanConfigPath();
  if (@file_exists($path)) {
    $c = json_decode(@file_get_contents($path), true);
    if (is_array($c) && !empty($c['CpuFanLevelList']) && !empty($c['SysFanLevelList']))
      return $c;
  }
  return $default;
}

/* The daemon's built-in DEFAULT_FANCONFIG (encld_default_curves) - what
 * promise-encld actually runs when no fanconfig.json exists. Raw PWM. */
function fanDefaultConfig()
{
  return array(
    'CpuFanLevelList' => array(
      array('Lower' => 0,  'Upper' => 59,  'Speedlevel' => 130),
      array('Lower' => 60, 'Upper' => 69,  'Speedlevel' => 160),
      array('Lower' => 70, 'Upper' => 79,  'Speedlevel' => 190),
      array('Lower' => 80, 'Upper' => 89,  'Speedlevel' => 220),
      array('Lower' => 90, 'Upper' => 100, 'Speedlevel' => 250)
    ),
    'SysFanLevelList' => array(
      array('Lower' => 0,  'Upper' => 59,  'Speedlevel' => 40),
      array('Lower' => 60, 'Upper' => 69,  'Speedlevel' => 90),
      array('Lower' => 70, 'Upper' => 79,  'Speedlevel' => 140),
      array('Lower' => 80, 'Upper' => 89,  'Speedlevel' => 190),
      array('Lower' => 90, 'Upper' => 100, 'Speedlevel' => 250)
    )
  );
}

/* promise-encld service state: 1 running / 0 stopped / -1 unknown (no
 * systemd, e.g. the container smoke). No shell: systemd keeps a
 * units/invocation:<unit> symlink in /run for every active unit. */
function fanEncldStatus()
{
  // systemd host (on-device install): authoritative unit state.
  if (is_dir('/run/systemd/system'))
    return @is_link('/run/systemd/units/invocation:promise-encld.service') ? 1 : 0;
  // container (no systemd): the entrypoint records promise-encld's pid here.
  $pid = @trim((string)@file_get_contents('/run/promise/encld.pid'));
  if ($pid !== '' && ctype_digit($pid))
    return @is_dir('/proc/' . $pid) ? 1 : 0;
  return 0;   // no systemd + no live pid -> stopped
}

/* Cooling-unit slots the SMBus backend can report, by stable key. Keys MUST
 * match the FanLoc labels smbus_encl.c collect_fans emits, so the FanEnable
 * map round-trips between this editor and the reader. A slot the operator
 * disables is nulled: removed from the enclosure view + no alarm. */
function fanCandidates()
{
  return array(
    array('key' => 'Blower',     'label' => 'Blower'),
    array('key' => 'System Fan', 'label' => 'System Fan'),
    array('key' => 'CPU Fan',    'label' => 'CPU Fan'),
  );
}

/* FanEnable map from fanconfig.json: key => bool. Absent key = enabled. */
function fanLoadEnable()
{
  $path = fanConfigPath();
  if (@file_exists($path)) {
    $c = json_decode(@file_get_contents($path), true);
    if (is_array($c) && isset($c['FanEnable']) && is_array($c['FanEnable']))
      return $c['FanEnable'];
  }
  return array();
}

/* True unless fanconfig.json explicitly disables $key. */
function fanIsEnabled($enable, $key)
{
  return !isset($enable[$key]) || $enable[$key] !== false;
}

/* Status cell markup shared by the fan + front-panel pages. */
function fanEncldStatusHtml()
{
  $sts = fanEncldStatus();
  if ($sts === 1)
    return '<span style="color:green;font-weight:bold">&#10003; Running (promise-encld)</span>';
  if ($sts === 0)
    return '<span style="color:red;font-weight:bold">&#10007; Stopped (promise-encld)</span>';
  return '<span style="color:orange;font-weight:bold">? Unknown (no systemd - promise-encld state not visible here)</span>';
}
?>
