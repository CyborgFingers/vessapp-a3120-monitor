# A3120 calibration — golden Windows reference box

Ground-truth captured from a **blank Vess A3120 running the real Promise vendor
stack** (Windows 10, `C:\PromiseApp`, all services live) via the vendor CLU:

```
clitest.exe -u administrator -p password -C <class> -a list -v
```

Full raw dump: [`samples/clu-verbose-golden.txt`](samples/clu-verbose-golden.txt).
This is the authority for every value the port previously marked "CALIBRATE on
device". The A3120 boxes are identical hardware (only the OS differs), so this
applies to the Linux boxes too.

## CORRECTIONS to the port (were wrong / assumed)

| Item | Port had | **Real (golden)** |
|------|----------|-------------------|
| Enclosure type | `ENC_TYPE_VESS_3U16BAY` (0x52) | **`ENC_TYPE_VESS_1U4BAY`** |
| Drive slots | 16 | **4** |
| Front-view image | `enclosureFrontView_3U16` | **1U/4-bay image** (`enclosureImages/`) |
| Enclosure sensor temp threshold | 60/70 °C default | **< 90 °C warn, 100 °C crit** (ctrl); drive 55/65 °C |
| PSU status decode | unresolved (empty) | **"Powered On and Functional"** (1 PSU) |
| Fans vs blowers | ambiguous | **0 fans, 3 blowers** ("Cooling Unit 1/2/3") |
| Voltage rail location | unresolved | **"Controller"**, 4 rails |

## Enclosure (ENC_TYPE_VESS_1U4BAY, id 1) — component counts

```
MaxNumOfControllers: 1     MaxNumOfPhyDrvSlots: 4
MaxNumOfFans: 0            MaxNumOfBlowers: 3
MaxNumOfTempSensors: 2     MaxNumOfPSUs: 1
MaxNumOfBatteries: 0       MaxNumOfVoltageSensors: 4
PowerSwitch: Enabled
CtrlWarningTempThreshold: 90C/194F   CtrlCriticalTempThreshold: 100C/212F
```

- **PSU 1**: `Powered On and Functional`
- **Blowers (3)** — location `Cooling Unit N`, healthy `> 600 RPM`; observed 3013 / 5625 / 5625 RPM
- **Temp sensors (2)**: `CPU` (39C), `Controller` (24C), healthy `< 90C/194F`
- **Voltage sensors (4)**, all location `Controller`:
  - 1.0V (0.0–1.3), 5.0V (4.7–5.3), 12.0V (11.4–12.6), 3.3V (3.1–3.5)
- **FRU VPD**: FRUType `Backplane`, FRU#1, SN `B53L18426200176`, Rev A1, WWN `2000-0001-555f-9316`, PDSN `WR0093003581`

## Subsystem / Controller

- Subsystem: Promise Technology, **Vess A3120**, SN `WR0093003581`, Rev A1, WWN `2000-0001-555f-9316`
- Controller 1: SCSI-3, **16GB DDR4**, Flash 128GB, NVRAM 128KB FRAM
- BootLoader `P3.50`, Firmware `0.00.0040.18`, Software `0.00.0359.68`, InstallPkg `5.00.0000.05` (all **Mar 28 2022** — matches the ported `20220328` package)
- Drive temp thresholds: `PhyDrvTempThreshold 55`, `PhyDrvCriticalTempThreshold 65`

## Array / drives (the path that is DARK on the Proxmox box in AHCI mode)

- **Array DA0** (DaId 0): OK, 14.55TB, 4 PDs, 1 LD, AvailableRAIDLevels `0 3 5 6 10 1E`
- **Logical drive LA0** (LdId 0): **RAID5**, 10.91TB, stripe 1MB, ReadAhead/WriteBack, SYNCed, WWN `2298-0001-556e-85ed`, algorithm "right asymmetric (4)", tolerable dead drives 1
- **Phys drives (4)** — all SATA 6Gb/s, 3.64TB, OK, in Array0:
  - Slot1 `HGST HUS726T4TALE6L4` FW VKGNW9U0, 31C
  - Slot2/3/4 `TOSHIBA MG04ACA400E` FW FP4B, 27/29/28C
  - Fields: BlockSize, NCQ depth 32, SMART yes, WriteCache/RLACache enabled, error counters

## Network (subsys mgmt port)

- Ctrl1 Port1: Up, DHCP, `192.0.2.211/24`, MAC `00:00:5E:00:53:21`, 1000Mbps
- Ctrl1 Port2: Down

## Notes

- `-C buzzer -a list` → `Error (0x401): invalid command` — buzzer uses a different action; not needed for read.
- CLU login `administrator / password` (also the WebPAM default; `userdb.xml` hash `caa2f4891e3c561f6b6e0e79d7591c88`).
- Reliable on-box exec = a `.bat` run via `cmd /c <path>` (PowerShell-as-default-shell over SSH mangles nested quotes and first-line script assignments; `-Command -` stdin is flaky here).
