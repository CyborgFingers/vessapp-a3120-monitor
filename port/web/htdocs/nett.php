<?php

/*******************************************************************************
 * Filename    : nett.php
 * Description : islaVista iSCSI network settings.
 * Created on  : 09/25/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_POST['cancel'])) {
  header('Location: net.php?act=' . $_GET['act'] . '&note=' . gprop(($_GET['act'] == 'iscsi' ? 'iscsiport' : 'managementport')) . gresult('portsettingcancel'));
  exit;
}

if (isset($_GET['ctid']))
  SetSessionValue('ctid', $_GET['ctid']);
if (isset($_GET['ptid']))
  SetSessionValue('ptid', $_GET['ptid']);

$specific = 'empty';
if (isset($_GET['act']))
  $specific = $_GET['act'];
$server = getServer();
$subsystem = $server->getSubsystem();
$supportDualCtrl = $subsystem->supportdualcontroller();
$supportMx10i = $subsystem->supportMx10i();
if ($supportMx10i->supportmx10i) {
  $vlans = $subsystem->getiscsivlan(-1);
  function isEnalbe($vlans, $ptid)
  {
    for ($i = 0; $i < count($vlans); $i++) {
      $vlan = $vlans[$i];
      if ($vlan->mvsd_JumboFrame == 1 && $vlan->mvsd_VLANInterface == $ptid && $vlan->mvsd_VLANInterface == ISCSI_VLAN_IF_TYPE_PHY) {
        return true;
      }
    }
    return false;
  }
}
  
/*get the cclass ctrl type*/
$currentCtrlId = $subsystem->getCurrentCtrlId();
$currentCtrl = $subsystem->getController($currentCtrlId->currentCtrlId);


if ($specific == 'iscsi')
  $port = $subsystem->getiSCSINetPortSettings($_SESSION['ctid'], $_SESSION['ptid']);
else
  $port = $subsystem->getEthernetPortSettings($_SESSION['ctid'], $_SESSION['ptid']);

$note = null;
$result = 0;
$pv = getUserPrivilege($subsystem);
if ($pv < USER_GROUP_POWER)
  $note = galert('noauthmodifysetting');
if (count($_POST)) {
  $IPAddress = $port->npis_PrimaryIPAddress;
  $port->npis_DHCPEnabled = ($port->npis_DHCPSupported && isset($_POST['npis_DHCPEnabled'])) ? 1 : 0;
  if ($specific == 'iscsi' && $port->npis_DHCPSupported && $port->npis_DNSLearningThroughDHCPIsSupported)
    $port->npis_DNSLearningThroughDHCP = isset($_POST['npis_DNSLearningThroughDHCP']) ? 1 : 0;

  if (isset($_POST['npis_PrimaryIPAddress']))
    $port->npis_PrimaryIPAddress = $_POST['npis_PrimaryIPAddress'];
  if (isset($_POST['npis_PrimarySubnetMask']))
    $port->npis_PrimarySubnetMask = $_POST['npis_PrimarySubnetMask'];

  // if(isset($_POST['npis_DefaultGatewayIPAddress']))
  //   $port->npis_DefaultGatewayIPAddress=$_POST['npis_DefaultGatewayIPAddress'];

  $port->npis_WOL_Status = isset($_POST['npis_WOL_Status']) ? 128 : 0;

  $port->ipni_JumboFrame = 0;
  if (isset($_POST['ipni_JumboFrame']))
    $port->ipni_JumboFrame = 1;
  if ($port->npis_SecondaryIPAddressSupported) {
    if (isset($_POST['npis_SecondaryIPAddress']))
      $port->npis_SecondaryIPAddress = $_POST['npis_SecondaryIPAddress'];
    if (isset($_POST['npis_SecondarySubnetMask']))
      $port->npis_SecondarySubnetMask = $_POST['npis_SecondarySubnetMask'];
  }
  /*
  if ($specific == 'iscsi') {
    if (isset($_POST['ipni_TCPPortNumber']))
      $port->ipni_TCPPortNumber = $_POST['ipni_TCPPortNumber'];
  } else {
    if (isset($_POST['npis_PrimaryDNSServerIPAddress']))
      $port->npis_PrimaryDNSServerIPAddress = $_POST['npis_PrimaryDNSServerIPAddress'];
  }
   */
  if ($specific == 'iscsi')
    $result = $subsystem->setiSCSINetPortSettings($_SESSION['ctid'], $_SESSION['ptid'], $port);
  else
    $result = $subsystem->setEthernetPortSettings($_SESSION['ctid'], $_SESSION['ptid'], $port);

  if ($result == 0) {
    redirectPage('net.php?act=' . $specific . '&note=' . gresult('ethernetsettingchangeok'));
    exit;
  } else
    $note = gresult('ethernetsettingchangefail');
}
SyncSession();
?>

<html>
<head>
<?php
page_head();
?>
</head>

<body>
<form method="post">
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('managementport') . ' ' . gprop('settings'), null, $note, $result, $specific);
table_tab_head();

$tabTitle = tabTitleNet($subsystem);
table_tab($tabTitle);
if ($supportDualCtrl->DualCtrlSupport)
  $mgmtPortMsg = ' - ' . gprop('virtualmgmntports');
else
  $mgmtPortMsg = '';

$s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner');
table_head_fold(gprop('managementport') . $mgmtPortMsg, 'NT', null, null, $s);

if ($port->npis_DHCPSupported)
  table_row_checkbox(gprop('enabledhcp'), $port->npis_DHCPEnabled, 'npis_DHCPEnabled');
