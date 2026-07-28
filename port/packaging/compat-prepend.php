<?php
/*
 * compat-prepend.php - SAPI compat shim (Phase 6), loaded via auto_prepend_file
 * so the htdocs stay byte-identical to the ported Windows tree.
 *
 * The export/import/event-log pages (import.php, export.php, download.php,
 * events.php, phydrvSmartLog.php) call apache_getenv('TMP') - a mod_php-only
 * function that does not exist under PHP-FPM (nor under any FastCGI SAPI).
 * Provide it, defaulting TMP to the system temp dir as the XAMPP Apache env did.
 */
if (!function_exists('apache_getenv')) {
    function apache_getenv($name, $walk_to_top = false)
    {
        $v = getenv($name);
        if ($v === false && strtoupper($name) === 'TMP') {
            return sys_get_temp_dir();
        }
        return $v;
    }
}
