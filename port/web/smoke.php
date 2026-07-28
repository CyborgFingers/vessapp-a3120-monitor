<?php
/*******************************************************************************
 * smoke.php - Phase-3 live round-trip check: PHP -> opasd over the UNIX socket.
 * Not production; a bring-up verifier (Apache/PHP-FPM is Phase 4). Run with
 * opasd already listening on $OPASD_SOCK.
 ******************************************************************************/
include_once __DIR__ . '/opas_client.php';

function show($label, $v) { echo $label . ': ' . json_encode($v) . "\n"; }

/* 1) dynamic code - what api/login.php's `dc` method returns to the login JS. */
$dc = opas_call('dc');
show('dc', $dc);
if (!is_array($dc) || !isset($dc['err']) || $dc['err'] != 0) { echo "FAIL: dc\n"; exit(1); }
if (!isset($dc['code']) || !isset($dc['id']))                 { echo "FAIL: dc fields\n"; exit(1); }

/* 2) login under opasd's default-deny auth (no engine user-db off-device):
 *    expected to be rejected (status 3). Proves the auth gate is live. */
$lg = opas_call('login', array('dc' => $dc['id'], 'username' => 'administrator', 'password' => 'deadbeef'));
show('login(default-deny)', $lg);
if (!is_array($lg) || $lg['status'] != 3) { echo "FAIL: expected login denied (3)\n"; exit(1); }

/* 3) a read without a session is gated (status 2 = inactive session). */
$rd = opas_call('enumPhysicalDrives');
show('enumPhysicalDrives(no session)', $rd);
if (!is_array($rd) || $rd['status'] != 2) { echo "FAIL: expected inactive-session (2)\n"; exit(1); }

/* 4) api/opas.php's export op: without a session it is auth-gated (status 2);
 *    with a valid session it is cataloged -> UNSUPPORTED (status 6, see
 *    test_opas). Off-device we can only reach the gate. */
$ex = opas_call('export', array('sid' => 0));
show('export(no session)', $ex);
if (!is_array($ex) || $ex['status'] != 2) { echo "FAIL: export gated\n"; exit(1); }

echo "SMOKE OK: PHP<->opasd transport round-trips; dc contract + auth gate verified\n";
