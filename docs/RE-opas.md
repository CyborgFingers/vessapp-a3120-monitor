# RE findings: the OPAS API/IPC layer + `opasd` (Phase 3)

The bridge between the PHP web tier and the in-band substrate for the **Vess
A3120**. Recovered primarily by **reading the shipped PHP** (readable source),
cross-checked against the shipped binaries with `strings`/imports. Builds the
Linux `opasd` daemon that collapses the Windows PHP→service→engine path onto the
Phase-2/2b substrate (`libi2` + `libi2bdg`).

Confidence: **high** = literal in the PHP source or a confirmed binary string;
**med** = inferred from cross-references; **UNRESOLVED** = not recoverable from the
shipped artifacts (lives in the RAID controller firmware / a native binary whose
internals were not decompiled), flagged and never invented.

Provenance paths are relative to `windows-src/rootfs/PromiseApp/`.

---

## 0. TL;DR — what "OPAS" actually is (corrects the Phase-3 premise)

The task framed `opas.exe`/`opassvc.exe` as *the* web API service. Reading the
artifacts shows two **distinct** things sharing the "OPAS" name:

1. **The web API bridge (what Phase 3 must port)** is the **PHP extension
   `WebServer/php/ext/php_i2.dll`** — a Zend extension exposing native functions
   `I2Client`, `I2ClientEx`, `getdynamiccode`, and rich client/subsystem objects
   whose methods (`getSubsystem`, `enumeratePhysicalDrives`, …) wrap the
   `pri2apiv4.dll` `i2_*` engine **in-process**. The PHP↔engine boundary on
   Windows is a **C ABI (zvals), not a wire protocol** — there is no XML/socket
   format to match at that seam. (Evidence: `tree.php:97` `extension_loaded('i2')`,
   `tree.php:144` `I2ClientEx(...)`, `tree.php:157` `I2Client(...)`; `php_i2.dll`
   strings are almost entirely `i2_*` engine calls — `i2_getPhyDrvInfo`,
   `i2_getLogDrvInfo`, `_getControllers - u32SessionId = %d`, etc.)

2. **`opas/opas.exe`** is a **standalone offline USB-provisioning tool** ("OPAS"
   = the offline admin/provisioning flow), *not* the web bridge. Its strings:
   `No OPAS folder in usb root`, `%s\OPAS_plan.xml`, `%s\OPAS_key.enc`,
   `\userdb.xml`, `Clean OPAS stats. Set LED and Buzzer off.`,
   `Found promise scisi device: %s`, and it shells out to
   `clitest -u %s -p %s -q %u -L "%s" -C %s` (i.e. drives `i2.exe`). It reads a
   plan/key off a USB stick and applies config offline. `C:\PromiseApp\opas\tmp\opas.log`.

**Consequence for the port:** `opasd` is the Linux replacement for the
**php_i2 → pri2apiv4 → i2.exe** path (all collapsed onto `libi2`, exactly as
Phase 2 collapsed the enclosure IPC). It exposes an operation-level
request/response over a UNIX socket; `api/login.php` + `api/opas.php` are adapted
to call it. The offline `opas.exe` USB tool is a separate future port (out of
Phase-3 scope) and is **not** on the login/read data path.

---

## 1. The login / session flow (HIGH — read straight from the PHP)

Two API entrypoints plus the login page:

- `WebServer/htdocs/api/login.php` — POST `{"method":"dc"}` → `getdynamiccode()`
  → JSON `{err, code, id}` (the login challenge). (login.php:17-32)
- `WebServer/htdocs/index.php` — the login form + POST handler.
- `WebServer/htdocs/api/opas.php` — an **authenticated** POST that exports the
  subsystem config; gated on `$_SESSION['sessionService']`. (opas.php:4-8)

### 1.1 Challenge → response (HIGH)

`getdynamiccode()` is a **native function in `php_i2.dll`** (no PHP definition
exists anywhere under `WebServer/htdocs`; only call sites in `index.php:230` and
`api/login.php:19`). It returns an associative array with `err`, `code`, `id`.

