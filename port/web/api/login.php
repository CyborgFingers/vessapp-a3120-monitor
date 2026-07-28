<?PHP
/*******************************************************************************
 * api/login.php - LINUX PORT (Phase 3). Surgical transport swap only.
 *
 * Windows original called the native php_i2 function getdynamiccode(). Here the
 * one engine call is routed to opasd; the request parsing, method switch, and
 * {err,code,id} response contract the login JS depends on are UNCHANGED.
 * Diff vs windows-src/.../api/login.php: + include opas_client.php; the single
 * line `$dc = getdynamiccode();` becomes `$dc = opas_call('dc');`.
 ******************************************************************************/
include_once realpath(__DIR__ . '/../opas_client.php');

$inputJSON = file_get_contents('php://input');
if (!isset($inputJSON) || strlen($inputJSON) == 0) {
    header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
    exit;
}

$input = json_decode($inputJSON, TRUE); //convert JSON into array
// print_r($input);
if (!isset($input) || !isset($input['method'])) {
    header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
    exit;
}


switch ($input['method']) {
    case 'dc':
        $dc = opas_call('dc');   // was: getdynamiccode();  (php_i2 -> opasd)

        if (!isset($dc)) {
            header("HTTP/1.0 500 Internal Server Error");
            die('$dc error');
        }

        if ($dc['err'] != 0) {
            header("HTTP/1.0 500 Internal Server Error");
            die('$dc->err error');
        }

        echo json_encode($dc);
    break;

    default:
        header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
}
