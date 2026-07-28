<?php

/*******************************************************************************
 * Filename    : schedule.php
 * Description : islaVista background activity schedule.
 * Created on  : 06/20/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$allld = 0;
if (isset($_POST['cancel'])) {
  header('Location: schedules.php?note=' . gprop(isset($_GET['seq']) ? 'modifying' : 'adding') . ' ' . strtolower(getScheduleString($_GET['sch'])) .
    ' ' . gresult('schedulecancel'));
  exit;
}

if (isset($_GET['sch']))
  SetSessionValue('sch', $_GET['sch']);
if (isset($_GET['seq']))
  $seqNum = $_GET['seq'];

$server = getServer();
$subsystem = $server->getSubsystem();
$dt = $subsystem->getDateAndTime();
$schedules = $subsystem->getBGASchedules();
$months = array(
  gprop('january'), gprop('february'), gprop('march'), gprop('april'), gprop('may'), gprop('june'),
  gprop('july'), gprop('august'), gprop('september'), gprop('october'), gprop('november'), gprop('december')
);
$minutes = array(':00', ':05', ':10', ':15', ':20', ':25', ':30', ':35', ':40', ':45', ':50', ':55');
$weekdays = array(gprop('sunday'), gprop('monday'), gprop('tuesday'), gprop('wednesday'), gprop('thursday'), gprop('friday'), gprop('saturday'));
$chioce = array(gprop('first'), gprop('second'), gprop('third'), gprop('fourth'), gprop('last'));

$leftWidth = 25;
$year = date('Y');

if ($_SESSION['sch'] == BGAS_TYPE_RC)
  $logicalDrives = $subsystem->getLogicalDrives();

function getScheduleParam($schedule)
{
  if (!isset($schedule))
    $schedule = new stdclass();
  $schedule->bs_u8BgaType = $_SESSION['sch'];
  if ($_SESSION['sch'] == BGAS_TYPE_RC) {
    $schedule->rcs_bFix = isset($_POST['rcs_bFix']) ? 1 : 0;
    $schedule->rcs_bPause = isset($_POST['rcs_bPause']) ? 1 : 0;
    global $logicalDrives;
    $k = 0;
    unset($schedule->rcs_u16DeviceIds);

    for ($i = 0; $i < count($_POST['logdrvs']); $i++) {
      for ($j = 0; $j < count($logicalDrives); $j++) {
        $logicalDrive = $logicalDrives[$j];
        if ($_POST['logdrvs'][$i] == $logicalDrive->ldi_FlatId) {
          if ($logicalDrive->ldi_RaidLevel != 'RAID0' && $logicalDrive->ldi_RaidLevel != 'JBOD')
            $schedule->rcs_u16DeviceIds[] = $logicalDrive->ldi_FlatId;
          break;
        }
      }
    }
  }

  $schedule->s_u16StartTime = $_POST['s_u16StartTime_h'] * 60 + $_POST['s_u16StartTime_m'] * 5;
  $schedule->s_bEnabled = isset($_POST['s_bEnabled']) ? 1 : 0;
  $schedule->s_u32Duration = 0;

  if ($_POST['s_u8Recurrency'] == 'daily') {
    $schedule->s_u8Recurrency = SCHEDULE_RECURRENCY_DAILY;
    $schedule->sdp_u8Interval = $_POST['sdp_u8Interval'];
  } else if ($_POST['s_u8Recurrency'] == 'weekly') {
    $schedule->s_u8Recurrency = SCHEDULE_RECURRENCY_WEEKLY;
    $schedule->swp_u8Interval = $_POST['swp_u8Interval'];
    $schedule->swp_u8DayMask = 0;
    global $weekdays;
    for ($i = 0; $i < count($weekdays); $i++)
      if (isset($_POST['swp_u8DayMask' . $i]))
      $schedule->swp_u8DayMask |= (1 << $_POST['swp_u8DayMask' . $i]);
  } else if ($_POST['s_u8Recurrency'] == 'monthly') {
    $schedule->s_u8Recurrency = SCHEDULE_RECURRENCY_MONTHLY;
    if ($_POST['smp_u8DayPattern'] == 'smp_u8DayPattern_d') {
      $schedule->smp_u8DayPattern = MONTHLY_DAY_PATTERN_DAY;
      $schedule->smp_u8DayOfMonth = $_POST['smp_u8DayOfMonth'];
    } else if ($_POST['smp_u8DayPattern'] == 'smp_u8DayPattern_o') {
      $schedule->smp_u8DayPattern = MONTHLY_DAY_PATTERN_ORDINAL;
      $schedule->smp_u8Ordinal = $_POST['smp_u8Ordinal'];
      $schedule->smp_u8DayOfWeek = $_POST['smp_u8DayOfWeek'];
    }
    $schedule->smp_u16MonthMask = 0;
    global $months;
    for ($i = 0; $i < count($months); $i++)
      if (isset($_POST['smp_u16MonthMask' . $i]))
      $schedule->smp_u16MonthMask |= (1 << $_POST['smp_u16MonthMask' . $i]);
  }

  $schedule->s_u8MonthStart = $_POST['s_u8MonthStart'];
  $schedule->s_u8DayStart = $_POST['s_u8DayStart'];
  $schedule->s_u16YearStart = $_POST['s_u16YearStart'];

  if ($_POST['s_u8RangeEndType'] == 's_u8RangeEndType_1') {
    $schedule->s_u8RangeEndType = SCHEDULE_RANGE_END_COUNT;
    $schedule->s_u8RecurrenceCount = $_POST['s_u8RecurrenceCount'];
  } else if ($_POST['s_u8RangeEndType'] == 's_u8RangeEndType_2') {
    $schedule->s_u8RangeEndType = SCHEDULE_RANGE_END_DATE;
    $schedule->s_u8MonthEnd = $_POST['s_u8MonthEnd'];
    $schedule->s_u8DayEnd = $_POST['s_u8DayEnd'];
    $schedule->s_u16YearEnd = $_POST['s_u16YearEnd'];
  } else
    $schedule->s_u8RangeEndType = SCHEDULE_RANGE_END_NONE;
  return $schedule;
}

function defaultScheduleParam($schedule)
{
  global $year;
  if (!isset($schedule->bs_u8BgaType)) {
    if (!isset($schedule))
      $schedule = new stdclass();
    $schedule->bs_u8BgaType = $_SESSION['sch'];
    if ($_SESSION['sch'] == BGAS_TYPE_RC) {
      if (!isset($schedule->rcs_bFix))
        $schedule->rcs_bFix = 0;
      if (!isset($schedule->rcs_bPause))
        $schedule->rcs_bPause = 0;
    }
  }
  if (!isset($schedule->s_bEnabled))
    $schedule->s_bEnabled = 1;
  if (!isset($schedule->s_u16StartTime))
    $schedule->s_u16StartTime = 120; // 2am
  if (!isset($schedule->s_u32Duration))
    $schedule->s_u32Duration = 0;  // until complete
  if (!isset($schedule->s_u8Recurrency))
    $schedule->s_u8Recurrency = SCHEDULE_RECURRENCY_WEEKLY;
  if (!isset($schedule->sdp_u8Interval))
    $schedule->sdp_u8Interval = 1;
  if (!isset($schedule->swp_u8Interval)) {
    $schedule->swp_u8Interval = 1;
    $schedule->swp_u8DayMask = 0x40; // Sat
  }
  if (!isset($schedule->smp_u8DayPattern)) {
    $schedule->smp_u8DayPattern = MONTHLY_DAY_PATTERN_DAY;
    $schedule->smp_u16MonthMask = 0xFFF;
  }
  if (!isset($schedule->smp_u8DayOfMonth))
    $schedule->smp_u8DayOfMonth = 1;
  if (!isset($schedule->smp_u8Ordinal)) {
    $schedule->smp_u8Ordinal = 0;
    $schedule->smp_u8DayOfWeek = 0;
  }
  if (!isset($schedule->s_u16YearStart)) {
    $schedule->s_u8MonthStart = date('m') - 1;
    $schedule->s_u8DayStart = date('d');
    $schedule->s_u16YearStart = $year - 1900;
  }
  if (!isset($schedule->s_u8RangeEndType))
    $schedule->s_u8RangeEndType = SCHEDULE_RANGE_END_NONE;
  if (!isset($schedule->s_u8RecurrenceCount))
    $schedule->s_u8RecurrenceCount = 1;
  if (!isset($schedule->s_u16YearEnd)) {
    $schedule->s_u8MonthEnd = date('m') - 1;
    $schedule->s_u8DayEnd = date('d');
    $schedule->s_u16YearEnd = $year - 1900;
  }
  return $schedule;
}

function logdrv_row_cb_cols($columns)
{
  $class = mod($_SESSION['row'], 2) == 0 ? 'tableRow1Cols' : 'tableRow2Cols';
  echo '
      <tr class=' . $class . ' >
        <td align=middle>
          <input type=checkbox name=logdrvs[] value=' . $columns[1] . ($columns[0] ? ' checked' : '') . ' onclick=checkAllLD()>
        </td>';
  for ($i = 1; $i < count($columns); $i++)
    echo '
        <td>' . $columns[$i] . '</td>';
  echo '
      </tr>';
  $_SESSION['row']++;
}

function getSchedule($seqNum)
{
  global $subsystem;
  global $schedules;
  for ($i = 0; $i < count($schedules); $i++)
    if ($_SESSION['sch'] == $schedules[$i]->bs_u8BgaType && (isset($seqNum) ? ($schedules[$i]->bs_u8SeqNum == $seqNum) : 1))
    return $schedules[$i];
  return null;
}

$note = null;
$result = 0;
if (count($_POST)) {
  $schedule = getSchedule(isset($seqNum) ? $seqNum : null);
  $schedule = getScheduleParam($schedule);
  if (isset($seqNum))
    $result = $subsystem->modifyBGASchedule($schedule);
  else
    $result = $subsystem->addBGASchedule($schedule);
  if ($result == 0) {
    redirectPage('schedules.php?note=' . getScheduleString($_GET['sch']) . ' ' . gresult(isset($seqNum) ? 'schedulemodifyok' : 'schedulecreateok'));
    exit;
  } else
    $note = gresult(isset($seqNum) ? 'schedulemodifyfail' : 'schedulecreatefail');
} else {
  if ($_SESSION['sch'] == BGAS_TYPE_RC) {
  } else if (!isset($seqNum)) { // only one allowed
    if ($_SESSION['sch'] == BGAS_TYPE_MP) {
      $arrays = $subsystem->getDiskArrays();
      if (count($arrays) == 0)
        $empty = gresult('mediapatrolschedule');
    }
    if (!isset($empty)) {
      $s = getSchedule(null);
      if (isset($s))
        $empty = galert('scheduleexisting');
    }
  }
  if (isset($seqNum))
    $schedule = getSchedule($seqNum);
}
if (!isset($empty))
  $schedule = defaultScheduleParam(isset($schedule) ? $schedule : null);
?>

<html>
<head>
<?php
page_head();
SetSessionValue('lastfile', htmlspecialchars($_SERVER['PHP_SELF']) . '?sch=' . $_SESSION['sch'] . (isset($seqNum) ? ('&seq=' . $seqNum) : ''));
SyncSession();

function table_row_edit_desld($left, $right, $fieldname, $maxlength = 100, $leftWidth = 30, $unit = null, $spanid = null, $disabled = 'false')
{
  if (isset($spanid))
    $spanTags = " <span id=$spanid class=warning nowrap></span>";
  else
    $spanTags = '';
  echo '
      <tr>
        <td width=' . $leftWidth . '% class=tableRowCol1>
          <span class=text4>' . $left . '</span>
        </td>
        <td class=tableRowColOther>
          <span class=text5>';
  if ($disabled == 'false')
    echo '<input maxlength=' . $maxlength . ' class=editFieldemailaddr type=text name=' . $fieldname . ' size=20 value="' . $right . '">' . ($unit ? $unit : '') . $spanTags;
  else
    echo '<input disabled style="background-color: rgb(240, 240, 240); color: rgb(136, 136, 136);" maxlength=' . $maxlength . ' class=editField type=text name=' . $fieldname . ' size=20 value="' . $right . '">' . ($unit ? $unit : '') . $spanTags;

  echo '</span>
        </td>
      </tr>';
}

?>
</head>

<body>
<form method=post>
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center>
<?php
$title = getScheduleString($_SESSION['sch']) . ' ' . gprop('schedule');
table_title($title, null, $note, $result);
table_tab_head();

$tabTitle = tabTitleSubsystem($subsystem);
table_tab($tabTitle);
$s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner');
table_head_fold(gprop(isset($seqNum) ? 'current' : 'new') . ' ' . $title . ' - ' . $dt->_strTime, 'SCH', null, null, $s);

if (isset($empty))
  table_row_empty($empty);
else {
  table_row_checkbox(gprop('enablethissechedule'), $schedule->s_bEnabled, 's_bEnabled', $leftWidth);
  table_header0(gprop('runtime'), 2);
  ?>
      <tr>
        <td class=tableRowCol1>
          <span class=text4>
          <?= gprop('starttime') ?>
          </span>
        </td>
        <td class=tableRowColOther>
<?php
for ($i = 0; $i < 24; $i++)
  $startHours[] = array($i, $i . '&nbsp;');
table_select($startHours, 's_u16StartTime_h', floor($schedule->s_u16StartTime / 60));

for ($i = 0; $i < count($minutes); $i++)
  $startMinutes[] = array($i, $minutes[$i]);
table_select($startMinutes, 's_u16StartTime_m', floor(mod($schedule->s_u16StartTime, 60) / 5));
?>
        </td>
      </tr>
      <!--
      <tr>
        <td rowspan=2 class=tableRowCol1>
          <span class=text4>
            <?= gprop('duration') ?>
          </span>
        </td>
        <td class=tableRowColOther>
          <input type=radio name=s_u32Duration value=s_u32Duration_0 onclick=durationChanged(this)
            <? echo ($schedule->s_u32Duration == 0 ? 'checked ' : ''); ?>><?= gprop('untilcompletion') ?>
        </td>
      </tr>
      <tr>
        <td class=tableRowColOther>
          <input type=radio name=s_u32Duration value=s_u32Duration_1 onclick=durationChanged(this)
            <? echo ((1 || $_SESSION['sch'] == BGAS_TYPE_BRC || $_SESSION['sch'] == BGAS_TYPE_SC) ? 'disabled' : ($schedule->s_u32Duration != 0 ? 'checked ' : '')) ?>><?= gprop('pauseafter') ?>&nbsp;
          <input maxlength=10 class=editField3 type=text name=s_u32Duration_h value=<? echo floor($schedule->s_u32Duration / 60) ?>><?= gprop('hours') ?>&nbsp;
          <input maxlength=10 class=editField3 type=text name=s_u32Duration_m value=<? echo mod($schedule->s_u32Duration, 60) ?>><?= gprop('minutes') ?>
          <? echo ($_SESSION['sch'] == BGAS_TYPE_BRC ? ('<br>&nbsp;&nbsp;' . gresult('batteryreconditionnopause')) : '') ?>
          <? echo ($_SESSION['sch'] == BGAS_TYPE_SC ? ('<br>&nbsp;&nbsp;' . gresult('sparechecknopause')) : '') ?>
        </td>
      </tr>
      -->
<?php
table_header0(gprop('recurrencepattern'), 2);
?>
      <tr>
        <td class=tableRowCol1>
          <span class=text4>
          <input type=radio name=s_u8Recurrency value=daily onclick=patternChanged(this)
            <? echo ($schedule->s_u8Recurrency == SCHEDULE_RECURRENCY_DAILY ? 'checked ' : ''); ?>><?= gprop('daily') ?>            
          </span>
        </td>
        <td class=tableRowColOther>&nbsp;<?= gprop('every') ?>
          <input maxlength=10 class=editField3 type=text name=sdp_u8Interval value=<? echo $schedule->sdp_u8Interval ?>><?= gprop('days') ?>
        </td>
      </tr>

      <tr>
        <td rowspan=2 class=tableRowCol1>
          <span class=text4>
          <input type=radio name=s_u8Recurrency value=weekly onclick=patternChanged(this)
            <? echo ($schedule->s_u8Recurrency == SCHEDULE_RECURRENCY_WEEKLY ? 'checked ' : ''); ?>><?= gprop('weekly') ?>
          </span>
        </td>
        <td class=tableRowColOther>&nbsp;<?= gprop('every') ?>
          <input maxlength=10 class=editField3 type=text name=swp_u8Interval value=<? echo $schedule->swp_u8Interval ?>><?= gprop('weeks') ?>
        </td>
      </tr>
      <tr>
        <td class=tableRowColOther>
          <table width=100% cellpadding=0 cellspacing=0 border=0>
            <tr class=text5>
<?php
for ($i = 0; $i < count($weekdays); $i++) {
  if ($i == round(count($weekdays) / 2))
    echo '
            </tr>
            <tr class=text5>';
  echo '
              <td>
                <input type=checkbox name=swp_u8DayMask' . $i . ' value=' . $i . ($schedule->swp_u8DayMask & (1 << $i) ? ' checked' : '') . '>' . $weekdays[$i] . '
              </td>';
}
?>
            </tr>
          </table>
        </td>
      </tr>
      <tr>
        <td rowspan=3 class=tableRowCol1>
          <span class=text4>
          <input type=radio name=s_u8Recurrency value=monthly onclick=patternChanged(this)
            <? echo ($schedule->s_u8Recurrency == SCHEDULE_RECURRENCY_MONTHLY ? 'checked ' : ''); ?>><?= gprop('monthly') ?>
            <? echo ($_SESSION['sch'] == BGAS_TYPE_BRC ? galert('batteryschedule') : '') ?>
          </span>
        </td>
        <td class=tableRowColOther>
          <input type=radio name=smp_u8DayPattern value=smp_u8DayPattern_d onclick=monthPatternChanged(this)
            <? echo ($schedule->smp_u8DayPattern == MONTHLY_DAY_PATTERN_DAY ? 'checked ' : ''); ?>><?= gprop('dayofmonth') ?>&nbsp;
<?php
for ($i = 1; $i <= 31; $i++)
  $dayOfMonth[] = array($i, $i);
table_select($dayOfMonth, 'smp_u8DayOfMonth', $schedule->smp_u8DayOfMonth);
?>
        </td>
      </tr>
      <tr>
        <td class=tableRowColOther>
          <input type=radio name=smp_u8DayPattern value=smp_u8DayPattern_o onclick=monthPatternChanged(this)
            <? echo ($schedule->smp_u8DayPattern == MONTHLY_DAY_PATTERN_ORDINAL ? 'checked ' : ''); ?>><?= gprop('the') ?>&nbsp;
<?php
for ($i = 0; $i < count($chioce); $i++)
  $ordinal[] = array($i, $chioce[$i]);
table_select($ordinal, 'smp_u8Ordinal', $schedule->smp_u8Ordinal);

for ($i = 0; $i < count($weekdays); $i++)
  $dayOfWeek[] = array($i, $weekdays[$i]);
table_select($dayOfWeek, 'smp_u8DayOfWeek', $schedule->smp_u8DayOfWeek);
?>
        </td>
      </tr>
      <tr>
        <td class=tableRowColOther>
          <table width=100% cellpadding=0 cellspacing=0 border=0>
            <tr class=text5>
<?php
for ($i = 0; $i < count($months); $i++) {
  if ($i != 0 && gettype($i / 4) == 'integer')
    echo '
            </tr>
            <tr class=text5>';
  echo '
              <td>
                <input type=checkbox name=smp_u16MonthMask' . $i . ' value=' . $i . ($schedule->smp_u16MonthMask & (1 << $i) ? ' checked' : '') . '>' . $months[$i] . '
              </td>';
}
?>
            </tr>
          </table>

        </td>
      </tr>
<?php
table_header0(gprop('rangeofrecurrence'), 2);
?>
      <tr>
        <td class=tableRowCol1>
          <span class=text4>
          <?= gprop('startfrom') ?>
          </span>
        </td>
        <td class=tableRowColOther>
<?php
for ($i = 0; $i < count($months); $i++)
  $startMonths[] = array($i, $months[$i]);
table_select($startMonths, 's_u8MonthStart', $schedule->s_u8MonthStart);

for ($i = 1; $i <= 31; $i++)
  $startDays[] = array($i, $i);
table_select($startDays, 's_u8DayStart', $schedule->s_u8DayStart);

for ($i = $year; $i < $year + 10; $i++)
  $startYears[] = array($i - 1900, $i);
table_select($startYears, 's_u16YearStart', $schedule->s_u16YearStart);
?>
        </td>
      </tr>
      <tr>
        <td rowspan=3 class=tableRowCol1>
          <span class=text4>
            <?= gprop('endon') ?>
          </span>
        </td>
        <td class=tableRowColOther>
          <input type=radio name=s_u8RangeEndType value=s_u8RangeEndType_0 onclick=endDateChanged(this)
            <? echo ($schedule->s_u8RangeEndType == SCHEDULE_RANGE_END_NONE ? ' checked' : '') ?>><?= gprop('noenddate') ?>
        </td>
      </tr>
      <tr>
        <td class=tableRowColOther>
          <input type=radio name=s_u8RangeEndType value=s_u8RangeEndType_1 onclick=endDateChanged(this)
            <? echo ($schedule->s_u8RangeEndType == SCHEDULE_RANGE_END_COUNT ? ' checked' : '') ?>><?= gprop('endafter') ?>
          <input maxlength=15 class=editField3 type=text name=s_u8RecurrenceCount value=<? echo $schedule->s_u8RecurrenceCount ?>><?= gprop('times') ?>
        </td>
      </tr>
      <tr>
        <td class=tableRowColOther>
          <input type=radio name=s_u8RangeEndType value=s_u8RangeEndType_2 onclick=endDateChanged(this)
            <? echo ($schedule->s_u8RangeEndType == SCHEDULE_RANGE_END_DATE ? ' checked' : '') ?>><?= gprop('until') ?>
<?php
for ($i = 0; $i < count($months); $i++)
  $endMonths[] = array($i, $months[$i] . '&nbsp;&nbsp;');
table_select($endMonths, 's_u8MonthEnd', $schedule->s_u8MonthEnd);

for ($i = 1; $i <= 31; $i++)
  $endDays[] = array($i, $i);
table_select($endDays, 's_u8DayEnd', $schedule->s_u8DayEnd);

for ($i = $year; $i < $year + 10; $i++)
  $endYears[] = array($i - 1900, $i);
table_select($endYears, 's_u16YearEnd', $schedule->s_u16YearEnd);
?>
        </td>
      </tr>
<?php
table_foot_fold();
table_tab_foot();
$s = array('pad' => 0, 'space' => 0, 'border' => 0, 'class' => 'tableContentInner');
if ($_SESSION['sch'] == BGAS_TYPE_RC) {
  table_head_fold_list(gprop('redundancycheckoptions'), 'RC', $s);
  $s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContent');
  table_head($s);

  table_row_checkbox(gprop('autofix'), $schedule->rcs_bFix, 'rcs_bFix', $leftWidth);
  table_row_checkbox(gprop('pauseonerror'), $schedule->rcs_bPause, 'rcs_bPause', $leftWidth);

  table_foot();
  table_head($s);

  $columns = array('', gprop('logicaldriveid'), gprop('raidlevel'), gprop('capacity'), gprop('status'));
  $widths = array($leftWidth, 20, 20, 20, 40 - $leftWidth);

  table_header0(gprop('applytoredundantld'), count($columns));
  table_header_cb_cols($columns, $widths);

  for ($i = 0; $i < count($logicalDrives); $i++) {
    $logicalDrive = $logicalDrives[$i];
    if ($logicalDrive->ldi_RaidLevel == 'RAID0' || $logicalDrive->ldi_RaidLevel == 'JBOD')
      continue;
    $usedLd = false;
    for ($j = 0; $j < count($schedules); $j++) {
      if ($schedules[$j]->bs_u8BgaType != BGAS_TYPE_RC)
        continue;
      if (isset($seqNum) && $seqNum == $schedules[$j]->bs_u8SeqNum)
        continue;
      if (isInArray($schedules[$j]->rcs_u16DeviceIds, $logicalDrive->ldi_FlatId)) {
        $usedLd = true;
        break;
      }
    }
    if ($usedLd)
      continue;
    $columns = array(
      isset($schedule->rcs_u16DeviceIds) && isInArray($schedule->rcs_u16DeviceIds, $logicalDrive->ldi_FlatId),
      $logicalDrive->ldi_FlatId, $logicalDrive->ldi_RaidLevel, $logicalDrive->ldi_Size, $logicalDrive->_opStatus
    );
    logdrv_row_cb_cols($columns);
  }

  if (isset($_GET['seq']) && $schedule->rcs_numDevice == 0)
    $allld = 1;
  table_foot();
  table_foot_fold();
}
}

if (isset($empty))
  table_foot_fold();
table_foot();

if (!isset($empty))
  table_row_submit(array('submitClick' => 'validate()'));
page_foot();
?>
  </table>
</form>
<?php
if (!isset($empty)) {
  ?>
<script>
    elements=document.forms[0].elements;

    // index=<? echo $schedule->s_u32Duration ? 1 : 0 ?>;
    // durationChanged(elements['s_u32Duration'][index]);

    index=<? echo ($schedule->s_u8Recurrency == SCHEDULE_RECURRENCY_DAILY) ? 0 : (($schedule->s_u8Recurrency == SCHEDULE_RECURRENCY_WEEKLY) ? 1 : 2) ?>;
    patternChanged(elements['s_u8Recurrency'][index]);

    index=<? echo ($schedule->s_u8RangeEndType == SCHEDULE_RANGE_END_NONE) ? 0 : (($schedule->s_u8RangeEndType == SCHEDULE_RANGE_END_COUNT) ? 1 : 2) ?>;
    endDateChanged(elements['s_u8RangeEndType'][index]);
    // when modify
    // echo 0 && 1 does not work

    oMs=elements['s_u8MonthStart'];
    oDs=elements['s_u8DayStart'];
    oYs=elements['s_u16YearStart'];
    if(oMs){
      oMs.onchange=adjDateStart;
      oYs.onchange=adjDateStart;
      adjDateStart();
    }
    oMe=elements['s_u8MonthEnd'];
    oDe=elements['s_u8DayEnd'];
    oYe=elements['s_u16YearEnd'];
    if(oMe){
      oMe.onchange=adjDateEnd;
      oYe.onchange=adjDateEnd;
      adjDateEnd();
    }
    function adjDateStart()
    {
      adjDateObj(oYs,oMs,oDs);
    }
    function adjDateEnd()
    {
      adjDateObj(oYe,oMe,oDe);
    }

    if ( <? echo ($_SESSION['sch'] == BGAS_TYPE_RC ? 1 : 0) ?>)
    {
      //old start

      //old end
    }
    else
      checkAllLD();

    function durationChanged(obj)
    {
      o=ylib_getObj('s_u32Duration_h');
      o.disabled=(obj.value=='s_u32Duration_0');

      o=ylib_getObj('s_u32Duration_m');
      o.disabled=(obj.value=='s_u32Duration_0');
    }

    function patternChanged(obj)
    {
      // daily
      o=ylib_getObj('sdp_u8Interval');
      o.disabled=(obj.value!='daily');

      // weekly
      o=ylib_getObj('swp_u8Interval');
      o.disabled=(obj.value!='weekly');

      for(i=0;i<7;i++){
        o=ylib_getObj('swp_u8DayMask' + i);
        o.disabled=(obj.value!='weekly');
      }

      // monthly
      o=ylib_getObj('smp_u8DayPattern');
      o[0].disabled=(obj.value!='monthly');
      o[1].disabled=(obj.value!='monthly');

      index=<? echo ($schedule->smp_u8DayPattern == MONTHLY_DAY_PATTERN_DAY) ? 0 : 1 ?>;
      monthPatternChanged(elements['smp_u8DayPattern'][index]);

      for(i=0;i<12;i++){
        o=ylib_getObj('smp_u16MonthMask' + i);
        o.disabled=(obj.value!='monthly');
      }
    }

    function monthPatternChanged(obj)
    {
      obj0=ylib_getObj('s_u8Recurrency');

      o=ylib_getObj('smp_u8DayOfMonth');
      o.disabled=(!obj0[2].checked)||(obj.value!='smp_u8DayPattern_d');

      o=ylib_getObj('smp_u8Ordinal');
      o.disabled=(!obj0[2].checked)||(obj.value!='smp_u8DayPattern_o');

      o=ylib_getObj('smp_u8DayOfWeek');
      o.disabled=(!obj0[2].checked)||(obj.value!='smp_u8DayPattern_o');
    }

    function endDateChanged(obj)
    {
      o=ylib_getObj('s_u8RecurrenceCount');
      o.disabled=(obj.value!='s_u8RangeEndType_1');

      o=ylib_getObj('s_u8MonthEnd');
      o.disabled=(obj.value!='s_u8RangeEndType_2');

      o=ylib_getObj('s_u8DayEnd');
      o.disabled=(obj.value!='s_u8RangeEndType_2');

      o=ylib_getObj('s_u16YearEnd');
      o.disabled=(obj.value!='s_u8RangeEndType_2');
    }

    function checkAllLD()
    {
      form=document.forms[0];
      if(!form.selectall)
        return;
      // the first element might be selectall
      for(i=1;i<form.elements.length;i++){
        if(form.elements[i].name.indexOf('logdrvs')!=-1){
          if(form.elements[i].checked==false){
            form.selectall.checked=false;
            return;
          }
        }
      }
      // all items are checked
      form.selectall.checked=true;
    }

    function selectAll()
    {
      form=document.forms[0];
      // the first element might be selectall
      for(i=1;i<form.elements.length;i++){
        if(form.elements[i].name.indexOf('logdrvs')!=-1)
          form.elements[i].checked=form.selectall.checked;
      }
    }

    function validate()
    {
      
      var r=1;
      elements=document.forms[0].elements;
     
     
      if(elements['s_u8Recurrency'][0].checked) {
    
        var day =elements['sdp_u8Interval'].value ;
        if(!validateNumber(day) ||parseInt(day)>255||parseInt(day)<0){
          alert('<?= gjsalert('invaliddaily') ?>');
          document.forms[0].sdp_u8Interval.focus() ;  
          return false ;
        }  	  
      }
      
      if(elements['s_u8Recurrency'][1].checked)
      {
        var week =elements['swp_u8Interval'].value ;
        if( !validateNumber(week) ||parseInt(week)>255||parseInt(week)<0){
          alert('<?= gjsalert('invalidweek') ?>');
          document.forms[0].swp_u8Interval.focus() ;  
          return false ;
        }
        var c=0,i=0;
        while(elements['swp_u8DayMask'+i]){
          if(elements['swp_u8DayMask'+i].checked) c=1;
          i++;
        }
        if(c==0){
          alert('<?= gjsalert('selectrecurrentweekday') ?>');
          elements['swp_u8DayMask0'].focus();
          return false;
        }
      }
      if(elements['s_u8Recurrency'][2].checked){
        var c=0,i=0;
        while(elements['smp_u16MonthMask'+i]){
          if(elements['smp_u16MonthMask'+i].checked) c=1;
          i++;
        }
        if(c==0){
          alert('<?= gjsalert('selectrecurrentmonth') ?>');
          elements['smp_u16MonthMask0'].focus();
          return false;
        }
      }
      if(!r) return false;


      
      if(<? echo ($_SESSION['sch'] == BGAS_TYPE_RC ? 1 : 0) ?>)
      {
        var ldchecked = false;
        // the first element might be selectall
        for(i=1;i<elements.length;i++)
        {
          if(elements[i].name.indexOf('logdrvs[]')!=-1)
          {
            if(elements[i].checked==true)
            {
              ldchecked = true;
              break;
            }
          }
        }
        if ( !ldchecked )
        {
          alert('<?= gjsalert('ldselect') ?>');
          return false;
        }
      }

      if(<? echo ($_SESSION['sch'] == BGAS_TYPE_BRC ? 0 : 1) ?>) return r; //following for battery only
      if(!elements['s_bEnabled'].checked)
        r=confirm('<?= gjsalert('brscheduledisable') ?>');
      if(!r) return false;
      if(elements['s_u8RangeEndType'][1].checked)
        r=confirm('<?= gjsalert('brscheduleendafter') ?>'+' '+elements['s_u8RecurrenceCount'].value+' '+
          (elements['s_u8RecurrenceCount'].value>1?'<?= gprop('times') ?>':'<?= gprop('time') ?>')+'?');
      if(!r) return false;
      if(elements['s_u8Recurrency'][0].checked&&elements['sdp_u8Interval'].value<30)
        r=confirm('<?= gjsalert('brschedule') ?>'+' '+
          (elements['sdp_u8Interval'].value>1?(elements['sdp_u8Interval'].value+' <?= gprop('days') ?>'):'<?= gprop('day') ?>')+'?');
      if(!r) return false;
      if(elements['s_u8Recurrency'][1].checked&&elements['swp_u8Interval'].value<4)
        r=confirm('<?= gjsalert('brschedule') ?>'+' '+
          (elements['swp_u8Interval'].value>1?(elements['swp_u8Interval'].value+' <?= gprop('weeks') ?>'):'<?= gprop('week') ?>')+'?');
      if(!r) return false;
      if(Math.floor(elements['s_u8MonthStart'].options[elements['s_u16YearStart'].selectedIndex].value)*12+
        Math.floor(elements['s_u8MonthStart'].options[elements['s_u8MonthStart'].selectedIndex].value)><? echo date('m') ?>+1)
        r=confirm('<?= gjsalert('startbatteryrecondition') ?>'+' '+
          (Math.floor(elements['s_u8MonthStart'].options[elements['s_u8MonthStart'].selectedIndex].value)+1)+'/'+
          (Math.floor(elements['s_u8MonthStart'].options[elements['s_u8DayStart'].selectedIndex].value)+1)+'/'+
          (Math.floor(elements['s_u8MonthStart'].options[elements['s_u16YearStart'].selectedIndex].value)+<? echo date('Y') ?>)+'?');
      if(!r) return false;
      if(elements['s_u8RangeEndType'][2].checked)
        r=confirm('<?= gjsalert('brscheduleendon') ?>'+' '+
          (Math.floor(elements['s_u8MonthEnd'].options[elements['s_u8MonthEnd'].selectedIndex].value)+1)+'/'+
          (Math.floor(elements['s_u8MonthEnd'].options[elements['s_u8DayEnd'].selectedIndex].value)+1)+'/'+
          (Math.floor(elements['s_u8MonthEnd'].options[elements['s_u16YearEnd'].selectedIndex].value)+<? echo date('Y') ?>)+'?');
      return r;
    }
</script>
<?php

}
?>

</body>
</html>
