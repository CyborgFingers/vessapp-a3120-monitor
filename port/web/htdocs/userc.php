<?php

/*******************************************************************************
 * Filename    : userc.php
 * Description : islaVista create new user.
 * Created on  : 08/25/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$server = getServer();
$subsystem = $server->getSubsystem();
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
  $statususer = PIERR_NO_ERROR;
  $statuspwd = PIERR_NO_ERROR;
  if (!isset($user))
    $user = new stdclass();
  $user->username = $_POST['username'];
  $user->displayName = $_POST['displayName'];
  $user->password = $_POST['newpassword'];
  if (isset($_POST['enable']))
    $user->enabled = 1;
  else
    $user->enabled = 0;
  $user->email = $_POST['email'];
  if ($_POST['userGroupId'] == 0)
    $user->userGroupId = USER_GROUP_USER;
  else if ($_POST['userGroupId'] == 1)
    $user->userGroupId = USER_GROUP_MAINTENANCE;
  else if ($_POST['userGroupId'] == 2)
    $user->userGroupId = USER_GROUP_POWER;
  else if ($_POST['userGroupId'] == 3)
    $user->userGroupId = USER_GROUP_SUPER;
  $statususer = $subsystem->addUser($user);

  if ($statususer == PIERR_INACTIVE_SESSION) {
    errorOnInvalidSession();
  }
  if ($statususer == PIERR_NO_ERROR) {
    if (!isset($password))
      $password = new stdclass();
    $password->username = $user->username;
    $password->newpassword = $_POST['newpassword'];
    $statuspwd = $subsystem->setPwd($password);
  }
  echo '
        <meta http-equiv=refresh target=_self
            content="0; URL=users.php?act=created&tab=0&errorcode='
    . $statususer . '&errcodepwd=' . $statuspwd . ' ">';

  exit;
}
?>

<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center
    bordercolor=#666666 bordercolordark=#FFFFFF>

<?php
table_title(gprop('createuser'), 'user');

table_tab_head();

$tabTitle = tabTitleUsers();
table_tab($tabTitle);

table_head_fold(gprop('usersettings'), 'usersetting', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
table_row_edit(gprop('username'), '', 'username', 31);
table_row_pwd_edit(gprop('newpassword'), '', 'newpassword', 31);
table_row_pwd_edit(gprop('retypepassword'), '', 'tmppassword', 31);
table_row_edit(gprop('displayname'), '', 'displayName', 39);
table_row_edit(gprop('email'), '', 'email', 47);

$privilege = array(
  array(0, getStringUserPrivilege(USER_GROUP_USER)),
  array(1, getStringUserPrivilege(USER_GROUP_MAINTENANCE)),
  array(2, getStringUserPrivilege(USER_GROUP_POWER)),
  array(3, getStringUserPrivilege(USER_GROUP_SUPER))
);
table_row_combo(gprop('privilege'), $privilege, 'userGroupId', 0);
table_row_checkbox(gprop('enable'), true, 'enable');
table_foot_fold();
table_tab_foot();
table_row_submit(array('submitClick' => 'validateSubmit()'));
page_foot();
?>
  </table>
</form>
<script>
  form = document.forms[0];
  elements=document.forms[0].elements;
  displayName=elements['displayName'];
  username=elements['username'];
  function validateSubmit()
  {
    for (var i=1; i < elements.length; i++)
    {
      if (elements[i].value==' '||elements[i].value=='  '||elements[i].value=='   ') {
        elements[i].value='';
        continue;
      }
    }

    if ( !validateUsername(username.value)) {
      
      alert("<?= gjsalert('invalidusername') ?>");
      return false;
    }
	if (form.newpassword.value.length < 8) {
        alert('<?=gjsalert('invalidpassword')?>');
        return false;
    }
    if (form.newpassword.value == "password") {
        alert('<?=gjsalert('strongerpassword')?>');
        return false;
    }
    if (!validatePassword(form.newpassword.value) ) {
      alert("<?= gjsalert('invalidnewpassword') ?>");
      return false;
    }

    if (form.newpassword.value != form.tmppassword.value){
      alert("<?= gjsalert('retypenewpassword') ?>") ;
      return false;
    }

    if (!validateAlias(displayName.value) ) {
      alert("<?= gjsalert('invaliddisplayname') ?>");
      return false;
    }

    if (form.displayName.value == ''){
      var ret = confirm("<?= gjsalert('emptydisplayname') ?>");
      if (!ret)
        return false;
    }

    for (var i=0;i<form.email.value.length;i++ )
    {
      if ((form.email.value.charCodeAt(i) < 0) || (form.email.value.charCodeAt(i) > 255))
      {
        alert("<?= gjsalert('invalidemail') ?>");
        return false;
      }

      if (form.displayName.value == '') {
        var ret = confirm("<?=gjsalert('emptydisplayname')?>");
        if (!ret)
          return false;
      }

      if (!validateEmailAddress(form.email.value)) {
        alert("<?=gjsalert('invalidemail')?>");
        form.email.focus();
        return false;
      }
    }
  }
</script>
</body>
</html>
