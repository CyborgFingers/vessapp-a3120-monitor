<?php

/*******************************************************************************
 * Filename    : admin.php
 * Description : islaVista administrative tools
 * Created on  : 10/06/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$server = getServer();
$subsystem = $server->getSubsystem();
$currentCtrlId = $subsystem->getCurrentCtrlId();
$currentCtrl = $subsystem->getController($currentCtrlId->currentCtrlId);
?>
<html>

<head>
<?php
page_head();
?>
</head>

<body>
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center
    bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_tab_head();
$tabTitle = array();
table_head();
table_title(gprop('administrativetools'), 'admin');
table_head(array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
$columns = array(gprop('toollist'), gprop('description'));
$widths = array(30, 70);
table_header_cols($columns, $widths);
$columns = array(
  array(gprop('usermanagement'), 'users.php?ssid=0'),
  gprop('usermgmtdesc')
);
table_row_cols($columns);

$columns = array(
  array(gprop('networkmanagement'), 'net.php?act=ether&ssid=0'),
  gprop('networkmgmtdesc')
);
table_row_cols($columns);

/*
if ($subsystem->frontendType == PI_FB_HOST_INTERFACE_ISCSI) {
  $columns = array(
    array(gprop('iscsimanagement'), 'iNode.php?ssid=0'),
    gprop('iscsimgmtdesc')
  );
} else if ($subsystem->frontendType == PI_FB_HOST_INTERFACE_FC) {
  $columns = array(
    array(gprop('fibrechannelmanagement'), 'fcNode.php?ssid=0'),
    gprop('fibrechannelmgmtdesc')
  );
} else if ($subsystem->frontendType == PI_FB_HOST_INTERFACE_SAS) {
  $columns = array(
    array(gprop('sasmanagement'), 'sasPortList.php?ssid=0'),
    gprop('sasmgmtdesc')
  );
} else if ($subsystem->frontendType == PI_FB_HOST_INTERFACE_SCSI) {
  $columns = array(
    array(gprop('scsimanagement'), 'pPort.php?ssid=0'),
    gprop('scsimgmtdesc')
  );
}
table_row_cols($columns);
 */

$columns = array(
  array(gprop('softwaremanagement'), 'services.php?ssid=0'),
  gprop('servicemgmtdesc')
);
table_row_cols($columns);

/*
$columns = array(
  array(gprop('snmpmanagement'), 'snmp.php?act=create&tab=0'),
  gprop('snmpmgmtdesc')
);
table_row_cols($columns);

$columns = array(
  array(gprop('netsendmanagement'), 'netsend.php?act=create&tab=0'),
  gprop('netsendmgmtdesc')
);
table_row_cols($columns);

$columns = array(
  array(gprop('webserversetting'), 'webserver.php?tab=0'),
  gprop('webserversettingdesc')
);
table_row_cols($columns);

$columns = array(
  array('SSL / TLS Certificate', 'sslsettings.php'),
  'Install your own server certificate + CA chain, or regenerate the self-signed certificate.'
);
table_row_cols($columns);

$columns = array(
  array(gprop('emailsetting'), 'email.php?act=create&tab=0'),
  gprop('emailsettingdesc')
);
table_row_cols($columns);

$columns = array(
  array(gprop('telnetsetting'), 'telnet.php?tab=0'),
  gprop('telnetsettingdesc')
);
table_row_cols($columns);
 */

if (getUserPrivilege($subsystem) >= USER_GROUP_SUPER) {
  $columns = array(
    array(gprop('export'), 'export.php?ssid=0&tab=1'),
    gprop('exportdesc')
  );
  table_row_cols($columns);

  $columns = array(
    array(gprop('import'), 'import.php?ssid=0&tab=2'),
    gprop('importdesc')
  );
  table_row_cols($columns);

  /*
  $columns = array(
    array(gprop('firmwareupdate'), 'uploadnew.php?ssid=0&tab=3'),
    gprop('firmwareupdatedesc')
  );
  table_row_cols($columns);
   */

  $columns = array(
    array(gprop('restorefactorydefaults'), 'restore.php?ssid=0'),
    gprop('restorefactorydefaultsdesc')
  );
  table_row_cols($columns);

  $columns = array(
    array(gprop('performancestatistics'), 'perfStats.php?ssid=0'),
    gprop('performancestatisticsdesc')
  );
  table_row_cols($columns);

  $columns = array(
    array(gprop('clearstatistics'), 'clearStat.php?ssid=0'),
    gprop('clearstatisticsdesc')
  );
  table_row_cols($columns);

  $columns = array(
    array(gprop('savesystemservicereport'), 'subsystem.php?ssid=0&tab=0'),
    gprop('savesystemservicereportdesc')
  );
  table_row_cols($columns);

  /*
  $columns = array(
    array(gprop('shutdown'), 'shutdown.php?ssid=0'),
    gprop('shutdowndesc')
  );
  table_row_cols($columns);
   */

  $columns = array(
    array(gprop('evtlogseverityfilter'), 'prevtlogd.php?ssid=0'),
    gprop('evtlogseverityfilterdesc')
  );
  table_row_cols($columns);
}
table_foot();
table_foot();
table_tab_foot();

page_foot();
?>
  </table>
</form>
</body>

</html>
