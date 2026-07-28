/* test_raid - RAID enumeration parse + lifecycle CDB assertions (Phase 2b).
 * Proves i2bApi_getDiskArrayInfo/getHardDiskDriveInfo/getLogDrvInfo/getStatsLogDrv
 * emit the exact recovered CDBs and parse seeded pages into the documented
 * structs, and that the write-path i2_raid_* marshal the exact recovered CDBs. */
#include "test_util.h"
#include "i2bdg_api.h"
#include "i2_raid.h"
#include <string.h>

/* little-endian pokes into a page buffer */
static void pl16(unsigned char *p, unsigned off, unsigned v) { p[off]=v&0xff; p[off+1]=(v>>8)&0xff; }
static void pl32(unsigned char *p, unsigned off, unsigned v) { pl16(p,off,v&0xffff); pl16(p,off+2,v>>16); }
static void pl64(unsigned char *p, unsigned off, unsigned long long v){ pl32(p,off,(unsigned)v); pl32(p,off+4,(unsigned)(v>>32)); }

/* assert the recovered RAID CDB shape on a recorded txn */
static void chk_cdb(const scsi_txn *t, uint16_t micop, uint16_t id, uint8_t sel,
                    scsi_dir dir, uint16_t xfer, uint8_t count) {
    CHECK(t->cdb[0]==0xD8 && t->cdb[1]==0xBA, "D8 BA got %02x %02x", t->cdb[0], t->cdb[1]);
    CHECK(scsi_txn_micop(t)==micop, "micop want %04x got %04x", micop, scsi_txn_micop(t));
    CHECK((t->cdb[6]<<8|t->cdb[7])==id, "id BE want %u got %u", id, t->cdb[6]<<8|t->cdb[7]);
    CHECK(t->cdb[8]==sel, "sel@8 want %02x got %02x", sel, t->cdb[8]);
    CHECK((unsigned)(t->cdb[10]<<8|t->cdb[11])==xfer, "xferlen BE want %u got %u", xfer, t->cdb[10]<<8|t->cdb[11]);
    CHECK(t->cdb[14]==count, "count@14 want %u got %u", count, t->cdb[14]);
    CHECK(t->dir==dir, "dir want %d got %d", dir, t->dir);
}

/* ---- disk array enumeration ---- */
static void test_array(void) {
    scsi_mock *m = mock_begin();
    unsigned char pg[1024] = {0};
    pl32(pg, I2R_PAGE_LEN_OFF_ARR, 2 * I2R_REC_ARRAY);          /* 2 records */
    unsigned char *r0 = pg + I2R_PAGE_HDR, *r1 = r0 + I2R_REC_ARRAY;
    pl16(r0, I2R_DA_ID, 7);   pl16(r0, I2R_DA_NUMHDD, 4);
    pl16(r1, I2R_DA_ID, 9);   pl16(r1, I2R_DA_NUMHDD, 2);
    scsi_mock_seed(m, I2_MICOP_GET_ARRAY_INFO, pg, 1024);

    unsigned char buf[2048];
    I2bArrayInfo_t *a = (I2bArrayInfo_t *)buf;
    CHECK(i2bApi_getDiskArrayInfo(a, sizeof buf) == I2B_API_RET_SUCCESS, "getDiskArrayInfo");
    chk_cdb(txn_at(m,0), I2_MICOP_GET_ARRAY_INFO, 0, 0, SCSI_XFER_READ, 0xffff, 0);
    CHECK(a->DACnt == 2, "DACnt got %u", a->DACnt);
    CHECK(a->DAData[0].DaId==7 && a->DAData[0].NumOfHdd==4, "array0 %u/%u", a->DAData[0].DaId, a->DAData[0].NumOfHdd);
    CHECK(a->DAData[1].DaId==9 && a->DAData[1].NumOfHdd==2, "array1 %u/%u", a->DAData[1].DaId, a->DAData[1].NumOfHdd);
    mock_end();
}

