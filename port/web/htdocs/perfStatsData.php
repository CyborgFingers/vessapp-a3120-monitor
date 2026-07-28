<?php
include 'tree.php';

$server = getServer();
$subsystem = $server->getSubsystem();
$perfData = $subsystem->getPerfStats();
if ($perfData->result == PIERR_NO_ERROR) {
  echo $perfData->data;
}
?>

