#!/usr/bin/env bash
# calibrate.sh - on-device bring-up for the Vess A3120 in-band substrate.
#
# Runs on the REAL A3120 (Linux) to close the hardware gate for Phase 2. The
# Windows stack drives the enclosure IN-BAND: a 16-byte Promise vendor CDB
# (0xD8 0xBA + MicOpCode) over SCSI-passthru to the RAID controller (see
# docs/RE-fwinterf-pri2api.md). On Linux that is /dev/sgN + SG_IO. This script:
#   [1] discovers /dev/sg* and identifies the Promise "Vess" controller (INQUIRY)
#   [2] probes for STANDARD SES-2 (sg_ses) - the potential shortcut that could
#       drive fans/LEDs with NO proprietary MicOpCode. REPORTED PROMINENTLY.
#   [3] MicOpCode round-trip self-test (GetEnclosureInfo 0x000F via sg_raw)
#   [4] cross-checks the recovered CDB/offsets (model byte @page+0x0e == 'R')
#
# Needs sg3-utils (sg_inq, sg_ses, sg_raw). Read-only except the optional LED
# self-test in [4b] which you must opt into.
#
# Usage:
#   sudo ./calibrate.sh              # scan, identify, probe SES, self-test
#   sudo ./calibrate.sh /dev/sg3     # target a specific device
set -uo pipefail

HDR="$(cd "$(dirname "$0")/.." && pwd)/include/i2_micops.h"
MAP="$(cd "$(dirname "$0")/.." && pwd)/include/i2c_map.h"

say()  { printf '%s\n' "$*"; }
rule() { printf '%s\n' "============================================================"; }
# hv <header> <macro> -> the macro's value (single source of truth = the headers)
hv() { awk -v k="$2" '$1=="#define" && $2==k {print $3; exit}' "$1"; }

need() { command -v "$1" >/dev/null 2>&1 || { say "MISSING: $1 (apt-get install sg3-utils)"; return 1; }; }

rule; say " Vess A3120 in-band substrate calibration (Phase 2)"; rule
need sg_inq || exit 1
need lsscsi 2>/dev/null || say "(lsscsi optional)"

# ---------------------------------------------------------------------------
say ""; say "[1] Discover the Promise controller among /dev/sg*"
TARGET="${1:-}"
FOUND=""
for d in ${TARGET:-/dev/sg*}; do
    [ -e "$d" ] || continue
    inq="$(sg_inq "$d" 2>/dev/null)" || continue
    vendor="$(printf '%s\n' "$inq" | awk -F"'" '/Vendor identification/{print $2}')"
    product="$(printf '%s\n' "$inq" | awk -F"'" '/Product identification/{print $2}')"
    printf "    %-10s vendor='%s' product='%s'\n" "$d" "$vendor" "$product"
    if printf '%s' "$vendor" | grep -qi promise || printf '%s' "$product" | grep -qi vess; then
        FOUND="$d"
        say "    -> MATCH: Promise/Vess controller at $d"
    fi
done
[ -z "$FOUND" ] && { say "    No Promise/Vess controller found. Is the RAID driver loaded? (lsscsi)"; exit 1; }
SG="$FOUND"

# ---------------------------------------------------------------------------
say ""; rule
say "[2] *** SES-2 SHORTCUT PROBE (do this FIRST) ***"
say "    If the SEP answers standard SES-2, fans/LEDs may be drivable with stock"
say "    sg_ses and NO proprietary MicOpCode - sidestepping most of the RE."
rule
if need sg_ses; then
    # Look for a real enclosure (SES) device; the SEP may enumerate separately.
    say "    Enclosure (SES) devices seen by the kernel:"
    ls -1 /sys/class/enclosure/ 2>/dev/null | sed 's/^/      /' || say "      (none in /sys/class/enclosure)"
    for d in ${TARGET:-/dev/sg*}; do
        [ -e "$d" ] || continue
        if sg_ses "$d" >/dev/null 2>&1; then
            say "    >>> $d ANSWERS standard SES-2. Supported diagnostic pages:"
            sg_ses "$d" 2>/dev/null | sed -n '1,20p' | sed 's/^/        /'
            say "    >>> If this covers Enclosure Status + Element Descriptor, prefer"
            say "        the standard-SES path (sg_ses) over the MicOpCode path."
        fi
    done
    sg_ses "$SG" >/dev/null 2>&1 || say "    $SG does NOT answer SES-2 -> proprietary MicOpCode path required (expected)."
fi

# ---------------------------------------------------------------------------
say ""; rule
say "[3] MicOpCode round-trip self-test: GetEnclosureInfo (0x000F) via sg_raw"
rule
if need sg_raw; then
    # CDB = D8 BA <micop LE=0F 00> 00 00 00 00 00 00 00 <xferlen BE=00 88> 00 00 00 00
    CDB="D8 BA 0F 00 00 00 00 00 00 00 00 88 00 00 00 00"
    say "    CDB: $CDB   (0xD8 0xBA + MicOpCode 0x000F + xferlen 0x0088 BE @10..11)"
    OUT="$(sg_raw -r 0x88 "$SG" $CDB 2>&1)" && {
        say "    sg_raw returned data. First 32 bytes:"
        printf '%s\n' "$OUT" | grep -E '^ *[0-9a-f]{4}' | head -2 | sed 's/^/      /'
        # model byte at page+0x0e
        MODOFF="$(hv "$MAP" I2C_MAP_ENC_INFO_OFF_MODEL)"
        say "    -> inspect byte at page offset $MODOFF (I2C_MAP_ENC_INFO_OFF_MODEL):"
        say "       expect 'W' (0x57) for the A3120 (1U/4-bay, golden-confirmed)."
    } || {
        say "    sg_raw failed: $OUT"
        say "    If ILLEGAL REQUEST/INVALID OPCODE: the vendor opcode/MicOpCode differs on"
        say "    this FW revision -> capture a Windows SCSI trace and update i2_micops.h."
    }
