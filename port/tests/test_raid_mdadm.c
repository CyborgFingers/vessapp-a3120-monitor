/* test_raid_mdadm - the mdadm-backed RAID READ backend (Phase 1, no hardware).
 *
 * Feeds canned lsblk/smartctl/mdadm//proc/mdstat output (mirroring the .212
 * box: 1x HGST HUS726T4TALE6L4 + 3x TOSHIBA MG04ACA400E data disks + an ADATA
 * OS disk that must be EXCLUDED) through the raid_cmd() seam and asserts the
 * produced Promise structs - fresh box (4 free PDs, no arrays) and a RAID5
 * md0 across the 4 (one DA + one synced RAID5 LD). Also proves the
 * VESSAPP_RAID_BACKEND resolution and the RAID_FIXTURE file-based source.
 */
#include "test_util.h"
#include "i2bdg_api.h"
#include "raid_backend.h"
#include <string.h>
#include <stdio.h>
#include <stdlib.h>
#include <unistd.h>

#define TB4 "4000787030016"   /* 3.64 TiB data disks */

/* ---- canned tool output ---------------------------------------------------- */
static const char LSBLK[] =
"NAME=\"sda\" TYPE=\"disk\" SIZE=\"" TB4 "\" MODEL=\"HGST HUS726T4TALE6L4\" PKNAME=\"\" MOUNTPOINT=\"\"\n"
"NAME=\"sdb\" TYPE=\"disk\" SIZE=\"" TB4 "\" MODEL=\"TOSHIBA MG04ACA400E\" PKNAME=\"\" MOUNTPOINT=\"\"\n"
"NAME=\"sdc\" TYPE=\"disk\" SIZE=\"" TB4 "\" MODEL=\"TOSHIBA MG04ACA400E\" PKNAME=\"\" MOUNTPOINT=\"\"\n"
"NAME=\"sdd\" TYPE=\"disk\" SIZE=\"" TB4 "\" MODEL=\"TOSHIBA MG04ACA400E\" PKNAME=\"\" MOUNTPOINT=\"\"\n"
"NAME=\"sde\" TYPE=\"disk\" SIZE=\"512110190592\" MODEL=\"ADATA SU800\" PKNAME=\"\" MOUNTPOINT=\"\"\n"
"NAME=\"sde1\" TYPE=\"part\" SIZE=\"536870912\" MODEL=\"\" PKNAME=\"sde\" MOUNTPOINT=\"/boot/efi\"\n"
"NAME=\"sde2\" TYPE=\"part\" SIZE=\"511562242048\" MODEL=\"\" PKNAME=\"sde\" MOUNTPOINT=\"/\"\n";

/* variant B adds the md0 child rows lsblk really emits (must be ignored) */
static const char LSBLK_MD[] =
"NAME=\"sda\" TYPE=\"disk\" SIZE=\"" TB4 "\" MODEL=\"HGST HUS726T4TALE6L4\" PKNAME=\"\" MOUNTPOINT=\"\"\n"
"NAME=\"md0\" TYPE=\"raid5\" SIZE=\"12001957380096\" MODEL=\"\" PKNAME=\"sda\" MOUNTPOINT=\"\"\n"
"NAME=\"sdb\" TYPE=\"disk\" SIZE=\"" TB4 "\" MODEL=\"TOSHIBA MG04ACA400E\" PKNAME=\"\" MOUNTPOINT=\"\"\n"
"NAME=\"md0\" TYPE=\"raid5\" SIZE=\"12001957380096\" MODEL=\"\" PKNAME=\"sdb\" MOUNTPOINT=\"\"\n"
"NAME=\"sdc\" TYPE=\"disk\" SIZE=\"" TB4 "\" MODEL=\"TOSHIBA MG04ACA400E\" PKNAME=\"\" MOUNTPOINT=\"\"\n"
"NAME=\"md0\" TYPE=\"raid5\" SIZE=\"12001957380096\" MODEL=\"\" PKNAME=\"sdc\" MOUNTPOINT=\"\"\n"
"NAME=\"sdd\" TYPE=\"disk\" SIZE=\"" TB4 "\" MODEL=\"TOSHIBA MG04ACA400E\" PKNAME=\"\" MOUNTPOINT=\"\"\n"
"NAME=\"md0\" TYPE=\"raid5\" SIZE=\"12001957380096\" MODEL=\"\" PKNAME=\"sdd\" MOUNTPOINT=\"\"\n"
"NAME=\"sde\" TYPE=\"disk\" SIZE=\"512110190592\" MODEL=\"ADATA SU800\" PKNAME=\"\" MOUNTPOINT=\"\"\n"
"NAME=\"sde2\" TYPE=\"part\" SIZE=\"511562242048\" MODEL=\"\" PKNAME=\"sde\" MOUNTPOINT=\"/\"\n";

static const char SCAN_MD[] =
"ARRAY /dev/md0 metadata=1.2 name=a3120:0 UUID=8c9a2f4e:11aa22bb:33cc44dd:55ee66ff\n";

static const char DETAIL_MD0[] =
"/dev/md0:\n"
"           Version : 1.2\n"
"     Creation Time : Fri Jul 17 09:00:00 2026\n"
"        Raid Level : raid5\n"
"        Array Size : 11720661504 (10.92 TiB 12.00 TB)\n"
"     Used Dev Size : 3906887168 (3.64 TiB 4.00 TB)\n"
"      Raid Devices : 4\n"
"     Total Devices : 4\n"
"       Persistence : Superblock is persistent\n"
"\n"
"       Update Time : Sat Jul 18 10:00:00 2026\n"
"             State : clean \n"
"    Active Devices : 4\n"
"   Working Devices : 4\n"
"    Failed Devices : 0\n"
"     Spare Devices : 0\n"
"\n"
"            Layout : left-symmetric\n"
"        Chunk Size : 512K\n"
"\n"
"Consistency Policy : resync\n"
"\n"
"              Name : a3120:0  (local to host a3120)\n"
"              UUID : 8c9a2f4e:11aa22bb:33cc44dd:55ee66ff\n"
"            Events : 42\n"
"\n"
"    Number   Major   Minor   RaidDevice State\n"
"       0       8        0        0      active sync   /dev/sda\n"
"       1       8       16        1      active sync   /dev/sdb\n"
"       2       8       32        2      active sync   /dev/sdc\n"
"       3       8       48        3      active sync   /dev/sdd\n";

