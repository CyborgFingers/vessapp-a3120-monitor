<?php
/*******************************************************************************
 * Filename    : frontpanel.php
 * Description : Front Panel network-LED assignment for Promise Vess A3120.
 *               Native page replacing the Windows FrontPanelPatch WinForms
 *               tool (frontcfg): assign a host NIC to each wired front-panel
 *               network LED (ETH1 0x12 / ETH2 0x13 on the NetworkPanel
 *               PCA9555 @0x21 - the chassis wires two network LEDs).
 *               Saves the JSON map promise-encld's netled monitor reads
 *               (PROMISE_FRONTPANEL, default /etc/promise/frontpanel.json)
 *               and hot-reloads on change:
 *                 { "EthLed1": {"Interface":..,"Mac":..,"Mode":0|1|2},
 *                   "EthLed2": {...} }
 *               Modes match the Windows tool: 0 blink when connected,
 *               1 solid when connected, 2 always off. The SMBus LED path has
 *               no autonomous blinker, so "blink" lights steady.
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
$pv = getUserPrivilege($subsystem);

function frontpanelPath()
{
  $p = getenv('PROMISE_FRONTPANEL');
  return $p ? $p : '/etc/promise/frontpanel.json';
}

/* Host NICs from /sys/class/net (loopback excluded): name -> mac, state. */
function frontpanelNics()
{
  $nics = array();
  /* Container: /sys/class/net is the container netns (eth0 only); the entrypoint
   * mirrors the HOST NICs into VESSAPP_NET_ROOT (/run/promise/net) via nsenter.
   * Bare-metal host install: no mirror -> read the real /sys/class/net. */
  $netdir = getenv('VESSAPP_NET_ROOT');
  if (!$netdir || !is_dir($netdir))
    $netdir = is_dir('/run/promise/net') ? '/run/promise/net' : '/sys/class/net';
  $names = @scandir($netdir);
  if (!is_array($names))
    return $nics;
  foreach ($names as $name) {
    if ($name === '.' || $name === '..' || $name === 'lo')
      continue;
    $base = $netdir . '/' . $name;
    $mac = trim((string)@file_get_contents($base . '/address'));
    $state = trim((string)@file_get_contents($base . '/operstate'));
    if ($state === 'unknown') {
      $carrier = trim((string)@file_get_contents($base . '/carrier'));
      $state = ($carrier === '1') ? 'up' : $state;
    }
    $nics[$name] = array('mac' => $mac, 'state' => $state);
  }
  ksort($nics);
  return $nics;
}

$mapPath = frontpanelPath();
$nics = frontpanelNics();
$modes = array(0 => 'Blink when connected', 1 => 'Solid when connected', 2 => 'Always Off');

// Current map (missing/unparseable -> both LEDs unassigned)
$map = array('EthLed1' => array('Interface' => '', 'Mac' => '', 'Mode' => 0),
             'EthLed2' => array('Interface' => '', 'Mac' => '', 'Mode' => 0));
if (@file_exists($mapPath)) {
  $cur = json_decode(@file_get_contents($mapPath), true);
  if (is_array($cur)) {
    foreach (array('EthLed1', 'EthLed2') as $k) {
      if (isset($cur[$k]['Interface'])) $map[$k]['Interface'] = (string)$cur[$k]['Interface'];
      if (isset($cur[$k]['Mac']))       $map[$k]['Mac'] = (string)$cur[$k]['Mac'];
      if (isset($cur[$k]['Mode']) && in_array((int)$cur[$k]['Mode'], array(0, 1, 2)))
        $map[$k]['Mode'] = (int)$cur[$k]['Mode'];
    }
  }
}

$saveMsg = '';
$saveMsgColor = 'green';

if (isset($_POST['submit']) && $pv >= USER_GROUP_POWER) {
  $new = array();
  foreach (array('EthLed1' => 'led1', 'EthLed2' => 'led2') as $k => $f) {
    $ifname = isset($_POST[$f . '_if']) ? (string)$_POST[$f . '_if'] : '';
    $mode = isset($_POST[$f . '_mode']) ? (int)$_POST[$f . '_mode'] : 0;
    // Whitelist: only NICs enumerated above (or none) are storable.
    if ($ifname !== '' && !isset($nics[$ifname]))
      $ifname = '';
    if ($mode < 0 || $mode > 2)
      $mode = 0;
    $new[$k] = array(
      'Interface' => $ifname,
      'Mac'       => $ifname !== '' ? $nics[$ifname]['mac'] : '',
      'Mode'      => $mode
    );
  }
  // ponytail: plain locked write; promise-encld re-reads on mtime change and
  // an unreadable map just parks the LEDs off until the next good write.
  if (@file_put_contents($mapPath, json_encode($new, JSON_PRETTY_PRINT), LOCK_EX) !== false) {
    $map = $new;
    $saveMsg = '&#10003; Saved. promise-encld applies the LEDs within a few seconds.';
  } else {
    $saveMsg = '&#10007; Error: could not write ' . $mapPath;
    $saveMsgColor = 'red';
  }
}

