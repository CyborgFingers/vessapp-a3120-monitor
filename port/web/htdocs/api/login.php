<?PHP

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
        $dc = getdynamiccode();

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
