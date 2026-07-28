#!/usr/bin/env bash
# Security validation for promise-raid-helper (the setuid-root privilege bridge).
#
# Runs the REAL binary (built, but NOT setuid here) as whoever ctest is: every
# rejection path must fire on bad input; valid device args must get PAST
# validation. We assert on the stderr reason. Rejections are printed with the
# "promise-raid-helper:" prefix BEFORE any setuid/exec, so they're deterministic
# regardless of whether the test runs as root (exec's the tool) or not
# (setuid(0) then fails) - a "passing" case simply carries no rejection reason.
set -u
H="${1:?usage: test_raid_helper.sh <path-to-promise-raid-helper>}"
fail=0

# A rejection must exit non-zero AND print the expected reason substring.
reject() {  # <desc> <want-substr> <helper args...>
  local desc="$1" want="$2"; shift 2
  local err rc
  err=$("$H" "$@" 2>&1 >/dev/null); rc=$?
  if [ "$rc" -eq 0 ]; then echo "FAIL [$desc]: expected non-zero exit"; fail=1; return; fi
  case "$err" in
    *"$want"*) echo "ok   [$desc]" ;;
    *) echo "FAIL [$desc]: want '$want', got: $err"; fail=1 ;;
  esac
}

# A pass must clear validation: stderr carries NONE of the rejection reasons.
# What happens AFTER validation is environment-dependent and treated as success:
# "tool not installed" (host lacks mdadm/smartctl), "setuid failed" (non-root
# runner), or the tool actually running (root runner) - all mean validation OK.
pass() {  # <desc> <helper args...>
  local desc="$1"; shift
  local err
  err=$("$H" "$@" 2>&1 >/dev/null)
  case "$err" in
    *"not permitted"*|*"not allowed"*|*"refusing"*|*"bad md"*|*"bad action"*)
      echo "FAIL [$desc]: unexpected rejection: $err"; fail=1 ;;
    *) echo "ok   [$desc]" ;;
  esac
}

# (High device letters sdx/sdy/sdw/md9 are used for the "pass" cases so a host
# whose real OS disk is /dev/sda can't trip the in-use guard and false-fail.)
reject "unknown verb refused"        "verb not permitted" bogus arg
reject "partition target refused"    "not allowed"        mdadm --create /dev/md9 --level=5 /dev/sdx1 /dev/sdy /dev/sdw
reject "bad /dev token refused"      "not allowed"        wipefs -a /dev/../etc/shadow
reject "syncaction bad action"       "bad action"         syncaction md9 wipe-it
reject "syncaction bad md name"      "bad md"             syncaction sdx check
# Destructive op on an excluded disk must trip the in-use/OS guard (deterministic
# without needing a real mount: VESSAPP_RAID_EXCLUDE seeds the in-use set).
err=$(VESSAPP_RAID_EXCLUDE=sdz "$H" wipefs -a /dev/sdz 2>&1 >/dev/null); rc=$?
if [ "$rc" -ne 0 ] && printf '%s' "$err" | grep -q "refusing destructive"; then
  echo "ok   [exclude/OS-disk guard]"
else echo "FAIL [exclude/OS-disk guard]: rc=$rc err=$err"; fail=1; fi

pass "valid smartctl device"         smartctl -i -A -H /dev/sdx
pass "valid whole-disk create"       mdadm --create /dev/md9 --level=5 --raid-devices=3 /dev/sdx /dev/sdy /dev/sdw
pass "valid syncaction"              syncaction md9 check

# mdadm.conf persistence verbs (mdconf-add/del): reject malformed tokens - nothing
# but md[0-9]+ and a [0-9a-f:] UUID may reach the conf writer.
reject "mdconf-add bad md"            "bad md"   mdconf-add sdz aaaa1111:bbbb2222:cccc3333:dddd4444
reject "mdconf-add bad uuid"          "bad uuid" mdconf-add md9 xyz
reject "mdconf-del bad md"            "bad md"   mdconf-del sdz

# Behaviour: a non-setuid self-run honours PROMISE_MDADM_CONF, so point it at a
# temp conf and assert the add is idempotent (upsert) and the del removes the line.
conf="$(mktemp)"; : > "$conf"
PROMISE_MDADM_CONF="$conf" "$H" mdconf-add md9 aaaa1111:bbbb2222:cccc3333:dddd4444 >/dev/null 2>&1
PROMISE_MDADM_CONF="$conf" "$H" mdconf-add md9 aaaa1111:bbbb2222:cccc3333:dddd4444 >/dev/null 2>&1
n=$(grep -c '^ARRAY /dev/md9 ' "$conf")
if [ "$n" -eq 1 ]; then echo "ok   [mdconf-add upsert idempotent]"
else echo "FAIL [mdconf-add upsert]: want 1 line, got $n"; fail=1; fi
PROMISE_MDADM_CONF="$conf" "$H" mdconf-del md9 >/dev/null 2>&1
if grep -q '/dev/md9' "$conf"; then echo "FAIL [mdconf-del]: line not removed"; fail=1
else echo "ok   [mdconf-del removes line]"; fi
rm -f "$conf"

if [ "$fail" -eq 0 ]; then echo "test_raid_helper: ALL PASS"; else echo "test_raid_helper: FAILURES"; exit 1; fi