static const char MDSTAT_CLEAN[] =
"Personalities : [raid6] [raid5] [raid4] \n"
"md0 : active raid5 sdd[3] sdc[2] sdb[1] sda[0]\n"
"      11720661504 blocks super 1.2 level 5, 512k chunk, algorithm 2 [4/4] [UUUU]\n"
"\n"
"unused devices: <none>\n";

static const char MD0_STAT[] = "  120 0 8000 50 300 0 16000 90 0 0 0 0 0 0 0 0 0\n";

/* ---- the injected command seam --------------------------------------------- */
static const char *g_lsblk, *g_scan, *g_detail, *g_mdstat, *g_blkstat;
static int g_sde_smart;   /* the excluded OS disk must never be smart-probed */

/* Write commands (mdadm --create/--stop/--zero-superblock, wipefs, syncaction)
 * are LOGGED verbatim and acknowledged, so the tests assert the exact argv.
 * (raid_cmd_priv runs BARE under the mock, so the helper prefix never appears.) */
#define MAX_WRITES 16
static char g_writes[MAX_WRITES][512];
static unsigned g_nwrites;
static int g_write_rc;               /* forced rc for write cmds (0 = ok)     */

static void writes_reset(void) { g_nwrites = 0; g_write_rc = 0; }

static int mock_cmd(const char *cmd, char *out, size_t cap) {
    const char *resp = NULL;
    if (strncmp(cmd, "mdadm --create ", 15) == 0 ||
        strncmp(cmd, "mdadm --stop ", 13) == 0 ||
        strncmp(cmd, "mdadm --zero-superblock ", 24) == 0 ||
        strncmp(cmd, "mdadm --add ", 12) == 0 ||
        strncmp(cmd, "mdadm --remove ", 15) == 0 ||
        strncmp(cmd, "mdadm --grow ", 13) == 0 ||
        strncmp(cmd, "wipefs ", 7) == 0 ||
        strncmp(cmd, "syncaction ", 11) == 0 ||
        strncmp(cmd, "mdconf-add ", 11) == 0 ||
        strncmp(cmd, "mdconf-del ", 11) == 0 ||
        strncmp(cmd, "update-initramfs", 16) == 0) {
        if (g_nwrites < MAX_WRITES)
            snprintf(g_writes[g_nwrites++], sizeof g_writes[0], "%s", cmd);
        out[0] = '\0';
        return g_write_rc;
    }
    if (strcmp(cmd, "lsblk -Pbn -o NAME,TYPE,SIZE,MODEL,PKNAME,MOUNTPOINT") == 0) resp = g_lsblk;
    /* device-scoped scan = "persist this just-created array" -> its ARRAY line */
    else if (strncmp(cmd, "mdadm --detail --scan /dev/md", 29) == 0) resp = SCAN_MD;
    else if (strcmp(cmd, "mdadm --detail --scan") == 0) resp = g_scan;
    else if (strcmp(cmd, "mdadm --detail /dev/md0") == 0) resp = g_detail;
    else if (strcmp(cmd, "cat /proc/mdstat") == 0) resp = g_mdstat;
    else if (strcmp(cmd, "cat /sys/block/md0/stat") == 0) resp = g_blkstat;
    else if (strncmp(cmd, "smartctl -i -A -H /dev/sd", 25) == 0) {
        char disk = cmd[strlen(cmd) - 1];
        if (disk == 'e') g_sde_smart = 1;
        const char *model = disk == 'a' ? "HGST HUS726T4TALE6L4" : "TOSHIBA MG04ACA400E";
        snprintf(out, cap,
            "=== START OF INFORMATION SECTION ===\n"
            "Device Model:     %s\n"
            "Serial Number:    Z8N0A0F%c\n"
            "Firmware Version: FS2D\n"
            "User Capacity:    4,000,787,030,016 bytes [4.00 TB]\n"
            "Sector Sizes:     512 bytes logical, 4096 bytes physical\n"
            "SMART overall-health self-assessment test result: PASSED\n"
            "ID# ATTRIBUTE_NAME          FLAG     VALUE WORST THRESH TYPE      UPDATED  WHEN_FAILED RAW_VALUE\n"
            "194 Temperature_Celsius     0x0022   036   050   000    Old_age   Always       -       36 (Min/Max 15/55)\n",
            model, disk);
        return 0;
    }
    if (!resp) return -1;
    snprintf(out, cap, "%s", resp);
    return 0;
}

static void use_mdadm(void) { raid_cmd_set(mock_cmd); raid_backend_set(RAID_MDADM); }
static void restore(void)   { raid_cmd_set(NULL); raid_backend_set(-1); }

/* ---- backend selection (VESSAPP_RAID_BACKEND) ------------------------------ */
static void test_backend_env(void) {
    unsetenv("VESSAPP_RAID_BACKEND"); raid_backend_set(-1);
    CHECK(raid_backend() == RAID_SGIO, "default backend must be sgio");
    setenv("VESSAPP_RAID_BACKEND", "mdadm", 1); raid_backend_set(-1);
    CHECK(raid_backend() == RAID_MDADM, "env mdadm");
    setenv("VESSAPP_RAID_BACKEND", "fixture", 1); raid_backend_set(-1);
    CHECK(raid_backend() == RAID_FIXTURE, "env fixture");
    unsetenv("VESSAPP_RAID_BACKEND"); raid_backend_set(-1);
    CHECK(raid_backend() == RAID_SGIO, "back to sgio");
}

