<?php

/*******************************************************************************
 * Filename    : servicet.php
 * Description : islaVista start/stop/configure software componenet.
 * Created on  : 010/30/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_GET['daemon']))
  $_SESSION['daemon'] = $_GET['daemon'];
if (isset($_GET['startuptype']))
  $_SESSION['startuptype'] = $_GET['startuptype'];
if (isset($_GET['daemonstatus']))
  $_SESSION['daemonstatus'] = $_GET['daemonstatus'];

$server = getServer();
$subsystem = $server->getSubsystem();
$note = null;
$result = null;

function table_row_start_stop_submit($b = array(
  'cancel' => 1, 'submit' => 1,
  'start' => 1, 'stop' => 1, 'restart' => 1
))
{
  echo '
    <table border=0 cellspacing=2 cellpadding=0 align=center>
      <tr>
        <td>';
  if (!isset($b['cancel']) || $b['cancel'])
    echo '
        <input class=input-p type=submit value=" ' . gprop('cancel') . ' " name=cancel>';
  if (!isset($b['submit']) || $b['submit'])
    echo '
        <input class=input-p type=submit value=" ' . gprop('submit') . ' " name=submit>';
  if (!isset($b['start']) || $b['start'])
    echo '
        <input class=input-p type=submit value=" ' . gprop('start') . ' " name=start>';
  if (!isset($b['stop']) || $b['stop'])
    echo '
        <input class=input-p type=submit value=" ' . gprop('stop') . ' " name=stop>';
  if (!isset($b['restart']) || $b['restart'])
    echo '
        <input class=input-p type=submit value=" ' . gprop('restart') . ' " name=restart>';
  echo '
      </td>
    </tr>';
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
if (isset($_POST['submit']) || isset($_POST['start']) || isset($_POST['restart']) ||
  isset($_POST['stop']) || isset($_POST['cancel'])) {
  $action = '';
  $daemon->daemonname = $_SESSION['daemon'];
  if (isset($_POST['submit'])) {
    if ($_POST['startuptype'] == "Auto")
      $daemon->startuptype = DAEMON_STARTTYPE_AUTO;
    if ($_POST['startuptype'] == "Manual")
      $daemon->startuptype = DAEMON_STARTTYPE_MANUAL;
    $result = $subsystem->setDaemonStartupType($daemon);
    if ($result == PIERR_INACTIVE_SESSION) {
      errorOnInvalidSession();
    }
    $action = 'submit';
  } else if (isset($_POST['start']) || isset($_POST['stop'])) {
    if (isset($_POST['start'])) {
      $daemon->status = DAEMON_STARTED;
      $action = 'start';
    } else if (isset($_POST['stop'])) {
      $daemon->status = DAEMON_STOPPED;
      $action = 'stop';
    }
    $result = $subsystem->startStopDaemon($daemon);
    if ($result == PIERR_INACTIVE_SESSION) {
      errorOnInvalidSession();
    }
  } else if (isset($_POST['restart'])) {
    $daemon->status = DAEMON_RESTART;
    $result = $subsystem->startStopDaemon($daemon);
    if ($result == PIERR_INACTIVE_SESSION) {
      errorOnInvalidSession();
    }
    $action = 'restart';
  }
  echo '
        <meta http-equiv=refresh target=_self
            content="0; URL=services.php?tab=0&act=' . $action . '&errorcode=' . $result . '">';
  exit;
}
?>
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
<?php form_csrf(); ?>
  <table width=100% border=1 cellspacing=0 cellpadding=2 align=center
    class=tableMain>
<?php
table_title('Service Management', 'services', $note);

table_tab_head();
$tabTitle = tabTitleAdminTool($subsystem);
table_tab($tabTitle);

table_head_fold(gprop('servicesetting') . ' -- ' . $_SESSION['daemon'], 'servicet');
?>
    <tr>
          <td width=15% class=tableRowCol1><?= gprop('startuptype') ?></td>
          <td width=85% class=tableRowColOther>
          <input type=radio
<?php
if ($_SESSION['startuptype'] == "Automatic")
  echo ' checked ';
?>
             name=startuptype value=Auto>
              Automatic&nbsp;&nbsp;
            <input type=radio
<?php
if ($_SESSION['startuptype'] == "Manual")
  echo ' checked ';
?>
             name=startuptype value=Manual>
              Manual
          </td>
        </tr>
<?php
table_foot_fold();
table_tab_foot();
if ($_SESSION['daemonstatus'] == 'Started') {
  table_row_start_stop_submit(array(
    'cancel' => 1, 'submit' => 1,
    'start' => 0, 'stop' => 1, 'restart' => 1
  ));
} else if ($_SESSION['daemonstatus'] == 'Stopped') {
  table_row_start_stop_submit(array(
    'cancel' => 1, 'submit' => 1,
    'start' => 1, 'stop' => 0, 'restart' => 0
  ));
} else {
  table_row_start_stop_submit(array(
    'cancel' => 1, 'submit' => 1,
    'start' => 0, 'stop' => 0, 'restart' => 0
  ));
}
page_foot();
?>

  </table>
</form>

</body>
</html>
