<?PHP
/*******************************************************************************
 * api/opas.php - LINUX PORT (Phase 3). Surgical transport swap only.
 *
 * Windows original built the export request in-process and called
 *   $server->getSubsystem(1)->export($export)   (php_i2 -> i2 engine).
 * Here the same request is sent to opasd via opas_call('export', ...). The
 * session gate (401), the HTTP method gate (400), and the success/failure
 * response contract ({"file":"opas"} / 500) are UNCHANGED.
 *
 * NOTE: config export is a WRITE/config op, cataloged not implemented
 * (docs/RE-opas.md §4) - opasd returns status=6 (UNSUPPORTED), so this endpoint
 * currently yields 500 until the export op is built. The transport swap is what
 * Phase 3 delivers; the op itself is Phase 4+/on-device.
 ******************************************************************************/
include_once realpath(__DIR__ . '/../opas_client.php');

if (!isset($_SESSION)) session_start();

if (!isset($_SESSION['sessionService']) || !isset($_SESSION['time'])) {
    header('HTTP/1.0 401 Unauthorized');
    // error_log('Unauthorized request');
    exit(0);
}

/* The Linux session stores an assoc array {sid,username,password}; tolerate the
 * legacy serialized-object form too. */
$sess = $_SESSION['sessionService'];
if (is_string($sess)) $sess = @unserialize($sess);
$sid      = is_array($sess) ? ($sess['sid']      ?? 0)  : ($sess->sid      ?? 0);
$username = is_array($sess) ? ($sess['username']  ?? '') : ($sess->username ?? '');
$password = is_array($sess) ? ($sess['password']  ?? '') : ($sess->password ?? '');

define('IMEXPORT_SUBSYSTEM', 1);   /* was a php_i2 constant */
define('PIERR_NO_ERROR', 0);

switch ($_SERVER['REQUEST_METHOD']) {
    case 'POST':
        $resp = opas_call('export', array(
            'sid'      => $sid,
            'type'     => IMEXPORT_SUBSYSTEM,
            'username' => $username,
            'password' => $password,
        ));
        $result = isset($resp['status']) ? $resp['status'] : -1;
        if ($result != PIERR_NO_ERROR) {
            error_log("export failed. result = $result");
            header("HTTP/1.0 500 Internal Server Error");
        } else {
            header('Content-Type: application/json');
            echo '{"file":"opas"}';
        }
        break;
    default:
        header('HTTP/1.1 400 Bad Request');
}