/* ---- physical drive enumeration ---- */
static void test_phydrv(void) {
    scsi_mock *m = mock_begin();
    unsigned char pg[1024] = {0};
    pl32(pg, I2R_PAGE_LEN_OFF, 2 * I2R_REC_PHYDRV);
    unsigned char *r0 = pg + I2R_PAGE_HDR, *r1 = r0 + I2R_REC_PHYDRV;
    pl16(r0, I2R_PD_ID, 0);            /* PDId = 0+1 = 1 */
    pl16(r0, I2R_PD_DRVTYPE, I2BAPI_DRV_TYPE_SAS);
    memcpy(r0 + I2R_PD_MODEL, "ST4000NM0023", 12);
    pl64(r0, I2R_PD_BLOCKSIZE, 512);
    pl16(r0, I2R_PD_NUMBLOCKS, 1000);  /* confCapacity = 1000*512 = 512000 */
    pl16(r0, I2R_PD_ENCID, 0);         /* enclosureId = 1 */
    pl16(r0, I2R_PD_SLOT, 3);
    pl32(r0, I2R_PD_OPFLAG, PHYDRV_STATE_ONLINE | PHYDRV_CONFIG_CONFIGURED);
    pl16(r0, I2R_PD_ARRAYID, 7);
    r0[I2R_PD_TEMP] = 38;
    pl16(r1, I2R_PD_ID, 5);            /* PDId = 6 */
    pl32(r1, I2R_PD_OPFLAG, 0);        /* unconfigured -> arrayId 0 */
    pl16(r1, I2R_PD_ARRAYID, 7);       /* ignored because opflag==0 */
    scsi_mock_seed(m, I2_MICOP_GET_PHYDRV_INFO, pg, 1024);

    I2bHddInfo_t hdd[2];
    CHECK(i2bApi_getHardDiskDriveInfo(hdd, sizeof hdd) == I2B_API_RET_SUCCESS, "getHDDInfo");
    chk_cdb(txn_at(m,0), I2_MICOP_GET_PHYDRV_INFO, 0, 0xff, SCSI_XFER_READ, 0xffff, 0);
    CHECK(hdd[0].NumOfPD==2, "NumOfPD got %u", hdd[0].NumOfPD);
    CHECK(hdd[0].PDId==1, "PDId got %u", hdd[0].PDId);
    CHECK(hdd[0].drvType==I2BAPI_DRV_TYPE_SAS, "drvType got %u", hdd[0].drvType);
    CHECK(strcmp((char*)hdd[0].driveModelNo,"ST4000NM0023")==0, "model got '%s'", hdd[0].driveModelNo);
    CHECK(hdd[0].confCapacity==512000ULL, "cap got %llu", hdd[0].confCapacity);
    CHECK(hdd[0].enclosureId==1, "encId got %u", hdd[0].enclosureId);
    CHECK(hdd[0].slotId==3, "slot got %u", hdd[0].slotId);
    CHECK(PHYDRV_STATE_FLAG(hdd[0].opStatusFlag)==PHYDRV_STATE_ONLINE, "opflag");
    CHECK(hdd[0].arrayId==7, "arrayId got %u", hdd[0].arrayId);
    CHECK(hdd[0].temperature==38, "temp got %u", hdd[0].temperature);
    CHECK(hdd[1].PDId==6, "PD1 id got %u", hdd[1].PDId);
    CHECK(hdd[1].arrayId==0, "PD1 arrayId (unconf) got %u", hdd[1].arrayId);
    mock_end();
}

