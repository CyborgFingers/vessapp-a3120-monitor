cmake_minimum_required(VERSION 3.16)
project(vessapp_libi2 C)

set(CMAKE_C_STANDARD 11)
set(CMAKE_C_STANDARD_REQUIRED ON)
add_compile_options(-Wall -Wextra)

# ---- libi2: the in-band substrate (SG_IO transport + i2_* marshalling) -------
# The real SG_IO backend needs <scsi/sg.h> (Linux kernel headers). On non-Linux
# hosts only the mock builds, which is all the unit tests need.
set(LIBI2_SOURCES
  src/libi2/scsi_transport_mock.c
  src/libi2/i2.c
  src/libi2/i2_raid.c
)
if(CMAKE_SYSTEM_NAME STREQUAL "Linux")
  list(APPEND LIBI2_SOURCES src/libi2/scsi_transport_sgio.c)
endif()

add_library(i2 STATIC ${LIBI2_SOURCES})
target_include_directories(i2 PUBLIC include src/libi2)
set_property(TARGET i2 PROPERTY POSITION_INDEPENDENT_CODE ON)

# ---- hal: native Linux enclosure backends (SMBus LEDs/fans + ASM2106B MMIO) ---
# Selectable at runtime via VESSAPP_BACKEND (encl_backend.c). The mock i2c/mmio
# seams + pure chip logic build everywhere (unit tests); the real /dev/i2c-N and
# PCI-config/BAR5 access build on Linux only. See docs/RE-enclosure-smbus.md and
# docs/RE-asm2106b.md.
set(HAL_SOURCES
  src/hal/encl_backend.c
  src/hal/smbus/i2c_bus_mock.c
  src/hal/smbus/pca9555.c
  src/hal/smbus/nct7802y.c
  src/hal/smbus/hwmon_sysfs.c
  src/hal/smbus/smbus_encl.c
  src/hal/mmio/asm_reg_mock.c
  src/hal/mmio/asm_ioport.c
  src/hal/mmio/asm2106b.c
  src/hal/mmio/mmio_raid.c
)
if(CMAKE_SYSTEM_NAME STREQUAL "Linux")
  list(APPEND HAL_SOURCES src/hal/smbus/i2c_bus_linux.c src/hal/mmio/asm_reg_linux.c)
endif()

add_library(hal STATIC ${HAL_SOURCES})
target_include_directories(hal PUBLIC include src/hal src/hal/smbus src/hal/mmio)
set_property(TARGET hal PROPERTY POSITION_INDEPENDENT_CODE ON)

# ---- library: the drop-in libi2bdg reimplementation, now riding libi2 --------
# src/raid = the selectable RAID backend (VESSAPP_RAID_BACKEND, raid_backend.c):
# sgio (default, the libi2 CDB path below) or the mdadm-backed Linux-native
# engine (raid_mdadm.c, docs/RAID-ENGINE-MDADM-PLAN.md Phase 1 = reads only).
set(I2BDG_SOURCES
  src/lib/i2bdg_session.c
  src/lib/i2bdg_sensors.c
  src/lib/i2bdg_led.c
  src/lib/i2bdg_alarm.c
  src/lib/i2bdg_wdt.c
  src/lib/i2bdg_vpd.c
  src/lib/i2bdg_raid.c
  src/lib/i2bdg_stubs.c
  src/raid/raid_backend.c
  src/raid/raid_mdadm.c
)

add_library(i2bdg_objs OBJECT ${I2BDG_SOURCES})
target_include_directories(i2bdg_objs PUBLIC include src/libi2 src/lib src/hal src/hal/smbus src/hal/mmio src/raid)
set_property(TARGET i2bdg_objs PROPERTY POSITION_INDEPENDENT_CODE ON)

add_library(i2bdg STATIC $<TARGET_OBJECTS:i2bdg_objs>)
target_include_directories(i2bdg PUBLIC include src/libi2 src/lib src/hal src/hal/smbus src/hal/mmio src/raid)
target_link_libraries(i2bdg PUBLIC i2 hal)

add_library(i2bdg_shared SHARED $<TARGET_OBJECTS:i2bdg_objs>)
set_target_properties(i2bdg_shared PROPERTIES OUTPUT_NAME i2bdg)
target_include_directories(i2bdg_shared PUBLIC include src/libi2 src/lib src/hal src/hal/smbus src/hal/mmio src/raid)
target_link_libraries(i2bdg_shared PUBLIC i2 hal)

# ---- CLI tools (mirror the vendor samples) -----------------------------------
add_library(toolcommon STATIC src/tools/tool_common.c)
target_include_directories(toolcommon PUBLIC include src/libi2 src/lib src/tools)

set(TOOLS getfan getelemcnt getenc gettmpr getvolt getled setled
          getrecled setrecled getsysled setsysled alarm wdt vpd)
foreach(t ${TOOLS})
  add_executable(${t} src/tools/${t}.c)
  target_link_libraries(${t} PRIVATE toolcommon i2bdg i2)
  target_include_directories(${t} PRIVATE include src/libi2 src/lib src/tools)
endforeach()

# asmprobe: read-only on-device ASM2106B GET-command dumper (verify-mmio.sh).
add_executable(asmprobe src/tools/asmprobe.c)
target_link_libraries(asmprobe PRIVATE hal)
target_include_directories(asmprobe PRIVATE src/hal src/hal/mmio)

