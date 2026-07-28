<?php

/*******************************************************************************
 * Filename    : restart.php
 * Description : islaVista restart webserver
 * Created on  : 03/18/05
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

header("HTTP/1.0 204 No Content");
sleep(3);
$server = getServer();
$subsystem = $server->getSubsystem();
$daemons = $subsystem->getAllDaemons();
if (isset($_GET['service'])) {
  $daemonName = $_GET['service'];
  $daemon->daemonname = $daemonName;
  $daemon->daemonstatus = DAEMON_RESTART;
  $subsystem->startStopDaemon($daemon, false);
}
?>

<html>
<head>
</head>
<body>
</body>
</html>
