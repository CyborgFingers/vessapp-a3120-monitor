# RE findings: the `php_i2` PHP extension + the Linux port (Phase 4)

The **web-tier bridge**: the native PHP extension the ~116 WebPAM `htdocs/*.php`
pages call in-process. On Windows this is `WebServer/php/ext/php_i2.dll` (a Zend
extension for PHP 7.3, `php7ts.dll`) that links `pri2apiv4.dll` in-process. The
Phase-4 port rebuilds it for Linux as `port/php-ext/` → `i2.so`, linking the
Phase-2/2b substrate (`libi2` + `libi2bdg`) and the Phase-3 session envelope
(`opas_session`) directly, so **the htdocs run unchanged**.

Confidence: **high** = the PHP calls it literally (file:line) and/or a confirmed
`php_i2.dll` string; **med** = inferred; **UNRESOLVED / FIXTURE** = the value is
engine-internal (a pri2apiv4/php_i2 numeric literal, not string-recoverable
off-device) and is registered with a documented placeholder, never invented.

Provenance paths: `windows-src/rootfs/PromiseApp/WebServer/`.

---

## 0. Why an extension (not the opasd socket)

Phase 3 recovered the same login/session flow and built `opasd`, a UNIX-socket
daemon, adapting two endpoints (`api/login.php`, `api/opas.php`) to call it. But
the **other ~114 pages call a native object model in-process** — `I2Client(...)
->getSubsystem()->enumeratePhysicalDrives()` — not a socket. Reproducing that
faithfully (PHP unchanged) means a Linux `php_i2` extension providing that object
model, exactly as the Windows DLL did. `opasd` stays for CLI/other clients; the
extension is the web path. Both ride the one substrate.

The Windows PHP↔engine seam was a **C ABI (zvals), not a wire protocol** (RE-opas
§0), so there is nothing to byte-match here — the fidelity target is the *object
model* (class/function/property/method names + shapes), recovered below.

---

## 1. The native surface (classes, functions, methods)

Two internal classes + three factory functions, confirmed against `php_i2.dll`
strings (`i2client`, `i2clientex`, `getdynamiccode`, `getsubsystem`, `logout`,
`enumeratephysicaldrives`, `getcliqueinfo`, `getdateandtime`, `getusers`,
`supportdualcontroller`, `syncsessionvalue`, `zif_i2subsystem_getCurfrontendtype`,
…) and the PHP call sites in `tree.php`.

### 1.1 Factory functions (module-level)

| function | PHP call site | returns | port impl |
|----------|---------------|---------|-----------|
| `getdynamiccode()` | `index.php:230`, `api/login.php:19` | array `{err, code, id}` | `opas_dc_issue` (Phase-3 envelope) |
| `I2ClientEx($user,$pw,$dcid)` | `tree.php:144` | `I2Client{sessionId,status}` | `opas_login` (fixture auth hook) |
| `I2Client($user,"",$sid)` | `tree.php:157`, `:390` | `I2Client` (reconnect) | `opas_session_valid` |
| `getLastError()` | `daemon.php:39`, `events.php`, … (16 sites; dll string `getlasterror`) | last engine status (`PIERR_*`) | per-request global, reset in RINIT; set to `PIERR_INACTIVE_SESSION` on a dead-session reconnect (Phase 6 — daemon frame reaches it once the frameset actually loads) |

`$dcid` (the 3rd arg to `I2ClientEx`) is the **dynamic-code id** (`hn1`), not a
token; on success `sessionId` is the engine-issued session token, `status==0`.
(RE-opas §1.2.) The fixture auth hook authenticates one operator (env
`I2_FIXTURE_USER`, default `administrator`) for any password — the credential
check is engine-internal off-device (RE-opas §1.4); on-device this hook binds to
the engine user-db.

### 1.2 `I2Client` (php_i2.dll "i2client")

| member | PHP call site | port impl |
|--------|---------------|-----------|
| prop `sessionId`, `status`, `frontendType`, `model` | `tree.php:145-146,333,640` | set by factory (`model` PHP-assigned) |
| `->getSubsystem($flag=0)` | `tree.php:334,337` | returns `I2Subsystem` (props filled) |
| `->logout()` | `tree.php:391` | `opas_logout(sessionId)` |

### 1.3 `I2Subsystem` (php_i2.dll "i2subsystem")

Returned by `I2Client::getSubsystem()`. Properties set at creation (subsystem
summary); methods serve reads from the substrate.

