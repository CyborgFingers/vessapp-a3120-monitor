<?php

/*******************************************************************************
 * Filename    : download.php
 * Description : islaVista download a file.
 * Created on  : 10/31/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';

// Function:
// Prerequisite:
//    - $_SESSION['sessionService'] is defined
//    - tree.php is included
function getCurrentUserPrivilege() {
    $server=getServer();
    $subsystem=$server->getSubsystem();
    $sessionInfo = $subsystem->getSessionInfo();

    if ($sessionInfo->errorcode != 0) {
        return null;
    }

    foreach ($sessionInfo->sessionlist as $key => $value) {
        if ($value->sessionid == unserialize($_SESSION['sessionService'])->sid) {
            return $value->userGroupId;
        }
    }
    return null;
}

if (!isset($_SESSION['sessionService'])) {
    die();
}

if (getCurrentUserPrivilege() != 'Super') {
    die();
}

if (!isset($_GET['file'])) {
    die();
}

$filetype = basename($_GET['file']);

$types = array(
    'opas' => array('opaslog.zip', 'opaslog.zip'),
    'export' => array('export', 'export'),
    'cfgscr' => array('configscript', 'configscript'),
    'evtlog' => array('eventlog', 'eventlog.txt'),
    'pdsmrt' => array('PDSMARTInfo.txt', 'PDSMARTInfo.txt'),
);

if (!isset($types[$filetype])) {
    header("HTTP/1.1 404 Not Found");
    die();
}

$localfilename = apache_getenv('TMP') . '\\' . $types[$filetype][0];
$transferfilename = $types[$filetype][1];

if (!file_exists($localfilename)) {
    header("HTTP/1.1 404 Not Found");
    die();
}

header("Content-type: application/octet-stream'");
header('Content-Disposition: attachment; filename="' . $transferfilename . '"');

readfile($localfilename);
ignore_user_abort(true);
unlink($localfilename);
?>
