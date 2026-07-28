/*******************************************************************************
 * Filename    : i2bdg_api.h
 * Description : I2 bridge api header
 * Created on  : 2010/10/01
 * Created by  : Quennel
 * CVS Version :
 * (C) Copyright Promise Technology Inc., 2010
 * All Rights Reserved
 ******************************************************************************/

#ifndef __I2BDG_API_H__
#define __I2BDG_API_H__

#ifdef __cplusplus
extern "C" {
#endif

#define I2B_API_DATA_TRANSFER_SIZE              65535

#define I2B_EVT_GET_RAM                         1
#define I2B_EVT_GET_RAM_LASTID                  2
#define I2B_EVT_GET_NVRAM                       3
#define I2B_EVT_GET_NVRAM_LASTID                4

/* LED type */
#define I2B_LED_FAN1                            0x02
#define I2B_LED_FAN2                            0x03
#define I2B_LED_RAID_STS                        0x0C
#define I2B_LED_USB1                            0x0D
#define I2B_LED_USB2                            0x0E
#define I2B_LED_RECORD                          0x0F
#define I2B_LED_SYSTEM                          0x10
#define I2B_LED_GLOBAL_HDD_STS                  0x11
#define I2B_LED_ETH_PORT1                       0x12
#define I2B_LED_ETH_PORT2                       0x13
#define I2B_LED_PWR                             0x14
#define I2B_LED_ETH_PORT3                       0x15
#define I2B_LED_ETH_PORT4                       0x16
#define I2B_LED_HeartBeat                       0x17
#define I2B_LED_HDD_SLOT1                       0x80
#define I2B_LED_HDD_SLOT2                       0x81
#define I2B_LED_HDD_SLOT3                       0x82
#define I2B_LED_HDD_SLOT4                       0x83
#define I2B_LED_HDD_SLOT5                       0x84
#define I2B_LED_HDD_SLOT6                       0x85
#define I2B_LED_HDD_SLOT7                       0x86
#define I2B_LED_HDD_SLOT8                       0x87
#define I2B_LED_HDD_SLOT9                       0x88
#define I2B_LED_HDD_SLOT10                      0x89
#define I2B_LED_HDD_SLOT11                      0x8A
#define I2B_LED_HDD_SLOT12                      0x8B
#define I2B_LED_HDD_SLOT13                      0x8C
#define I2B_LED_HDD_SLOT14                      0x8D
#define I2B_LED_HDD_SLOT15                      0x8E
#define I2B_LED_HDD_SLOT16                      0x8F

/* LED status */
#define I2B_LED_STS_OFF                         0x80
#define I2B_LED_STS_GREEN                       0x01
#define I2B_LED_STS_RED                         0x02
#define I2B_LED_STS_AMBER                       0x04
#define I2B_LED_STS_BLINK_GREEN                 0x08
#define I2B_LED_STS_BLINK_RED                   0x10
#define I2B_LED_STS_BLINK_AMBER                 0x20

/* --- SMART constant definitions ------------------------------------------------ */
#define MAX_SMART_DATA_SIZE 1024

// Maximum allowed number of SMART Attributes
#define NUMBER_ATA_SMART_ATTRIBUTES     30

// ATA Specification Command Register Values (Commands)
#define ATA_IDENTIFY_DEVICE             0xec                                              
#define ATA_IDENTIFY_PACKET_DEVICE      0xa1
#define ATA_SMART_CMD                   0xb0
#define ATA_CHECK_POWER_MODE            0xe5
#define ATA_DEVICE_DIAGNOSTIC           0x90

// ATA Specification Feature Register Values (SMART Subcommands).
// Note that some are obsolete as of ATA-7.
#define ATA_SMART_READ_VALUES           0xd0
#define ATA_SMART_READ_THRESHOLDS       0xd1
#define ATA_SMART_AUTOSAVE              0xd2
#define ATA_SMART_SAVE                  0xd3
#define ATA_SMART_IMMEDIATE_OFFLINE     0xd4
#define ATA_SMART_READ_LOG_SECTOR       0xd5
#define ATA_SMART_WRITE_LOG_SECTOR      0xd6
#define ATA_SMART_WRITE_THRESHOLDS      0xd7
#define ATA_SMART_ENABLE                0xd8
#define ATA_SMART_DISABLE               0xd9
#define ATA_SMART_STATUS                0xda

/* Watch Dog Timer Unit */
#define WATCH_DOG_TIMER_UNIT_SECOND             0x01
#define WATCH_DOG_TIMER_UNIT_MINUTE             0x02

/* device flag for logdrv and phydrv */
#define DEVICE_STATE_MASK       0xFF000000
#define DEVICE_COND_MASK        0x00FF0000
#define DEVICE_OPERATION_MASK   0x0000FF00
#define DEVICE_CONFIG_MASK      0x000000FF

typedef enum
{
	I2B_API_RET_SUCCESS               = 0x00,
	I2B_API_RET_PARAM_ERR             = 0x01,
	I2B_API_RET_LOGIN_ERR             = 0x02,
	I2B_API_RET_CREATE_ERR            = 0x03,
	I2B_API_RET_I2API_ERR             = 0x04,
	I2B_API_RET_MEMALLOC_ERR          = 0x05,
	I2B_API_RET_UNSUPPORTED           = 0x06,

	I2B_API_RET_UNKNOWN               = 0xFF
}
I2B_API_RET_E;


typedef struct
{
    unsigned char 	 NumOfMB;
    unsigned char 	 NumOfSep;
    unsigned char 	 NumOfPsus;
    unsigned char 	 NumOfCUs;

    unsigned char 	 NumOfFans;
    unsigned char 	 NumOfTempSensors;
    unsigned char 	 NumOfVoltageSensors;
    unsigned char 	 NumOfAudibleAlarms;

    unsigned char 	 NumOfBatteries;
    unsigned char 	 NumOfHDSlots;

    unsigned char 	 reserved[10];
}
I2bElementCount_t;

/* Thermal Sensor data struct
 */
typedef struct
{
    unsigned char 	EncId;			/* Enclosure ID */
    unsigned char 	EncType[30];		/* Enclosure Type */
}
I2bEncData_t;

/* Thermal Sensor data struct
 */
typedef struct
{
    unsigned char 	SensorId;			/* Sensor ID */
    unsigned char 	WarnThreshold;		/* Warning threshold */
    unsigned char 	CritThreshold;		/* Critical threshold */
    unsigned char 	CurTemp;			/* Current temperature */
    unsigned char 	Location[16];		/* Sensor location string */
    unsigned char   StatusFlag;         /* Temperature status flag */
	
#define ENC_TEMERATURE_STS_NORMAL           0x00
#define ENC_TEMERATURE_STS_OVER_WARNING     0x01
#define ENC_TEMERATURE_STS_OVER_CRITICAL    0x02

    unsigned char 	Rsvd[3];
}
I2bThermalSensorData_t;

/* Voltage Sensor data struct
 */
typedef struct
{
    unsigned char 	SensorId;			/* Sensor ID */
    unsigned char 	CurVolt;			/* Current Voltage */
    unsigned char 	Rsvd[2];
    unsigned char 	Type[8];		    /* Sensor type string */
    unsigned char 	Threshold[24];		/* Sensor healthy threshold string */
    unsigned char   StatusFlag;         /* Voltage status flag */
	
#define VOLTAGE_STS_NORMAL                  0x00
#define VOLTAGE_STS_OVER_HIGH_THRESHOLD     0x01
#define VOLTAGE_STS_OVER_LOW_THRESHOLD      0x02

}
I2bVoltageSensorData_t;



/* Hard Disk Drive info struct
 */
typedef struct
{
    unsigned long      FanId; 		    /* Fan ID                */
    unsigned long      FanThrd;     	/* Fan health threshold  */
    unsigned long      FanSpd;     		/* Fan Speed RPM         */
    unsigned char      FanLoc[32];    	/* Fan location          */
    unsigned char      FanSts[32];		/* Fan status            */
	unsigned char      FanStsFlag;      /* Fna status flag       */
	
#define FAN_STS_FUNCTIONAL        0x00
#define FAN_STS_MALFUNCTION       0x01
#define FAN_STS_NOT_INSTALLED     0x02

}
I2bFanData_t;

/* Disk array info struct
 */
typedef struct
{
	unsigned short		DaId; 			/* Disk array ID */
	unsigned short		NumOfHdd; 		/* Count of HDD in array */
	/* Port extension (RAID-ENGINE-MDADM-PLAN.md P2): per-DA capacity so
	 * arrays.php can show real Configurable/Free sizes. Filled by the mdadm
	 * backend; the sgio record parse leaves them 0 (callers zero the buffer),
	 * which the records layer renders exactly as before (N/A / 0B). */
	unsigned long long	CfgCapacity;	/* bytes: sum of member PD capacity */
	unsigned long long	FreeCapacity;	/* bytes: unallocated on the members */
	/* webpam-monitor health backend fills these with the real pool/array name and
	 * op-status; the mdadm/sgio backends memset the record so they stay empty and
	 * the records layer keeps its existing "DiskArray<n>" + LD-derived status. */
	unsigned char		ArrayName[36];
	unsigned char		OpStatus[24];
}
I2bDiskArrayData_t;

typedef struct
{
	unsigned char  DACnt;
	I2bDiskArrayData_t		DAData[1];
}
I2bArrayInfo_t;

/* Hard Disk Drive info struct
 */
typedef struct
{
    unsigned int        NumOfPD;                /* Tota Number of Physical Drive  */
    unsigned short     	PDId;       			/* Flat ID                        */
    unsigned short      drvType;    			/* Drive Type SAS or SATA         */
	
#define I2BAPI_DRV_TYPE_UNKNOWN        0
#define I2BAPI_DRV_TYPE_SATA           1
#define I2BAPI_DRV_TYPE_SAS            4

    unsigned char      	driveModelNo [40];    	/* Drive Model Number             */
    unsigned long long 	confCapacity;   		/* configurable capacity          */
    unsigned short     	enclosureId;
    unsigned short     	slotId;
    unsigned char     	opStatus [16];   		/* Operation Status string        */
    unsigned int        opStatusFlag;           /* Operation Status flag          */
	
#define PHYDRV_STATE_MASK               DEVICE_STATE_MASK
#define PHYDRV_STATE_FLAG(u32Flag)      (u32Flag & PHYDRV_STATE_MASK)
#define PHYDRV_STATE_ONLINE             0x01000000
#define PHYDRV_STATE_MISSING            0x02000000
#define PHYDRV_STATE_REBUILD            0x08000000

#define PHYDRV_COND_MASK                DEVICE_COND_MASK
#define PHYDRV_COND_FLAG(u32Flag)       (u32Flag & PHYDRV_COND_MASK)
#define PHYDRV_COND_PFA                 0x00020000
#define PHYDRV_COND_FORCED              0x00040000
#define PHYDRV_COND_TEMP                0x00080000
#define PHYDRV_COND_TEMPSPARE           0x00100000
#define PHYDRV_COND_PRESENT             0x00200000
#define PHYDRV_COND_ACCESSIBLE          0x00400000

#define PHYDRV_OPERATION_MASK           DEVICE_OPERATION_MASK
#define PHYDRV_OPERATION_FLAG(u32Flag)  (u32Flag & PHYDRV_OPERATION_MASK)
#define PHYDRV_OPERATION_INITIALIZING   0x00000400
#define PHYDRV_OPERATION_MP             0x00000500
#define PHYDRV_OPERATION_SYNCHRONIZING  0x00000600
#define PHYDRV_OPERATION_TRANSITION     0x00000700
#define PHYDRV_OPERATION_MIGRATION      0x00000800
#define PHYDRV_OPERATION_PDM            0x00000900

#define PHYDRV_CONFIG_MASK              DEVICE_CONFIG_MASK
#define PHYDRV_CONFIG_FLAG(u32Flag)     (u32Flag & PHYDRV_CONFIG_MASK)
#define PHYDRV_CONFIG_UNCONFIGURED      0x00000000
#define PHYDRV_CONFIG_CONFIGURED        0x00000001
#define PHYDRV_CONFIG_GLOBALSPARE       0x00000002
#define PHYDRV_CONFIG_DEDICATEDSPARE    0x00000004
#define PHYDRV_CONFIG_STALE             0x00000008
#define PHYDRV_CONFIG_PASSTHRU          0x00000010

    unsigned char      	configStatus [32];   	/* Configuration Status string    */
    unsigned char       HDDTempWarThreshold;    /* PD temperature warning thershould */
    unsigned char       HDDTempCriThreshold;    /* PD temperature warning thershould */
    unsigned char       temperature;            /* PD temperature                 */
    unsigned char       TempStatusFlag;         /* PD temperature status          */
	
#define PD_TEMPERATURE_STS_NORMAL           0x00
#define PD_TEMPERATURE_STS_OVER_WARNING     0x01
#define PD_TEMPERATURE_STS_OVER_CRITICAL    0x02

    unsigned char       controllerId;           /* PD belong to controller Id     */
    unsigned short      arrayId;                /* PD belong to array Id          */
    /* webpam-monitor health backend: kernel device name ("sda"), so a non-A3120
     * host (no enclosure/bays) can show the device instead of a fake "Slot N".
     * mdadm/sgio memset the record -> empty -> pages keep their Encl/Slot view. */
    unsigned char       osName[16];
}
I2bHddInfo_t;

typedef struct
{
	unsigned short		LdId; 			/* Flat logical ID */
	unsigned short		Rsvd0; 			/* Reserved */
	unsigned char		Alias[32];		/* Alias */
	unsigned char		OpStatus[32]; 	/* Operation Status String*/
    unsigned int        OpStatusFlag;   /* Operation Status Flag */
	
#define LOGDRV_STATE_MASK               DEVICE_STATE_MASK
#define LOGDRV_STATE_FLAG(u32Flag)      (u32Flag & LOGDRV_STATE_MASK)
#define LOGDRV_STATE_OFFLINE            0x00000000
#define LOGDRV_STATE_ONLINE             0x01000000
#define LOGDRV_STATE_CRITICAL           0x02000000
#define LOGDRV_STATE_DEGRADED           0x04000000

#define LOGDRV_COND_MASK                DEVICE_COND_MASK
#define LOGDRV_COND_FLAG(u32Flag)       (u32Flag & LOGDRV_COND_MASK)
#define LOGDRV_COND_INITIALIZED         0x00010000
#define LOGDRV_COND_PFA                 0x00020000
#define LOGDRV_COND_SYNCHRONIZED        0x00040000
#define LOGDRV_MIGRATION_NVWM_MISSING   0x00080000
#define LOGDRV_COND_TRANSPORT_READY     0x00100000
#define LOGDRV_COND_SYNC_FORCED         0x00200000
#define LOGDRV_COND_SERVICE_EXIST       0x00800000

#define LOGDRV_OPERATION_MASK           DEVICE_OPERATION_MASK
#define LOGDRV_OPERATION_FLAG(u32Flag)  (u32Flag & LOGDRV_OPERATION_MASK)
#define LOGDRV_OPERATION_IDEL           0x00000000
#define LOGDRV_OPERATION_REBUILDING     0x00000100
#define LOGDRV_OPERATION_INITIALIZING   0x00000400
#define LOGDRV_OPERATION_REDCHECKING    0x00000500
#define LOGDRV_OPERATION_SYNCHRONIZING  0x00000600
#define LOGDRV_OPERATION_MIGRATING      0x00000800

#define LOGDRV_CONFIG_MASK              DEVICE_CONFIG_MASK
#define LOGDRV_CONFIG_FLAG(u32Flag)     (u32Flag & LOGDRV_CONFIG_MASK)
#define LOGDRV_CONFIG_CONFIGURED        0x00000001

	unsigned long long	Capacity; 		/* Logcal Drive Capacity in bytes */
	unsigned long long	StripeSz; 		/* Stripe Size */

	/* Logcal Drive RAID Level
	 */
	unsigned char		RaidLvl;

#define I2BAPI_LDRAIDLVL_RAID0		0x00
#define I2BAPI_LDRAIDLVL_RAID1		0x01
#define I2BAPI_LDRAIDLVL_RAID3		0x03
#define I2BAPI_LDRAIDLVL_RAID5		0x05
#define I2BAPI_LDRAIDLVL_RAID6		0x06
#define I2BAPI_LDRAIDLVL_RAID10		0x10
#define I2BAPI_LDRAIDLVL_RAID50		0x50
#define I2BAPI_LDRAIDLVL_RAID60		0x60

 	/* Logcal Drive Cache Policy
 	 */
	unsigned char		CachePolicy;

#define I2BAPI_LDCACHEPOLICY_NOCACHE_WTHRU	0
#define I2BAPI_LDCACHEPOLICY_RCACHE_WTHRU	1
#define I2BAPI_LDCACHEPOLICY_RCACHE_WBACK	2
#define I2BAPI_LDCACHEPOLICY_RAHEAD_WTHRU	3
#define I2BAPI_LDCACHEPOLICY_RAHEAD_WBACK	4

	/* Logcal Drive Synced?
	 */
	unsigned char		SYNCed;

#define I2BAPI_LDSYNCED_YES					0
#define I2BAPI_LDSYNCED_NO					1

    unsigned short      arrayId;                /* LD belong to array Id */
    unsigned char       controllerId;           /* LD belong to controller Id     */
}
I2bLogDrvData_t;


typedef struct
{
	unsigned long		LDCnt;
	I2bLogDrvData_t		LDData[1];
}
I2bLogDrvInfo_t;

typedef struct
{
    unsigned char            sld_LogicalDriveId;
    unsigned long long       sld_BlocksTransferred;
    unsigned long long       sld_ReadBlocksTransferred;
    unsigned long long       sld_WriteBlocksTransferred;
    unsigned long            sld_Errors;
    unsigned long            sld_ReadErrors;
    unsigned long            sld_WriteErrors;
    unsigned long            sld_NonRWErrors;
    unsigned long long       sld_IOReq;
    unsigned long long       sld_ReadIOReq;
    unsigned long long       sld_WriteIOReq;
    unsigned long long       sld_NonRWReq;
    unsigned long long       sld_statsStartTime;
    unsigned long long       sld_statsCollectionTime;
}I2StatsLogicalDrive_t;

typedef struct
{
	unsigned long		    LDCnt;
	I2StatsLogicalDrive_t	LDStatsData[1];
}
I2bLogDrvStatusInfo_t;

typedef struct
{
    unsigned char          evt_MetaData[64];
    unsigned short         evt_SequenceNumber;
    unsigned char          evt_DeviceId[16];
    unsigned char          evt_Severity[16];
    unsigned char          evt_Timestamp[32];
    unsigned char          evt_EventDesc[128];
}
I2bEventInfo_t;

typedef struct
{
	unsigned char	TypeOfLED;
	unsigned char	DescOfLED[32];
	unsigned char	ColorOfLED[8];
}
LEDInfo;

typedef struct
{
	unsigned char	DefOfLED;
	unsigned char *	DescOfColor;
}
LEDColor;

typedef struct
{
    unsigned char        LDId;
    unsigned char        CacheUsage;     /*% cache usage*/
    unsigned char        DirtyCache;     /*% dirty cache */
    unsigned char        spd_Reserved0[7];   /*reserved for future usage */
    unsigned short       MaxLatency;    /*in ms */
    unsigned short       MinLatency;    /*in ms */
    unsigned short       AverLatency;    /*in ms */
    unsigned long long       IOReq;          /*number of IOs */
    unsigned long long       BandWidth;      /*in bytes/second */
}
LD_PerfStat;

typedef struct
{
    unsigned char        PDId;
    unsigned char        spd_Reserved0[9];   /*reserved for future usage */
    unsigned short       MaxLatency;    /*in ms */
    unsigned short       MinLatency;    /*in ms */
    unsigned short       AverLatency;    /*in ms */
    unsigned long long       IOReq;          /*number of IOs */
    unsigned long long       BandWidth;      /*in bytes/second */
}
PD_PerfStat;

/* --- SMART data structures ----------------------------------------------------- */

// SCT (SMART Command Transport) data structures
// See Sections 8.2 and 8.3 of:
//   AT Attachment 8 - ATA/ATAPI Command Set (ATA8-ACS)
//   T13/1699-D Revision 3f (Working Draft), December 11, 2006.

// SCT Status response (read with SMART_READ_LOG page 0xe0)
// Table 60 of T13/1699-D Revision 3f 
#pragma pack(1)
typedef struct
{
    unsigned short format_version;    // 0-1: Status response format version number (2, 3)
    unsigned short sct_version;       // 2-3: Vendor specific version number
    unsigned short sct_spec;          // 4-5: SCT level supported (1)
    unsigned int status_flags;        // 6-9: Status flags (Bit 0: Segment initialized, Bits 1-31: reserved)
    unsigned char device_state;       // 10: Device State (0-5)
    unsigned char bytes011_013[3];    // 11-13: reserved
    unsigned short ext_status_code;   // 14-15: Status of last SCT command (0xffff if executing)
    unsigned short action_code;       // 16-17: Action code of last SCT command
    unsigned short function_code;     // 18-19: Function code of last SCT command
    unsigned char bytes020_039[20];   // 20-39: reserved
    unsigned long long lba_current;             // 40-47: LBA of SCT command executing in background
    unsigned char bytes048_199[152];  // 48-199: reserved
    signed char hda_temp;             // 200: Current temperature in Celsius (0x80 = invalid)
    signed char min_temp;             // 201: Minimum temperature this power cycle
    signed char max_temp;             // 202: Maximum temperature this power cycle
    signed char life_min_temp;        // 203: Minimum lifetime temperature
    signed char life_max_temp;        // 204: Maximum lifetime temperature
    unsigned char byte205;            // 205: reserved (T13/e06152r0-2: Average lifetime temperature)
    unsigned int over_limit_count;    // 206-209: # intervals since last reset with temperature > Max Op Limit
    unsigned int under_limit_count;   // 210-213: # intervals since last reset with temperature < Min Op Limit
    unsigned char bytes214_479[266];  // 214-479: reserved
    unsigned char vendor_specific[32];// 480-511: vendor specific
}
I2b_ata_sct_status_response_t;
#pragma pack()

/* ata_smart_attribute is the vendor specific in SFF-8035 spec */ 
#pragma pack(1)
typedef struct
{
    unsigned char id;
    // meaning of flag bits: see MACROS just below
    // WARNING: MISALIGNED!
    unsigned short flags; 
    unsigned char current;
    unsigned char worst;
    unsigned char raw[6];
    unsigned char reserv;
}
I2b_ata_smart_attribute_t;
#pragma pack()

/* ata_smart_values is format of the read drive Attribute command */
/* see Table 34 of T13/1321D Rev 1 spec (Device SMART data structure) for *some* info */
#pragma pack(1)
typedef struct
{
    unsigned short revnumber;
    I2b_ata_smart_attribute_t vendor_attributes [NUMBER_ATA_SMART_ATTRIBUTES];
    unsigned char offline_data_collection_status;
    unsigned char self_test_exec_status;  //IBM # segments for offline collection
    unsigned short total_time_to_complete_off_line; // IBM different
    unsigned char vendor_specific_366; // Maxtor & IBM curent segment pointer
    unsigned char offline_data_collection_capability;
    unsigned short smart_capability;
    unsigned char errorlog_capability;
    unsigned char vendor_specific_371;  // Maxtor, IBM: self-test failure checkpoint see below!
    unsigned char short_test_completion_time;
    unsigned char extend_test_completion_time;
    unsigned char conveyance_test_completion_time;
    unsigned char reserved_375_385[11];
    unsigned char vendor_specific_386_510[125]; // Maxtor bytes 508-509 Attribute/Threshold Revision #
    unsigned char chksum;
} 
I2b_ata_smart_values_t;
#pragma pack()

// Table 42 of T13/1321D Rev 1 spec (Error Data Structure)
#pragma pack(1)
typedef struct ata_smart_errorlog_error_struct 
{
    unsigned char reserved;
    unsigned char error_register;
    unsigned char sector_count;
    unsigned char sector_number;
    unsigned char cylinder_low;
    unsigned char cylinder_high;
    unsigned char drive_head;
    unsigned char status;
    unsigned char extended_error[19];
    unsigned char state;
    unsigned short timestamp;
} 
I2b_ata_smart_errorlog_error_struct_t;
#pragma pack()

// Table 41 of T13/1321D Rev 1 spec (Command Data Structure)
#pragma pack(1)
typedef struct ata_smart_errorlog_command_struct 
{
    unsigned char devicecontrolreg;
    unsigned char featuresreg;
    unsigned char sector_count;
    unsigned char sector_number;
    unsigned char cylinder_low;
    unsigned char cylinder_high;
    unsigned char drive_head;
    unsigned char commandreg;
    unsigned int timestamp;
}
I2b_ata_smart_errorlog_command_struct_t;
#pragma pack()

// Table 40 of T13/1321D Rev 1 spec (Error log data structure)
#pragma pack(1)
typedef struct ata_smart_errorlog_struct 
{
    I2b_ata_smart_errorlog_command_struct_t commands[5];
    I2b_ata_smart_errorlog_error_struct_t error_struct;
} 
I2b_ata_smart_errorlog_struct_t;
#pragma pack()

// Table 39 of T13/1321D Rev 1 spec (SMART error log sector)
#pragma pack(1)
typedef struct ata_smart_errorlog 
{
    unsigned char revnumber;
    unsigned char error_log_pointer;
    I2b_ata_smart_errorlog_struct_t errorlog_struct[5];
    unsigned short ata_error_count;
    unsigned char reserved[57];
    unsigned char checksum;
}
I2b_ata_smart_errorlog_t;
#pragma pack()

// Table 45 of T13/1321D Rev 1 spec (Self-test log descriptor entry)
#pragma pack(1)
typedef struct 
{
    unsigned char selftestnumber; // Sector number register
    unsigned char selfteststatus;
    unsigned short timestamp;
    unsigned char selftestfailurecheckpoint;
    unsigned int lbafirstfailure;
    unsigned char vendorspecific[15];
} 
I2b_ata_smart_selftestlog_struct_t;
#pragma pack()

// Table 44 of T13/1321D Rev 1 spec (Self-test log data structure)
#pragma pack(1)
typedef struct 
{
    unsigned short revnumber;
    I2b_ata_smart_selftestlog_struct_t selftest_struct[21];
    unsigned char vendorspecific[2];
    unsigned char mostrecenttest;
    unsigned char reserved[2];
    unsigned char chksum;
} 
I2b_ata_smart_selftestlog_t;
#pragma pack()

typedef struct ata_smart_log_info
{
    unsigned char asli_u8SmartHealthStatus[16];

    //ata_smart_log_support       asli_aslsLogSupport;
    //ata_smart_selftest_in_progress_t asli_assipInProgress;
    //ata_identify_device_t       asli_aidDevice;
    I2b_ata_sct_status_response_t   asli_assrSctStatus;
    I2b_ata_smart_values_t          asli_asvSmartValue;
    //ata_smart_thresholds_pvt_t  asli_astThreshold;
    I2b_ata_smart_selftestlog_t     asli_assSelftestLog;
    I2b_ata_smart_errorlog_t        asli_aseErrorLog;

}
I2b_ata_smart_log_info_t;

typedef struct
{
    unsigned char dsi_u8SenseLen;
    unsigned char dsi_u8Reserve[7];

#define MAX_DISKUTIL_SENSE_BUFFER 64
    unsigned char dsi_u8SenseBuffer[MAX_DISKUTIL_SENSE_BUFFER];

}
I2b_smart_sense_info_t;

typedef struct  
{
    union
    {
        I2b_ata_smart_log_info_t  dsli_asliAtaLog;
        //i2b_scsi_smart_log_info_t dsli_ssliScsiLog;
    };

} 
I2b_smart_log_info_t;

typedef struct
{
    unsigned short dsi_u16PdId;
    unsigned char dsi_u8PdType;
    unsigned char dsi_u8SmartStatus; /* Enable, Disable, In progress... */
    unsigned char dsi_u8Reserve[4];

    unsigned char dsi_u8ModelName[40];
    unsigned char dsi_u8Reserve1[2];
    
    unsigned char dsi_u8Buffer[MAX_SMART_DATA_SIZE];

    I2b_smart_log_info_t dsi_dsliLogInfo;
    I2b_smart_sense_info_t dsi_senseInfo;

}
I2b_smart_info_t;

/***************************************************************************
* Function Name: i2bApi_getByesToSizeString

* Description  : Get the size string in GB, MB, KB and B. The size string will
*                be the format of "<xxxx|xxxx.x|xxxx.xx><TB|GB|MB|KB|B>"
*
* Parameters   : [in]  u64Size, the size in bytes
*                [out] out_pStrBuf, the size string to be returned;
*
* Return       : none.
*
* Remarks      : This function does not check the size of the string buffer.
*                please make sure it is big enough to avoid memory access violation.
****************************************************************************/
void i2bApi_getByesToSizeString( const unsigned long long u64Size, char *pStrBuf,unsigned int u32Size );

/***************************************************************************
* Function Name: i2bApi_getElementCnt

* Description  : Get the count of different elements of the enclosure.
*
* Parameters   : [out] out_pCntBuf, the structure containing element
*                      counts to be returned.
*
* Return       : I2B_API_RET_SUCCESS, No error.
*                I2B_API_RET_PARAM_ERR, empty return buffer.
*                I2B_API_RET_MEMALLOC_ERR, internal memory allocation error.
*
* Remarks      : This function does not check the size of the buffer.
*                please make sure that the buffer the size of structure I2bElementCount_t.
****************************************************************************/
I2B_API_RET_E i2bApi_getElementCnt ( I2bElementCount_t *out_CntBuf );

/***************************************************************************
* Function Name: 

* Description  : Get the information of each enclosure.
*
* Parameters   : 
*
* Return       : I2B_API_RET_SUCCESS, No error.
*                I2B_API_RET_PARAM_ERR, incorrect input parameters.
*                I2B_API_RET_MEMALLOC_ERR, internal memory allocation error.
*                I2B_API_RET_I2API_ERR, internal error getting data from the system.
*
* Remarks      :
****************************************************************************/
I2B_API_RET_E i2bApi_getEncInfo ( I2bEncData_t *out_DataBuf, unsigned long in_BufLen );

/***************************************************************************
* Function Name: i2bApi_getThermalSensorInfo

* Description  : Get the information of each thermal sensor in the enclosure.
*
* Parameters   : [out] out_DataBuf, array of structures to return thermal
*                      sensor data.
*                [in]  in_BufLen, size of the input buffer. Buffer length must
*                      not be less than "sensor count * size of structure I2bThermalSensorData_t".
*                      sensor count can be obtained using function i2bApi_getElementCnt.
*
* Return       : I2B_API_RET_SUCCESS, No error.
*                I2B_API_RET_PARAM_ERR, incorrect input parameters.
*                I2B_API_RET_MEMALLOC_ERR, internal memory allocation error.
*                I2B_API_RET_I2API_ERR, internal error getting data from the system.
*
* Remarks      :
****************************************************************************/
I2B_API_RET_E i2bApi_getThermalSensorInfo ( I2bThermalSensorData_t *out_DataBuf, unsigned long in_BufLen );

/***************************************************************************
* Function Name: i2bApi_getVoltageSensorInfo

* Description  : Get the information of each voltage sensor in the enclosure.
*
* Parameters   : [out] out_DataBuf, array of structures to return voltage
*                      sensor data.
*                [in]  in_BufLen, size of the input buffer. Buffer length must
*                      not be less than "sensor count * size of structure I2bVoltageSensorData_t".
*                      sensor count can be obtained using function i2bApi_getElementCnt.
*
* Return       : I2B_API_RET_SUCCESS, No error.
*                I2B_API_RET_PARAM_ERR, incorrect input parameters.
*                I2B_API_RET_MEMALLOC_ERR, internal memory allocation error.
*                I2B_API_RET_I2API_ERR, internal error getting data from the system.
*
* Remarks      :
****************************************************************************/
I2B_API_RET_E i2bApi_getVoltageSensorInfo ( I2bVoltageSensorData_t *out_DataBuf, unsigned long in_BufLen );

/***************************************************************************
* Function Name: i2bApi_getFanInfo

* Description  : Get the information of each fan in the enclosure.
*
* Parameters   : [out] out_DataBuf, array of structures to return fan data.
*                [in]  in_BufLen, size of the input buffer. Buffer length must
*                      not be less than "fan count * size of structure I2bFanData_t".
*                      fan count can be obtained using function i2bApi_getElementCnt.
*
* Return       : I2B_API_RET_SUCCESS, No error.
*                I2B_API_RET_PARAM_ERR, incorrect input parameters.
*                I2B_API_RET_MEMALLOC_ERR, internal memory allocation error.
*                I2B_API_RET_I2API_ERR, internal error getting data from the system.
*
* Remarks      :
****************************************************************************/
I2B_API_RET_E i2bApi_getFanInfo ( I2bFanData_t *out_DataBuf, unsigned long in_BufLen );

/***************************************************************************
* Function Name: i2bApi_getDiskArrayInfo

* Description  : Get the information of each disk array present in the enclosure.
*
* Parameters   : [out] out_DataBuf, array of structures to return logical drive data.
*                [in]  in_BufLen, size of the input buffer. Buffer length must
*                      not be less than "max logical drive count * size of structure I2bLogDrvInfo_t".
*                      A large buffer of 64K should be allocated.
*
* Return       : I2B_API_RET_SUCCESS, No error.
*                I2B_API_RET_PARAM_ERR, incorrect input parameters.
*                I2B_API_RET_MEMALLOC_ERR, internal memory allocation error.
*                I2B_API_RET_I2API_ERR, internal error getting data from the system.
*
* Remarks      :
****************************************************************************/
I2B_API_RET_E i2bApi_getDiskArrayInfo ( I2bArrayInfo_t *out_DataBuf, unsigned long in_BufLen );

/***************************************************************************
* Function Name: i2bApi_getHardDiskDriveInfo

* Description  : Get the information of each Hard disk drive present in the enclosure.
*
* Parameters   : [out] out_DataBuf, array of structures to return hdd data.
*                [in]  in_BufLen, size of the input buffer. Buffer length must
*                      not be less than "max hdd count * size of structure I2bHddInfo_t".
*                      max hdd count can be obtained using function i2bApi_getElementCnt.
*
* Return       : I2B_API_RET_SUCCESS, No error.
*                I2B_API_RET_PARAM_ERR, incorrect input parameters.
*                I2B_API_RET_MEMALLOC_ERR, internal memory allocation error.
*                I2B_API_RET_I2API_ERR, internal error getting data from the system.
*
* Remarks      :
****************************************************************************/
I2B_API_RET_E i2bApi_getHardDiskDriveInfo ( I2bHddInfo_t *out_DataBuf, unsigned long in_BufLen );

/***************************************************************************
* Function Name: i2bApi_getLogDrvInfo

* Description  : Get the information of each logical drive present in the enclosure.
*
* Parameters   : [out] out_DataBuf, array of structures to return logical drive data.
*                [in]  in_BufLen, size of the input buffer. Buffer length must
*                      not be less than "max logical drive count * size of structure I2bLogDrvInfo_t".
*                      A large buffer of 64K should be allocated.
*
* Return       : I2B_API_RET_SUCCESS, No error.
*                I2B_API_RET_PARAM_ERR, incorrect input parameters.
*                I2B_API_RET_MEMALLOC_ERR, internal memory allocation error.
*                I2B_API_RET_I2API_ERR, internal error getting data from the system.
*
* Remarks      :
****************************************************************************/
I2B_API_RET_E i2bApi_getLogDrvInfo ( I2bLogDrvInfo_t *out_DataBuf, unsigned long in_BufLen );

/***************************************************************************
* Function Name: i2bApi_getStatsLogDrv

* Description  : Get the information and status of each logical drive present in the enclosure.
*
* Parameters   : [out] out_DataBuf, array of structures to return logical drive status data.
*                [in]  in_BufLen, size of the input buffer. Buffer length must
*                      not be less than "max logical drive count * size of structure I2bLogDrvStatusInfo_t".
*                      A large buffer of 64K should be allocated.
*
* Return       : I2B_API_RET_SUCCESS, No error.
*                I2B_API_RET_PARAM_ERR, incorrect input parameters.
*                I2B_API_RET_MEMALLOC_ERR, internal memory allocation error.
*                I2B_API_RET_I2API_ERR, internal error getting data from the system.
*
* Remarks      :
****************************************************************************/
I2B_API_RET_E i2bApi_getStatsLogDrv ( I2bLogDrvStatusInfo_t *out_DataBuf, unsigned long in_BufLen );

I2B_API_RET_E i2bApi_dispose ( void );

/*
** I2 Bridge additional APIs
*/
int i2bdg_dispose (void);

/* get the specific events in the ram and nvram */
int i2bdg_getEvent (unsigned char action, unsigned short seq_id, unsigned short count, I2bEventInfo_t *buffer);
int i2bdg_setEvent (unsigned short ClassEventID, unsigned short CodeEventID, unsigned short DeviceID, unsigned char *ExtraLog);
/* get/set record led (turn on or off) */
int i2bdg_getRecordLED (unsigned char *p_color);
int i2bdg_setRecordLED (unsigned char    color);

/* get/set system led (3 colors and turn off) */
int i2bdg_getSysErrorLED (unsigned char *p_color, unsigned short *p_cause);
int i2bdg_setSysErrorLED (unsigned char    color, unsigned short    cause);

/* get/set common LED status */
int i2bdg_getLED (unsigned char id, unsigned char *p_color, unsigned short *p_cause);
int i2bdg_setLED (unsigned char id, unsigned char color, unsigned short cause);

/* get/set terminal alarm state */
int i2bdg_getTermAlramState (unsigned short *p_state);
int i2bdg_setTermAlramState (unsigned short    state);

/* get/set terminal sensor state */
int i2bdg_getTermSensorState (unsigned short *p_state);
//int i2bdg_setTermSensorState (unsigned short    state);

/* get/set uart mode */
int i2bdg_getUARTMode (unsigned char uart_id, unsigned char *p_mode);
int i2bdg_setUARTMode (unsigned char uart_id, unsigned char    mode);

/* init/update it8720f watch dog timer */
I2B_API_RET_E i2bdg_enableWDT (void);
I2B_API_RET_E i2bdg_updateWDT (unsigned char interval);
I2B_API_RET_E i2bdg_disableWDT (void);

/* send bypass command */
int i2bdg_SMARTReadData (I2b_smart_info_t * pInfo);
int i2bdg_SMARTReadSelftestLog (I2b_smart_info_t * pInfo);
int i2bdg_SMARTReadErrorLog (I2b_smart_info_t * pInfo);
int i2bdg_SMARTStatus (I2b_smart_info_t * pInfo);

/*
** get/set VPD
*/
int i2bdg_getMBVPD (unsigned char *p_data, int offset, int length);
int i2bdg_setMBVPD (unsigned char *p_data, int offset, int length);
int i2bdg_getBPVPD (unsigned char *p_data, int offset, int length);
int i2bdg_setBPVPD (unsigned char *p_data, int offset, int length);

#ifdef __cplusplus
}
#endif
#endif /* __I2BDG_API_H__ */