- `code` = the dynamic challenge string; rendered into the form as the
  `dynccode` attribute (`index.php:229-236`).
- `id`   = an opaque handle for the issued challenge; rendered into hidden field
  `hn1` (`index.php:258-260`).

The browser hashes the password with the challenge (`index.php:100-130`,
`js/md5.js`):

```
md5password = MD5( MD5( MD5(dynccode) + password ) + username )
```

Then POSTs `login` (username), `md5password`, and `hn1` (the challenge id)
(`index.php:174-177`).

### 1.2 Login → session token (HIGH)

`index.php:175` → `new CSessionService($login, $md5password, $hn1)`.
`CSessionService::loginex()` (`tree.php:126-153`):

```php
$client = I2ClientEx($username, $password /*=md5password*/, $sid /*=hn1 dc id*/);
$this->sid    = $client->sessionId;   // the SESSION TOKEN (engine-issued)
$this->status = $client->status;      // 0 == success
```

So: the **dynamic-code id** (`hn1`) is passed *into* `I2ClientEx` as the
challenge handle; the engine verifies `md5password` against the stored password
salted by that challenge, and returns a **`sessionId`** (the durable session
token) and `status`. (`I2ClientEx` is native in `php_i2.dll`; the string
`I2Client logout - sessionId = %d` and `_getControllers - u32SessionId = %d`
confirm the sessionId is the engine session key.)

On `status == 0`, `index.php:187` serializes the session object into
`$_SESSION['sessionService']`; it carries `username`, `password` (=md5password),
`sid` (=sessionId), `status`.

### 1.3 Authenticated calls + logout (HIGH)

Every authenticated page rebuilds a client from the stored session:
`CSubsystem::CSubsystem()` → `$session->connect()` → `I2Client($username, "",
$sid)` (`tree.php:155-159`, `tree.php:332`), then calls `$client->getSubsystem()`
etc. Logout: `I2Client($username, "", $sid)->logout()` (`tree.php:390-391`).

Session-validity is checked engine-side: an inactive session surfaces as
`PIERR_INACTIVE_SESSION` on any call (`session.php:27`, `common.php:16`), and the
PHP additionally enforces a 24-minute idle timeout in `tree.php:23-33`.

### 1.4 What is NOT recoverable off-device (UNRESOLVED, flagged)

- The **dynamic-code generation algorithm** and the **exact password-verification
  hash** inside `php_i2.dll`/`pri2apiv4.dll` (and ultimately the engine user-db,
  `userdb.xml`). The MD5 *chain* the client computes is recovered (§1.1); the
  server-side stored-secret comparison is engine-internal.
- Therefore `opasd` implements the full session **envelope** (challenge issue,
  token issue/validate/consume, logout) and delegates the credential check to an
  **injected auth hook** — real deployments bind it to the engine user-db
  (on-device / Phase 4); tests inject a known-answer hook. `opasd` never fakes a
  crypto result.

---

## 2. The opasd request/response contract (port-defined framing, recovered content)

Because the Windows PHP↔engine seam was a C ABI (not bytes on a wire, §0), there
is **no native wire format to reproduce** at the PHP↔opasd boundary. `opasd`
therefore defines a minimal, explicit framing. Its **content** (operation set,
field names, page decode) is recovered; its **envelope** is port-defined and
labelled as such. The one recovered wire constant reused is the IPC signature.

### 2.1 Frame (port-defined)

```
[ magic : u32 LE = 0xBABA0001 ][ len : u32 LE ][ body : len bytes ]
```

- `magic` = `I2_IPC_SIGNATURE` — the **recovered** Promise IPC signature word
  (`i2_micops.h`; pri2apiv4 @0x5530 response check `(hdr & 0xffff0000)==0xbaba0000`,
  libi2bdg `i2_initialize` init struct). Reused as a frame/version marker so the
  value is provenance-backed rather than arbitrary.
- Request `body` = newline-delimited `key=value` text; first line `method=NAME`
  (trivial to build in PHP, trivial to parse in C).