fi

# ---------------------------------------------------------------------------
say ""; rule
say "[4] Cross-check recovered constants (verify behaviour, not invent bytes)"
rule
say "    MicOpCodes (i2_micops.h):"
for k in I2_MICOP_GET_ENCL_INFO I2_MICOP_GET_ENCL_STATUS I2_MICOP_SET_LED_FRU \
         I2_MICOP_GET_LED_FRU I2_MICOP_SET_WDT I2_MICOP_GET_TERM_ALARM \
         I2_MICOP_SET_TERM_ALARM I2_MICOP_GET_CTRLEX_INFO I2_MICOP_SET_CTRLEX; do
    printf "      %-26s %s\n" "$k" "$(hv "$HDR" "$k")"
done
say ""
say "    Enclosure STATUS page (0x400) offsets to confirm against a live dump:"
say "      model byte @ $(hv "$MAP" I2C_MAP_ENC_INFO_OFF_MODEL)  ('R' for A3120)"
say "      NumFans @ page[$(hv "$MAP" I2C_MAP_ENC_CNT_OFF_FANS)]  NumTemp @ page[$(hv "$MAP" I2C_MAP_ENC_CNT_OFF_TEMP)]"
say "      fan-status base +$(hv "$MAP" I2C_MAP_ENC_STATUS_OFF_FANSTS)  temp +$(hv "$MAP" I2C_MAP_ENC_STATUS_OFF_TEMP)  speed/volt +$(hv "$MAP" I2C_MAP_ENC_STATUS_OFF_SPEED)"
say "      fan RPM = $(hv "$MAP" I2C_MAP_FAN_RPM_NUMERATOR) / (raw_tach * k)   [k: A3120 fans0-1=16, 2+=2]"
say "    CALIBRATE (need a real 0x400 status dump to pin down):"
say "      - the located enclosure base (I2C_MAP_ENC_STATUS_BASE, assumed 0)"
say "      - identities of page[1],[2] region counts; the voltage-count byte"
say ""
say "  [4b] OPTIONAL LED self-test (WRITES hardware): set RECORD LED red then off."
say "       Run manually only when safe:"
say "         sg_raw -s 4 $SG D8 BA 0F 13 00 00 0F 02 00 00 00 04 00 00 00 00 </dev/zero"
say "       (MicOpCode 0x130F, FRU 0x0f=RECORD @CDB[6], colour 0x02=RED @CDB[7])"
say ""
say ""; rule
say "[5] READ-ONLY RAID enumeration self-test (list phydrv / logdrv / arrays)"
say "    Non-destructive: pure GET MicOpCodes (family 0x04) over sg_raw; no config"
say "    is changed. Confirms the Phase-2b storage-config opcodes on this FW."
rule
if need sg_raw; then
    # CDB = D8 BA <family=04> <sub> 00 00 <id BE=00 00> <sel> 00 <xfer BE=10 00> 00 00 00 00
    # MicOpCode = (sub<<8)|family; xferlen 0x1000. See i2_micops.h / docs/RE-i2-raid.md.
    enum() { # label sub sel  (family fixed 04)
        cdb="D8 BA 04 $2 00 00 00 00 $3 00 10 00 00 00 00 00"
        printf "    %-8s MicOp 0x%s04  CDB: %s\n" "$1" "$2" "$cdb"
        out="$(sg_raw -r 0x1000 "$SG" $cdb 2>&1)" \
            && say "      -> OK: $1 page returned (inspect length u32 @ +0x24/+0x20, records @ +0x40)" \
            || say "      -> $1 FAILED: $(printf '%s' "$out" | grep -iE 'sense|illegal|invalid|error' | head -1)"
    }
    enum phydrv 08 ff   # I2_MICOP_GET_PHYDRV_INFO, verbosity 0xff -> 0x188 records
    enum logdrv 0B 00   # I2_MICOP_GET_LOGDRV_INFO,  records 0xC8
    enum array  05 00   # I2_MICOP_GET_ARRAY_INFO,   records 0xB8, length @ +0x20
    say ""
    say "    If ILLEGAL REQUEST/INVALID OPCODE: this FW revision differs -> capture a"
    say "    Windows SCSI trace and reconcile i2_micops.h. NEVER run a create/delete"
    say "    (0x0004/0x0104/0x0404) here; the DATA-OUT payload is unconfirmed (CALIBRATE)."
fi

say ""; rule
say "After confirming a value, edit include/i2c_map.h or i2_micops.h and re-run"
say "build-in-container.sh so the unit tests stay honest against the real bytes."
