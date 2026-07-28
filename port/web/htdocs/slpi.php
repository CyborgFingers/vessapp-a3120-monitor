<?php

/*******************************************************************************
 * Filename    : slpi.php
 * Description : islaVista iSCSI SLP port information.
 * Created on  : 09/16/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$server = getServer();
$subsystem = $server->getSubsystem();
$ports = $subsystem->getiSCSISLP();

$note = isset($_GET['note']) ? $_GET['note'] : null;
?>

<html>
<head>
<?php
page_head();
?>
</head>

<body>
<form method=post>
  <table width=100% border=1 cellspacing=0 cellpadding=2 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('iscsislps'), 'iscsi', $note);
table_tab_head();

$tabTitle = tabTitleISCSI($subsystem);
table_tab($tabTitle);

for ($j = 0; $j < count($ports); $j++) {
  $port = $ports[$j];
  table_head_fold(array(gprop('slpport' . ($port->msc_PortID + 1)), 'slpt.php?ptid=' . $port->msc_PortID), 'PT' . $port->msc_PortID);
  table_row(gprop('portid'), $port->msc_PortID + 1, 40);
  table_row(gprop('slp'), getEnableString($port->msc_SLPFlags & SLP_FLAGS_ENABLE_SA));
  table_row(gprop('usedirectoryagent'), ($port->msc_SLPFlags & SLP_FLAGS_USE_DA) ? 'Yes' : 'No');
  if ($port->msc_SLPFlags & SLP_FLAGS_USE_DA) {
    table_row(gprop('getdirectoryagentipthrudhcp'), ($port->msc_SLPFlags & SLP_FLAGS_GET_DA_THRU_DHCP) ? 'Yes' : 'No');
    table_row(gprop('directoryagentipaddress'), $port->msc_DAIPAddress);
  }
  table_foot_fold();
  if ($j == 0) table_tab_foot();
}

if (count($ports) == 0) {
  table_head_fold(gprop('slp'), 'PT');
  table_row_empty(gresult('noiscsislpports'));
  table_foot_fold();
  table_tab_foot();
}
page_foot();
?>
  </table>
</form>

</body>
</html>
