<?php

/*******************************************************************************
 * Filename    : services.php
 * Description : islaVista software component(service) list.
 * Created on  : 10/30/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$note = null;
$server = getServer();
$subsystem = $server->getSubsystem();
$currentCtrlId = $subsystem->getCurrentCtrlId();
$currentCtrl = $subsystem->getController($currentCtrlId->currentCtrlId);
$daemons = $subsystem->getAllDaemons();
$result = $daemons->errorcode;
if ($result == PIERR_INACTIVE_SESSION) {
  errorOnInvalidSession();
}
if ($result == PIERR_NO_ERROR) {
  $daemons = $daemons->daemonlist;
}
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
    table_title(gprop('softwaremanagement'), 'services', $note, $result);

table_tab_head();
$tabTitle = tabTitleService();
table_tab($tabTitle);

    if ( $result == PIERR_NO_ERROR) {
        $columns = array(gprop('servicename'), gprop('status'), gprop('startuptype'));
        $widths = array(34, 33, 33);
        table_head_fold(gprop('servicelist'), 'servicelist',null, null, array('pad'=>2,'space'=>1,'border'=>0,'class'=>'tableContentInner'));
        table_header_cols($columns, $widths);

        for ($i = 0; $i < count($daemons ?? array()); $i++) {
            $daemon = $daemons[$i];
            if(_isCClassCtrl($currentCtrl->ci_ProductID) && $daemon->daemonname == 'CIM')
                continue;
            if ( $daemon->daemonname == 'prevtlogd')
                $arr = array('Event Forwarding', strtolower($daemon->daemonname).'.php?tab=0');
            else
                $arr = array($daemon->daemonname, strtolower($daemon->daemonname).'.php?tab=0');
            $columns = array($arr, gprop(strtolower($daemon->daemonstatus)), gprop(strtolower($daemon->startuptype)));
            table_row_cols($columns);
        }
    } else {
        table_head_fold(gprop('servicelist'), 'content', null, null, array('pad'=>2,'space'=>1,'border'=>0,'class'=>'tableContentInner'));
        table_row_empty(gerror('servicelistdisplay'));
    }
    table_foot_fold();
    table_tab_foot();

page_foot();
?>

  </table>
</form>

</body>
</html>