/* ---- fresh .212 box: 4 free PDs, OS disk excluded, no arrays --------------- */
static void test_fresh_box(void) {
    g_lsblk = LSBLK; g_scan = ""; g_detail = NULL; g_mdstat = NULL; g_blkstat = NULL;
    g_sde_smart = 0;
    use_mdadm();

    I2bHddInfo_t hdd[8];
    CHECK(i2bApi_getHardDiskDriveInfo(hdd, sizeof hdd) == I2B_API_RET_SUCCESS, "getHDDInfo");
    CHECK(hdd[0].NumOfPD == 4, "want 4 PDs (OS disk excluded), got %u", hdd[0].NumOfPD);
    CHECK(!g_sde_smart, "the excluded OS disk must not be smart-probed");
    CHECK(strcmp((char *)hdd[0].driveModelNo, "HGST HUS726T4TALE6L4") == 0,
          "pd1 model got '%s'", hdd[0].driveModelNo);
    for (int i = 1; i < 4; i++)
        CHECK(strcmp((char *)hdd[i].driveModelNo, "TOSHIBA MG04ACA400E") == 0,
              "pd%d model got '%s'", i + 1, hdd[i].driveModelNo);
    CHECK(hdd[0].confCapacity == 4000787030016ULL, "cap got %llu", hdd[0].confCapacity);
    CHECK(hdd[0].PDId == 1 && hdd[3].PDId == 4, "flat ids 1..4");
    CHECK(hdd[0].drvType == I2BAPI_DRV_TYPE_SATA, "drvType SATA");
    CHECK(PHYDRV_STATE_FLAG(hdd[0].opStatusFlag) == PHYDRV_STATE_ONLINE, "online");
    CHECK(PHYDRV_CONFIG_FLAG(hdd[0].opStatusFlag) == PHYDRV_CONFIG_UNCONFIGURED,
          "fresh disk must be Unconfigured (flag %08x)", hdd[0].opStatusFlag);
    CHECK(strcmp((char *)hdd[0].configStatus, "Unconfigured") == 0, "cfg str");
    CHECK(hdd[0].temperature == 36, "temp got %u", hdd[0].temperature);
    CHECK(hdd[0].HDDTempWarThreshold == 55 && hdd[0].HDDTempCriThreshold == 65, "thresholds");
    CHECK(hdd[0].enclosureId == 0, "enclosureId (matches getEnclosures _id=0 so phydrvs.php shows the drives)");

    unsigned char buf[4096];
    I2bArrayInfo_t *a = (I2bArrayInfo_t *)buf;
    CHECK(i2bApi_getDiskArrayInfo(a, sizeof buf) == I2B_API_RET_SUCCESS, "getDiskArrayInfo");
    CHECK(a->DACnt == 0, "fresh box: no disk arrays, got %u", a->DACnt);

    I2bLogDrvInfo_t *l = (I2bLogDrvInfo_t *)buf;
    CHECK(i2bApi_getLogDrvInfo(l, sizeof buf) == I2B_API_RET_SUCCESS, "getLogDrvInfo");
    CHECK(l->LDCnt == 0, "fresh box: no logical drives, got %lu", l->LDCnt);
    restore();
}

/* ---- RAID5 md0 across the 4 data disks ------------------------------------- */
static void test_raid5_box(void) {
    g_lsblk = LSBLK_MD; g_scan = SCAN_MD; g_detail = DETAIL_MD0;
    g_mdstat = MDSTAT_CLEAN; g_blkstat = MD0_STAT;
    use_mdadm();

    unsigned char buf[4096];
    I2bArrayInfo_t *a = (I2bArrayInfo_t *)buf;
    CHECK(i2bApi_getDiskArrayInfo(a, sizeof buf) == I2B_API_RET_SUCCESS, "getDiskArrayInfo");
    CHECK(a->DACnt == 1, "want 1 DA, got %u", a->DACnt);
    CHECK(a->DAData[0].DaId == 0, "DaId got %u", a->DAData[0].DaId);
    CHECK(a->DAData[0].NumOfHdd == 4, "NumOfHdd got %u", a->DAData[0].NumOfHdd);

    I2bLogDrvInfo_t *l = (I2bLogDrvInfo_t *)buf;
    CHECK(i2bApi_getLogDrvInfo(l, sizeof buf) == I2B_API_RET_SUCCESS, "getLogDrvInfo");
    CHECK(l->LDCnt == 1, "want 1 LD, got %lu", l->LDCnt);
    CHECK(l->LDData[0].RaidLvl == I2BAPI_LDRAIDLVL_RAID5, "RaidLvl got %u", l->LDData[0].RaidLvl);
    CHECK(l->LDData[0].Capacity == 11720661504ULL * 1024ULL,
          "capacity got %llu", l->LDData[0].Capacity);
    CHECK(l->LDData[0].StripeSz == 512ULL * 1024ULL, "chunk got %llu", l->LDData[0].StripeSz);
    CHECK(l->LDData[0].SYNCed == I2BAPI_LDSYNCED_YES, "clean array must be SYNCed");
    CHECK(LOGDRV_STATE_FLAG(l->LDData[0].OpStatusFlag) == LOGDRV_STATE_ONLINE, "LD online");
    CHECK(l->LDData[0].arrayId == 0, "LD arrayId");
    CHECK(strcmp((char *)l->LDData[0].Alias, "md0") == 0, "alias got '%s'", l->LDData[0].Alias);

    I2bHddInfo_t hdd[8];
    CHECK(i2bApi_getHardDiskDriveInfo(hdd, sizeof hdd) == I2B_API_RET_SUCCESS, "getHDDInfo");
    CHECK(hdd[0].NumOfPD == 4, "still 4 PDs, got %u", hdd[0].NumOfPD);
    for (int i = 0; i < 4; i++) {
        CHECK(PHYDRV_CONFIG_FLAG(hdd[i].opStatusFlag) == PHYDRV_CONFIG_CONFIGURED,
              "pd%d must be configured", i + 1);
        CHECK(hdd[i].arrayId == 0, "pd%d arrayId got %u", i + 1, hdd[i].arrayId);
        CHECK(strcmp((char *)hdd[i].configStatus, "Array") == 0, "pd%d cfg str", i + 1);
    }

    I2bLogDrvStatusInfo_t *st = (I2bLogDrvStatusInfo_t *)buf;
    CHECK(i2bApi_getStatsLogDrv(st, sizeof buf) == I2B_API_RET_SUCCESS, "getStatsLogDrv");
    CHECK(st->LDCnt == 1, "stats LDCnt got %lu", st->LDCnt);
    CHECK(st->LDStatsData[0].sld_ReadIOReq == 120 && st->LDStatsData[0].sld_WriteIOReq == 300,
          "io req got %llu/%llu", st->LDStatsData[0].sld_ReadIOReq, st->LDStatsData[0].sld_WriteIOReq);
    CHECK(st->LDStatsData[0].sld_WriteBlocksTransferred == 16000ULL << 9, "bytes written");
    restore();
}