- Response `body` = a JSON object; always carries `status` (0 = OK), plus method
  fields / arrays.

### 2.2 Methods (opasd selectors → substrate)

| method               | auth? | substrate call(s)                                   | response |
|----------------------|-------|-----------------------------------------------------|----------|
| `dc`                 | no    | `opas_dc_issue` (envelope)                           | `{err,code,id}` |
| `login`              | no    | `opas_login` (auth hook + `sessionId` issue)         | `{status,sessionId}` |
| `logout`             | sid   | `opas_logout`                                        | `{status}` |
| `getSubsystem`       | sid   | `i2bApi_getEncInfo` + `i2bApi_getElementCnt`         | `{status,model,ssi_CustModel,numFans,numTempSensors,numVoltageSensors}` |
| `enumEnclosure`      | sid   | `getEncInfo`+`getElementCnt`+`getFanInfo`+`getThermalSensorInfo`+`getVoltageSensorInfo` | `{status,enclosures:[{encId,type,fans[],temps[],volts[]}]}` |
| `enumControllers`    | sid   | `i2_fw_cmd(GET_CTRL_INFO 0x0103)`                    | `{status,controllers:[{ci_DeviceID,vendor,product,revision}]}` |
| `enumPhysicalDrives` | sid   | `i2bApi_getHardDiskDriveInfo`                        | `{status,physicaldrives:[{pdi_FlatId,drvType,capacity,enclosureId,slotId,opStatusFlag,arrayId,temperature}]}` |
| `enumLogicalDrives`  | sid   | `i2bApi_getLogDrvInfo`                               | `{status,logicaldrives:[{ldi_FlatId,alias,raidLevel,capacity,stripe,arrayId,synced,cachePolicy,opStatusFlag}]}` |
| `enumDiskArrays`     | sid   | `i2bApi_getDiskArrayInfo`                            | `{status,arrays:[{acd_ArrayID,numOfHdd}]}` |
| `statsLogDrv`        | sid   | `i2bApi_getStatsLogDrv`                              | `{status,stats:[{ldId,blocks,ioReq,errors,collectionTime}]}` |
| `export`             | sid   | (config op — cataloged, see §4)                     | `{status:6}` (UNSUPPORTED, not faked) |

Field names follow the **PHP-visible** names where recovered (`pdi_FlatId`,
`ldi_FlatId`, `acd_ArrayID`, `ssi_CustModel`, `ci_DeviceID` — from
`tree.php:417/465/484/494/640` and `php_i2.dll` strings) so a Phase-4 php_i2
adapter maps 1:1; the RAID field values come from the Phase-2b recovered page
offsets (`i2_raid.h`).

---

## 3. What works end-to-end through `opasd` (against the mock substrate)

`tests/test_opas.c` drives `opas_dispatch()` (the socket-free core) with fixture
requests copied from the PHP shapes, seeding the mock `scsi_transport` with the
recovered pages:

- **login/session envelope**: `dc` → `{err:0,code,id}`; `login` gated by the
  injected auth hook → `sessionId`; authenticated methods reject a missing/invalid
  `sid` with `status=PIERR_INACTIVE_SESSION`; `logout` consumes the session.
- **enclosure** (`getSubsystem`, `enumEnclosure`): model `'R'`→`ENC_TYPE_VESS_3U16BAY`,
  element counts, fan RPM (`1350000/(raw*k)`), temps, volts — seeded on MicOpCode
  `0x000F`/`0x010F`.
- **RAID reads** (`enumPhysicalDrives`, `enumLogicalDrives`, `enumDiskArrays`,
  `statsLogDrv`): seeded on `0x0804`/`0x0B04`/`0x0504`/`0x0412`, parsed via the
  Phase-2b `i2bApi_*` into the documented records, emitted as JSON.
- **controllers** (`enumControllers`): `0x0103` page, vendor/product/revision.

---

## 4. Endpoint catalog — the rest of the web API (cataloged)

