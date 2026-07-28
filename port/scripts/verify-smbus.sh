#!/usr/bin/env bash
#
# verify-smbus.sh - READ-ONLY on-device check of the A3120 SMBus enclosure.
#
# Reads the PCA9555 panel output registers (0x20/0x21) and the NCT7802Y fan
# tach/PWM registers (0x2e) via i2cget and decodes them. It issues NO writes
# (never i2cset) - it will not change a single LED or fan. Compare the decoded
# state against what the front panel actually shows to confirm the pin map in
# docs/RE-enclosure-smbus.md before trusting the SMBus backend's writes.
#
# Usage: sudo ./verify-smbus.sh [i2c_bus]      (default bus 0 = Intel i801)
# Needs: i2c-tools (i2cget), root.
set -euo pipefail

BUS="${1:-0}"
FP=0x20   # FrontPanel  (HDD slot LEDs)
NP=0x21   # NetworkPanel (system LEDs)
NCT=0x2e  # NCT7802Y

echo "=== verify-smbus.sh  (READ-ONLY - no i2cset, no writes) ==="
echo "i2c bus: $BUS"

if ! command -v i2cget >/dev/null 2>&1; then
  echo "ERROR: i2cget not found - install i2c-tools" >&2; exit 1
fi

get() { i2cget -y "$BUS" "$1" "$2" 2>/dev/null || echo "ERR"; }
bit() { echo $(( ( $1 >> $2 ) & 1 )); }
onoff() { [ "$1" = "1" ] && echo "ON " || echo "off"; }

echo
echo "--- PCA9555 FrontPanel $FP (HDD slot LEDs) ---"
O0=$(get $FP 0x02); O1=$(get $FP 0x03)
echo "OUTPUT_0=0x02 -> $O0   OUTPUT_1=0x03 -> $O1"
if [ "$O0" != "ERR" ]; then
  d=$((O0))
  for bay in 1 2 3 4; do
    g=$(( (bay-1)*2 )); r=$(( g+1 ))
    printf "  HDD%d  green(pin%d)=%s  red(pin%d)=%s\n" \
      "$bay" "$g" "$(onoff $(bit $d $g))" "$r" "$(onoff $(bit $d $r))"
  done
fi

echo
echo "--- PCA9555 NetworkPanel $NP (system LEDs) ---"
O0=$(get $NP 0x02); O1=$(get $NP 0x03)
echo "OUTPUT_0=0x02 -> $O0   OUTPUT_1=0x03 -> $O1"
if [ "$O0" != "ERR" ] && [ "$O1" != "ERR" ]; then
  p0=$((O0)); p1=$((O1))
  printf "  USB     green(0)=%s red(1)=%s\n"     "$(onoff $(bit $p0 0))" "$(onoff $(bit $p0 1))"
  printf "  Blower  green(2)=%s red(3)=%s\n"     "$(onoff $(bit $p0 2))" "$(onoff $(bit $p0 3))"
  printf "  RAID    green(4)=%s red(5)=%s\n"     "$(onoff $(bit $p0 4))" "$(onoff $(bit $p0 5))"
  printf "  Power   green(6)=%s\n"               "$(onoff $(bit $p0 6))"
  printf "  Record  red(7)=%s\n"                 "$(onoff $(bit $p0 7))"
  printf "  System  green(8)=%s red(9)=%s\n"     "$(onoff $(bit $p1 0))" "$(onoff $(bit $p1 1))"
  printf "  Net1    flash(10)=%s solid(11)=%s\n" "$(onoff $(bit $p1 2))" "$(onoff $(bit $p1 3))"
  printf "  Net2    flash(12)=%s solid(13)=%s\n" "$(onoff $(bit $p1 4))" "$(onoff $(bit $p1 5))"
fi

echo
echo "--- NCT7802Y $NCT (blower tach + PWM) ---"
echo "NOTE: valid tach needs the chip in bank 0 / tach mode. If a fan daemon or"
echo "      the Python driver has run, it is initialized. This script does NOT"
echo "      write, so it reads whatever mode the chip is currently in."
H8=$(get $NCT 0x10); LO=$(get $NCT 0x13)
P1=$(get $NCT 0x60); P2=$(get $NCT 0x61)
echo "FAN_IN1=0x10 -> $H8   FAN_LOW=0x13 -> $LO   FANCTL1=0x60 -> $P1   FANCTL2=0x61 -> $P2"
if [ "$H8" != "ERR" ] && [ "$LO" != "ERR" ]; then
  h8=$((H8)); lo=$((LO))
  if [ "$h8" = "255" ] && [ "$lo" = "248" ]; then
    echo "  blower: stopped/not-present (0xFF/0xF8 sentinel)"
  else
    tach=$(( ( (h8 << 5) | (lo >> 3) ) >> 4 ))
    if [ "$tach" -gt 0 ] && [ "$tach" -ne 255 ]; then
      echo "  blower RPM = 1350000/($tach*16) = $(( 1350000 / (tach * 16) ))"
    else
      echo "  blower: tach=$tach -> 0 RPM"
    fi
  fi
fi
[ "$P1" != "ERR" ] && echo "  FANCTL1 PWM = $((P1)) / 255"

echo
echo "=== done (nothing was written) ==="
