/*
 * i2_client.c - the I2Client native class (php_i2.dll string "i2client").
 *
 * Returned by the I2Client()/I2ClientEx() factory functions. The htdocs call two
 * methods on it (tree.php): ->getSubsystem($flag) to reach the subsystem object,
 * and ->logout(). Properties sessionId/status/frontendType/model are set by the
 * factory; the class allows dynamic properties because the PHP also assigns
 * ->model (tree.php:640).
 */
#include "php_i2_int.h"

zend_class_entry *i2_client_ce;

ZEND_BEGIN_ARG_INFO_EX(arginfo_client_none, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_client_getsubsystem, 0, 0, 0)
    ZEND_ARG_INFO(0, flag)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_client_call, 0, 0, 0)
    ZEND_ARG_INFO(0, name)
    ZEND_ARG_INFO(0, args)
ZEND_END_ARG_INFO()

/* ->getSubsystem($flag = 0): tree.php:334/337. Returns an I2Subsystem object
 * carrying the subsystem summary props (u32Ret == 0 means "no error"; the pages
 * redirect to login on a nonzero u32Ret). */
PHP_METHOD(I2Client, getSubsystem)
{
    zend_long flag = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &flag) == FAILURE)
        RETURN_NULL();
    (void)flag;
    object_init_ex(return_value, i2_subsystem_ce);
    i2_subsystem_fill_props(return_value);
}

/* ->logout(): tree.php:391. Consume the session token. */
PHP_METHOD(I2Client, logout)
{
    zval rv;
    zval *sid = I2_READ_PROP(i2_client_ce, getThis(), "sessionId", sizeof("sessionId") - 1, &rv);
    if (i2_fixture_mgr() && sid)
        opas_logout(i2_fixture_mgr(), (unsigned)zval_get_long(sid));
    RETURN_TRUE;
}

/* __call: catch-all so any other client method (cataloged/unimplemented) returns
 * an empty object instead of fataling. */
PHP_METHOD(I2Client, __call)
{
    char *name = NULL; size_t nlen = 0; zval *args = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "sz", &name, &nlen, &args) == FAILURE)
        RETURN_NULL();
    object_init(return_value);
}

static const zend_function_entry i2_client_methods[] = {
    PHP_ME(I2Client, getSubsystem, arginfo_client_getsubsystem, ZEND_ACC_PUBLIC)
    PHP_ME(I2Client, logout,       arginfo_client_none,         ZEND_ACC_PUBLIC)
    PHP_ME(I2Client, __call,       arginfo_client_call,         ZEND_ACC_PUBLIC)
    PHP_FE_END
};

void i2_client_register(void)
{
    zend_class_entry ce;
    INIT_CLASS_ENTRY(ce, "I2Client", i2_client_methods);
    i2_client_ce = zend_register_internal_class(&ce);
    i2_allow_dynamic_props(i2_client_ce);
}
