# RE findings: A3120 enclosure over SMBus (PCA9555 LEDs + NCT7802Y fans)

On the user's bare Linux/Proxmox A3120 boxes the enclosure LEDs, fans and sensors are
**not** behind the Promise controller's in-band SEP path (that is the Windows *appliance*
model — see `docs/RE-libi2bdg.md` §4.1, which left "does a direct /dev/i2c path exist?"
explicitly open). They are motherboard chips on the Intel i801 SMBus, **`i2c-0`**:

- **PCA9555 @ 0x20** — FrontPanel (HDD slot LEDs)
- **PCA9555 @ 0x21** — NetworkPanel (system / network LEDs)
- **NCT7802Y @ 0x2e** — blower tach + PWM
- **nct6776** (hwmon0) — board temps + extra fan PWM (kernel driver)

This resolves that open question for this hardware: the direct SMBus path **does** exist.

**Provenance:** the user's proven, physically-tested Python driver in
`reference/promise-a3120-linux-driver/truenas-addon/app/` (`ledctl.py`, `hwmon.py`,
`fanctl.py`) + `config.yaml`. Every map below is reused **verbatim** from it — these are
tested-on-hardware facts, not guesses. Implemented in `port/src/hal/smbus/`, selected by
`VESSAPP_BACKEND=smbus`.

---

## 1. PCA9555 register model

Register map (`ledctl.py` PCA9555): `0x00/0x01` Input 0/1, `0x02/0x03` **Output 0/1**,
`0x06/0x07` Config 0/1. Pin = `port*8 + bit`. The panel pins are already configured as
outputs by the board, so — exactly like the Python `_init` — the port **never writes the
config registers**; it read-modify-writes only the OUTPUT registers (`pca9555.c`).

## 2. LED FRU → PCA9555 pin table

Maps the port's LED FRU ids + colours (`i2bdg_api.h`) onto green/red pins. `smbus_encl.c`
`LEDS[]` is this table verbatim.

| FRU (i2bdg_api.h) | id | addr | green pin | red pin | panel line (ledctl.py) |
|---|---|---|---|---|---|
| HDD_SLOT1 | 0x80 | 0x20 | 0 | 1 | HDD1 green/red |
| HDD_SLOT2 | 0x81 | 0x20 | 2 | 3 | HDD2 |
| HDD_SLOT3 | 0x82 | 0x20 | 4 | 5 | HDD3 |
| HDD_SLOT4 | 0x83 | 0x20 | 6 | 7 | HDD4 |
| USB1 | 0x0d | 0x21 | 0 | 1 | USB green/red |
| FAN1 | 0x02 | 0x21 | 2 | 3 | Blower green/red |
| RAID_STS | 0x0c | 0x21 | 4 | 5 | RAID green/red |
| PWR | 0x14 | 0x21 | 6 | — | Power (green-only) |
| RECORD | 0x0f | 0x21 | — | 7 | Recording (red-only) |
| SYSTEM | 0x10 | 0x21 | 8 | 9 | System status green/red |
| ETH_PORT1 | 0x12 | 0x21 | 11 (solid) | 10 (flash) | Net1 link/activity |
| ETH_PORT2 | 0x13 | 0x21 | 13 (solid) | 12 (flash) | Net2 link/activity |

**Colour → pins:** `GREEN` = green on / red off; `RED` = green off / red on; `AMBER` =
both on; `OFF` = both off. `BLINK_*` is set as the steady colour — autonomous blink needs
a software toggler (the Python `_blink_loop`), out of scope for a single `setLED`. `getLED`
reconstructs the colour from the two OUTPUT-register bits.

**UNRESOLVED / not wired on this box** (the Python driver maps no pins for these — the
FrontPanel PCA9555 exposes only 4 bays): `HDD_SLOT5..16`, `FAN2`, `USB2`, `ETH_PORT3/4`,
`GLOBAL_HDD_STS`, `HeartBeat`. `setLED` on them returns `UNSUPPORTED` (no I2C write). The
SYSTEM `cause` u16 has no dedicated panel pin (UNRESOLVED — not stored in hardware).

Wired i2bdg calls: `i2bdg_setLED`/`getLED` (+ SysError/Record variants) dispatch to
`smbus_led_write`/`smbus_led_read` when `VESSAPP_BACKEND=smbus`, keeping the existing
i2bdg validation (HDD-slot read-only, Record RED/OFF-only, etc.).

## 3. NCT7802Y fans — register map, tach, PWM floor

Register map + sequences reused verbatim from `hwmon.py` (`nct7802y.c`):

| reg | role |
|---|---|
| 0x00 | bank select (0) |
| 0x10 / 0x13 | tach high byte / low bits (ch1 blower) |
| 0x21 | monitoring start (0 stop / 1 start) |
| 0x22 | CONFIG2 = **0x42** (tach mode) |
| 0x24 | fan enable = 0x03 (ch1+ch2) |
| 0x55 / 0x56 | tach divisors = 0x10 |
| 0x5e / 0x5f | output type PWM (0) / mode manual (0) |
| 0x60 / 0x61 | FANCTL1 / FANCTL2 PWM (both blowers) |

**RPM formula** (validated against the Windows Prom_Enclosure calibration tool):

```
tach = ((reg0x10 << 5) | (reg0x13 >> 3)) >> 4        (0xFF/0xF8 => stopped)
RPM  = 1350000 / (tach * 16)
```

Wired to `i2bApi_getFanInfo` (blower + any hwmon fans). `getElementCnt` reports the same
counts.

**Fan curve + hard floor (thermal safety).** `smbus_fan_pwm_for_temp` is the step-lookup
from `fanctl.py` `DEFAULT_FANCONFIG` raw-PWM mode (no calibration file present):

| temp band | CPU PWM | SYS PWM |
|---|---|---|
| ≤59 | 130 | 40 |
| ≤69 | 160 | 90 |
| ≤79 | 190 | 140 |
| ≤89 | 220 | 190 |
| ≥90 | 250 | 250 |

`nct7802y_set_pwm` clamps to **`NCT7802Y_PWM_FLOOR = 20` (~8%) and never writes 0** — the
never-stop-the-fans guard from the Python driver. A below-floor or zero request is floored
(unit-tested). **CALIBRATE:** the user's on-device `fanconfig.json` uses *percentages* of a
measured RPM range and needs a `FanCalibration.json`; without it the raw-PWM defaults above
are the safe, proven fallback (exactly the Python driver's two modes).

## 4. Temperatures / voltages

- **Temps** (`smbus_getThermalSensorInfo`): from `/sys/class/hwmon/*/tempN_input`
  (nct6776 = hwmon0, coretemp, …), mirroring `hwmon.py hwmon_sysfs_temps`. Root
  overridable via `VESSAPP_HWMON_ROOT` (test fixtures).
- **Voltages** (`smbus_getVoltageSensorInfo`): **CALIBRATE.** The reference Python driver
  does not read voltage; these come from the standard kernel `hwmon inN_input` rails
  (millivolts → packed hi-nibble.lo-nibble volts). The rail set + scaling need on-device
  confirmation. No nct7802y voltage registers were invented.

## 5. On-device confirmation

`port/scripts/verify-smbus.sh` (READ-ONLY, `i2cget` only — no writes) dumps the PCA9555
output registers and NCT7802Y tach/PWM and decodes them, so the pin map above can be
checked against the physical panel before trusting any LED/fan write.
