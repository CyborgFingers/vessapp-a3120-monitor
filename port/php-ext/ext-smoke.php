<?php
/*
 * ext-smoke.php - exercises the php_i2 native object model in isolation (no web
 * tier), the way tree.php does: getdynamiccode() -> I2ClientEx() login ->
 * getSubsystem() -> enumerate*(). Proves the extension serves the seeded fixture
 * substrate. Run: php -d extension=modules/i2.so ext-smoke.php
 */
function ok($c, $m) { if (!$c) { fwrite(STDERR, "FAIL: $m\n"); exit(1); } echo "ok: $m\n"; }

ok(extension_loaded('i2'), "extension_loaded('i2')");

/* 1) login challenge */
$dc = getdynamiccode();
ok(is_array($dc) && $dc['err'] == 0, "getdynamiccode err==0");
ok(isset($dc['code']) && isset($dc['id']), "dc has code+id");

/* 2) login as the fixture operator (I2ClientEx: $sid arg is the dc id) */
$client = I2ClientEx('administrator', 'anepassword', $dc['id']);
ok(is_object($client) && $client->status == 0, "I2ClientEx status==0");
ok($client->sessionId > 0, "sessionId issued");

/* 3) wrong user is rejected by the auth gate (fresh dc; dc is one-time) */
$dc2 = getdynamiccode();
$bad = I2ClientEx('intruder', 'x', $dc2['id']);
ok($bad->status != 0, "wrong user rejected");

/* 4) subsystem summary */
$ss = $client->getSubsystem(1);
ok($ss->u32Ret == 0, "getSubsystem u32Ret==0");
ok($ss->ssi_CustModel === 'Vess A3120P', "ssi_CustModel");

/* 5) enclosure + RAID reads come from the seeded substrate */
$pds = $ss->enumeratePhysicalDrives();
ok(count($pds) == 1, "1 physical drive");
ok($pds[0]->pdi_FlatId == 5, "pdi_FlatId==5");
ok($pds[0]->pdi_DriveModelNo === 'ST4000NM0023', "pd model");

$lds = $ss->enumerateLogicalDrives();
ok(count($lds) == 1 && $lds[0]->ldi_FlatId == 2, "ld id==2");
ok($lds[0]->ldi_Alias === 'SystemVolume', "ld alias");

$das = $ss->enumerateDiskArrays();
ok(count($das) == 1 && $das[0]->acd_ArrayID == 7, "array id==7");

$encls = $ss->getEnclosures(0);
ok(is_array($encls) && count($encls) == 1, "getEnclosures returns a 1-element array");
$encl = $encls[0];
ok($encl->mpvs_EnclosureType == 0x57, "model byte 'W' -> mpvs_EnclosureType 0x57");
ok($encl->_EnclosureTypeString === 'ENC_TYPE_VESS_1U4BAY', "enclosure type string");
ok(count($encl->_slots) == 4, "1U4 model grid has 4 slots");
ok($encl->maxNumOfPhyDrvSlots == 4 && $encl->maxNumOfControllers == 1,
   "golden maxima: 4 slots / 1 controller");
ok($encl->maxNumOfFans == 1 && $encl->maxNumOfBlowers == 2 && $encl->maxNumPSU == 1
   && $encl->maxNumOfTempSensor == 2 && $encl->maxNumOfVoltageSensors == 4,
   "maxima: 1 CPU fan / 2 blowers / 1 PSU / 2 temps / 4 volts");
ok(count($encl->_fans) == 1, "A3120: tach0 is the CPU fan; the other 2 tachs are blowers");
ok(count($encl->_fans) == 3 && $encl->_fans[0]->_speed == 3013
   && $encl->_fans[1]->_speed == 5625 && $encl->_fans[2]->_speed == 5625,
   "fan rpm 3013/5625/5625 (golden)");
ok($encl->_fans[0]->_speedThreshold == 600 && $encl->_fans[0]->_location == "CPU"
   && $encl->_fans[1]->_location == "Cooling Unit", "fan healthy >600 RPM, Fan 1=CPU / Fan 2=Cooling Unit");
ok(count($encl->_temps) == 2 && $encl->_temps[0]->_temp == 39
   && $encl->_temps[0]->_location === 'CPU'
   && $encl->_temps[1]->_location === 'Controller', "temps: CPU 39C + Controller");
ok($encl->_ctrlTempWarning == 90 && $encl->_ctrlTempCritical == 100,
   "ctrl temp thresholds 90/100 (golden)");
ok(count($encl->_volts) == 4 && $encl->_volts[0]->_sensorType === '1.0V'
   && $encl->_volts[0]->_healthyThreshold === '(0.0 - 1.3) V'
   && $encl->_volts[0]->_volt === '0.2V'
   && $encl->_volts[2]->_volt === '12.3V', "4 golden voltage rails");
ok(count($encl->_psus) == 1
   && $encl->_psus[0]->_statusStr === 'Powered On and Functional', "golden PSU status");
ok(CClassCtrlType($encl->mpvs_CtrlType) === '', "CClassCtrlType: A3120 is not C-class");

$users = $ss->getUsers();
ok($users->errorcode == 0, "getUsers errorcode==0");
ok($users->userlist[0]->username === 'administrator', "fixture user");

$ctrl = $ss->getController(0);
ok(strpos($ctrl->ci1_PriSWBuildDate, '2026') !== false, "controller build date");
ok($ctrl->ci_PDTempThreshold == 55 && $ctrl->ci_PDCriTempThreshold == 65,
   "drive temp thresholds 55/65 (golden)");

/* 5b) RAID write verbs under the DEFAULT (sgio) backend stay the cataloged
 *     empty-array no-op - byte-identical to the old __call fallthrough. The
 *     mdadm write path only wakes under VESSAPP_RAID_BACKEND=mdadm|fixture. */
$r = $ss->createDiskArray(new stdClass(), array(1), array(new stdClass()));
ok(is_array($r) && count($r) == 0, "sgio createDiskArray stays the cataloged no-op");
$r = $ss->deleteDiskArrays(array(7));
ok(is_array($r) && count($r) == 0, "sgio deleteDiskArrays stays the cataloged no-op");
$ld = $ss->getLogicalDrive(2);
ok(is_object($ld) && $ld->ldi_Alias === 'SystemVolume', "getLogicalDrive(2) finds the fixture LD");
ok(is_string($ld->ldi_RaidLevel), "ldi_RaidLevel is a display string (vendor parity)");

/* 6) getLastError(): PIERR_NO_ERROR while healthy, INACTIVE_SESSION after a
 *    reconnect against a dead session id (daemon.php:39's poll contract). */
ok(getLastError() == PIERR_NO_ERROR, "getLastError()==PIERR_NO_ERROR");
I2Client('administrator', '', 999999);   /* no such session */
ok(getLastError() == PIERR_INACTIVE_SESSION, "getLastError()==PIERR_INACTIVE_SESSION after dead sid");

echo "EXT SMOKE OK: php_i2 object model serves the fixture substrate\n";
