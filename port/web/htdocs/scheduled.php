<?php

/*******************************************************************************
 * Filename    : scheduled.php
 * Description : islaVista background activity schedule list.
 * Created on  : 06/20/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_POST['cancel'])) {
  header('Location: schedules.php?note=' . gresult('scheduledeletecancel'));
  exit;
}

$server = getServer();
$subsystem = $server->getSubsystem();
$dt = $subsystem->getDateAndTime();

/*get the controller type*/
$currentCtrlId = $subsystem->getCurrentCtrlId();
$currentCtrl = $subsystem->getController($currentCtrlId->currentCtrlId);
$batteries = $subsystem->getBatteries(0);
$schedules = $subsystem->getBGASchedules();

// hidden the battery schedules when battery number is 0 for cclass
if (_isCClassCtrl($currentCtrl->ci_ProductID) && count($batteries) == 0) {
  for ($i = 0; $i < count($schedules); $i++) {
    if ($schedules[$i]->bs_u8BgaType == BGAS_TYPE_BRC) {
      array_splice($schedules, $i, 1);
      break;
    }
  }
}

$note = null;
$result = 0;
if (count($_POST)) {
  for ($i = 0; $i < count($schedules); $i++) {
    if (isset($_POST['items'][$i])) {
      $result = $subsystem->deleteBGASchedule($schedules[$_POST['items'][$i]]);
      if ($result) {
        $note = gresult('scheduledeletefail');
        break;
      }
    }
  }
  if ($result == 0) {
    redirectPage('schedules.php?note=' . gresult('scheduledeleteok'));
    exit;
  }
  $schedules = $subsystem->getBGASchedules();
}
?>

<html>
<head>
<?php
page_head();
?>

<script>
  function validate()
  {
    form=document.forms[0];
    var count=0;

    if(<? echo count($schedules) ?>==0){
      alert('<?= gjsresult('schedulenotany') ?>');
      return false;
    }
    for(i=0;i<form.elements.length;i++)
      if(form.elements[i].name=="items[]"&&form.elements[i].checked)
        count++;
    if(count==0){
      alert('<?= gjsalert('selectscheduletodelete') ?>');
      return false;
    } else
      return confirm('<?= gjsalert('scheduledelete') ?>');
  }
</script>
</head>

<body>
<form method="post">
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('deletebgaschedules'), null, $note, $result);
table_tab_head();

$tabTitle = tabTitleSubsystem($subsystem);
table_tab($tabTitle);

table_head_fold(gprop('schedulelist') . ' - ' . $dt->_strTime, 'SCH', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

$columns = array('', gprop('type'), gprop('recurrence'), gprop('starttime'), gprop('status'));
$widths = array(15, 25, 20, 20, 20);

table_header_cb_cols($columns, $widths);

if (count($schedules) == 0)
  table_row_empty(gresult('schedulenotodelete'), count($columns));
else {
  for ($i = 0; $i < count($schedules); $i++) {
    $s = $schedules[$i];
    $type = getScheduleString($s->bs_u8BgaType);
    $columns = array(0, $type . ' ' . ($s->bs_u8SeqNum + 1), getScheduleRecurrency($s), getScheduleStartTime($s), getEnableString($s->s_bEnabled));
    table_row_cb_cols($columns);
  }

}

table_foot_fold();
table_tab_foot();

if (isset($type))
  table_row_submit(array('submitClick' => 'validate()'));
page_foot();
?>
  </table>
</form>

</body>
</html>
