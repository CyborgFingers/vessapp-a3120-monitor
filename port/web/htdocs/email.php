<?php

/*******************************************************************************
 * Filename    : email.php
 * Description : islaVista modify email settings.
 * Created on  : 11/25/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$note = null;
$result = null;
$test = null;
$pwLength = '';

$server = getServer();
$subsystem = $server->getSubsystem();

$daemons = $subsystem->getAllDaemons();
$result = $daemons->errorcode;
if ($result == PIERR_INACTIVE_SESSION) {
  errorOnInvalidSession();
}
if ($result == PIERR_NO_ERROR) {
  $daemons = $daemons->daemonlist;
  for ($i = 0; $i < count($daemons ?? array()); $i++) {
    $daemon = $daemons[$i];
    if ($daemon->daemonname == DAEMON_EMAIL)
      break;
  }

  $emailsetting = $subsystem->getEmailSetting();
}
$result = $emailsetting->errorcode;
if ($result == PIERR_INACTIVE_SESSION) {
  errorOnInvalidSession();
}
// default ctrl 0
// $controller = $subsystem->getController(0);
// $result = getLastError();
// if ( $result == PIERR_INACTIVE_SESSION ){
//   errorOnInvalidSession();
// }
$curuser = getCurrentUser($subsystem);
?>

<html>

<head>
  <?php
  page_head();
  ?>
</head>

<body>

  <?php
  if (isset($_POST['start']) || isset($_POST['stop']) || isset($_POST['restart'])) {
    if (isset($_POST['start'])) {
      $daemon->daemonstatus = DAEMON_STARTED;
      $note = gresult('emailstart');
    } else if (isset($_POST['stop'])) {
      $daemon->daemonstatus = DAEMON_STOPPED;
      $note = gresult('emailstop');
    } else if (isset($_POST['restart'])) {
      $daemon->daemonstatus = DAEMON_RESTART;
      $note = gresult('emailrestart');
    }
    $setresult = $subsystem->startStopDaemon($daemon);
    if ($setresult != PIERR_NO_ERROR)
      $note = gresult('emailnotstartorstop');
    else {
      if ($daemon->daemonstatus == DAEMON_RESTART || $daemon->daemonstatus == DAEMON_STARTED)
        $daemon->daemonstatus = 'Started';
      else
        $daemon->daemonstatus = 'Stopped';
    }
  }
  if (isset($_POST['submit'])) {
    $testresult = PIERR_NO_ERROR;
    if (isset($_POST['submit'])) {
      if ($_POST['startuptype'] == "Auto")
        $daemon->startuptype = DAEMON_STARTTYPE_AUTO;
      else if ($_POST['startuptype'] == "Manual")
        $daemon->startuptype = DAEMON_STARTTYPE_MANUAL;
      $testresult = $subsystem->setDaemonStartupType($daemon);
    }
    if ($testresult == PIERR_NO_ERROR) {
      if ($daemon->startuptype == DAEMON_STARTTYPE_AUTO)
        $daemon->startuptype = 'Automatic';
      else
        $daemon->startuptype = 'Manual';
      $emailsetting->smtphost = htmlspecialchars($_POST['smtphost']);
      $emailsetting->smtpport = htmlspecialchars($_POST['smtpport']);
      $emailsetting->encryption = $_POST['encryption'];
      if ($_POST['authmethod'] == "AUTO") {
        $emailsetting->authmethod = EMAIL_AUTH_AUTO;
        $emailsetting->username = htmlspecialchars($_POST['username']);
        if ($emailsetting->password != '') {
          if (isset($_POST['changepassword'])) {
            $emailsetting->password = htmlspecialchars($_POST['password']);
          }
        } else {
          $emailsetting->password = htmlspecialchars($_POST['password']);
        }
      } else if ($_POST['authmethod'] == "NONE") {
        $emailsetting->authmethod = EMAIL_AUTH_NONE;
        $emailsetting->username = "";
        $emailsetting->password = "";
      }
      $emailsetting->from = htmlspecialchars($_POST['from']);
      $emailsetting->subject = htmlspecialchars($_POST['subject']);
      $testresult = $subsystem->setEmailSetting($emailsetting);
      // Optional: route TrueNAS's own system alerts through this same SMTP
      // server. www-data only QUEUES (reads what was just POSTed); the root
      // watcher (promise-webpam-mail-sync) applies it via midclt mail.update.
      if (isset($_POST['truenassync'])) {
        $__port = (int)$_POST['smtpport'];
        $__auto = ($_POST['authmethod'] === 'AUTO');
        $__m = array(
          'outgoingserver' => $_POST['smtphost'],
          'port'           => $__port,
          'security'       => ($_POST['encryption'] == 0 ? 'PLAIN' : ($__port === 465 ? 'SSL' : 'TLS')),
          'smtp'           => $__auto,
          'user'           => $__auto ? $_POST['username'] : '',
          'pass'           => $__auto ? ($_POST['password'] ?? '') : '',
          'fromemail'      => $_POST['from'],
        );
        @file_put_contents('/run/promise/mailsync.request', json_encode($__m));
      }
    }
    if ($testresult == PIERR_NO_ERROR)
      $note = gresult('emailapplyok');
    else
      $note = gresult('emailapplyfail');

    if (isset($_POST['testckb']) && $testresult == PIERR_NO_ERROR) {
      if (($_POST['emailaddr']) != 'notset') {
        $users = $subsystem->getUsers();
        $users = $users->userlist;
        for ($i = 0; $i < count($users); $i++) {
          $user = $users[$i];
          if ($user->username == $_SESSION['username']) {
            break;
          }
        }
        $user->email = $_POST['emailaddr'];
        $subsystem->setUser($user);
      }

      if (!isset($testemail))
        $testemail = new stdclass();
      $testemail->testuser = htmlspecialchars($_SESSION['username']);
      $testresult = $subsystem->testEmail($testemail);
      if ($testresult == PIERR_NO_ERROR)
        $note = $note . ' ' . gresult('sendtestemail');
      // else
      //   $note = $note.' The test email failed to be sent.';
    }
  }

  for ($i = 0; $i < strlen($emailsetting->password); $i++) {
    $pwLength .= '*';
  }
  ?>

  <?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
  <form method=post action="<?php echo $action_url; ?>">
<?php form_csrf(); ?>
    <table width=100% border=0 cellspacing=0 cellpadding=0 align=center>
      <input type=hidden value=notset name=emailaddr>
      <?php
      if (isset($testresult) && $testresult != PIERR_NO_ERROR)
        table_title(gprop('emailsettings'), 'email', $note, $testresult);
      else
        table_title(gprop('emailsettings'), 'email', $note, $result);

      table_tab_head();
      $tabTitle = tabTitleService();
      table_tab($tabTitle);
      if ($result == PIERR_NO_ERROR) {
        $privilege = getUserPrivilege($subsystem);
        table_swmgt($daemon, $privilege, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

        table_head_fold(gprop('emailserversettings'), 'emailsetting', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

        table_row_edit(gprop('smtpserverip'), $emailsetting->smtphost, 'smtphost', 63, 30, null, 'msg_smtphost');
        table_row_edit(gprop('smtpserverport'), $emailsetting->smtpport, 'smtpport', 5, 30, null, 'msg_smtpport');
        table_row_checkbox('Also route TrueNAS system alerts through this SMTP server', false, 'truenassync');
      ?>
        <!-- SMTP Encryption -->
        <tr>
          <td width=15% class=tableRowCol1><span><?= gprop('smtpencryption') ?></span></td>
          <td width=85% class=tableRowColOther>
            <span>
              <input type=radio name=encryption value=0<?PHP if ($emailsetting->encryption == 0) echo ' checked=checked'; ?> /> None &nbsp;&nbsp;
              <input type=radio name=encryption value=1<?PHP if ($emailsetting->encryption != 0) echo ' checked=checked'; ?> /> SSL/TLS
            </span>
          </td>
        </tr>

        <!-- SMTP Authentication -->
        <tr>
          <td width=15% class=tableRowCol1><span><?= gprop('smtpauthentication') ?></span></td>
          <td width=85% class=tableRowColOther><span>
              <input type=radio <?php
                                if ($emailsetting->authmethod == EMAIL_AUTH_NONE)
                                  echo ' checked ';
                                ?> name=authmethod value=NONE onClick="changeAuth()">
              No&nbsp;&nbsp;
              <input type=radio <?php
                                if ($emailsetting->authmethod == EMAIL_AUTH_AUTO)
                                  echo ' checked ';
                                ?> name=authmethod value=AUTO onClick="changeAuth()">
              Yes
            </span></td>
        </tr>
        <?php
        echo "<TBODY ID=smtphidden  style=display:none;>";
        table_row_edit(gprop('smtpauthenticationusername'), $emailsetting->username, 'username', 31, null, null, "msg_smtpusername");
        if ($emailsetting->password != '') {
        ?>
          <tr>
            <td width=15% class=tableRowCol1><span class=text4><?= gprop('cimchangepassword') ?></span></td>
            <td width=85% class=tableRowColOther>
              <input type="checkbox" name="changepassword" value="1" onClick="changePassword()">
              Yes
            </td>
          </tr>
      <?php }
        table_row_pwd_edit(gprop('smtpauthenticationpassword'), $pwLength, 'password', 31);
        echo "</TBODY>";
        table_foot_fold();
        // table_tab_foot();

        table_head_fold(gprop('emailcontentcustomization'), 'emailcontent', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
        table_row_edit(gprop('emailsenderaddress'), $emailsetting->from, 'from', 47, 30, null, 'msg_emailaddress');
        table_row_edit(gprop('emailsubject'), $emailsetting->subject, 'subject', 43, 30, null, 'msg_emailsubject');
        table_foot_fold();

        if (getUserPrivilege($subsystem) >= USER_GROUP_SUPER) {
          table_head_fold(gprop('emailtest'), 'emailtest', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
          table_row_checkbox(gprop('emailsendtest'), 0, 'testckb');
          table_foot_fold();
        }

    table_tab_foot();
    if (getUserPrivilege($subsystem) >= USER_GROUP_SUPER)
        table_row_submit(array('submitClick'=>'validateSubmit()','resetClick'=>'resetClick()','cancel'=>0));
    } else {
        table_head_fold(gprop('emailsettings'), 'emailsettings', null, null, array('pad'=>2,'space'=>1,'border'=>0,'class'=>'tableContentInner'));
        table_row_empty(gerror('emailsettingdisplay'));
        table_foot_fold();
        table_tab_foot();
      }
      page_foot();
      ?>
    </table>
  </form>
  <script>
    function changeAuth() {
      var item = document.getElementById("smtphidden");
      if (document.forms[0].authmethod[0].checked) {
        item.style.display = "none";
        document.forms[0].username.value = "";
        document.forms[0].password.value = "";
      } else {
        document.forms[0].password.value = "";
        item.style.display = "";
      }
    }
    changeAuth();

    function validatePort(input) {
      if (!/^[1-9][0-9]*$/.test(input)) {
        return false;
      }
      if (input > 65535) {
        return false;
      }
      return true;
    }

    function validateSubmit() {
      // validate server port
      var smtpport = document.forms[0].smtpport;
      msg_smtpport.innerHTML = "";
      if (!validatePort(smtpport.value)) {
        msg_smtpport.innerHTML = '<?= gjsalert('invalidport') ?>';
        smtpport.focus();
        return false;
      }
      // validate email alias
      var username = document.forms[0].username;
      msg_smtpusername.innerHTML = "";
      if (document.forms[0].authmethod[1].checked) {
        if (username.value.length == 0 || !validateEmailAlias(username.value)) {
          msg_smtpusername.innerHTML = "<?= gjsalert('invalideamilusername') ?>";
          username.focus();
          return false;
        }
        if (document.forms[0].password.value.length == 0) {
          alert('<?= gjsalert('invalidemailpassword') ?>')
          return false;
        }
      }

      // validate email subject
      msg_emailsubject.innerHTML = "";
      subject = document.forms[0].subject;
      if (!validateAlias(document.forms[0].subject.value)) {
        msg_emailsubject.innerHTML = '<?= gjsalert('invalidsubject') ?>';
        subject.focus();
        return false;
      }

      // validate email address
      address = document.forms[0].from;
      msg_emailaddress.innerHTML = "";
      if (!validateEmailAddress(document.forms[0].from.value)) {
        msg_emailaddress.innerHTML = "<?= gjsalert('invalidemail') ?>";
        address.focus();
        return false;
      }
      if (document.forms[0].testckb.checked) {
        <?php
        echo '
            var string = "' . htmlspecialchars($curuser->email, ENT_QUOTES) . '";' . PHP_EOL;
        ?>
        if (string == '') {
          string = prompt("<?= gjsalert('emailinput') ?>", "");
          document.forms[0].emailaddr.value = string;
          if (string)
            return true;
          else
            return false;
        } else {
          var ret = confirm("<?= gjsalert('emailsendtoaccount') ?>" + string);
          if (!ret)
            return false;
          return true;
        }
      }
    }

    function validateStop() {
      var ret = confirm('<?= gjsalert('stopservice') ?>');
      if (!ret)
        return false;
      return true;
    }

    function validateRestart() {
      return true;
    }

    changePassword();

    function changePassword() {
      if (document.forms[0].changepassword) {
        if (document.forms[0].changepassword.checked) {
          document.forms[0].password.value = "";
          document.forms[0].password.disabled = false;
        } else {
          document.forms[0].password.value = "<? echo $pwLength ?>";
          document.forms[0].password.disabled = true;
        }
      } else {
        document.forms[0].password.value = "";
      }
    }

    function resetClick() {
      if (document.forms[0].changepassword) {
        document.forms[0].password.disabled = true;
      }
    }
  </script>
</body>

</html>