/* One LED row: NIC selector + mode selector + live preview text. */
function frontpanelLedFold($title, $anchor, $field, $entry, $nics, $modes, $s)
{
  table_head_fold($title, $anchor, null, null, $s);

  $sel = '<select name="' . $field . '_if" class=input-p style="width:330px;padding:3px">';
  $sel .= '<option value=""' . ($entry['Interface'] === '' ? ' selected' : '') . '>(None - LED off)</option>';
  foreach ($nics as $name => $info) {
    $chosen = ($entry['Interface'] === $name) ? ' selected' : '';
    $sel .= '<option value="' . htmlspecialchars($name) . '"' . $chosen . '>'
      . htmlspecialchars('[' . strtoupper($info['state'] === 'up' ? 'UP' : '--') . '] ' . $name
        . ($info['mac'] !== '' ? '  (' . $info['mac'] . ')' : ''))
      . '</option>';
  }
  $sel .= '</select>';
  table_row('Adapter', $sel);

  $mod = '<select name="' . $field . '_mode" class=input-p style="width:330px;padding:3px">';
  foreach ($modes as $v => $label) {
    $chosen = ($entry['Mode'] === $v) ? ' selected' : '';
    $mod .= '<option value="' . $v . '"' . $chosen . '>' . $label . '</option>';
  }
  $mod .= '</select>';
  table_row('Behaviour', $mod);

  if ($entry['Interface'] === '') {
    $status = '<span style="color:#888">Unassigned &rarr; LED off</span>';
  } else if (!isset($nics[$entry['Interface']])) {
    $status = '<span style="color:orange">Interface "' . htmlspecialchars($entry['Interface'])
      . '" not present' . ($entry['Mac'] !== '' ? ' - the monitor falls back to MAC ' . htmlspecialchars($entry['Mac']) : '') . '</span>';
  } else if ($entry['Mode'] === 2) {
    $status = 'Always OFF';
  } else if ($nics[$entry['Interface']]['state'] === 'up') {
    $status = '<span style="color:green;font-weight:bold">Connected &rarr; '
      . ($entry['Mode'] === 1 ? 'SOLID' : 'BLINK') . '</span>';
  } else {
    $status = '<span style="color:#888">Disconnected &rarr; OFF</span>';
  }
  table_row('Current State', $status);
  table_foot_fold();
}
?>
<html>
<head><?php page_head(); ?></head>
<body>
<form method=post>
<?php form_csrf(); ?>
<input type=hidden name=submit value=1>
<table width=100% border=0 cellspacing=0 cellpadding=0 align=center>
<?php
table_title('Front Panel LEDs', 'encl', null);
table_tab_head();
$tabTitle = tabTitleEncl($subsystem, $encls[0]);   // page_foot() counts the global
table_tab($tabTitle);
$s = array('pad'=>2,'space'=>1,'border'=>0,'class'=>'tableContentInner');

// Monitor status
table_head_fold('Network LED Monitor', 'FPMON', null, null, $s);
table_row('Monitor Status', fanEncldStatusHtml());
table_row('Assignment File', $mapPath .
  (@file_exists($mapPath) ? '' : ' <span style="color:#888">(absent - network LEDs stay off)</span>'));
table_row('Note', '<small style="color:#888">Each front-panel network LED follows the link state of its '.
  'assigned adapter (from /sys/class/net). "Blink" lights steady on this LED path.</small>');
table_foot_fold();

// The two wired network-panel LEDs
frontpanelLedFold('ETH 1 LED (Network Panel, LED 0x12)', 'ETH1', 'led1', $map['EthLed1'], $nics, $modes, $s);
frontpanelLedFold('ETH 2 LED (Network Panel, LED 0x13)', 'ETH2', 'led2', $map['EthLed2'], $nics, $modes, $s);

table_tab_foot();
?>
<tr>
  <td colspan=2 align=center style="padding:10px">
    <?php if ($pv >= USER_GROUP_POWER): ?>
    <input class=input-p type=submit value="Save & Apply">
    <?php if ($saveMsg): ?>
    &nbsp;&nbsp;<span style="color:<?php echo $saveMsgColor; ?>;font-weight:bold"><?php echo $saveMsg; ?></span>
    <?php endif; ?>
    <?php else: ?>
    <span style="color:red">Insufficient privilege to modify front-panel settings.</span>
    <?php endif; ?>
  </td>
</tr>
</table>
<?php page_foot(); ?>
</form>
</body>
</html>
