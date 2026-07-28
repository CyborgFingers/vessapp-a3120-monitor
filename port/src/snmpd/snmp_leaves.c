/* snmp_leaves.c - materialise the raidv4 subtree from the substrate. See .h.
 *
 * Fixture VALUES are representative demo data (fixture mode, no hardware); the
 * OID<->i2 MAPPING is recovered (raidv4.mib + prsnmpagent.dll). Subsystem
 * identity strings (model/vendor/serial) map to i2_getSubsystemInfo, whose page
 * offsets are UNRESOLVED - served here as fixture constants (CALIBRATE).
 */
#include "snmp_leaves.h"
#include "tool_common.h"
#include "scsi_transport.h"
#include "i2bdg_internal.h"   /* i2bdg_session_set_transport / _i2 */
#include "i2bdg_api.h"
#include "i2_raid.h"
#include "i2_micops.h"
#include "i2.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#define BASE            ".1.3.6.1.4.1.7933.1.20"
#define FIX_MODEL       "Vess A3120"          /* the assertion value          */
#define FIX_VENDOR      "Promise"
#define MAX_LEAVES      512
#define BIGBUF          (64u * 1024u)

static snmp_leaf g_leaf[MAX_LEAVES];
static int       g_n;

/* little-endian page pokes (mirror tests/test_raid.c) */
static void pl16(unsigned char*p,unsigned o,unsigned v){p[o]=v&0xff;p[o+1]=(v>>8)&0xff;}
static void pl32(unsigned char*p,unsigned o,unsigned v){pl16(p,o,v&0xffff);pl16(p,o+2,v>>16);}
static void pl64(unsigned char*p,unsigned o,unsigned long long v){pl32(p,o,(unsigned)v);pl32(p,o+4,(unsigned)(v>>32));}

/* ------------------------------------------------------------ fixture seed */
void snmp_fixture_install(void) {
    scsi_mock *m = scsi_mock_open();
    if (!m) return;
    tool_seed_a3120_demo(m);                     /* 6 fans / 3 temp / 3 volt  */

    unsigned char ci[256] = {0};                 /* ctrl-info 0x0103: product@224 */
    memcpy(ci + 224, FIX_MODEL, sizeof FIX_MODEL - 1);
    scsi_mock_seed(m, I2_MICOP_GET_CTRL_INFO, ci, sizeof ci);

    unsigned char da[1024] = {0};                /* arrays: DA7(4 HDD) DA9(2)  */
    pl32(da, I2R_PAGE_LEN_OFF_ARR, 2 * I2R_REC_ARRAY);
    unsigned char *a0 = da + I2R_PAGE_HDR, *a1 = a0 + I2R_REC_ARRAY;
    pl16(a0, I2R_DA_ID, 7); pl16(a0, I2R_DA_NUMHDD, 4);
    pl16(a1, I2R_DA_ID, 9); pl16(a1, I2R_DA_NUMHDD, 2);
    scsi_mock_seed(m, I2_MICOP_GET_ARRAY_INFO, da, sizeof da);

    unsigned char pd[1024] = {0};                /* phydrv: PD1 online, PD6 unconf */
    pl32(pd, I2R_PAGE_LEN_OFF, 2 * I2R_REC_PHYDRV);
    unsigned char *p0 = pd + I2R_PAGE_HDR, *p1 = p0 + I2R_REC_PHYDRV;
    pl16(p0, I2R_PD_ID, 0); pl16(p0, I2R_PD_DRVTYPE, I2BAPI_DRV_TYPE_SAS);
    memcpy(p0 + I2R_PD_MODEL, "ST4000NM0023", 12);
    pl64(p0, I2R_PD_BLOCKSIZE, 512); pl16(p0, I2R_PD_NUMBLOCKS, 1000);
    pl16(p0, I2R_PD_ENCID, 0); pl16(p0, I2R_PD_SLOT, 3);
    pl32(p0, I2R_PD_OPFLAG, PHYDRV_STATE_ONLINE | PHYDRV_CONFIG_CONFIGURED);
    pl16(p0, I2R_PD_ARRAYID, 7); p0[I2R_PD_TEMP] = 38;
    pl16(p1, I2R_PD_ID, 5); pl32(p1, I2R_PD_OPFLAG, 0);
    scsi_mock_seed(m, I2_MICOP_GET_PHYDRV_INFO, pd, sizeof pd);

    unsigned char ld[1024] = {0};                /* logdrv: LD0 R5 sync, LD1 crit */
    pl32(ld, I2R_PAGE_LEN_OFF, 2 * I2R_REC_LOGDRV);
    unsigned char *l0 = ld + I2R_PAGE_HDR, *l1 = l0 + I2R_REC_LOGDRV;
    pl16(l0, I2R_LD_ID, 0); l0[I2R_LD_RAIDLVL] = I2BAPI_LDRAIDLVL_RAID5;
    pl32(l0, I2R_LD_OPFLAG, LOGDRV_STATE_ONLINE | LOGDRV_COND_SYNCHRONIZED);
    pl32(l0, I2R_LD_CACHEFLAGS, 2);
    pl64(l0, I2R_LD_CAPBLOCKS, 2048); pl32(l0, I2R_LD_STRIPESECT, 128);
    pl16(l0, I2R_LD_ARRAYID, 7); memcpy(l0 + I2R_LD_ALIAS, "SystemVolume", 12);
    pl16(l1, I2R_LD_ID, 1); pl32(l1, I2R_LD_OPFLAG, LOGDRV_STATE_CRITICAL);
    scsi_mock_seed(m, I2_MICOP_GET_LOGDRV_INFO, ld, sizeof ld);

    unsigned char st[1024] = {0};                /* LD stats: LD2 */
    pl32(st, I2R_PAGE_LEN_OFF, 1 * I2R_REC_STATS);
    unsigned char *s0 = st + I2R_PAGE_HDR;
    s0[I2R_ST_LDID] = 2; pl64(s0, I2R_ST_BLOCKS, 100); pl32(s0, I2R_ST_ERRORS, 3);
    pl64(s0, I2R_ST_IOREQ, 4096); pl64(s0, I2R_ST_COLLTIME, 0x1122334455ULL);
    scsi_mock_seed(m, I2_MICOP_STATS_LOGDRV, st, sizeof st);

    i2bdg_session_set_transport(scsi_mock_iface(m));
}