**Summary properties** (read `subsystem.php:88-98`, `tree.php:504,640`):
`u32Ret` (0 = no error → pages proceed), `frontendType`, `sysfrontendType`,
`ssi_UserName`, `ssi_CustName`, `ssi_CustModel` (= "Vess A3120"),
`ssi_CustSerialNo`, `ssi_WWN`, `ssi_CustPartNo`, `ssi_CustRev`,
`ssi_JOBExpSSPresent`, `ssi_MaxJOBExpSSSupported`, `ssi_NumberOfCtlPresent`.
The identifiers marked FIXTURE (serial/WWN/part) are placeholders; the model is
the real product name.

**Read methods** (→ substrate, RE-opas §2 field names):

| method | PHP call site | substrate call | record fields (PHP-visible) |
|--------|---------------|----------------|------------------------------|
| `enumeratePhysicalDrives()` / `getPhysicalDrives()` | `tree.php:463`, `phydrvs.php:39` | `i2bApi_getHardDiskDriveInfo` | `pdi_FlatId, pdi_DriveModelNo, pdi_DriveTypeString, pdi_ConfigurableSize, pdi_SlotId, pdi_EnclosureId, pdi_Flags, pdi_ArrayId, _opStatus, _cfgStatus` |
| `enumerateLogicalDrives()` / `getLogicalDrives()` | `tree.php:482` | `i2bApi_getLogDrvInfo` | `ldi_FlatId, ldi_Alias, ldi_RaidLevel, ldi_Capacity, ldi_ArrayId, ldi_Flags, _opStatus` |
| `enumerateDiskArrays()` / `getDiskArrays()` | `tree.php:492`, `arrays.php:20` | `i2bApi_getDiskArrayInfo` | `acd_ArrayID, acd_ArrayName, acd_PDs, _opStatus, _cfgSize, _availableSize` |
| `enumerateControllers()` / `getControllers()` | `tree.php:409` | `i2_fw_cmd(0x0103)` | `ci_DeviceID, ci_ReadinessStatus, ci_ProductID, ci1_PriSWBuildDate` |
| `getController($devId)` | `home.php:52`, `tree.php:449` | `i2_fw_cmd(0x0103)` | as above (single) |
| `getEnclosures($id=0)` | `tree.php:506`, `phydrvs.php:21` | `i2bApi_getEncInfo`+`getElementCnt`+`getFanInfo` | `_id, _encType, _numFans, _fans[], _slots[]` |
| `getUsers()` | `common2.php:15` | fixture operator | `{errorcode, userlist:[{username, userPrivilege}]}` |
| `enumerateSpareDrives()` / `enumerateUpss()` / `getBatteries()` / `getLastEvents()` | `tree.php:528,519`, … | (cataloged) | empty array |
| `supportdualcontroller()` | `subsystem.php:20` | fixture | `{DualCtrlSupport:0}` (single-ctrl A3120) |
| `getCliqueInfo()` | `subsystem.php:21` | fixture | `{cgi_RedundancyType, cgi_CacheMirroring, cgi_RedundancyStatus}` |
| `getDateAndTime()` | `subsystem.php:104` | host clock | `{_strTime}` |
| `getControllerSettings($id)` | `arrays.php:19` | fixture | `{cs_LUNMasking:0}` |
| `getCurrentCtrlId()` / `getCurfrontendtype()` | `common.php:544,602` | fixture | `{currentCtrlId:0}` / `{curfrontendtype}` |
| `getDiskArray($id)` | `phydrvs.php:43` | `getDiskArrays` + pick | one array record |
| `syncsessionvalue($sessId,$sid)` | `common.php:27` | no-op | null |
| `export($opts)` | `subsystem.php:32` | cataloged | nonzero engine status (UNSUPPORTED, not faked) |

Derived `_`-prefixed strings (`_opStatus`, `_cfgStatus`, `_cfgSize`, …) are the
values `php_i2.dll` computes from the raw flag/capacity words; the port's
derivations are documented approximations (a display calibration point).

### 1.4 Engine constants

`grep` proves **zero `define()`** for them in the htdocs — `php_i2.dll` registers
them as module constants. The device-flag masks (`PHYDRV_*`, `LOGDRV_*`,
`DEVICE_CONFIG_MASK`) are **RECOVERED** from `port/include/i2bdg_api.h` and
registered with those values. The engine enums (`USER_GROUP_*`, `PI_FB_HOST_INTERFACE_*`,
`PIERR_*`, `IMEXPORT_*`, `ENC_TYPE_*`, `BGAS_/RBD_/ISCSI_VLAN_*`) live as numeric
literals inside `php_i2.dll`/`pri2apiv4` and are **not string-recoverable**; they
are registered with documented **FIXTURE** values so the htdocs never fatal on an
undefined constant (they only affect branch selection, not the login/read path).
`PIERR_NO_ERROR = 0` is certain. See `php_i2.c i2_register_constants()`.