/* ---- degraded + rebuilding variant ----------------------------------------- */
static void test_rebuilding(void) {
    static const char MDSTAT_REC[] =
        "Personalities : [raid6] [raid5] [raid4] \n"
        "md0 : active raid5 sdd[3] sdc[2] sdb[1] sda[0]\n"
        "      11720661504 blocks super 1.2 level 5, 512k chunk, algorithm 2 [4/3] [UUU_]\n"
        "      [==>..................]  recovery = 12.6% (494218496/3906887168) finish=316.8min speed=179512K/sec\n"
        "\n"
        "unused devices: <none>\n";
    /* same mdadm detail with a degraded State line */
    static char detail2[sizeof DETAIL_MD0 + 32];
    const char *state = "             State : clean \n";
    const char *p = strstr(DETAIL_MD0, state);
    CHECK(p != NULL, "fixture State line");
    snprintf(detail2, sizeof detail2, "%.*s             State : clean, degraded \n%s",
             (int)(p - DETAIL_MD0), DETAIL_MD0, p + strlen(state));
    g_lsblk = LSBLK_MD; g_scan = SCAN_MD; g_detail = detail2;
    g_mdstat = MDSTAT_REC; g_blkstat = MD0_STAT;
    use_mdadm();

    unsigned char buf[4096];
    I2bLogDrvInfo_t *l = (I2bLogDrvInfo_t *)buf;
    CHECK(i2bApi_getLogDrvInfo(l, sizeof buf) == I2B_API_RET_SUCCESS, "getLogDrvInfo");
    CHECK(l->LDCnt == 1, "one LD");
    CHECK(LOGDRV_STATE_FLAG(l->LDData[0].OpStatusFlag) == LOGDRV_STATE_CRITICAL,
          "degraded RAID5 reports Critical (flag %08x)", l->LDData[0].OpStatusFlag);
    CHECK(LOGDRV_OPERATION_FLAG(l->LDData[0].OpStatusFlag) == LOGDRV_OPERATION_REBUILDING,
          "rebuild operation flag");
    CHECK(l->LDData[0].SYNCed == I2BAPI_LDSYNCED_NO, "rebuilding LD is not synced");
    CHECK(strstr((char *)l->LDData[0].OpStatus, "Rebuilding (12%)") != NULL,
          "op status got '%s'", l->LDData[0].OpStatus);
    restore();
}

/* ---- RAID_FIXTURE: canned files instead of tools --------------------------- */
static void test_fixture_files(void) {
    char dir[] = "/tmp/raidfixXXXXXX";
    CHECK(mkdtemp(dir) != NULL, "mkdtemp");
    char slug[256], path[512];
    raid_cmd_slug("lsblk -Pbn -o NAME,TYPE,SIZE,MODEL,PKNAME,MOUNTPOINT", slug, sizeof slug);
    snprintf(path, sizeof path, "%s/%s", dir, slug);
    FILE *f = fopen(path, "w");
    CHECK(f != NULL, "write fixture");
    fputs(LSBLK, f);
    fclose(f);

    setenv("VESSAPP_RAID_FIXTURE", dir, 1);
    raid_cmd_set(NULL);                     /* real seam: fixture files */
    raid_backend_set(RAID_FIXTURE);

    I2bHddInfo_t hdd[8];
    CHECK(i2bApi_getHardDiskDriveInfo(hdd, sizeof hdd) == I2B_API_RET_SUCCESS, "fixture getHDDInfo");
    CHECK(hdd[0].NumOfPD == 4, "fixture: 4 PDs, got %u", hdd[0].NumOfPD);
    /* no smartctl fixture files -> identity falls back to the lsblk MODEL */
    CHECK(strcmp((char *)hdd[0].driveModelNo, "HGST HUS726T4TALE6L4") == 0,
          "fixture model got '%s'", hdd[0].driveModelNo);
    unsigned char buf[4096];
    I2bArrayInfo_t *a = (I2bArrayInfo_t *)buf;
    CHECK(i2bApi_getDiskArrayInfo(a, sizeof buf) == I2B_API_RET_SUCCESS, "fixture DA");
    CHECK(a->DACnt == 0, "no mdadm fixture -> no arrays");

    unsetenv("VESSAPP_RAID_FIXTURE");
    restore();
    snprintf(path, sizeof path, "%s/%s", dir, slug);
    unlink(path); rmdir(dir);
}

/* ======================= Phase 2: the write path =========================== */

