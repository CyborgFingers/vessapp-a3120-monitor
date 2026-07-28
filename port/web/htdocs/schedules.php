<?php

/*******************************************************************************
 * Filename    : schedules.php
 * Description : islaVista background activity schedule list.
 * Created on  : 06/20/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$server = getServer();
$subsystem = $server->getSubsystem();
$schedules = $subsystem->getBGASchedules();

/*get the controller type*/
$currentCtrlId = $subsystem->getCurrentCtrlId();
$currentCtrl = $subsystem->getController($currentCtrlId->currentCtrlId);

// $encls = $subsystem->getEnclosures();
// $encl = $encls[0];
// $batteries = $subsystem->getBatteries(0);
$note = isset($_GET['note']) ? $_GET['note'] : null;
?>

<html>
<head>
<?php
page_head();
?>
</head>

<body>
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('bgaschedules'), 'schedule', $note);
table_tab_head();

$tabTitle = tabTitleSubsystem($subsystem);
table_tab($tabTitle);

$dt = $subsystem->getDateAndTime();

table_head_fold(gprop('schedulelist') . ' - ' . $dt->_strTime, 'SCH', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

$columns = array(gprop('type'), gprop('recurrence'), gprop('starttime'), gprop('status'));
$widths = array(30, 25, 25, 20);

table_header_cols($columns, $widths);
if (count($schedules) == 0)
  table_row_empty(gresult('scheduleempylist'), count($columns));
else {
  for ($i = 0; $i < count($schedules); $i++) {
    $s = $schedules[$i];
    $columns = array(
      array(getScheduleString($s->bs_u8BgaType) . ' ' . ($s->bs_u8SeqNum + 1), 'schedule.php?sch=' . $s->bs_u8BgaType . '&seq=' . $s->bs_u8SeqNum),
      getScheduleRecurrency($s), getScheduleStartTime($s), getEnableString($s->s_bEnabled)
    );
    table_row_cols($columns);
  }
}

table_foot_fold();
table_tab_foot();

page_foot();
?>

  </table>
</form>

</body>
</html>
