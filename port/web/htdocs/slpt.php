<?php

/*******************************************************************************
 * Filename    : slpt.php
 * Description : islaVista iSCSI SLP port settings.
 * Created on  : 09/16/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_POST['cancel'])) {
  header('Location: slpi.php?note=' . gresult('slpsettingcancel'));
  exit;
}

if (isset($_GET['ptid']))
  SetSessionValue('ptid', $_GET['ptid']);

$server = getServer();
$subsystem = $server->getSubsystem();
$ports = $subsystem->getiSCSISLPSettings($_SESSION['ptid']);
$port = $ports[0];

$note = null;
$result = 0;
if (count($_POST)) {
  if (isset($_POST['_useDA']))
    $port->msc_SLPFlags |= SLP_FLAGS_USE_DA;
  else
    $port->msc_SLPFlags &= (~SLP_FLAGS_USE_DA);

  if (isset($_POST['_thruDHCP']))
    $port->msc_SLPFlags |= SLP_FLAGS_GET_DA_THRU_DHCP;
  else
    $port->msc_SLPFlags &= (~SLP_FLAGS_GET_DA_THRU_DHCP);

  if (isset($_POST['_SA']))
    $port->msc_SLPFlags |= SLP_FLAGS_ENABLE_SA;
  else
    $port->msc_SLPFlags &= (~SLP_FLAGS_ENABLE_SA);

  if (isset($_POST['msc_DAIPAddress']))
    $port->msc_DAIPAddress = $_POST['msc_DAIPAddress'];

  $result = $subsystem->setiSCSISLPSettings($_SESSION['ptid'], $port);

  if ($result == 0) {
    redirectPage('slpi.php?note=' . gresult('slpchangeok'));
    exit;
  } else
    $note = gresult('slpchangefail');
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
<form method=post>
<?php form_csrf(); ?>
  <table width=100% border=1 cellspacing=0 cellpadding=2 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('iscsislpsettings'), 'iscsi', $note, $result);
table_tab_head();

$tabTitle = tabTitleISCSI($subsystem);
table_tab($tabTitle);

table_head_fold(gprop('iscsislpport') . ' ' . ($port->msc_PortID + 1), 'PT');
table_row(gprop('portid'), '&nbsp;&nbsp;' . ($port->msc_PortID + 1), 40);

// use SLP_FLAGS_ENABLE_SA,not SLP_FLAGS_ENABLE_SLP
table_row_checkbox(gprop('slpenable'), $port->msc_SLPFlags & SLP_FLAGS_ENABLE_SA, '_SA');
table_row_checkbox(gprop('usedirectoryagent'), $port->msc_SLPFlags & SLP_FLAGS_USE_DA, '_useDA');
table_row_checkbox(gprop('getdirectoryagentipthrudhcp'), $port->msc_SLPFlags & SLP_FLAGS_GET_DA_THRU_DHCP, '_thruDHCP');
table_row_edit(gprop('directoryagentipaddress'), $port->msc_DAIPAddress, 'msc_DAIPAddress', null, null, null, 'msg_DAIPAddress');

table_foot_fold();
table_tab_foot();

table_row_submit(array('submitClick' => 'validateSubmit()'));
page_foot();
?>
  </table>
</form>

<script>
  elements=document.forms[0].elements;
  var obj=elements['msc_DAIPAddress'];

  var o1=elements['_useDA'];
  ylib_addEvt(o1,'click',new Function('useDAChanged()'));
  var o2=elements['_thruDHCP'];
  ylib_addEvt(o2,'click',new Function('useDAChanged()'));

  originValue();

  function originValue()
  {
    o2.disabled=!o1.checked;
    if (!(o1.checked)||(o2.checked)){
      obj.ovalue='';
      obj.value='';
    }else
      obj.ovalue=obj.value;
    updateItems();
  }

  function useDAChanged()
  {
    o2.disabled=!o1.checked;
    if (!(o1.checked)||(o2.checked)){
      if (obj.value != '')
        obj.ovalue=obj.value;
      obj.value='';
    }else
      obj.value=obj.ovalue;
    updateItems();
  }

  function updateItems()
  {
    obj.disabled=!(o1.checked)||(o2.checked);
    updateEnableItemBgColor(obj);
  }

  function validateSubmit()
  {
    ip=elements['msc_DAIPAddress'];
    if(ip.disabled)
      return true;
    msg=msg_DAIPAddress;
    if(!validateIP(ip.value)){
      msg.innerHTML='<?= gjsalert('invalidip') ?>';
      ip.focus();
      return false;
    }
    else
      msg.innerHTML='';

    return true;
  }
</script>

</body>
</html>