/* ---------------------------------------------------------------- emitters */
static void emit(const char *suffix, const char *type, const char *val) {
    if (g_n >= MAX_LEAVES) return;
    snmp_leaf *L = &g_leaf[g_n];
    snprintf(L->oid, sizeof L->oid, "%s%s", BASE, suffix);
    L->narc = 0;
    for (const char *p = L->oid; *p; ) {
        if (*p == '.') { p++; continue; }
        char *end; unsigned long v = strtoul(p, &end, 10);
        if (end == p) break;
        if (L->narc < SNMP_OID_ARCS) L->arc[L->narc++] = v;
        p = end;
    }
    snprintf(L->type, sizeof L->type, "%s", type);
    snprintf(L->val, sizeof L->val, "%s", val);
    g_n++;
}
/* seg: 1=raidv4System, 2=raidv4Storage. idx = MIB INDEX arc string. */
static void e_s(int seg,int tbl,int col,const char*idx,const char*s){
    char sfx[56]; snprintf(sfx,sizeof sfx,".%d.%d.1.%d.%s",seg,tbl,col,idx); emit(sfx,"string",s);}
static void e_i(int seg,int tbl,int col,const char*idx,long v){
    char sfx[56],b[24]; snprintf(sfx,sizeof sfx,".%d.%d.1.%d.%s",seg,tbl,col,idx);
    snprintf(b,sizeof b,"%ld",v); emit(sfx,"integer",b);}
static void e_c64(int seg,int tbl,int col,const char*idx,unsigned long long v){
    char sfx[56],b[32]; snprintf(sfx,sizeof sfx,".%d.%d.1.%d.%s",seg,tbl,col,idx);
    snprintf(b,sizeof b,"%llu",v); emit(sfx,"counter64",b);}

