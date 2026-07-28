<?php
/*******************************************************************************
 * ledLive.php - live front-panel LED state (JSON {domId: imageFile}) for the
 * enclosure Front View. Reuses front.php's ledFruMap(), which derives each LED
 * from live health (fans, array + physical-drive op-status, NIC link), so the
 * front panel refreshes without a page reload. Session-gated by tree.php; output
 * is buffered + discarded so only clean JSON reaches the client.
 ******************************************************************************/
ob_start();
include 'tree.php';
include 'common.php';
include 'front.php';

$server    = getServer();
$subsystem = $server->getSubsystem();
$map = is_object($subsystem) ? ledFruMap($subsystem) : array();

ob_end_clean();
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode($map);
