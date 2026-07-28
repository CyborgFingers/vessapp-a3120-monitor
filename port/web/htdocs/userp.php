<?php

/*******************************************************************************
 * Filename    : userp.php
 * Description : islaVista change user password.
 * Created on  : 08/25/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_GET['ssid']))
  $_SESSION['ssid'] = $_GET['ssid'];

$server = getServer();
$subsystem = $server->getSubsystem();
$users = $subsystem->getUsers();
$result = $users->errorcode;
if ($result == PIERR_INACTIVE_SESSION) {
  errorOnInvalidSession();
}

if ($result == PIERR_NO_ERROR) {
  $users = $users->userlist;
  if (!isset($_SESSION['userid'])) {
    for ($i = 0; $i < count($users); $i++) {
      $user = $users[$i];
      if ($user->username == $_SESSION['username']) {
        $_SESSION['userid'] = $i;
        break;
      }
    }
  }
  $user = $users[$_SESSION['userid']];
  if (!isset($password))
    $password = new stdclass();
  $password->username = $user->username;
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
    $act = 'pwd';

    if (isset($_POST['submit']))
    {
        if (isset($_POST['oldpassword']))
            $password->oldpassword = $_POST['oldpassword'];
        $password->newpassword = $_POST['newpassword'];
        $status = $subsystem->setPwd($password);
        if($status == PIERR_NO_ERROR && $password->username == unserialize($_SESSION['sessionService'])->username)
        {
?>
            <script>
                alert('<?=gresult('passwordchangeok')?>');
                top.location.href="index.php?logout";
            </script>
<?php
        }
        else
        {
            echo '
            <meta http-equiv=refresh target=_self
                        content="0; URL=users.php?tab=0&act=pwd&errorcode='.$status.'">';
        }
        exit;
    }
?>

<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=2 align=center
    bordercolor=#666666 bordercolordark=#FFFFFF>

<?php
table_title(gprop('changepassword'), 'user', null, $result);

table_tab_head();

if (count($users) == 1 && $users[0]->userGroupId != USER_GROUP_SUPER)
  $tabTitle = tabTitleUser();
else
  $tabTitle = tabTitleUsers();
table_tab($tabTitle);

table_head_fold(gprop('passwordsettings'), 'pwd', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
if ($result == PIERR_NO_ERROR) {
  table_row(gprop('username'), $password->username, 'username');
  if ($_SESSION['username'] == $user->username)
    table_row_pwd_edit(gprop('oldpassword'), '', 'oldpassword');
  table_row_pwd_edit(gprop('newpassword'), '', 'newpassword', 31);

  table_row_pwd_edit(gprop('retypepassword'), '', 'tmppassword', 31);

  table_foot_fold();
  table_tab_foot();

  table_row_submit(array('cancel' => 0, 'submitClick' => 'validateSubmit()'));
} //NO_ERROR
else {
  table_row_empty(gerror('displaypasswordsetting'));
  table_foot_fold();
  table_tab_foot();
}
page_foot();
?>
  </table>
</form>

<script>
  form = document.forms[0];

  function validateSubmit() {
		if (form.newpassword.value.length < 8) {
            alert('<?=gjsalert('invalidpassword')?>');
            return false;
        }
        if (form.newpassword.value == "password") {
            alert('<?=gjsalert('strongerpassword')?>');
            return false;
        }
        if (form.newpassword.value == form.tmppassword.value) {
            return true;
        }
        alert('<?=gjsalert('retypenewpassword')?>');
        return false;
    }
</script>

</body>
</html>
