dnl config.m4 for the Linux php_i2 extension (Phase 4).
dnl
dnl Faithful in-process port of the Windows WebServer/php/ext/php_i2.dll: the
dnl same native object model (getdynamiccode / I2Client / I2ClientEx -> I2Client
dnl and I2Subsystem objects) that the ~116 htdocs PHP pages call. Links the
dnl Phase-2/2b substrate (libi2 + libi2bdg) and the Phase-3 session envelope
dnl (opas_session) directly into the .so, mirroring how php_i2.dll linked
dnl pri2apiv4 in-process. Off-device it serves seeded fixture data (mock SCSI
dnl transport); on-device the same code drives /dev/sg* (I2BDG_SG_DEV).

PHP_ARG_ENABLE([i2],
  [whether to enable the Promise i2 extension],
  [AS_HELP_STRING([--enable-i2], [Enable the Promise i2 WebPAM extension])],
  [no])

if test "$PHP_I2" != "no"; then
  PHP_ADD_INCLUDE([$srcdir/../include])
  PHP_ADD_INCLUDE([$srcdir/../src/libi2])
  PHP_ADD_INCLUDE([$srcdir/../src/lib])
  PHP_ADD_INCLUDE([$srcdir/../src/opasd])
  PHP_ADD_INCLUDE([$srcdir/../src/hal])
  PHP_ADD_INCLUDE([$srcdir/../src/hal/smbus])
  PHP_ADD_INCLUDE([$srcdir/../src/hal/mmio])
  PHP_ADD_INCLUDE([$srcdir/../src/raid])

  dnl The extension sources + the substrate compiled straight in (same .c the
  dnl CMake tests build, reached via substrate/ symlinks because phpize only
  dnl names object files correctly for sources under the extension dir).
  dnl scsi_transport_sgio.c gives the real /dev/sg* backend on Linux; the mock is
  dnl what the fixture mode injects.
  i2_sources="php_i2.c i2_fixture.c i2_client.c i2_subsystem.c i2_records.c i2_services.c i2_stats.c \
    substrate/i2.c \
    substrate/i2_raid.c \
    substrate/scsi_transport_mock.c \
    substrate/scsi_transport_sgio.c \
    substrate/i2bdg_session.c \
    substrate/i2bdg_sensors.c \
    substrate/i2bdg_led.c \
    substrate/i2bdg_alarm.c \
    substrate/i2bdg_wdt.c \
    substrate/i2bdg_vpd.c \
    substrate/i2bdg_raid.c \
    substrate/i2bdg_stubs.c \
    substrate/opas_session.c \
    substrate/encl_backend.c \
    substrate/i2c_bus_mock.c \
    substrate/i2c_bus_linux.c \
    substrate/pca9555.c \
    substrate/nct7802y.c \
    substrate/hwmon_sysfs.c \
    substrate/smbus_encl.c \
    substrate/asm_reg_mock.c \
    substrate/asm_reg_linux.c \
    substrate/asm2106b.c \
    substrate/mmio_raid.c \
    substrate/raid_backend.c \
    substrate/raid_mdadm.c \
    substrate/raid_health.c"

  dnl crypt_r (libxcrypt) verifies the local WebPAM password against its stored
  dnl one-way hash (i2_fixture.c). Explicit link even though PHP loads libcrypt.
  PHP_ADD_LIBRARY([crypt], [1], [I2_SHARED_LIBADD])
  PHP_SUBST([I2_SHARED_LIBADD])

  dnl -DCOMPILE_DL_I2=1 so ZEND_GET_MODULE(i2) emits get_module() (the loader
  dnl entry). phpize does not reliably define it for out-of-tree sources here.
  PHP_NEW_EXTENSION([i2], [$i2_sources], [$ext_shared],, [-DZEND_ENABLE_STATIC_TSRMLS_CACHE=1 -DCOMPILE_DL_I2=1])
fi