/* ---- create emits the exact mdadm argv ------------------------------------- */
static void test_create_argv(void) {
    g_lsblk = LSBLK; g_scan = ""; g_detail = NULL; g_mdstat = NULL; g_blkstat = NULL;
    writes_reset();
    use_mdadm();
    char err[256];

    /* 4-drive RAID5, 512 KiB chunk, full capacity (the .212 shape). */
    unsigned short ids[] = { 1, 2, 3, 4 };
    CHECK(raid_mdadm_create(I2BAPI_LDRAIDLVL_RAID5, ids, 4, 512, 0, err, sizeof err) == 0,
          "create RAID5 must succeed (err: %s)", err);
    /* Each member is wipefs'd (clear stale GPT/RAID sig) BEFORE the create, then
     * the array is persisted to mdadm.conf + initramfs refreshed (survives reboot). */
    CHECK(g_nwrites == 7, "4x wipefs + create + mdconf-add + update-initramfs, got %u", g_nwrites);
    static const char *wipes[] = {
        "wipefs -a /dev/sda", "wipefs -a /dev/sdb",
        "wipefs -a /dev/sdc", "wipefs -a /dev/sdd",
    };
    for (int i = 0; i < 4; i++)
        CHECK(strcmp(g_writes[i], wipes[i]) == 0, "wipefs[%d] got '%s'", i, g_writes[i]);
    CHECK(strcmp(g_writes[4],
          "mdadm --create /dev/md0 --metadata=1.2 --run --level=5 "
          "--raid-devices=4 --chunk=512 /dev/sda /dev/sdb /dev/sdc /dev/sdd") == 0,
          "create argv got '%s'", g_writes[4]);
    CHECK(strcmp(g_writes[5],
          "mdconf-add md0 8c9a2f4e:11aa22bb:33cc44dd:55ee66ff") == 0,
          "persist argv got '%s'", g_writes[5]);
    CHECK(strcmp(g_writes[6], "update-initramfs -u") == 0,
          "initramfs refresh got '%s'", g_writes[6]);

    /* 2-drive RAID1 at half capacity: no --chunk (mirror), --size in KiB. */
    writes_reset();
    unsigned short ids2[] = { 1, 2 };
    CHECK(raid_mdadm_create(I2BAPI_LDRAIDLVL_RAID1, ids2, 2, 64,
                            2000000000000ULL, err, sizeof err) == 0,
          "create RAID1 must succeed (err: %s)", err);
    CHECK(g_nwrites == 5, "2x wipefs + create + mdconf-add + update-initramfs, got %u", g_nwrites);
    CHECK(strcmp(g_writes[2],
          "mdadm --create /dev/md0 --metadata=1.2 --run --level=1 "
          "--raid-devices=2 --size=1953125000 /dev/sda /dev/sdb") == 0,
          "raid1 argv got '%s'", g_writes[2]);
    CHECK(strcmp(g_writes[3], "mdconf-add md0 8c9a2f4e:11aa22bb:33cc44dd:55ee66ff") == 0,
          "raid1 persist argv got '%s'", g_writes[3]);
    CHECK(strcmp(g_writes[4], "update-initramfs -u") == 0, "raid1 initramfs got '%s'", g_writes[4]);
    restore();
}

/* ---- the root-disk / non-member guard -------------------------------------- */
static void test_create_guard(void) {
    g_lsblk = LSBLK; g_scan = ""; g_detail = NULL; g_mdstat = NULL; g_blkstat = NULL;
    writes_reset();
    use_mdadm();
    char err[256];

    /* PD id 5 would be the OS disk (sde) - it is never enumerated as a data
     * PD, so a create that tries to include it MUST be rejected. */
    unsigned short with_os[] = { 2, 3, 4, 5 };
    CHECK(raid_mdadm_create(I2BAPI_LDRAIDLVL_RAID5, with_os, 4, 512, 0, err, sizeof err) == -1,
          "create including the OS disk must be REJECTED");
    CHECK(strstr(err, "OS disk") != NULL, "err must name the OS disk, got '%s'", err);
    CHECK(g_nwrites == 0, "no command may be issued on a rejected create");

    unsigned short dup[] = { 1, 1, 2 };
    CHECK(raid_mdadm_create(I2BAPI_LDRAIDLVL_RAID5, dup, 3, 512, 0, err, sizeof err) == -1,
          "duplicate PD ids rejected");
    unsigned short two[] = { 1, 2 };
    CHECK(raid_mdadm_create(I2BAPI_LDRAIDLVL_RAID5, two, 2, 512, 0, err, sizeof err) == -1,
          "RAID5 with 2 drives rejected");
    CHECK(raid_mdadm_create(I2BAPI_LDRAIDLVL_RAID50, two, 2, 512, 0, err, sizeof err) == -1,
          "unsupported level (RAID50) rejected");
    CHECK(g_nwrites == 0, "still no write commands");

    /* Busy members: with md0 already across sda..sdd, re-creating over them
     * must be refused. */
    g_lsblk = LSBLK_MD; g_scan = SCAN_MD; g_detail = DETAIL_MD0; g_mdstat = MDSTAT_CLEAN;
    unsigned short ids[] = { 1, 2, 3, 4 };
    CHECK(raid_mdadm_create(I2BAPI_LDRAIDLVL_RAID5, ids, 4, 512, 0, err, sizeof err) == -1,
          "members of an existing md are busy");
    CHECK(strstr(err, "already part of") != NULL, "busy err got '%s'", err);
    CHECK(g_nwrites == 0, "no write commands for busy members");
    restore();
}

/* ---- delete: --stop + --zero-superblock each member ------------------------ */
static void test_delete_argv(void) {
    g_lsblk = LSBLK_MD; g_scan = SCAN_MD; g_detail = DETAIL_MD0;
    g_mdstat = MDSTAT_CLEAN; g_blkstat = MD0_STAT;
    writes_reset();
    use_mdadm();
    char err[256];

    CHECK(raid_mdadm_delete(0, err, sizeof err) == 0, "delete DA0 (err: %s)", err);
    CHECK(g_nwrites == 7, "stop + 4x zero-superblock + mdconf-del + update-initramfs, got %u", g_nwrites);
    CHECK(strcmp(g_writes[0], "mdadm --stop /dev/md0") == 0, "stop argv got '%s'", g_writes[0]);
    static const char *zs[] = {
        "mdadm --zero-superblock /dev/sda", "mdadm --zero-superblock /dev/sdb",
        "mdadm --zero-superblock /dev/sdc", "mdadm --zero-superblock /dev/sdd",
    };
    for (int i = 0; i < 4; i++)
        CHECK(strcmp(g_writes[i + 1], zs[i]) == 0, "zero-superblock[%d] got '%s'",
              i, g_writes[i + 1]);
    CHECK(strcmp(g_writes[5], "mdconf-del md0") == 0, "unpersist argv got '%s'", g_writes[5]);
    CHECK(strcmp(g_writes[6], "update-initramfs -u") == 0, "initramfs refresh got '%s'", g_writes[6]);

    writes_reset();
    CHECK(raid_mdadm_delete(3, err, sizeof err) == -1, "no such array rejected");
    CHECK(g_nwrites == 0, "no commands for a missing array");
    restore();
}