The full PHP surface (~116 pages) maps onto engine `i2_*` calls exposed by
`php_i2.dll`. Phase 3 implements login + core reads (§2); the remainder is
tabulated here with its engine call and status. **Writes/config are cataloged,
not implemented** (they need on-device SCSI-trace confirmation of the DATA-OUT
payload — see `RE-i2-raid.md`; array/LD *create* payload is UNRESOLVED/CALIBRATE).

| PHP page(s)                     | operation                    | engine `i2_*` (php_i2 string)          | class | status |
|---------------------------------|------------------------------|----------------------------------------|-------|--------|
| `api/login.php`, `index.php`    | dynamic code / login         | `getdynamiccode`, `I2ClientEx`         | read/auth | **implemented** |
| `session.php`                   | list / force-logout sessions | `_getControllers`/`getSessionInfo`     | read/write | cataloged |
| `subsystem.php`, `home.php`     | subsystem summary            | `_getSubsystemSettings`                | read  | **implemented** (getSubsystem) |
| `controllers.php`,`controller.php`,`controllert.php` | controller info/settings | `_getControllers`, `_addControllerInfo`, `i2_getControllerSettings` | read/write | **read implemented** (enumControllers) / settings cataloged |
| `encls.php`,`encl.php`,`enclt.php`,`front.php`,`fru.php` | enclosure/FRU/fans/LEDs | `i2_getEnclosure*`, `_enclosureParam`, `_getFRUs` | read | **implemented** (enumEnclosure) |
| `phydrvs.php`,`phydrv.php`,`phydrvst.php` | physical-drive list/detail | `i2_getPhyDrvInfo`, `_getPhysicalDrive*` | read | **implemented** (enumPhysicalDrives) |
| `phydrvStat.php`                | PD statistics                | `i2_getStatsPhyDrv` (opcode CALIBRATE) | read  | cataloged (opcode UNRESOLVED) |
| `phydrvSmart.php`,`phydrvSmartLog.php` | SMART data            | `i2bdg_SMARTReadData/…`                | read  | cataloged |
| `logdrvs.php`,`logdrv.php`,`logdrvt.php` | logical-drive list/detail | `i2_getLogDrvInfo`, `_getLogDrvSettings` | read | **implemented** (enumLogicalDrives) |
| `logdrvStat.php`,`perfStats*.php` | LD statistics / perf       | `i2_getStatsLogDrv`, `LD_PerfStat`     | read  | **implemented** (statsLogDrv) / perf cataloged |
| `arrays.php`,`array.php`,`arrayt.php` | array list/detail        | `i2_getDiskArrayInfo`, `i2_getDiskArrayConfig` | read | **implemented** (enumDiskArrays) |
| `arrayc*.php`,`arraya.php`,`arrayc.php` | array create/config      | `i2_addDiskArray`, `i2_configArray`, `i2_arrayConfigParam` | write | cataloged — **create payload UNRESOLVED** |
| `arrayd.php`                    | array delete                 | `i2_delDiskArray` (0x0104)             | write | cataloged (CDB recovered, DATA-OUT calibrate) |
| `logdrvc*.php`,`ldinit*.php`    | LD create / init             | `i2_addLogDrv`, `i2_startLogDrvInit`   | write | cataloged — create payload UNRESOLVED |
| `logdrvd.php`                   | LD delete                    | `i2_delLogDrv` (0x0404)                | write | cataloged (CDB recovered) |
| `ldsync.php`,`ldrc*.php`,`mpatrol.php` | synch / redundancy / patrol | `i2_startLogDrvSynch` (0x0109/0x0209) | write | cataloged (CDB recovered) |
| `migrate*.php`,`ldbga.php`      | migrate / expand             | `i2_migrateDiskArray`                  | write | cataloged |
| `spares.php`,`sparec.php`,`spared.php` | spare list/create/delete | `i2_getAllSpareInfo`, `i2_addSpare`, `i2_delSpare` | read/write | list cataloged / write cataloged |
| `bga.php`,`bgat.php`,`bgasched` | background-activity schedule | `i2_addBGASchedule`/`modify`/`delete`  | write | cataloged |
| `admin.php`,`user*.php`         | users / privileges           | `getUsers`, user CRUD                  | read/write | cataloged |
| `net.php`,`nett.php`            | network settings             | `_getControllerEtherNetInfo`           | read/write | cataloged |
| `evtsubscribe.php`,`email.php`  | iSCSI/FC/SAS + email/events  | `_getControlleriSCSINetInfo`,`_getFC*` | read/write | cataloged |
| `events.php`,`event*.php`       | event log                    | `_getEvents`, `i2bdg_getEvent`         | read  | cataloged (representative — reuse `i2bdg_getEvent`) |
| `buzzer.php`,`battery*.php`     | alarm / battery              | `i2_*TerminalAlarm*`, `_getBattery`    | read/write | alarm read implemented in libi2bdg / cataloged here |
| `daemon.php`,`servicet.php`     | service control              | daemon start/stop/restart              | write | cataloged |
| `clearStat.php`,`ctrlStat.php`  | reset statistics             | `i2_getStatsLogDrv` reset (0x0712)     | write | cataloged (CDB recovered) |
| `lock.php`                      | FW lock                      | `checkFwLock`/lock                     | write | cataloged |
| `import.php`,`export.php`,`download.php`,`api/opas.php` | config import/export | subsystem `export()`/`import()` (`IMEXPORT_SUBSYSTEM`) | write | cataloged (`opas.php` adapted as transport demo, returns UNSUPPORTED) |

