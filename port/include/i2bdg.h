/*******************************************************************************
 * Filename    : i2bdg.h
 * Description : Library generic header of i2api
 * Created on  :
 * Created by  : Quennel
 * CVS Version :
 * (C) Copyright Promise Technology Inc., 2010
 * All Rights Reserved
 ******************************************************************************/

#ifndef __I2BDG_H__
#define __I2BDG_H__

/* generic i2 bridge function returns */
#define I2BDG_ERR_SUCCESS               0x00
#define I2BDG_ERR_PARAM                 0x01
#define I2BDG_ERR_LOGIN                 0x02
#define I2BDG_ERR_CREATE                0x03
#define I2BDG_ERR_I2API                 0x04
#define I2BDG_ERR_MEMALLOC              0x05
#define I2BDG_ERR_UNSUPPORTED           0x06
/* 0x07-0xFE are reserved for future error codes */
#define I2BDG_ERR_UNKNOWN               0xFF

/* i2 bridge error handling return status, i2bdg_error_handling */
#define I2BDG_EH_SUCCESS                0x00
#define I2BDG_EH_RETRY                  0x01
#define I2BDG_EH_UNKNOW                 0xFF

#define I2BDG_ERR_LOG_PATH              "C:\\Programdata\\Promise\\i2bdg_log.txt"

#endif /* __I2BDG_H__ */