/* ---- delete guard: an array carrying the OS disk (or a partition) refuses -- */
static void test_delete_guard(void) {
    /* md0 whose mdadm --detail lists the OS disk sde as a member. */
    static const char DETAIL_OS[] =
        "/dev/md0:\n"
        "        Raid Level : raid5\n"
        "    Number   Major   Minor   RaidDevice State\n"
        "       0       8        0        0      active sync   /dev/sda\n"
        "       1       8       16        1      active sync   /dev/sdb\n"
        "       2       8       32        2      active sync   /dev/sdc\n"
        "       3       8       64        3      active sync   /dev/sde\n";
    g_lsblk = LSBLK; g_scan = SCAN_MD; g_detail = DETAIL_OS; g_mdstat = NULL; g_blkstat = NULL;
    writes_reset();
    use_mdadm();
    char err[256];
    CHECK(raid_mdadm_delete(0, err, sizeof err) == -1,
          "delete of an array containing the OS disk must be REFUSED");
    CHECK(strstr(err, "OS disk") != NULL, "err must name the OS disk, got '%s'", err);
    CHECK(g_nwrites == 0, "no --stop/--zero-superblock may be issued");

    /* a partition member (an md the OS owns) is refused too */
    static const char DETAIL_PART[] =
        "/dev/md0:\n"
        "        Raid Level : raid1\n"
        "    Number   Major   Minor   RaidDevice State\n"
        "       0       8        3        0      active sync   /dev/sda3\n"
        "       1       8       19        1      active sync   /dev/sdb3\n";
    g_detail = DETAIL_PART;
    CHECK(raid_mdadm_delete(0, err, sizeof err) == -1, "partition-member md refused");
    CHECK(g_nwrites == 0, "no commands for partition-member md");
    restore();
}

/* ---- init / synch drive the md sync_action --------------------------------- */
static void test_init_synch(void) {
    g_lsblk = LSBLK_MD; g_scan = SCAN_MD; g_detail = DETAIL_MD0;
    g_mdstat = MDSTAT_CLEAN; g_blkstat = MD0_STAT;
    writes_reset();
    use_mdadm();
    char err[256];

    CHECK(raid_mdadm_init_ld(0, 1, err, sizeof err) == 0, "init start (err: %s)", err);
    CHECK(raid_mdadm_init_ld(0, 0, err, sizeof err) == 0, "init stop");
    CHECK(raid_mdadm_synch_ld(0, 1, err, sizeof err) == 0, "synch start");
    CHECK(raid_mdadm_synch_ld(0, 0, err, sizeof err) == 0, "synch stop");
    CHECK(g_nwrites == 4, "4 sync_action writes, got %u", g_nwrites);
    CHECK(strcmp(g_writes[0], "syncaction md0 repair") == 0,
          "init start argv got '%s'", g_writes[0]);
    CHECK(strcmp(g_writes[1], "syncaction md0 idle") == 0,
          "init stop argv got '%s'", g_writes[1]);
    CHECK(strcmp(g_writes[2], "syncaction md0 check") == 0,
          "synch start argv got '%s'", g_writes[2]);
    CHECK(strcmp(g_writes[3], "syncaction md0 idle") == 0,
          "synch stop argv got '%s'", g_writes[3]);

    CHECK(raid_mdadm_synch_ld(9, 1, err, sizeof err) == -1, "unknown LD rejected");
    restore();
}

/* ---- create -> the box now shows md0 -> enumeration yields DA0 + RAID5 LD -- */
static void test_create_roundtrip(void) {
    g_lsblk = LSBLK; g_scan = ""; g_detail = NULL; g_mdstat = NULL; g_blkstat = NULL;
    writes_reset();
    use_mdadm();
    char err[256];
    unsigned short ids[] = { 1, 2, 3, 4 };
    CHECK(raid_mdadm_create(I2BAPI_LDRAIDLVL_RAID5, ids, 4, 512, 0, err, sizeof err) == 0,
          "roundtrip create (err: %s)", err);

    /* ...the kernel builds md0; the tools now answer like the RAID5 box. */
    g_lsblk = LSBLK_MD; g_scan = SCAN_MD; g_detail = DETAIL_MD0;
    g_mdstat = MDSTAT_CLEAN; g_blkstat = MD0_STAT;

    unsigned char buf[4096];
    I2bArrayInfo_t *a = (I2bArrayInfo_t *)buf;
    CHECK(i2bApi_getDiskArrayInfo(a, sizeof buf) == I2B_API_RET_SUCCESS, "roundtrip DA");
    CHECK(a->DACnt == 1 && a->DAData[0].DaId == 0, "DA0 exists");
    CHECK(a->DAData[0].NumOfHdd == 4, "DA0 spans the 4 drives");
    /* capacity: 4x 4.00TB members; free = the ~128MiB/member md overhead */
    CHECK(a->DAData[0].CfgCapacity == 4ULL * 4000787030016ULL,
          "CfgCapacity got %llu", a->DAData[0].CfgCapacity);
    CHECK(a->DAData[0].FreeCapacity ==
          4ULL * (4000787030016ULL - 3906887168ULL * 1024ULL),
          "FreeCapacity got %llu", a->DAData[0].FreeCapacity);

    I2bLogDrvInfo_t *l = (I2bLogDrvInfo_t *)buf;
    CHECK(i2bApi_getLogDrvInfo(l, sizeof buf) == I2B_API_RET_SUCCESS, "roundtrip LD");
    CHECK(l->LDCnt == 1 && l->LDData[0].RaidLvl == I2BAPI_LDRAIDLVL_RAID5,
          "the created LD is RAID5");
    CHECK(l->LDData[0].arrayId == 0, "LD lives on DA0");
    restore();
}

