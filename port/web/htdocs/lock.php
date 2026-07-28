<?php

/*******************************************************************************
 * Filename    : lock.php
 * Description : islaVista lock subsystem management
 * Created on  : 09/25/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$server = getServer();
$subsystem = $server->getSubsystem();
$status = $subsystem->checkLock();

$leftWidth = 25;
$note = null;
$result = $status->errorcode;
if ($result == PIERR_INACTIVE_SESSION) {
  errorOnInvalidSession();
}
if (isset($_GET['status']))
  $ret = $_GET['status'];
?>

<html>

<head>
<?php
page_head();

if ($status->locked == "true") {
  echo '
                <meta http-equiv=refresh target=_self
                    content="20; URL=lock.php">';
}
?>
</head>
<body>

<?php
if (isset($_POST['submit'])) {
  if ($_POST['lockoperation'] == "LOCK") {
    if (!isset($lock))
      $lock = new stdclass();
    $status = $subsystem->checkLock();
    if ($status->locked == "true")
      $lock->extend = "true";
    else
      $lock->extend = "false";
    $lock->duration = $_POST['duration'];
    $result = $subsystem->lock($lock);
  } else if ($_POST['lockoperation'] == "UNLOCK") {
    $status = $subsystem->checkLock();
    if (isset($_POST['forceunlock']))
      $unlockforce = 1;
    else
      $unlockforce = 0;
    $result = $subsystem->unLock($unlockforce);
  }
  echo '<script>this.location="lock.php?status=' . $result . '"; </script>';
  exit;
}
?>

<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center>

<?php
if (isset($ret) && (int)$ret != PIERR_NO_ERROR)
  table_title(gprop('lockmanagement'), 'lock', $note, (int)$ret);
else
  table_title(gprop('lockmanagement'), 'lock', $note, $result);

table_tab_head();
$s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner');
$tabTitle = tabTitleSubsystem($subsystem);
table_tab($tabTitle);

if ($result == PIERR_NO_ERROR) {
  table_head_fold(gprop('lockinfomation'), 'lockinfo', null, null, $s);
  table_row(gprop('lockstatus'), $status->status, $leftWidth);
  table_row(gprop('expirationtime'), $status->duration, $leftWidth);
  table_row(gprop('expireattime'), $status->expirationtime, $leftWidth);
  table_foot_fold();

  table_head_fold(gprop('lockoperation'), 'lockop', null, null, $s);
  ?>
            <tr>
            <td width=<? echo $leftWidth ?>% class=tableRowCol1>
            <span>
              <input type=radio name=lockoperation value=LOCK
<?php
if ($status->locked == "false")
  echo ' checked';
?>
              >
<?php
if ($status->locked == "true")
  echo gprop('renew');
else
  echo gprop('lock');
?>
            </span></td>
            <td class=tableRowColOther>
              &nbsp;
              <input maxlength=10 class=editField3 type=text name=duration value=
<?php
echo '30';
?>
              >
              <?= gprop('minutes') ?>
            </td>
          </tr>

          <tr>
            <td rowspan=2 class=tableRowCol1><span>
              <input type=radio name=lockoperation value=UNLOCK
<?php
if ($status->locked == "true")
  echo ' checked';
?>
              >
              <?= gprop('unlock') ?>
            </span></td>
          </tr>

<?php
if (getUserPrivilege($subsystem) >= USER_GROUP_SUPER) {
  echo '
            <tr>
            <td class=tableRowColOther>
              <table width=100% cellpadding=0 cellspacing=0 border=0>
                <tr class=text5>';
  echo '
                  <td><span>
                    <input type=checkbox name=forceunlock value=force';
  echo ' unchecked';
  echo '
                    > Force Unlock.
                  </span></td>';
  echo '
                </tr>
              </table>
            </td>
          </tr>';
}
?>
<?php
table_foot_fold();
table_tab_foot();

table_row_submit(array('submitClick' => 'validateSubmit()'));
} //NO_ERROR
else {
  table_head_fold(gprop('lockinfomation'), 'content');
  table_row_empty(gerror('lockinfodisplay'));
  table_foot_fold();
  table_tab_foot();
}
page_foot();
?>
  </table>
</form>
<script>
    var form = document.forms[0];

    function validateSubmit()
    {
        obj = form.elements['duration'];
        if (form.elements.lockoperation[0].checked == true &&
            obj && (obj.value == '' || obj.value < 1 || obj.value > 1440))
        {
            alert('<?= gjsalert('alertlocktime') ?>');
            obj.focus();
            return false;
        }
        return true;
    }
    
    
</script>
</body>
</html>
