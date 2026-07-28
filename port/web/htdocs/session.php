<?php

/*******************************************************************************
 * Filename    : session.php
 * Description : islaVista session list.
 * Created on  : 07/06/03
 *
 * (C) Copyright Promise Technology Inc., 2003
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_GET['ssid']))
  $_SESSION['ssid'] = $_GET['ssid'];

unset($GLOBALS['_SESSION']['userid']);

$server = getServer();
$subsystem = $server->getSubsystem();
$sessionid = $subsystem->getCurrentSessionId();
$note = null;
$result = null;

$sessions = $subsystem->getSessionInfo();
$result = $sessions->errorcode;
if ($result == PIERR_INACTIVE_SESSION) {
  errorOnInvalidSession();
}

if ($result == PIERR_NO_ERROR) {
  $sessions = $sessions->sessionlist;
  $sts = PIERR_NO_ERROR;
  if (isset($_GET['errorcode'])) {
    $sts = (int)$_GET['errorcode'];
  }
}
?>

<html>

<head>
<?php
page_head();
if (isset($_POST['submit'])) {
  for ($i = 0; $i < count($sessions ?? array()); $i++) {
    if (isset($_POST['items'][$i])) {
      $session = $sessions[$_POST['items'][$i]];
      $delresult = $subsystem->forceSessionLogout($session);
    }
  }
  if (isset($delresult)) {
    if ($delresult == PIERR_NO_ERROR)
      $note = gresult('sessionlogoutok');
    else
      $note = gresult('sessionilogoutfail');
  }
  $sessions = $subsystem->getSessionInfo();
  $sessions = $sessions->sessionlist;
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
  table_title(gprop('sessionviewer'), 'user', $note, $delresult);
else
  table_title(gprop('sessionviewer'), 'user', $note, $result);

table_tab_head();
$privilege = getUserPrivilege($subsystem);
if ($privilege < USER_GROUP_POWER)
  $tabTitle = tabTitleUser();
else
  $tabTitle = tabTitleUsers();
table_tab($tabTitle);

if ($result == PIERR_NO_ERROR) {
  table_head(array('class' => 'tableBorder'));
  table_head(array('pad' => 0, 'space' => 1, 'border' => 0));
  $columns = array(
    '', gprop('username'), gprop('privilege'), gprop('applicationinterface'),
    gprop('logintime'), gprop('idletime')
  );
  $widths = array(5, 20, 10, 20, 25, 15);

  table_header(gprop('sessionlist'), count($columns));
  table_head(array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
  table_header_cols($columns, $widths);

  for ($i = 0; $i < count($sessions ?? array()); $i++) {
    $session = $sessions[$i];

    $columns = array(
      0, $session->username, $session->userGroupId, $session->interface,
      $session->logintime, $session->inactivity
    );
    if ($sessionid == $session->sessionid)
      table_row_cb_cols($columns, true);
    else
      table_row_cb_cols($columns);
  }
  table_foot();
  table_foot();
  table_foot();
  table_tab_foot();
  if ($privilege == USER_GROUP_SUPER)
    table_row_submit(array('cancel' => 0, 'submitClick' => 'validateSubmit()'), gprop('logout'));
} //NO_ERROR
else {
  table_head_fold(gprop('sessionlist'), 'content');
  table_row_empty(gerror('displaysessionlist'));
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
        var ret = confirm('<?= gjsalert('forcelogout') ?>');
        if (!ret)
          return false;
        return true;
      }
    }
    alert("<?= gjsalert('selectsessiontologout') ?>")
    return false;
  }
</script>
</body>
</html>
