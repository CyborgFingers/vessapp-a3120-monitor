<?PHP
include_once realpath(__DIR__ . '/../tree.php');

if (!isset($_SESSION['sessionService']) || !isset($_SESSION['time'])) {
    header('HTTP/1.0 401 Unauthorized');
    // error_log('Unauthorized request');
    exit(0);
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'POST':
        $server = getServer();
        $subsystem = $server->getSubsystem(1);
        $export = new stdClass();
        $export->type = IMEXPORT_SUBSYSTEM;
        $export->username = unserialize($_SESSION['sessionService'])->username;
        $export->password = unserialize($_SESSION['sessionService'])->password;
        $result = $subsystem->export($export);
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