/* ---- logical drive enumeration ---- */
static void test_logdrv(void) {
    scsi_mock *m = mock_begin();
    unsigned char pg[1024] = {0};
    pl32(pg, I2R_PAGE_LEN_OFF, 2 * I2R_REC_LOGDRV);
    unsigned char *r0 = pg + I2R_PAGE_HDR, *r1 = r0 + I2R_REC_LOGDRV;
    pl16(r0, I2R_LD_ID, 0);
    r0[I2R_LD_RAIDLVL] = I2BAPI_LDRAIDLVL_RAID5;
    pl32(r0, I2R_LD_OPFLAG, LOGDRV_STATE_ONLINE | LOGDRV_COND_SYNCHRONIZED); /* sync bit @0x12 */
    pl32(r0, I2R_LD_CACHEFLAGS, 2);       /* bit1 set, bit0 clear -> RAHEAD_WTHRU (3) */
    pl64(r0, I2R_LD_CAPBLOCKS, 2048);     /* Capacity = 2048<<9 = 1048576 */
    pl32(r0, I2R_LD_STRIPESECT, 128);     /* StripeSz = 128<<9 = 65536 */
    pl16(r0, I2R_LD_ARRAYID, 7);
    memcpy(r0 + I2R_LD_ALIAS, "SystemVolume", 12);
    pl16(r1, I2R_LD_ID, 1);
    pl32(r1, I2R_LD_OPFLAG, LOGDRV_STATE_CRITICAL);   /* not synced */
    pl32(r1, I2R_LD_CACHEFLAGS, 4);       /* bit2 -> NOCACHE (0) */
    scsi_mock_seed(m, I2_MICOP_GET_LOGDRV_INFO, pg, 1024);

    unsigned char buf[2048];
    I2bLogDrvInfo_t *l = (I2bLogDrvInfo_t *)buf;
    CHECK(i2bApi_getLogDrvInfo(l, sizeof buf) == I2B_API_RET_SUCCESS, "getLogDrvInfo");
    chk_cdb(txn_at(m,0), I2_MICOP_GET_LOGDRV_INFO, 0, 0, SCSI_XFER_READ, 0xffff, 0);
    CHECK(l->LDCnt==2, "LDCnt got %lu", l->LDCnt);
    CHECK(l->LDData[0].LdId==0, "Ld0 id");
    CHECK(l->LDData[0].RaidLvl==I2BAPI_LDRAIDLVL_RAID5, "raid got %u", l->LDData[0].RaidLvl);
    CHECK(l->LDData[0].CachePolicy==I2BAPI_LDCACHEPOLICY_RAHEAD_WTHRU, "cache got %u", l->LDData[0].CachePolicy);
    CHECK(l->LDData[0].Capacity==1048576ULL, "cap got %llu", l->LDData[0].Capacity);
    CHECK(l->LDData[0].StripeSz==65536ULL, "stripe got %llu", l->LDData[0].StripeSz);
    CHECK(l->LDData[0].arrayId==7, "ld arrayId got %u", l->LDData[0].arrayId);
    CHECK(l->LDData[0].SYNCed==I2BAPI_LDSYNCED_YES, "synced got %u", l->LDData[0].SYNCed);
    CHECK(strcmp((char*)l->LDData[0].Alias,"SystemVolume")==0, "alias got '%s'", l->LDData[0].Alias);
    CHECK(l->LDData[1].CachePolicy==I2BAPI_LDCACHEPOLICY_NOCACHE_WTHRU, "ld1 cache got %u", l->LDData[1].CachePolicy);
    CHECK(l->LDData[1].SYNCed==I2BAPI_LDSYNCED_NO, "ld1 synced got %u", l->LDData[1].SYNCed);
    mock_end();
}

/* ---- LD statistics ---- */
static void test_stats(void) {
    scsi_mock *m = mock_begin();
    unsigned char pg[1024] = {0};
    pl32(pg, I2R_PAGE_LEN_OFF, 1 * I2R_REC_STATS);
    unsigned char *r0 = pg + I2R_PAGE_HDR;
    r0[I2R_ST_LDID] = 2;
    pl64(r0, I2R_ST_BLOCKS, 100);         /* -> 100<<9 = 51200 bytes */
    pl32(r0, I2R_ST_ERRORS, 3);
    pl64(r0, I2R_ST_IOREQ, 4096);
    pl64(r0, I2R_ST_COLLTIME, 0x1122334455ULL);
    scsi_mock_seed(m, I2_MICOP_STATS_LOGDRV, pg, 1024);

    unsigned char buf[2048];
    I2bLogDrvStatusInfo_t *s = (I2bLogDrvStatusInfo_t *)buf;
    CHECK(i2bApi_getStatsLogDrv(s, sizeof buf) == I2B_API_RET_SUCCESS, "getStatsLogDrv");
    chk_cdb(txn_at(m,0), I2_MICOP_STATS_LOGDRV, 0, 0, SCSI_XFER_READ, 0xffff, 0);
    CHECK(s->LDCnt==1, "LDCnt got %lu", s->LDCnt);
    CHECK(s->LDStatsData[0].sld_LogicalDriveId==2, "ldid");
    CHECK(s->LDStatsData[0].sld_BlocksTransferred==51200ULL, "blocks got %llu", s->LDStatsData[0].sld_BlocksTransferred);
    CHECK(s->LDStatsData[0].sld_Errors==3, "errors");
    CHECK(s->LDStatsData[0].sld_IOReq==4096ULL, "ioreq");
    CHECK(s->LDStatsData[0].sld_statsCollectionTime==0x1122334455ULL, "colltime");
    mock_end();
}