/* ---- P3: add / remove a hot spare ----------------------------------------- */
static void test_spare(void) {
    /* md0 = RAID5 across sda/sdb/sdc; sdd is a FREE data disk; sde = OS. */
    static const char LSBLK_3[] =
"NAME=\"sda\" TYPE=\"disk\" SIZE=\"" TB4 "\" MODEL=\"HGST HUS726T4TALE6L4\" PKNAME=\"\" MOUNTPOINT=\"\"\n"
"NAME=\"md0\" TYPE=\"raid5\" SIZE=\"7813344768\" MODEL=\"\" PKNAME=\"sda\" MOUNTPOINT=\"\"\n"
"NAME=\"sdb\" TYPE=\"disk\" SIZE=\"" TB4 "\" MODEL=\"TOSHIBA MG04ACA400E\" PKNAME=\"\" MOUNTPOINT=\"\"\n"
"NAME=\"md0\" TYPE=\"raid5\" SIZE=\"7813344768\" MODEL=\"\" PKNAME=\"sdb\" MOUNTPOINT=\"\"\n"
"NAME=\"sdc\" TYPE=\"disk\" SIZE=\"" TB4 "\" MODEL=\"TOSHIBA MG04ACA400E\" PKNAME=\"\" MOUNTPOINT=\"\"\n"
"NAME=\"md0\" TYPE=\"raid5\" SIZE=\"7813344768\" MODEL=\"\" PKNAME=\"sdc\" MOUNTPOINT=\"\"\n"
"NAME=\"sdd\" TYPE=\"disk\" SIZE=\"" TB4 "\" MODEL=\"TOSHIBA MG04ACA400E\" PKNAME=\"\" MOUNTPOINT=\"\"\n"
"NAME=\"sde\" TYPE=\"disk\" SIZE=\"512110190592\" MODEL=\"ADATA SU800\" PKNAME=\"\" MOUNTPOINT=\"\"\n"
"NAME=\"sde2\" TYPE=\"part\" SIZE=\"511562242048\" MODEL=\"\" PKNAME=\"sde\" MOUNTPOINT=\"/\"\n";
    static const char DETAIL_MD0_3[] =
"/dev/md0:\n"
"        Raid Level : raid5\n"
"     Used Dev Size : 3906887168 (3.64 TiB 4.00 TB)\n"
"      Raid Devices : 3\n"
"     Total Devices : 3\n"
"             State : clean \n"
"        Chunk Size : 64K\n"
"    Number   Major   Minor   RaidDevice State\n"
"       0       8        0        0      active sync   /dev/sda\n"
"       1       8       16        1      active sync   /dev/sdb\n"
"       2       8       32        2      active sync   /dev/sdc\n";
    static const char MDSTAT_3[] =
"Personalities : [raid6] [raid5] [raid4] \n"
"md0 : active raid5 sdc[2] sdb[1] sda[0]\n"
"      7813344768 blocks super 1.2 level 5, 64k chunk, algorithm 2 [3/3] [UUU]\n"
"\n"
"unused devices: <none>\n";
    g_lsblk = LSBLK_3; g_scan = SCAN_MD; g_detail = DETAIL_MD0_3;
    g_mdstat = MDSTAT_3; g_blkstat = MD0_STAT;
    writes_reset();
    use_mdadm();
    char err[256];

    /* add the free sdd (PD 4) as a dedicated spare to DA0 -> mdadm --add. */
    CHECK(raid_mdadm_add_spare(4, 0, err, sizeof err) == 0, "add spare (err: %s)", err);
    CHECK(g_nwrites == 1, "one command, got %u", g_nwrites);
    CHECK(strcmp(g_writes[0], "mdadm --add /dev/md0 /dev/sdd") == 0,
          "add-spare argv got '%s'", g_writes[0]);

    /* guards: out-of-range PD, a busy member, an unknown array. */
    writes_reset();
    CHECK(raid_mdadm_add_spare(9, 0, err, sizeof err) == -1, "PD 9 out of range rejected");
    CHECK(raid_mdadm_add_spare(1, 0, err, sizeof err) == -1, "busy member (sda) rejected");
    CHECK(strstr(err, "already part of") != NULL, "busy err got '%s'", err);
    CHECK(raid_mdadm_add_spare(4, 7, err, sizeof err) == -1, "unknown array (md7) rejected");
    CHECK(g_nwrites == 0, "no command may be issued on a rejected add");

    /* Global spare needs exactly one array: a fresh (no-array) box refuses it. */
    g_lsblk = LSBLK; g_scan = ""; g_detail = NULL; g_mdstat = NULL;
    CHECK(raid_mdadm_add_spare(1, -1, err, sizeof err) == -1, "Global spare with no array rejected");
    CHECK(strstr(err, "create one first") != NULL, "no-array err got '%s'", err);

    /* remove_spare: md0 currently has no spare, and md9 does not exist. */
    g_lsblk = LSBLK_3; g_scan = SCAN_MD; g_detail = DETAIL_MD0_3; g_mdstat = MDSTAT_3;
    writes_reset();
    CHECK(raid_mdadm_remove_spare(0, err, sizeof err) == -1, "remove with no spare rejected");
    CHECK(strstr(err, "no spare") != NULL, "no-spare err got '%s'", err);
    CHECK(raid_mdadm_remove_spare(9, err, sizeof err) == -1, "remove from missing array rejected");
    CHECK(g_nwrites == 0, "no command may be issued on a rejected remove");
    restore();
}

