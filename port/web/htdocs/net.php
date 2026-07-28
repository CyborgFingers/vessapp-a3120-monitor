<?php

/*******************************************************************************
 * Filename    : net.php
 * Description : islaVista ethernet and iSCSI network information.
 * Created on  : 09/26/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_GET['ssid']))
  SetSessionValue('ssid', $_GET['ssid']);

$act = isset($_GET['act']) ? $_GET['act'] : '';
$server = getServer();
$subsystem = $server->getSubsystem();
$controllers = $subsystem->getControllers();
$supportDualCtrl = $subsystem->supportdualcontroller();
$currentCtrlId = $subsystem->getCurrentCtrlId();
$currentCtrl = $subsystem->getController($currentCtrlId->currentCtrlId);
$CtrlId = $currentCtrl->ci_ProductID;
   
$nets = $subsystem->getiSCSINetInfo();

$note = isset($_GET['note']) ? $_GET['note'] : null;

function showNetPort($port, $CtrlId)
{
  global $act;
  $server = getServer();
  $subsystem = $server->getSubsystem();
  $supportDualCtrl = $subsystem->supportdualcontroller();
  $supportMx10i = $subsystem->supportMx10i();

  if ($supportDualCtrl->DualCtrlSupport)
    table_row(gprop('activeport'), gprop('controller') . ' ' . $port->activeCtrl, 40);
  table_row(gprop('port'), getEnableString($port->npis_PortEnabled));
  table_row(gprop('link'), $port->npis_PortStatus);
  table_row(gprop('iptype'), $port->npis_IPAddressType);
        
  table_row(gprop('ipaddress'), $port->npis_PrimaryIPAddress);
  table_row(gprop('subnetmask'), $port->npis_PrimarySubnetMask);
  table_row(gprop('macaddress'), $port->npis_PrimaryMACAddress);
  table_row(gprop('maxsupportedspeed'), $port->pni_MaxSupportedSpeed . ' Mbps');
}
?>

<html>
<head>
<?php
page_head();
$_SESSION['lastfile'] = htmlspecialchars($_SERVER['PHP_SELF']) . '?act=' . $act;
SyncSession();
?>
</head>

<body>
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center
    bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
if (count($nets) == 0)
  $note = gresult('nonetworkinfo');
$tabletitle = gprop('managementport');
$title = gprop('networkmgmtiscsiportstab');
table_title($tabletitle . ' ' . gprop('information'), 'ether', $note, null, $act);
table_tab_head();
$tabTitle = tabTitleNet($subsystem);
table_tab($tabTitle);
if (!isset($subsystem->frontendType)) {
  table_head_fold(gprop('portconfig'), 'IN');
  table_row_empty(gresult('nofronttypeuse'));
  table_foot_fold();
  table_tab_foot();
} else {
  if ($supportDualCtrl->DualCtrlSupport)
    $mgmtPortMsg = '  - ' . gprop('virtualmgmntports');
  else
    $mgmtPortMsg = '';
  if (count($nets)) {
    $net = $nets[0];
    $ports = $net->_ports;
    for ($j = 0; $j < count($ports); $j++) {
      $s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner');

      $port = $ports[$j];              
      table_head_fold(gprop('portconfig') . ' - ' . gprop('port') . ($port->npis_PortId + 1), 'PT' . $port->npis_PortId);
      showNetPort($port, $CtrlId);
      table_foot_fold();
      if ($j == 0) table_tab_foot();
    }
  }
}
page_foot();
?>
  </table>
</form>

</body>
</html>