# ---- opasd: the OPAS API/IPC daemon (Phase 3) --------------------------------
# Socket-free dispatch core + session envelope + JSON emitter, reused by both the
# daemon and its unit test. Rides libi2bdg/libi2 (the collapsed engine path).
add_library(opas_core STATIC
  src/opasd/opas_json.c
  src/opasd/opas_session.c
  src/opasd/opas_dispatch.c
)
target_include_directories(opas_core PUBLIC include src/libi2 src/lib src/opasd)
target_link_libraries(opas_core PUBLIC i2bdg i2)

add_executable(opasd src/opasd/opasd.c)
target_link_libraries(opasd PRIVATE opas_core)

# ---- snmpd: net-snmp pass_persist subagent + trap emitter (Phase 5) ----------
# Linux replacement for prsnmpagent.dll. Serves the raidv4 subtree from the same
# libi2bdg substrate the AgentX subagent drove via pri2apiv4 i2_get* calls.
add_executable(promise_snmp
  src/snmpd/promise_snmp.c
  src/snmpd/snmp_leaves.c
)
target_link_libraries(promise_snmp PRIVATE toolcommon i2bdg i2)
target_include_directories(promise_snmp PRIVATE include src/libi2 src/lib src/tools src/snmpd)

add_executable(promise_trap
  src/snmpd/promise_trap.c
  src/snmpd/event_map.c
)
target_include_directories(promise_trap PRIVATE src/snmpd)

# ---- emaild: engine-event -> SMTP alert daemon (Phase 5) --------------------
# Linux port of clitest/emaild.exe: reuses the shared event map (event_EN.xml)
# and a minimal SMTP client in place of the Windows mail-go.exe.
add_executable(emaild
  src/emaild/emaild.c
  src/emaild/smtp.c
  src/emaild/emailcfg.c
  src/snmpd/event_map.c
)
target_include_directories(emaild PRIVATE src/emaild src/snmpd)

# ---- helper daemons: catalog scaffolds (Phase 5) ----------------------------
# bgasched / pwrmon / prevtlogd - recovered behaviour; trivial paths implemented
# (config load, syslog write, ShutdownHBA notify), deep i2 ops stubbed
# (CALIBRATE). See docs/RE-snmp-daemons.md. SLP = OpenSLP slpd + src/slp/promise.reg.
add_library(daemon_util STATIC src/daemons/daemon_util.c)
target_include_directories(daemon_util PUBLIC src/daemons)

add_executable(bgasched src/daemons/bgasched.c)
target_link_libraries(bgasched PRIVATE daemon_util)

add_executable(pwrmon src/daemons/pwrmon.c)
target_link_libraries(pwrmon PRIVATE daemon_util)

add_executable(prevtlogd src/daemons/prevtlogd.c src/snmpd/event_map.c)
target_link_libraries(prevtlogd PRIVATE daemon_util)
target_include_directories(prevtlogd PRIVATE src/snmpd)

# ---- promise-encld: the enclosure runtime daemon (fans + status LEDs) -------
# Replaces the Python promise-hwmon fan/LED control on the bare A3120: drives
# the NCT7802Y blower + nct6776 CPU fan from the fanconfig.json curves (with
# FanCalibration.json percentage mode) and keeps Power/Fan/System LEDs live.
add_library(encld_core STATIC src/encld/encld_curve.c src/encld/encld_netled.c)
target_include_directories(encld_core PUBLIC src/encld include)

add_executable(promise_encld src/encld/promise_encld.c)
set_target_properties(promise_encld PROPERTIES OUTPUT_NAME promise-encld)
target_link_libraries(promise_encld PRIVATE encld_core hal)
if(ENCLD_STATIC)   # scripts/build-encld-static.sh: fully static deploy binary
  target_link_options(promise_encld PRIVATE -static)
endif()

# ---- promise-raid-helper: setuid-root privilege bridge for the mdadm backend --
# www-data (php_i2) can't run mdadm/smartctl/wipefs or open raw disks. This tiny
# whitelisting helper is the ONLY privileged path; install-webpam.sh installs it
# setuid root (root:www-data, mode 4750). Standalone - no engine linkage.
add_executable(promise_raid_helper src/raid/promise_raid_helper.c)
set_target_properties(promise_raid_helper PROPERTIES OUTPUT_NAME promise-raid-helper)

# ---- unit tests (against the mock scsi_transport) ----------------------------
enable_testing()
set(TESTS test_scsi test_led test_sensors test_alarm_wdt test_vpd test_raid
          test_raid_mdadm test_smbus test_mmio)
foreach(tst ${TESTS})
  add_executable(${tst} tests/${tst}.c)
  target_link_libraries(${tst} PRIVATE i2bdg i2)
  target_include_directories(${tst} PRIVATE include src/libi2 src/lib src/raid)
  add_test(NAME ${tst} COMMAND ${tst})
endforeach()

# opasd dispatch test also links opas_core (propagates its include dirs).
add_executable(test_opas tests/test_opas.c)
target_link_libraries(test_opas PRIVATE opas_core i2bdg i2)
target_include_directories(test_opas PRIVATE include src/libi2 src/lib src/opasd)
add_test(NAME test_opas COMMAND test_opas)

# encld decision core + hwmon PWM control (sysfs fixture; no hardware).
add_executable(test_encld tests/test_encld.c)
target_link_libraries(test_encld PRIVATE encld_core hal)
add_test(NAME test_encld COMMAND test_encld)

# promise-raid-helper security validation: runs the real (non-setuid) binary and
# asserts it rejects bad verbs/partitions/excluded disks and lets valid device
# args reach the setuid(0) step (which fails as non-root - proving validation).
add_test(NAME test_raid_helper
         COMMAND bash ${CMAKE_CURRENT_SOURCE_DIR}/tests/test_raid_helper.sh $<TARGET_FILE:promise_raid_helper>)