/* ---- P4: migrate / reshape (OCE + RLM subset) + media patrol -------------- */
static void test_migrate(void) {
    /* md0 = RAID5 across sda/sdb/sdc (PDs 1-3); sdd free (PD4); sde = OS. */
    static const char LSBLK_M[] =
"NAME=\"sda\" TYPE=\"disk\" SIZE=\"" TB4 "\" MODEL=\"HGST HUS726T4TALE6L4\" PKNAME=\"\" MOUNTPOINT=\"\"\n"
"NAME=\"md0\" TYPE=\"raid5\" SIZE=\"7813344768\" MODEL=\"\" PKNAME=\"sda\" MOUNTPOINT=\"\"\n"
"NAME=\"sdb\" TYPE=\"disk\" SIZE=\"" TB4 "\" MODEL=\"TOSHIBA MG04ACA400E\" PKNAME=\"\" MOUNTPOINT=\"\"\n"
"NAME=\"md0\" TYPE=\"raid5\" SIZE=\"7813344768\" MODEL=\"\" PKNAME=\"sdb\" MOUNTPOINT=\"\"\n"
"NAME=\"sdc\" TYPE=\"disk\" SIZE=\"" TB4 "\" MODEL=\"TOSHIBA MG04ACA400E\" PKNAME=\"\" MOUNTPOINT=\"\"\n"
"NAME=\"md0\" TYPE=\"raid5\" SIZE=\"7813344768\" MODEL=\"\" PKNAME=\"sdc\" MOUNTPOINT=\"\"\n"
"NAME=\"sdd\" TYPE=\"disk\" SIZE=\"" TB4 "\" MODEL=\"TOSHIBA MG04ACA400E\" PKNAME=\"\" MOUNTPOINT=\"\"\n"
"NAME=\"sde\" TYPE=\"disk\" SIZE=\"512110190592\" MODEL=\"ADATA SU800\" PKNAME=\"\" MOUNTPOINT=\"\"\n"
"NAME=\"sde2\" TYPE=\"part\" SIZE=\"511562242048\" MODEL=\"\" PKNAME=\"sde\" MOUNTPOINT=\"/\"\n";
    static const char DETAIL_M[] =
"/dev/md0:\n"
"        Raid Level : raid5\n"
"     Used Dev Size : 3906887168 (3.64 TiB 4.00 TB)\n"
"      Raid Devices : 3\n"
"     Total Devices : 3\n"
"             State : clean \n"
"        Chunk Size : 64K\n"
"    Number   Major   Minor   RaidDevice State\n"
"       0       8        0        0      active sync   /dev/sda\n"
"       1       8       16        1      active sync   /dev/sdb\n"
"       2       8       32        2      active sync   /dev/sdc\n";
    static const char MDSTAT_M[] =
"Personalities : [raid6] [raid5] [raid4] \n"
"md0 : active raid5 sdc[2] sdb[1] sda[0]\n"
"      7813344768 blocks super 1.2 level 5, 64k chunk, algorithm 2 [3/3] [UUU]\n"
"\nunused devices: <none>\n";
    g_lsblk = LSBLK_M; g_scan = SCAN_MD; g_detail = DETAIL_M;
    g_mdstat = MDSTAT_M; g_blkstat = MD0_STAT;
    writes_reset();
    use_mdadm();
    char err[256];

    /* OCE: expand RAID5 3 -> 4 by adding the free sdd (keep level = -1). */
    unsigned short t4[] = { 1, 2, 3, 4 };
    CHECK(raid_mdadm_migrate(0, t4, 4, -1, err, sizeof err) == 0, "OCE expand (err: %s)", err);
    CHECK(g_nwrites == 2, "add + grow, got %u", g_nwrites);
    CHECK(strcmp(g_writes[0], "mdadm --add /dev/md0 /dev/sdd") == 0, "add argv got '%s'", g_writes[0]);
    CHECK(strcmp(g_writes[1],
          "mdadm --grow /dev/md0 --raid-devices=4 --backup-file=/var/lib/promise-raid/md0.backup") == 0,
          "grow argv got '%s'", g_writes[1]);

    /* RLM: RAID5 -> RAID6 (adds the 2nd parity disk -> 4 devices, level 6). */
    writes_reset();
    CHECK(raid_mdadm_migrate(0, t4, 4, I2BAPI_LDRAIDLVL_RAID6, err, sizeof err) == 0,
          "RAID5->RAID6 (err: %s)", err);
    CHECK(strcmp(g_writes[1],
          "mdadm --grow /dev/md0 --raid-devices=4 --level=6 "
          "--backup-file=/var/lib/promise-raid/md0.backup") == 0,
          "raid6 grow argv got '%s'", g_writes[1]);

    /* guards: no-op, unsupported level change, shrink, unknown array. */
    writes_reset();
    unsigned short t3[] = { 1, 2, 3 };
    CHECK(raid_mdadm_migrate(0, t3, 3, -1, err, sizeof err) == -1, "no-op migration rejected");
    CHECK(strstr(err, "changes nothing") != NULL, "no-op err got '%s'", err);
    CHECK(raid_mdadm_migrate(0, t3, 3, I2BAPI_LDRAIDLVL_RAID0, err, sizeof err) == -1,
          "RAID5->RAID0 rejected (unsupported)");
    CHECK(strstr(err, "not supported") != NULL, "unsupported err got '%s'", err);
    unsigned short t2[] = { 1, 2 };   /* a subset of current members -> no new drives */
    CHECK(raid_mdadm_migrate(0, t2, 2, -1, err, sizeof err) == -1, "no-new-drives rejected");
    CHECK(raid_mdadm_migrate(9, t4, 4, -1, err, sizeof err) == -1, "unknown array rejected");
    CHECK(g_nwrites == 0, "no command may be issued on a rejected migration");

    /* media patrol -> a 'check' scrub of every array. */
    writes_reset();
    CHECK(raid_mdadm_media_patrol(err, sizeof err) == 0, "media patrol (err: %s)", err);
    CHECK(g_nwrites == 1 && strcmp(g_writes[0], "syncaction md0 check") == 0,
          "media patrol argv got '%s'", g_writes[0]);
    restore();
}

int main(void) {
    test_backend_env();
    test_fresh_box();
    test_raid5_box();
    test_rebuilding();
    test_fixture_files();
    test_create_argv();
    test_create_guard();
    test_delete_argv();
    test_delete_guard();
    test_init_synch();
    test_create_roundtrip();
    test_spare();
    test_migrate();
    PASS();
}
