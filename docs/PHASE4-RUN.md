# Phase 4 — run the WebPAM web tier (Linux)

Everything runs in a Linux/amd64 container (mirroring the A3120), so it works off
the real box. Needs a working `docker` daemon or Apple `container`. `windows-src/`
is never mutated — the tier serves the copy in `port/web/htdocs/`.

## 1. Build + test the C libs and the extension (PHP 8.2)

```
bash port/build-in-container.sh
```

Builds the Phase-1/2/3 C libs, runs the **7 C test suites**, then builds the
`php_i2` extension with phpize and verifies it **loads** (`php -m | grep i2`) and
passes the native object-model smoke (`ext-smoke.php`). Expected tail:

```
100% tests passed, 0 tests failed out of 7
== php_i2 extension ==
php -m: i2 loaded
EXT SMOKE OK: php_i2 object model serves the fixture substrate
```

Fast extension-only iteration (skips the C suites):

```
bash port/php-ext/build-ext.sh
```

## 2. Headless web smoke — dc → login → data (PHP 7.4)

```
bash port/web/smoke-flow.sh          # default port 8088
```

Builds the extension against PHP 7.4 (matching the shipped 7.3), serves the
htdocs against the fixture substrate, and curls the full flow — all inside one
container (no host port mapping needed). Expected tail:

```
php_i2 loaded on PHP 7.4.33
== 1) GET index.php (login challenge) ==      dc id=… csrf=…
== 2) POST login (administrator / fixture auth) ==   login OK -> redirect to screen.php
== 3) GET arrays.php (disk array data from substrate) ==   arrays.php rendered array DA7 / DiskArray7
== 4) GET subsystem.php (subsystem summary) ==   subsystem.php rendered model 'Vess A3120'
SMOKE OK: dc -> login -> arrays.php + subsystem.php render live fixture data, no PHP errors
```

## 3. Launch for a browser render-check

> **Phase 6:** for the FULL framed UI (screen.php frameset: tree + panels +
> event bar) use the production stack instead — `bash port/packaging/run-fpm.sh`
> (nginx + PHP-FPM, concurrent). The single-threaded `php -S` tier below serves
> individual pages fine but starves the frameset. See `docs/PACKAGING.md`.

```
bash port/web/run-webtier.sh         # foreground; Ctrl-C to stop
```

Then open **http://localhost:8088/index.php** and log in:

- **username:** `administrator`
- **password:** anything (off-device fixture auth; on-device this binds to the
  engine user-db)

Fixture data you should see after login: disk array **DA7**, one RAID-5 logical
drive (**SystemVolume**), one physical drive (**ST4000NM0023**, PD5), a 3U/16-bay
enclosure (2 fans), model **Vess A3120**. Pages that render: `index.php` (login),
`screen.php` (frameset), `home.php`, `subsystem.php`, `arrays.php`, `logdrvs.php`,
`phydrvs.php`, `encls.php`, `controllers.php`.

First launch builds the extension (~1 min). If your container runtime does not
publish `-p` to `localhost`, use the container's own IP (the launcher prints a
note).

## 4. On-device (real A3120)

Set `I2BDG_SG_DEV=/dev/sgN` in the serving container's environment: the mock
substrate is not injected and the identical reads drive the real controller. The
remaining gate is binding the auth hook to the engine user-db (see
`docs/RE-php_i2.md` §5).