table_row_edit(gprop('ipaddress'), $port->npis_PrimaryIPAddress, 'npis_PrimaryIPAddress', '31', '', '', 'msg_PrimaryIPAddress');
if ($port->npis_SecondaryIPAddressSupported)
  table_row_edit(gprop('secipaddress'), $port->npis_SecondaryIPAddress, 'npis_SecondaryIPAddress', '31', '', '', 'msg_SecondaryIPAddress');
table_row_edit(gprop('subnetmask'), $port->npis_PrimarySubnetMask, 'npis_PrimarySubnetMask', '31', '', '', 'msg_PrimarySubnetMask');
if ($port->npis_SecondaryIPAddressSupported)
  table_row_edit(gprop('secondarysubnetmask'), $port->npis_SecondarySubnetMask, 'npis_SecondarySubnetMask', '31', '', '', 'msg_SecondarySubnetMask');
// table_row_edit(gprop('gatewayipaddress'),$port->npis_DefaultGatewayIPAddress,'npis_DefaultGatewayIPAddress','31','','','msg_DefaultGatewayIPAddress');

if ($specific == 'iscsi') {
  table_row_checkbox(gprop('jumboframe'), $port->ipni_JumboFrame, 'ipni_JumboFrame');
  // table_row_edit(gprop('tcpportnumber'),$port->ipni_TCPPortNumber,'ipni_TCPPortNumber','31','','','msg_TCPPortNumber');
} else
  table_row_edit(gprop('primarydnsserveripaddress'), $port->npis_PrimaryDNSServerIPAddress, 'npis_PrimaryDNSServerIPAddress', '31', '', '', 'msg_PrimaryDNSServerIPAddress');
    
table_row_checkbox(gprop('wakeonlan'), $port->npis_WOL_Status, 'npis_WOL_Status');

table_foot_fold();
table_tab_foot();
table_row_submit(array('submitClick' => 'validateSubmit()', 'submit' => ($pv < USER_GROUP_POWER ? 2 : 1)));
page_foot();
?> 
  </table>
</form>

<script>
  var elements=document.forms[0].elements;

  var o1=elements['npis_DHCPEnabled'];
  ylib_addEvt(o1,'click',new Function('DHCPChanged()'));
  // var o2=elements['npis_DNSLearningThroughDHCP'];
  // ylib_addEvt(o2,'click',new Function('autoDNSChanged()'));

  originValue();

  function originValue()
  {
    // if(!o1||!o1.checked){
    //  o2.disabled=true;
    // }
    updateItems();
  }

  function DHCPChanged()
  {
    if(!o1) return;
    // if(o2){
    //  if(o1.checked)
    //    o2.disabled=false;
    // else{
    //    o2.disabled=true;
    //    o2.checked=false;
    //    autoDNSChanged();
    //  }
    // }
    updateItems();
  }

  function autoDNSChanged()
  {
    // if(!o2) return;
    updateItems();
  }

  function updateItems()
  {
    if(o1)
    {
      elements['npis_PrimaryIPAddress'].disabled=o1.checked;
      elements['npis_PrimarySubnetMask'].disabled=o1.checked;
      // elements['npis_DefaultGatewayIPAddress'].disabled=o1.checked;
      if(<? echo $specific == 'iscsi' ? 0 : 1 ?>)
        elements['npis_PrimaryDNSServerIPAddress'].disabled=o1.checked;

      updateEnableItemBgColor(elements['npis_PrimaryIPAddress']);
      updateEnableItemBgColor(elements['npis_PrimarySubnetMask']);
      // updateEnableItemBgColor(elements['npis_DefaultGatewayIPAddress']);
      if(<? echo $specific == 'iscsi' ? 0 : 1 ?>)
        updateEnableItemBgColor(elements['npis_PrimaryDNSServerIPAddress']);
    }
  }

  function validateSubmit()
  {
    npis_PrimaryIPAddress=elements['npis_PrimaryIPAddress']; 
    npis_PrimarySubnetMask=elements['npis_PrimarySubnetMask'];
    // npis_DefaultGatewayIPAddress=elements['npis_DefaultGatewayIPAddress'];  
    // ipni_TCPPortNumber=elements['ipni_TCPPortNumber'];                       
    focusItem = null;        
    if(!validateIP(npis_PrimaryIPAddress.value)){
        msg_PrimaryIPAddress.innerHTML='<?= gjsalert('ipaddresserror') ?>' ;
        focusItem=npis_PrimaryIPAddress;
    }    
    else   
        msg_PrimaryIPAddress.innerHTML='';   
    if(!validateIP(npis_PrimarySubnetMask.value)){
        msg_PrimarySubnetMask.innerHTML='<?= gjsalert('subneterror') ?>' ;
        focusItem=npis_PrimarySubnetMask;
     }           
    else    
        msg_PrimarySubnetMask.innerHTML='';    
    /* 
    if(!validateIP(npis_DefaultGatewayIPAddress.value)){
      msg_DefaultGatewayIPAddress.innerHTML='<?= gjsalert('subneterror') ?>' ;
      focusItem=npis_DefaultGatewayIPAddress;
    }           
    else         
      msg_DefaultGatewayIPAddress.innerHTML='';   
    if(!check_range(ipni_TCPPortNumber,1,65535)){
      msg_TCPPortNumber.innerHTML='<?= gjsalert('tcpportnumbererror') ?>' ;
      focusItem=ipni_TCPPortNumber;
    }          
    else       
      msg_TCPPortNumber.innerHTML=''; 
     */     
    if (focusItem!=null) {
        focusItem.focus();
        return false;
    }      
    return true ;    
  } 
</script>

</body>
</html>
