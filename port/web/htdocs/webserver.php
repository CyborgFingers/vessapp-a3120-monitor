<?php

/*******************************************************************************
 * Filename    : webserver.php
 * Description : islaVista modify webserver settings.
 * Created on  : 03/17/04
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$note = null;
$result = null;

$server = getServer();
$subsystem = $server->getSubsystem();
$controllers = $subsystem->getControllers();
$supportDualCtrl = $subsystem->supportdualcontroller();
$daemons = $subsystem->getAllDaemons();
$result = $daemons->errorcode;
if (!isset($newsetting))
  $newsetting = new stdclass();
if (isset($_GET['errorcode']) && $_GET['errorcode'] == PIERR_NO_ERROR) {
  $note = gresult('webserverrestart');
}

if ($result == PIERR_INACTIVE_SESSION) {
  errorOnInvalidSession();
}
if ($result == PIERR_NO_ERROR) {
  $daemons = $daemons->daemonlist;
  for ($i = 0; $i < count($daemons ?? array()); $i++) {
    $daemon = $daemons[$i];
    if ($daemon->daemonname == DAEMON_WEBSERVER)
      break;
  }
  $webserver = $subsystem->getWebserverSetting();
}
$result = $webserver->errorcode;
if ($result == PIERR_INACTIVE_SESSION) {
  errorOnInvalidSession();
}
?>
<html>
<head>
<?php
page_head();
?>
</head>
<body>
<?php
if (isset($_POST['submit'])) {
  $newsetting->ssldownload = false;
  $setresult = PIERR_NO_ERROR;
  if (isset($_POST['startuptype'])) {
    if ($_POST['startuptype'] == "Auto")
      $daemon->startuptype = DAEMON_STARTTYPE_AUTO;
    else if ($_POST['startuptype'] == "Manual")
      $daemon->startuptype = DAEMON_STARTTYPE_MANUAL;
    $setresult = $subsystem->setDaemonStartupType($daemon);
  }
  if ($setresult == PIERR_NO_ERROR) {
    if ($daemon->startuptype == DAEMON_STARTTYPE_AUTO)
      $daemon->startuptype = 'Automatic';
    else
      $daemon->startuptype = 'Manual';
    $newsetting->httpport = $_POST['httpport'];
    $newsetting->httpsport = $_POST['httpsport'];
    $newsetting->sessiontimeout = $_POST['sessiontimeout'] * 60;
    $_SESSION['timeout'] = $newsetting->sessiontimeout;

    if (isset($_POST['sslenabled']))
      $newsetting->sslenabled = 1;
    else
      $newsetting->sslenabled = 1;
  }
  if ($setresult == PIERR_NO_ERROR) {
    $setresult = $subsystem->setWebserverSetting($newsetting);
  }
  if ($setresult == PIERR_NO_ERROR) {
    if ($_POST['warnrestart'] == 'restart') {
      $restart = false;

    if ( $newsetting->httpport != $webserver->httpport ||
	     $newsetting->httpsport != $webserver->httpsport ||
         $newsetting->sessiontimeout != $webserver->sessiontimeout)
      {
        $daemon->daemonstatus = DAEMON_RESTART;
        $subsystem->startStopDaemon($daemon, false);
        $restart = true;
      }
      if ($restart == true) {
        header('Location: restart.php');
        exit;
      }
    } else if ($_POST['warnrestart'] == 'norestart') {
      $note = galert('webserversetting');
    } else {
      $note = gresult('webserversettingapplyok');
    }
    //avoid to call get api again
    $webserver = $newsetting;
  } else
    $note = gresult('webserversettingapplyfail');
}
?>

<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form enctype="multipart/form-data" method=post action="<?php echo $action_url; ?>">
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center
    bordercolor=#666666 bordercolordark=#FFFFFF>
    <input type=hidden value=none name=warnrestart>
<?php
if (isset($setresult) && $setresult != PIERR_NO_ERROR)
  table_title(gprop('webserversettings'), 'webserver', $note, $setresult);
else
  table_title(gprop('webserversettings'), 'webserver', $note, $result);
$s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner');
table_tab_head();
$tabTitle = tabTitleService();
table_tab($tabTitle);
if ($result == PIERR_NO_ERROR) {
  $privilege = getUserPrivilege($subsystem);

  table_head_fold(gprop('overallwebserversettings'), 'webserversetting', null, null, $s);
  table_row_edit(gprop('httpport'), $webserver->httpport, 'httpport', 5, null, null, 'msg_httpport');
  table_row_edit(gprop('sessiontimeout'), $webserver->sessiontimeout / 60, 'sessiontimeout', 5, 30, gprop('minutes'), 'msg_sessiontimeout');

  table_foot_fold();
  table_head_fold(gprop('sslsettings'), 'SSL', null, null, $s);

    table_row_edit(gprop('httpsport'), $webserver->httpsport, 'httpsport',5,null,null,'msg_httpsport');
    table_foot_fold();
    if (getUserPrivilege($subsystem) >= USER_GROUP_SUPER)
        table_row_submit(array('submitClick'=>'validateSubmit()','cancel'=>0));
    } else {
        table_head_fold(gprop('overallwebserversettings'), 'content');
        table_row_empty(gerror('webserverdisplay'));
        table_foot_fold();
        table_tab_foot();
    }
    page_foot();
?>
  </table>
</form>
<script>
<?php
echo '
    var daemonstatus = "' . $daemon->daemonstatus . '";';
?>

  function validateSubmit()
  {
    var form = document.forms[0];
    var ret=true;

    obj=form.elements['httpsport'];
    msg=msg_httpsport;
    if (!validateNumber(obj.value)||obj.value<1||obj.value>65535)
    {
      msg.innerHTML='<?= gjsalert('invalidhttpsport') ?>';
      obj.focus();
      ret=false;
    }
    else
      msg.innerHTML='';

    obj=form.elements['sessiontimeout'];
    msg=msg_sessiontimeout;
    if (!validateNumber(obj.value)||obj.value<1||obj.value>24*60)
    {
      msg.innerHTML="<?= gjsalert('invalidsessiontimeout') ?>";
      obj.focus();
      ret=false;
    }
    else
      msg.innerHTML='';

    obj=form.elements['httpport'];
    msg=msg_httpport;
    if (!validateNumber(obj.value)||obj.value<1||obj.value>65535)
    {
      msg.innerHTML='<?= gjsalert('invalidhttpport') ?>';
      obj.focus();
      ret=false;
    }
    else
      msg.innerHTML='';

    if(!ret)
      return ret;

    /*
    if ( form.elements['sslenabled'].checkded == false)
    {
      var ret = confirm('<?= gjsalert('webservercontentifssloff') ?>');
      if (!ret)
        return false;
    }
    */
      
    if ( daemonstatus == 'Started')
    {
      var ret = confirm('<?= gjsalert('webserverrestartwebserver') ?>');

      if ( ret)
      {
        form.warnrestart.value = 'restart';
      }
      else
        form.warnrestart.value = 'norestart';
    }
    return true;
  }
  function validateStop()
  {
    var ret = confirm("<?= gjsalert('webserverrestopwebserver') ?>");

    if (!ret)
      return false;
    return true;
  }
  function validateRestart()
  {
    return true;
  }
</script>
</body>
</html>
