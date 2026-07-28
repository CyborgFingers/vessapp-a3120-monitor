<?php

/*******************************************************************************
 * Filename    : usert.php
 * Description : islaVista modify user settings.
 * Created on  : 08/25/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$server = getServer();
$subsystem = $server->getSubsystem();
$users = $subsystem->getUsers();
$result = $users->errorcode;
if ($result == PIERR_INACTIVE_SESSION) {
  errorOnInvalidSession();
}

if ($result == PIERR_NO_ERROR) {
  $users = $users->userlist;
  if (isset($_GET['userid']))
    $_SESSION['userid'] = $_GET['userid'];

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
  //nobody can modify its own privilege
  if ($_SESSION['username'] != $user->username) {
    if (isset($_POST['enable']))
      $user->enabled = 1;
    else
      $user->enabled = 0;
    if ($_POST['userGroupId'] == 0)
      $user->userGroupId = USER_GROUP_USER;
    else if ($_POST['userGroupId'] == 1)
      $user->userGroupId = USER_GROUP_MAINTENANCE;
    else if ($_POST['userGroupId'] == 2)
      $user->userGroupId = USER_GROUP_POWER;
    else if ($_POST['userGroupId'] == 3)
      $user->userGroupId = USER_GROUP_SUPER;
  }
  $user->displayName = $_POST['displayName'];
  $user->email = $_POST['email'];

  $status = $subsystem->setUser($user);
  echo '
        <meta http-equiv=refresh target=_self
            content="0; URL=users.php?tab=0&act=changed&errorcode=' . $status . '">';

  exit;
}
?>

<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center
    bordercolor=#666666 bordercolordark=#FFFFFF>

<?php
table_title(gprop('usersettings'), 'user', null, $result);

table_tab_head();

if (count($users) == 1 && $users[0]->userGroupId != USER_GROUP_SUPER)
  $tabTitle = tabTitleUser();
else
  $tabTitle = tabTitleUsers();
table_tab($tabTitle);

table_head_fold(gprop('usersettings'), 'usersetting', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
if ($result == PIERR_NO_ERROR) {
  table_row(gprop('username'), $user->username, 'username', 31);
  if ($_SESSION['username'] != $user->username) {
    if ($user->enabled)
      table_row_checkbox(gprop('enable'), true, 'enable');
    else
      table_row_checkbox(gprop('enable'), false, 'enable');
  }
  table_row_edit(gprop('displayname'), $user->displayName, 'displayName', 39);
  table_row_edit(gprop('email'), $user->email, 'email', 47);

  $privilege = array(
    array(0, getStringUserPrivilege(USER_GROUP_USER)),
    array(1, getStringUserPrivilege(USER_GROUP_MAINTENANCE)),
    array(2, getStringUserPrivilege(USER_GROUP_POWER)),
    array(3, getStringUserPrivilege(USER_GROUP_SUPER))
  );
  if ($_SESSION['username'] != $user->username) {
    $arraytmp = array(USER_GROUP_USER, USER_GROUP_MAINTENANCE, USER_GROUP_POWER, USER_GROUP_SUPER);
    for ($i = 0; $i < count($arraytmp); $i++) {
      if ($arraytmp[$i] == $user->userGroupId)
        break;
    }
    table_row_combo(gprop('privilege'), $privilege, 'userGroupId', $i);
  }

  table_foot_fold();
  table_tab_foot();

  table_row_submit(array('submitClick' => 'validateSubmit()'));
} //NO_ERROR
else {
  table_row_empty(gerror('displayusersetting'));
  table_foot_fold();
  table_tab_foot();
}
page_foot();
?>
  </table>
</form>
<script>
  form = document.forms[0];
  elements = document.forms[0].elements;
  displayName = elements['displayName'];
  email = elements['email'];
  function validateSubmit()
  {
    if (!validateAlias(displayName.value) ) {
      alert("<?= gjsalert('invaliddisplayname') ?>");
      displayName.focus();
      return false;
    }

    if (form.displayName.value == ''){
      var ret = confirm("<?= gjsalert('emptydisplayname') ?>");
      if (!ret)
        return false;
    }

    if (form.email.value == '') {
      // alert("<?= gjsalert('invalidemail') ?>");
      var ret = confirm("Email is empty. Are you sure to proceed?");
      if (!ret)
        return false;
    }
    else if (!validateEmailAddress(form.email.value)) {
        alert('<?=gjsalert('invalidemail')?>');
        email.focus();
        return false;
      }
    }
</script>
</body>
</html>
