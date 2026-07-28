<?php

/*******************************************************************************
 * Filename    : restore.php
 * Description : islaVista restore factory defaults.
 * Created on  : 08/29/04
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_GET['ssid']))
  SetSessionValue('ssid', $_GET['ssid']);
SyncSession();

$server = getServer();
$subsystem = $server->getSubsystem();
$pv = getUserPrivilege($subsystem);
$currentCtrlId = $subsystem->getCurrentCtrlId();
$currentCtrl = $subsystem->getController($currentCtrlId->currentCtrlId);

$msgSkipped = '<font color=brown>' . gprop('skipped') . '</font>';
$msgSuccess = '<font color=green>' . gprop('successful') . '</font>';
$msgSuccess2 = '<font color=green>' . gprop('successful') . '. ' . gresult('nobootnoeffect') . '</font>';
$msgFailure = '<font color=red>' . gprop('failed') . ' : </font>';

if (isset($_POST['submit'])) {
  $restored = true;

  if (isset($_POST['fwBga'])) {
    $ret = $subsystem->restoreFactoryDefaultsBga();
    $fwBga = $ret ? $msgFailure . gerror($ret) : $msgSuccess;
  } else
    $fwBga = $msgSkipped;

  if (isset($_POST['fwCtrl'])) {
    $ret = $subsystem->restoreFactoryDefaultsCtrl();
    $fwCtrl = $ret ? $msgFailure . gerror($ret) : $msgSuccess;
  } else
    $fwCtrl = $msgSkipped;

  if (isset($_POST['fwEncl'])) {
    $ret = $subsystem->restoreFactoryDefaultsEncl();
    $fwEncl = $ret ? $msgFailure . gerror($ret) : $msgSuccess;
  } else
    $fwEncl = $msgSkipped;

  if (isset($_POST['fwNetMgmt'])) {
    $ret = $subsystem->restoreFactoryDefaultsNetMgmt();
    $fwNetMgmt = $ret ? $msgFailure . gerror($ret) : $msgSuccess;
  } else
    $fwNetMgmt = $msgSkipped;

  if (isset($_POST['fwPhydrv'])) {
    $ret = $subsystem->restoreFactoryDefaultsPhydrv();
    $fwPhydrv = $ret ? $msgFailure . gerror($ret) : $msgSuccess;
  } else
    $fwPhydrv = $msgSkipped;

  if (isset($_POST['fwSubsys'])) {
    $ret = $subsystem->restoreFactoryDefaultsSubsys();
    $fwSubsys = $ret ? $msgFailure . gerror($ret) : $msgSuccess;
  } else
    $fwSubsys = $msgSkipped;

  if (isset($_POST['swBgaScheduler'])) {
    $ret = $subsystem->restoreFactoryDefaultsBgaScheduler();
    $swBgaScheduler = $ret ? $msgFailure . gerror($ret) : $msgSuccess;
  } else
    $swBgaScheduler = $msgSkipped;

  if (isset($_POST['swService'])) {
    $ret = $subsystem->restoreFactoryDefaultsService();
    $swService = $ret ? $msgFailure . gerror($ret) : $msgSuccess;
  } else
    $swService = $msgSkipped;

  if (isset($_POST['swWebserver'])) {
    $ret = $subsystem->restoreFactoryDefaultsWebserver();
    $swWebserver = $ret ? $msgFailure . gerror($ret) : $msgSuccess2;
  } else
    $swWebserver = $msgSkipped;

  if (isset($_POST['swSnmp'])) {
    $ret = $subsystem->restoreFactoryDefaultsSnmp();
    $swSnmp = $ret ? $msgFailure . gerror($ret) : $msgSuccess;
  } else
    $swSnmp = $msgSkipped;
    
  if (isset($_POST['swEmail'])) {
    $ret = $subsystem->restoreFactoryDefaultsEmail();
    $swEmail = $ret ? $msgFailure . gerror($ret) : $msgSuccess;
  } else
    $swEmail = $msgSkipped;

  if (isset($_POST['swTelnet'])) {
    $ret = $subsystem->restoreFactoryDefaultsTelnet();
    $swTelnet = $ret ? $msgFailure . gerror($ret) : $msgSuccess;
  } else
    $swTelnet = $msgSkipped;

  if (isset($_POST['swUser'])) {
    $ret = $subsystem->restoreFactoryDefaultsUser();
    $swUser = $ret ? $msgFailure . gerror($ret) : $msgSuccess;

    if ($ret == 0 && $subsystem->username != 'administrator')
      unset($_SESSION['sessionService']);
  } else
    $swUser = $msgSkipped;
}

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
    <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
        <?php
        table_title(gprop('restorefactorydefaults'), 'restore');

        table_tab_head();
        $tabTitle = $tabTitle = array(array(gprop('factorydefaults'), 'restore.php'));
        table_tab($tabTitle);
        $s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner');
        if (!isset($restored)) {
          table_head_fold(gprop('firmwarefactorydefaultsettings'), 'FW', null, null, $s);
          table_row_checkbox(gprop('backgroundactivitysettings'), 0, 'fwBga');
          table_row_checkbox(gprop('controllersettings'), 0, 'fwCtrl');
          table_row_checkbox(gprop('enclosuresettings'), 0, 'fwEncl');
            
          table_row_checkbox(gprop('physicaldrivesettings'), 0, 'fwPhydrv');
          table_row_checkbox(gprop('subsystemsettings'), 0, 'fwSubsys');
          table_foot_fold();

          table_head_fold(gprop('softwarefactorydefaultsettings'), 'SW', null, null, $s);
          // table_row_checkbox(gprop('bgaschedulersettings'),0,'swBgaScheduler');
          table_row_checkbox(gprop('servicesettings'), 0, 'swService');
          table_row_checkbox(gprop('webserversettings'), 0, 'swWebserver');
          table_row_checkbox(gprop('snmpsettings'), 0, 'swSnmp');
          table_row_checkbox(gprop('emailsettings'), 0, 'swEmail');
          table_row_checkbox(gprop('usersettings'), 0, 'swUser');
          table_foot_fold();
          table_tab_foot();
          table_row_submit2(array('submit' => ($pv < USER_GROUP_SUPER ? 2 : 1), 'submitClick' => 'validateSubmit()'));

        } else {
          table_head_fold(gprop('firmwarefactorydefaultsettings'), 'FW', null, null, $s);
          table_row(gprop('backgroundactivitysettings'), $fwBga);
          table_row(gprop('controllersettings'), $fwCtrl);
          table_row(gprop('enclosuresettings'), $fwEncl);
          table_row(gprop('physicaldrivesettings'), $fwPhydrv);
          table_row(gprop('subsystemsettings'), $fwSubsys);
          table_foot_fold();

          table_head_fold(gprop('softwarefactorydefaultsettings'), 'SW', null, null, $s);
          table_row(gprop('servicesettings'), $swService);
          table_row(gprop('webserversettings'), $swWebserver);
          table_row(gprop('snmpsettings'), $swSnmp);
          table_row(gprop('emailsettings'), $swEmail);
          table_row(gprop('usersettings'), $swUser);
          table_foot_fold();
          table_tab_foot();
        }

        page_foot();
        ?>
    </table>
</form>
<script>
  function getreturn(ret)
  {
    var alertmsg="<? echo gjsalert('errconfirm') ?>";
    if(ret==1)
    {
      var submitbutton = document.createElement('input');
      submitbutton.type = "submit";
      submitbutton.name = "submit";
      submitbutton.style.display = "none";
      document.forms[0].appendChild(submitbutton);
      submitbutton.click();
    }
    else if(ret=='cancelled')
      return false;
    else{
      alert(alertmsg);
      return false;
    }

  }

  function validateSubmit()
  {
    if (document.getElementById('windowconfirm') != null)
      return false;
    showConfirm('<?= gjsconfirm('restorefactorysetting') ?>', getreturn);
    return false;
  }
</script>
</body>
</html>
<?
if (empty($_SESSION['sessionService']))
  echo '<script>top.location.href="index.php"</script>';
?>