---

## 2. Fixture mode (off-device)

`i2_fixture.c` at `MINIT`: creates the `opas_session` manager, installs the
fixture auth hook, and — unless `I2BDG_SG_DEV` is set — seeds a mock SCSI
transport with the same canned pages as `tests/test_opas.c` (enclosure 3U16 head
unit, 2 fans/1 temp/1 volt; one PD `ST4000NM0023` PDId 5; one RAID-5 LD
`SystemVolume` id 2; one disk array id 7) and injects it into the process-global
`i2bdg` session. So `i2bApi_*` / `i2_fw_cmd` return that data with **no hardware**.

**On-device**: set `I2BDG_SG_DEV=/dev/sgN` (or leave discovery) — the mock is not
injected and the identical `i2bApi_*` calls drive the real controller. Auth then
binds the fixture hook to the engine user-db (the one on-device TODO).

Session state (dc challenges + tokens) is a **process-global** in the extension,
mirroring the Windows in-process php_i2 state. `php -S` is single-process, so the
`dc → login → read` flow is coherent. **Phase 6 resolved the multi-worker
php-fpm case**: `opas_session.c` allocates the manager in fork-shared memory
(`MAP_SHARED|MAP_ANONYMOUS`, created at MINIT in the FPM master before it
forks), so every worker validates challenges/sessions issued by any other —
proven by `port/packaging/fpm-smoke.sh` under `pm.max_requests=1`. Unimplemented
subsystem methods (`__call`) return an **empty array** (not an empty object):
on PHP 7.4 that is benign for both htdocs conventions — collection getters
(`getBuzzers()`, `daemon.php:80`) `count()`/index it, singular getters
`isset(->prop)` it.

---

## 3. Build + run

```
# extension only, fast (PHP 8.2 container): compiles, loads, native-model smoke
bash port/php-ext/build-ext.sh

# full: 7 C suites + extension load-check on PHP 8.2
bash port/build-in-container.sh

# web tier on PHP 7.4 (matches shipped 7.3; htdocs unchanged):
bash port/web/run-webtier.sh          # foreground, http://localhost:8088/
bash port/web/smoke-flow.sh           # headless dc -> login -> data assertions
```

The extension builds on **PHP 7.4** (the web runtime, matching the shipped 7.3)
and **PHP 8.x** (the container load-check) via two `PHP_VERSION_ID` guards
(`php_i2_int.h`: `zend_read_property` arg type; `ZEND_ACC_ALLOW_DYNAMIC_PROPERTIES`).
phpize only names object files for sources under the ext dir, so the substrate
`.c` are reached via `port/php-ext/substrate/` symlinks.

---

## 4. Windows-isms fixed (surgical, documented)

The copied htdocs (`port/web/htdocs/`, `windows-src/` untouched) needed one code
fix; the rest is runtime config:

- **`index.php`**: removed the two Windows `findstr` shell-outs that read the SSL
  `Listen` port from the bundled XAMPP Apache conf (there is no Apache on the
  Linux stack); host:port now comes from `$_SERVER`, and the "PHP is always
  fronted by an HTTPS stunnel" assumption is relaxed so the fixture can
  serve/redirect over plain HTTP off-device.
- **PHP version**: the app shipped **PHP 7.3** and uses PHP4-style (method-name)
  constructors, removed in PHP 8. The web tier therefore runs on **PHP 7.4** (the
  faithful match) — the htdocs are otherwise **unchanged**. `subsystem.php`'s
  `PHP_OS == "WINNT"` branch already had a Linux `else` and needed no change.

---

## 5. On-device TODO

1. Bind the fixture auth hook to the engine user-db (credential check, RE-opas §1.4).
2. Set `I2BDG_SG_DEV` and confirm the substrate reads on the real controller.
3. Fill the config/write methods (cataloged §1.3, currently stub/empty): users,
   network, iSCSI/FC/SAS, events, schedules, array/LD create-delete. Array/LD
   **create** payload is UNRESOLVED upstream (RE-i2-raid.md).
4. For multi-worker php-fpm, share the session store (or front with `opasd`).