static int oidcmp(const void*A,const void*B){
    const snmp_leaf*a=A,*b=B; int n=a->narc<b->narc?a->narc:b->narc;
    for(int i=0;i<n;i++) if(a->arc[i]!=b->arc[i]) return a->arc[i]<b->arc[i]?-1:1;
    return a->narc-b->narc;
}

static const char *pd_status(unsigned f){
    if(PHYDRV_STATE_FLAG(f)==PHYDRV_STATE_ONLINE) return "Online";
    return f==0 ? "Unconfigured" : "Offline";
}
static const char *ld_status(unsigned f){
    if(f&LOGDRV_STATE_ONLINE)   return "OK (Online)";
    if(f&LOGDRV_STATE_CRITICAL) return "Critical";
    return "Offline";
}

/* ------------------------------------------------------------ build tables */
int snmp_build_leaves(const snmp_leaf **out) {
    g_n = 0;
    char idx[24];

    /* ---- enclosure identity + element counts (i2_getEnclosureInfo/Settings) */
    I2bEncData_t enc; I2bElementCount_t cnt;
    int okEnc = i2bApi_getEncInfo(&enc,sizeof enc)==I2B_API_RET_SUCCESS &&
                i2bApi_getElementCnt(&cnt)==I2B_API_RET_SUCCESS;

    /* ---- storage reads (i2_getPhyDrvInfo/DiskArrayConfig/LogDrvInfo/Stats) */
    I2bHddInfo_t *hdd = calloc(256,sizeof *hdd);
    unsigned char *abuf=calloc(1,BIGBUF), *lbuf=calloc(1,BIGBUF), *sbuf=calloc(1,BIGBUF);
    unsigned nPD=0,nOnline=0,nDA=0,nLD=0,nST=0;
    I2bArrayInfo_t *arr=(I2bArrayInfo_t*)abuf;
    I2bLogDrvInfo_t *log=(I2bLogDrvInfo_t*)lbuf;
    I2bLogDrvStatusInfo_t *sta=(I2bLogDrvStatusInfo_t*)sbuf;
    if(hdd && i2bApi_getHardDiskDriveInfo(hdd,256*sizeof *hdd)==I2B_API_RET_SUCCESS){
        nPD=hdd[0].NumOfPD; if(nPD>256)nPD=256;
        for(unsigned i=0;i<nPD;i++) if(PHYDRV_STATE_FLAG(hdd[i].opStatusFlag)==PHYDRV_STATE_ONLINE) nOnline++;
    }
    if(abuf && i2bApi_getDiskArrayInfo(arr,BIGBUF)==I2B_API_RET_SUCCESS) nDA=arr->DACnt;
    if(lbuf && i2bApi_getLogDrvInfo(log,BIGBUF)==I2B_API_RET_SUCCESS) nLD=(unsigned)log->LDCnt;
    if(sbuf && i2bApi_getStatsLogDrv(sta,BIGBUF)==I2B_API_RET_SUCCESS) nST=(unsigned)sta->LDCnt;

    /* ---- raidv4InstanceTable  (System.1)  INDEX {InstanceIndex=1} --------- */
    e_i(1,1,1,"1",1);                                   /* index                */
    e_i(1,1,2,"1",1);                                   /* type: subsys(1)      */
    e_s(1,1,3,"1", FIX_VENDOR " " FIX_MODEL);           /* description          */
    e_s(1,1,4,"1","2000000155a0b1c2");                  /* identification (WWN) */
    e_s(1,1,5,"1","2016/03/04 12:00:00");               /* time                 */
    e_i(1,1,7,"1",1);                                   /* NumControllersPresent*/
    e_i(1,1,9,"1",1);                                   /* NumEnclosuresPresent */
    e_i(1,1,11,"1",255);                                /* RedundancyType n/s   */

    /* ---- raidv4SubsystemTable (System.2)  INDEX {InstanceIndex=1} --------- */
    e_s(1,2,1,"1", FIX_MODEL);                          /* alias                */
    e_s(1,2,2,"1","2000000155a0b1c2");                  /* WWN                  */
    e_s(1,2,3,"1", FIX_VENDOR);                         /* vendor  = "Promise"  */
    e_s(1,2,4,"1", FIX_MODEL);                          /* model   = "Vess A3120"*/
    e_s(1,2,5,"1","M32160000001");                      /* serial               */
    e_s(1,2,6,"1","F29VA3120010000");                   /* part                 */
    e_s(1,2,7,"1","A1");                                /* revision             */
    e_s(1,2,8,"1","2016/03/04");                        /* manufacture date     */

    /* ---- raidv4ControllerTable (System.3) INDEX {Inst=1,Ctrl=1} ---------- */
    char product[32]="";
    i2_handle *h=i2bdg_session_i2();
    if(h){ unsigned char page[0x458]; memset(page,0,sizeof page);
        if(i2_fw_cmd(h,I2_MICOP_GET_CTRL_INFO,0,0,SCSI_XFER_READ,page,sizeof page)==I2_OK){
            size_t i=0; for(;i<16 && page[224+i];i++) product[i]=(char)page[224+i]; product[i]='\0'; }
    }
    e_i(1,3,1,"1.1",1);                                 /* index                */
    e_s(1,3,2,"1.1","Controller 1");                    /* alias                */
    e_s(1,3,4,"1.1", FIX_VENDOR);                       /* vendor               */
    e_s(1,3,5,"1.1", product[0]?product:FIX_MODEL);     /* model (ctrl page@224)*/
    e_s(1,3,13,"1.1","1.00.0000.00");                   /* firmware rev         */
    e_s(1,3,15,"1.1","OK");                             /* op status            */
    e_i(1,3,18,"1.1",(long)nDA);                        /* NumOfArrayPresent    */
    e_i(1,3,19,"1.1",(long)nPD);                        /* NumOfPhyDrvPresent   */
    e_i(1,3,20,"1.1",(long)nOnline);                    /* NumOfPhyDrvOnline    */
    e_i(1,3,26,"1.1",(long)nLD);                        /* NumOfLogDrvPresent   */

    /* ---- enclosure + cooling/temp/volt (System.10/11/13/14) -------------- */
    if(okEnc){
        e_i(1,10,1,"1.1",1);                            /* EnclosureIndex       */
        e_s(1,10,2,"1.1",(const char*)enc.EncType);     /* EnclosureType        */
        e_s(1,10,3,"1.1","OK");                          /* EnclosureStatus      */
        e_s(1,10,4,"1.1","Operational");                 /* status description   */

        I2bFanData_t fans[16]; I2bThermalSensorData_t temps[16]; I2bVoltageSensorData_t volts[16];
        unsigned nf=cnt.NumOfFans>16?16:cnt.NumOfFans;
        unsigned nt=cnt.NumOfTempSensors>16?16:cnt.NumOfTempSensors;
        unsigned nv=cnt.NumOfVoltageSensors>16?16:cnt.NumOfVoltageSensors;
        if(nf) i2bApi_getFanInfo(fans,nf*sizeof fans[0]);
        if(nt) i2bApi_getThermalSensorInfo(temps,nt*sizeof temps[0]);
        if(nv) i2bApi_getVoltageSensorInfo(volts,nv*sizeof volts[0]);
        for(unsigned i=0;i<nf;i++){                      /* CoolingDeviceTable  */
            snprintf(idx,sizeof idx,"1.1.%lu",(unsigned long)fans[i].FanId);
            e_i(1,11,1,idx,(long)fans[i].FanId);
            e_s(1,11,2,idx,"Cooling Fan");
            e_s(1,11,3,idx, fans[i].FanStsFlag==FAN_STS_FUNCTIONAL?"Functional":"Malfunction");
            e_i(1,11,4,idx,(long)fans[i].FanSpd);       /* speed RPM Integer32  */
        }
        for(unsigned i=0;i<nt;i++){                      /* TemperatureSensor   */
            snprintf(idx,sizeof idx,"1.1.%u",temps[i].SensorId);
            e_i(1,13,1,idx,temps[i].SensorId);
            e_i(1,13,2,idx,temps[i].CurTemp);           /* degrees C  INTEGER   */
            e_s(1,13,3,idx,"Normal");
        }
        for(unsigned i=0;i<nv;i++){                      /* VoltageSensor  (BCD) */
            snprintf(idx,sizeof idx,"1.1.%u",volts[i].SensorId);
            char vb[12]; snprintf(vb,sizeof vb,"%d.%d",volts[i].CurVolt>>4,volts[i].CurVolt&0xf);
            e_i(1,14,1,idx,volts[i].SensorId);
            e_s(1,14,2,idx,vb);                          /* DisplayString "3.3" */
        }
    }

    /* ---- raidv4PhyDrvTable (Storage.1) INDEX {Inst=1,Phydrv=PDId} -------- */
    for(unsigned i=0;i<nPD;i++){
        snprintf(idx,sizeof idx,"1.%u",hdd[i].PDId);
        e_i(2,1,1,idx,hdd[i].PDId);
        e_s(2,1,2,idx, hdd[i].drvType==I2BAPI_DRV_TYPE_SAS?"SAS":"SATA"); /* interface */
        e_s(2,1,4,idx,(const char*)hdd[i].driveModelNo);
        e_s(2,1,8,idx, pd_status(hdd[i].opStatusFlag)); /* operational status   */
        e_i(2,1,12,idx,hdd[i].arrayId);
        e_i(2,1,14,idx,hdd[i].enclosureId);
        e_i(2,1,15,idx,hdd[i].slotId);
        e_c64(2,1,17,idx,hdd[i].confCapacity);          /* PhysicalCapacity C64 */
        e_i(2,1,20,idx,hdd[i].drvType);
    }

    /* ---- raidv4DiskArrayTable (Storage.4) INDEX {Inst=1,Array=DaId} ------ */
    for(unsigned i=0;i<nDA;i++){
        unsigned did=arr->DAData[i].DaId;
        snprintf(idx,sizeof idx,"1.%u",did);
        char alias[16]; snprintf(alias,sizeof alias,"DA%u",did);   /* -> "DA7" */
        unsigned ldcount=0;
        for(unsigned k=0;k<nLD;k++) if(log->LDData[k].arrayId==did) ldcount++;
        e_i(2,4,1,idx,did);
        e_s(2,4,2,idx,alias);                           /* alias "DA7"/"DA9"    */
        e_s(2,4,3,idx,"OK");                            /* operational status   */
        e_i(2,4,12,idx,arr->DAData[i].NumOfHdd);
        e_i(2,4,13,idx,(long)ldcount);
    }

    /* ---- raidv4LogDrvTable (Storage.5) INDEX {Inst=1,Logdrv=LdId} -------- */
    for(unsigned i=0;i<nLD;i++){
        snprintf(idx,sizeof idx,"1.%u",log->LDData[i].LdId);
        e_i(2,5,1,idx,log->LDData[i].LdId);
        e_s(2,5,2,idx,(const char*)log->LDData[i].Alias);
        e_s(2,5,5,idx, ld_status(log->LDData[i].OpStatusFlag));
        e_i(2,5,8,idx, log->LDData[i].SYNCed);          /* Synchronized         */
        e_i(2,5,9,idx, log->LDData[i].RaidLvl);         /* RAIDLevel INTEGER     */
        e_c64(2,5,10,idx, log->LDData[i].Capacity);     /* Capacity Counter64    */
        e_i(2,5,12,idx, log->LDData[i].arrayId);
    }

    /* ---- raidv4LogDrvStatsTable (Storage.8) INDEX {Inst=1,Logdrv=LdId} --- */
    for(unsigned i=0;i<nST;i++){
        snprintf(idx,sizeof idx,"1.%u",sta->LDStatsData[i].sld_LogicalDriveId);
        e_c64(2,8,1,idx, sta->LDStatsData[i].sld_BlocksTransferred);
        e_c64(2,8,4,idx, sta->LDStatsData[i].sld_Errors);
        e_c64(2,8,8,idx, sta->LDStatsData[i].sld_IOReq);
        e_c64(2,8,13,idx,sta->LDStatsData[i].sld_statsCollectionTime);
    }

    free(hdd); free(abuf); free(lbuf); free(sbuf);
    qsort(g_leaf,(size_t)g_n,sizeof g_leaf[0],oidcmp);
    *out = g_leaf;
    return g_n;
}
