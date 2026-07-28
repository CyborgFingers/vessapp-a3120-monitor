<?php

/*******************************************************************************
 * Filename    : userd.php
 * Description : IslaVista delete a user.
 * Created on  : 06/05/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$server = getServer();
$subsystem = $server->getSubsystem();

$note = null;
$result = null;

$users = $subsystem->getUsers();
$result = $users->errorcode;
if ($result == PIERR_INACTIVE_SESSION) {
  errorOnInvalidSession();
}
if ($result == PIERR_NO_ERROR) {
  $users = $users->userlist;
}
?>

<html>

<head>
<?php
page_head();

if (isset($_POST['submit'])) {
  for ($i = 0; $i < count($users); $i++) {
    if (isset($_POST['items'][$i])) {
      $user = $users[$_POST['items'][$i]];
      $delresult = $subsystem->delUser($user);
    }
  }
  if (isset($delresult)) {
    if ($delresult == PIERR_NO_ERROR)
      $note = gresult('userdeleteok');
    else
      $note = gresult('userdeletefail');
  }
  $users = $subsystem->getUsers();
  $users = $users->userlist;
}
?>
</head>

<body>

<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center
    bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
if (isset($delresult) && $delresult != PIERR_NO_ERROR)
  table_title(gprop('deleteuser'), 'user', $note, $delresult);
else
  table_title(gprop('deleteuser'), 'user', $note, $result);

table_tab_head();

if (count($users) == 1 && $users[0]->userGroupId != USER_GROUP_SUPER)
  $tabTitle = tabTitleUser();
else
  $tabTitle = tabTitleUsers();
table_tab($tabTitle);

if ($result == PIERR_NO_ERROR) {
  $columns = array('', gprop('username'), gprop('privilege'), gprop('displayname'), gprop('email'));
  $widths = array(10, 20, 20, 20, 30);

  table_head(array('class' => 'tableBorder'));
  table_head(array('pad' => 0, 'space' => 1, 'border' => 0));
  table_header(gprop('userlist'), count($columns));
  table_head(array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));	
  table_header_cols($columns, $widths);
  for ($i = 0; $i < count($users); $i++) {
    $user = $users[$i];
    $columns = array(
      0, $user->username, getStringUserPrivilege($user->userGroupId),
      $user->displayName, $user->email
    );
    if ($user->username == $_SESSION['username'])
      table_row_cb_cols($columns, true);
    else
      table_row_cb_cols($columns);
  }
  table_foot();
  table_foot();
  table_foot();
  table_tab_foot();

  if (count($users) != 0)
    table_row_submit(array('cancel' => 0, 'submitClick' => 'validateSubmit()'));
} else {
  table_head_fold(gprop('userlist'), 'content');
  table_row_empty(gerror('displayuserlist'));
  table_foot_fold();
  table_tab_foot();
}
page_foot();
?>
  </table>
</form>

<script>
  form = document.forms[0];

  function validateSubmit()
  {
    for (i = 0; i < form.elements.length; i++)
    {
      if (form.elements[i].name == "items[]" &&
          form.elements[i].checked && form.elements[i].disabled == false)
      {
        var ret = confirm('<?= gjsalert('deleteuser') ?>');
        if (!ret)
          return false;
        return true;
      }
    }
    alert('<?= gjsalert('selectusertodelete') ?>')
    return false;
  }
</script>

</body>
</html>