---

## 5. `opasd` design (the Linux daemon)

- Links `libi2bdg` + `libi2` (same as the CLI tools). Uses the process-global
  enclosure session (`i2bdg_session_*`): real hardware via `i2_initialize_discover`
  / `$I2BDG_SG_DEV`; tests inject a mock via `i2bdg_session_set_transport`.
- **`opas_session.{c,h}`** — dynamic-code + session tables (fixed-cap ring),
  injected auth hook. The envelope; no crypto/user-db invented.
- **`opas_json.{c,h}`** — tiny append/escape JSON emitter (no dependency).
- **`opas_dispatch.c`** — socket-free core: parse `key=value` request → dispatch
  to `i2bApi_*`/session → emit JSON. **The unit-test boundary.**
- **`opasd.c`** — thin UNIX-socket daemon (`$OPASD_SOCK` or `/tmp/opasd.sock`):
  accept → read frame → `opas_dispatch` → write frame. One connection at a time
  (the handle is the serialization point, per `RE-fwinterf-pri2api.md` §5).

## 6. PHP adaptation (surgical transport swap, copied into `port/web/`)

- `port/web/opas_client.php` — NEW ~40-line helper: `opas_call($method,$params)`
  frames a request to the `opasd` UNIX socket and returns decoded JSON.
- `port/web/api/login.php` — `getdynamiccode()` → `opas_call('dc',[])` (keeps the
  exact `{err,code,id}` contract the JS expects).
- `port/web/api/opas.php` — the Windows `getServer()->getSubsystem()->export()`
  service call → `opas_call('export',[...])`, session-gate + JSON response
  preserved. `export` returns UNSUPPORTED from opasd (cataloged, not faked).

Only these two endpoints are swapped; the ~114 other pages still need the full
php_i2 object model (a Phase-4 Linux php_i2 extension or a broader adapter) and
are untouched under `windows-src/`.

## 7. On-device TODO (the auth/engine gate)

1. Bind `opasd`'s auth hook to the real engine user-db (`userdb.xml` / engine
   login) — the credential check is engine-internal (§1.4).
2. Confirm the `sessionId` issued by the engine's `I2ClientEx` maps onto opasd's
   session token model (opasd currently mints its own token post-auth).
3. Recover the dynamic-code algorithm from a live `getdynamiccode` if per-byte
   fidelity with existing Windows clients is ever required (not needed for a
   self-contained Linux stack).
</content>
</invoke>