/* ---- lifecycle write-path CDB marshalling (mock only, never a real device) ---- */
static void test_lifecycle(void) {
    scsi_mock *m = scsi_mock_open();
    i2_handle *h = i2_initialize_borrow(scsi_mock_iface(m));
    CHECK(h != NULL, "handle");

    CHECK(i2_raid_deleteDiskArray(h, 7)==0, "delArray");
    chk_cdb(txn_at(m,0), I2_MICOP_CFG_DELETE, 7, 0, SCSI_XFER_WRITE, 0, 1);

    CHECK(i2_raid_deleteSpare(h, 3)==0, "delSpare");
    chk_cdb(txn_at(m,1), I2_MICOP_CFG_DELETE, 3, 0, SCSI_XFER_WRITE, 0, 1);

    CHECK(i2_raid_deleteLogDrv(h, 2)==0, "delLD");
    chk_cdb(txn_at(m,2), I2_MICOP_LOGDRV_DELETE, 2, 0, SCSI_XFER_WRITE, 0, 1);

    CHECK(i2_raid_initLogDrv(h, 4, 1)==0, "initStart");
    chk_cdb(txn_at(m,3), I2_MICOP_LOGDRV_INIT_START, 4, 0, SCSI_XFER_WRITE, 0, 1);
    CHECK(i2_raid_initLogDrv(h, 4, 0)==0, "initStop");
    chk_cdb(txn_at(m,4), I2_MICOP_LOGDRV_INIT_STOP, 4, 0, SCSI_XFER_WRITE, 0, 1);

    CHECK(i2_raid_synchLogDrv(h, 4, 1)==0, "synchStart");
    chk_cdb(txn_at(m,5), I2_MICOP_SYNCH_START, 4, 0, SCSI_XFER_WRITE, 0, 1);
    CHECK(i2_raid_synchLogDrv(h, 4, 0)==0, "synchStop");
    chk_cdb(txn_at(m,6), I2_MICOP_SYNCH_STOP, 4, 0, SCSI_XFER_WRITE, 0, 1);

    unsigned char payload[16] = {1,2,3,4};
    CHECK(i2_raid_applyConfig(h, payload, sizeof payload)==0, "applyConfig");
    const scsi_txn *t = txn_at(m, 7);
    chk_cdb(t, I2_MICOP_CFG_APPLY, 0, 0, SCSI_XFER_WRITE, sizeof payload, 0);
    CHECK(memcmp(t->data, payload, sizeof payload)==0, "payload DATA-OUT captured");

    i2_dispose(h);
    scsi_mock_free(m);
}

/* ---- guardrails ---- */
static void test_guards(void) {
    CHECK(i2bApi_getDiskArrayInfo(NULL, 100)==I2B_API_RET_PARAM_ERR, "null array");
    CHECK(i2bApi_getHardDiskDriveInfo(NULL, 100)==I2B_API_RET_PARAM_ERR, "null hdd");
    scsi_mock *m = mock_begin();
    unsigned char pg[1024] = {0};
    pl32(pg, I2R_PAGE_LEN_OFF, 2 * I2R_REC_LOGDRV);
    scsi_mock_seed(m, I2_MICOP_GET_LOGDRV_INFO, pg, 1024);
    unsigned char small[8];
    CHECK(i2bApi_getLogDrvInfo((I2bLogDrvInfo_t*)small, sizeof small)==I2B_API_RET_PARAM_ERR, "small buf");
    mock_end();
}

int main(void) {
    test_array();
    test_phydrv();
    test_logdrv();
    test_stats();
    test_lifecycle();
    test_guards();
    PASS();
}
