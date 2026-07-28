<?php

/*******************************************************************************
 * Filename    : evtsubscribe.php
 * Description : islaVista evnt subscription settings.
 * Created on  : 11/25/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

    if (isset($_POST['cancel'])) {
        header('Location: /users.php?tab=0');
        exit;
    }

if (isset($_GET['ssid']))
  $_SESSION['ssid'] = $_GET['ssid'];

$server = getServer();
$subsystem = $server->getSubsystem();
$severity = $subsystem->evtCusGetStrSeverity($language);
$cstr = $subsystem->evtCusGetStrClass($language);

$note = null;
$result = null;
$status = null;



$count = array(
  array(0, 0, 0, 0, 0, 0, 0), array(0, 0, 0, 0, 0, 0, 0), array(0, 0, 0, 0, 0, 0, 0),
  array(0, 0, 0, 0, 0, 0, 0), array(0, 0, 0, 0, 0, 0, 0)
);
   
$classtype = array(
  // shortcut
  gprop('all'), gprop('enclosure'), gprop('raidcore'), gprop('hostinterface'),
  gprop('driveinterface'),
  // from 5, enclosure
  gprop('blower'), gprop('coolingdevice'),
  gprop('psu'), gprop('temperature'),
  // from 12, raid_core
  gprop('controller'), gprop('diskarray'), gprop('initialization'), gprop('logicaldrive'),
  gprop('ddf'), gprop('mediapatrol'), gprop('oce'), gprop('physicaldisk'),
  /*$cstr->raidlevel_migration, */ gprop('raidmigration'), gprop('rebuild'), gprop('redundancycheck'),
  gprop('smart'),           /*$cstr->stripelevel_migration,*/ gprop('sync'), gprop('subsystem'),
  gprop('pdm'), gprop('sparecheck'), gprop('powermanagement'),
  // from 28 iSCSI
  gprop('frontendcontroller'),
  // from 29 background
  gprop('backendcontroller'),
  );
  //convert class id to UI index
  $class = array(
    5 => EVT_CLASS_BLOWER,
    6 => EVT_CLASS_COOLING_DEVICE,
    7 => EVT_CLASS_PSU,
                    // 10=> EVT_CLASS_SEP,
    8 => EVT_CLASS_TEMPERATURE,
    9 => EVT_CLASS_CONTROLLER,
    10 => EVT_CLASS_DISK_ARRAY,
    11 => EVT_CLASS_INITIALIZATION,
    12 => EVT_CLASS_LOGICAL_DRIVE,
    13 => EVT_CLASS_MDD,
    14 => EVT_CLASS_MEDIA_PATROL,
    15 => EVT_CLASS_ONLINE_CAPACITY_EXPANSION,
    16 => EVT_CLASS_PHYSICAL_DISK,
    // 20=> EVT_CLASS_RAID_LEVEL_MIGRATION,
    17 => EVT_CLASS_RAID_MIGRATION,
    18 => EVT_CLASS_REBUILD,
    19 => EVT_CLASS_REDUNDANCY_CHECK,
    20 => EVT_CLASS_SMART,
    // 25=> EVT_CLASS_STRIPE_LEVEL_MIGRATION,
    21 => EVT_CLASS_SYNCHRONIZATION,
    22 => EVT_CLASS_SUBSYSTEM,
    23 => EVT_CLASS_PDM,
    24 => EVT_CLASS_SPARE_CHECK,
    25 => EVT_CLASS_POWER_MANAGEMENT,
    26 => EVT_CLASS_FRONT_END_CONTROLLER,
    27 => EVT_CLASS_BACK_END_CONTROLLER
  );
  //skip first one all
  $groupsIndex = array(0, 5, 9, 26, 27, 28);

  $emailsetting = $subsystem->getEmailSetting();
  $result = $emailsetting->errorcode;
  if ($result == PIERR_INACTIVE_SESSION) {
    errorOnInvalidSession();
  }
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
    if (!isset($name))
      $name = new stdclass();
    $name->username = $user->username;
    $preference = $subsystem->getUserPreference($name);
    $result = $preference->errorcode;
    if ($result == PIERR_NO_ERROR) {
      for ($i = 0; $i < EVT_CURRENT_MAX_CLASSES; $i++) {
        $eventFilters[] = $preference->eventfilters[$i]->filter;
      }
    }
  }

?>
<html>

<head>
<?php
function getGroupIndex($groupsIndex, $data)
{
  for ($i = count($groupsIndex) - 1; $i >= 0; $i--) {
    if ($data >= $groupsIndex[$i])
      break;
  }
  return $i;
}

function table_row_submit_test($b = array(
  'submit' => 1, 'reset' => 1, 'cancel' => 1,
  'test email' => 0
))
{
  echo '
        <table border=0 cellspacing=0 cellpadding=0 align=center>
        <tr>
            <td>';
  if (!isset($b['reset']) || $b['reset']) {
    echo '
                <input class=input-p type=reset value="' . gprop('reset') . '" name=reset>';
  }
  if (!isset($b['submit']) || $b['submit']) {
    echo '
                <input class=input-p type=submit value="' . gprop('submit') . '" name=submit>';
  }
  if (!isset($b['cancel']) || $b['cancel']) {
    echo '
                <input class=input-p type=submit value="' . gprop('cancel') . '" name=cancel>';
  }
  if (isset($b['test email']) && $b['test email']) {
    echo '
                <input class=input-p type=submit value="' . gprop('emailtest') . '" name=test>';
  }
  echo '
            </td>
        </tr>
        </table>';
}
page_head();
?>
</head>

<body>

<?php
if (isset($_POST['submit']) || isset($_POST['cancel']) || isset($_POST['test'])) {
  $act = null;
  $status = PIERR_NO_ERROR;
  if (isset($_POST['test'])) {
    if (!isset($testemail))
      $testemail = new stdclass();
    $testemail->testuser = $user->username;
    $status = $subsystem->testEmail($testemail);
    $act = 'test email';
  } else if (isset($_POST['submit'])) {
    $preference->username = $user->username;
    if (isset($_POST['enabled']))
      $preference->enabled = 1;
    else
      $preference->enabled = 0;
    for ($i = 0; $i < EVT_CURRENT_MAX_CLASSES; $i++) {
      if (!isset($preference->filters[$i]))
        $preference->filters[$i] = new stdclass();
      $preference->filters[$i]->filter = EVT_SEVERITY_NONE;
    }
    for ($i = $groupsIndex[1]; $i < $groupsIndex[count($groupsIndex) - 1]; $i++) {
      if ($_POST['startuptype' . $i] == 'INFO')
        $filter = EVT_SEVERITY_INFORMATION;
      else if ($_POST['startuptype' . $i] == 'WARNING')
        $filter = EVT_SEVERITY_WARNING;
      else if ($_POST['startuptype' . $i] == 'MINOR')
        $filter = EVT_SEVERITY_MINOR;
      else if ($_POST['startuptype' . $i] == 'MAJOR')
        $filter = EVT_SEVERITY_MAJOR;
      else if ($_POST['startuptype' . $i] == 'CRITICAL')
        $filter = EVT_SEVERITY_CRITICAL;
      else if ($_POST['startuptype' . $i] == 'FATAL')
        $filter = EVT_SEVERITY_FATAL;
      else if ($_POST['startuptype' . $i] == 'NONE')
        $filter = EVT_SEVERITY_NONE;
      $preference->filters[$class[$i] - 1]->filter = $filter;
    }
    $status = $subsystem->setUserPreference($preference, $preference->filters);
    $act = 'set preference';

    $eventFilters = array();
    $preference = $subsystem->getUserPreference($name);
    $result = $preference->errorcode;
    if ($result == PIERR_NO_ERROR) {
      for ($i = 0; $i < EVT_CURRENT_MAX_CLASSES; $i++) {
        $eventFilters[] = $preference->eventfilters[$i]->filter;
      }
    }
  }
  if ($act == 'test email') {
    if ($status == PIERR_NO_ERROR)
      $note = gresult('testemail');
    else
      $note = gerror('testemail');
  } else if ($act == 'set preference') {
    if ($status == PIERR_NO_ERROR)
      $note = gresult('eventsubscriptionsetok');
    else
      $note = gresult('eventsubscriptionsetfail');
  }

}
?>
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center>

<?php
if ($result == PIERR_NO_ERROR)
  table_title(gprop('eventsubscription'), 'user', $note, $status);
else
  table_title(gprop('eventsubscription'), 'user', $note, $status);
table_tab_head();

if (count($users) == 1 && $users[0]->userGroupId != USER_GROUP_SUPER)
  $tabTitle = tabTitleUser();
else
  $tabTitle = tabTitleUsers();

table_tab($tabTitle);

if ($result == PIERR_NO_ERROR) {
  table_head_fold(gprop('generalinfo'), 'setting', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
  table_row(gprop('username'), $user->username);
  table_row(gprop('email'), $user->email);
  table_row_checkbox(gprop('enableeventnotification'), $preference->enabled, 'enabled');
  table_foot_fold();
  table_tab_foot();

  $groups = array('subbyshortcut', 'subbyenclosure', 'subbyraidcore', 'subbyhostinterface', 'subbydriveinterface');
  $groupscript = array('shortcut', 'encl', 'raidcore', 'hostinterface', 'driveinterface');
  $index = 0;
  table_head_fold(gprop($groups[$index]), $groupscript[$index], null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

  // skip all for now
  for ($i = 0; $i < count($classtype); $i++) {
    // skip 0 (all) for now
    if ($i == 0)
      continue;
    if ($i == $groupsIndex[$index + 1]) {
      if ($i > $groupsIndex[1])
        table_foot_fold($groupscript[$index]);
      else
        table_foot_fold();
        // next line causes trouble, take out
        // table_tab_foot();
      $index++;
      table_head_fold(gprop($groups[$index]), $groupscript[$index], null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

    }
    ?>
        <tr>
          <td width=30% class=tableRowCol1><span>
          <?php
          echo $classtype[$i];
          ?>
          </span></td>
          <td width=70% class=tableRowColOther><span>
          <input type=radio
<?php
// offset by 1
if ($i >= $groupsIndex[1] && $eventFilters[$class[$i] - 1] == EVT_SEVERITY_INFORMATION) {
  echo ' checked ';
  $count[getGroupIndex($groupsIndex, $i)][0]++;
}
?>
          name=startuptype<?php echo $i; ?> value=INFO onClick="changeFilter(<?php echo $i; ?>,0)">
              <?php
              echo $severity->info;
              ?>&nbsp;&nbsp;
          <input type=radio
<?php
// offset by 1
if ($i >= $groupsIndex[1] && $eventFilters[$class[$i] - 1] == EVT_SEVERITY_WARNING) {
  echo ' checked ';
  $count[getGroupIndex($groupsIndex, $i)][1]++;
}
?>
          name=startuptype<?php echo $i; ?> value=WARNING onClick="changeFilter(<?php echo $i; ?>,1)">
              <?php
              echo $severity->warning;
              ?>&nbsp;&nbsp;
          <input type=radio
<?php
// offset by 1
if ($i >= $groupsIndex[1] && $eventFilters[$class[$i] - 1] == EVT_SEVERITY_MINOR) {
  echo ' checked ';
  $count[getGroupIndex($groupsIndex, $i)][2]++;
}
?>
          name=startuptype<?php echo $i; ?> value=MINOR onClick="changeFilter(<?php echo $i; ?>,2)">
              <?php
              echo $severity->minor;
              ?>&nbsp;&nbsp;
          <input type=radio
<?php
// offset by 1
if ($i >= $groupsIndex[1] && $eventFilters[$class[$i] - 1] == EVT_SEVERITY_MAJOR) {
  echo ' checked ';
  $count[getGroupIndex($groupsIndex, $i)][3]++;
}
?>
          name=startuptype<?php echo $i; ?> value=MAJOR onClick="changeFilter(<?php echo $i; ?>,3)">
              <?php
              echo $severity->major;
              ?>&nbsp;&nbsp;
          <input type=radio
<?php
// offset by 1
if ($i >= $groupsIndex[1] && $eventFilters[$class[$i] - 1] == EVT_SEVERITY_CRITICAL) {
  echo ' checked ';
  $count[getGroupIndex($groupsIndex, $i)][4]++;
}
?>
          name=startuptype<?php echo $i; ?> value=CRITICAL onClick="changeFilter(<?php echo $i; ?>,4)">
              <?php
              echo $severity->critical;
              ?>&nbsp;&nbsp;
          <input type=radio
<?php
// offset by 1
if ($i >= $groupsIndex[1] && $eventFilters[$class[$i] - 1] == EVT_SEVERITY_FATAL) {
  echo ' checked ';
  $count[getGroupIndex($groupsIndex, $i)][5]++;
}
?>
          name=startuptype<?php echo $i; ?> value=FATAL onClick="changeFilter(<?php echo $i; ?>,5)">
              <?php
              echo $severity->fatal;
              ?>&nbsp;&nbsp;
            <input type=radio
<?php
// offset by 1
if ($i >= $groupsIndex[1] && $eventFilters[$class[$i] - 1] == EVT_SEVERITY_NONE) {
  echo ' checked ';
  $count[getGroupIndex($groupsIndex, $i)][6]++;
}
?>
             name=startuptype<?php echo $i; ?> value=NONE onClick="changeFilter(<?php echo $i; ?>,6)">
              None
          </span></td>
         </tr>
<?php

}

table_foot_fold($groupscript[$index]);
table_tab_foot();

table_row_submit_test();
} // NO_ERROR
else {
  table_head_fold(gprop('eventsubscription'), 'content');
  table_row_empty(gerror('displayeventsubscription'));
  table_foot_fold();
  table_tab_foot();
}
page_foot();
?>
  </table>
</form>
<script>

  elements = document.forms[0].elements;
<?php
  for ($i = 1; $i < $groupsIndex[1]; $i++) {
    for ($j = 0; $j < 7; $j++) {
      if ($count[$i][$j] >= $groupsIndex[$i + 1] - $groupsIndex[$i])
        echo 'initButtons(' . $i . ',' . $j . ');';
    }
  }
?>
  //initButtons(3, 4);  

  function initButtons(index, value)
  {
    if ( index == 1)
      elements.startuptype1[value].checked = true;
    else if ( index == 2)
      elements.startuptype2[value].checked = true;
    else if ( index == 3)
      elements.startuptype3[value].checked = true;
    else if ( index == 4)
      elements.startuptype4[value].checked = true;
  }

  function changeFilter(index, value)
  {
    if ( index == 1){
      <?php
      for ($i = $groupsIndex[1]; $i < $groupsIndex[2]; $i++) {
        echo '
          elements.startuptype' . $i . '[value].checked = true';
      } ?>
    } else if ( index == 2){
      <?php
      for ($i = $groupsIndex[2]; $i < $groupsIndex[3]; $i++) {
        echo '
          elements.startuptype' . $i . '[value].checked = true';
      } ?>
    } else if ( index == 3){
      <?php
      for ($i = $groupsIndex[3]; $i < $groupsIndex[4]; $i++) {
        echo '
          elements.startuptype' . $i . '[value].checked = true';
      } ?>
    } else if ( index == 4){
      <?php
      for ($i = $groupsIndex[4]; $i < $groupsIndex[5]; $i++) {
        echo '
          elements.startuptype' . $i . '[value].checked = true';
      } ?>
    } else if ( index >=5 && index < 8){ // enclosure group
      // check each of 5 to 8
      // if all of them checked, check 1
      // if not everyone checked, uncheck 1
      var count = new Array(7);
      for ( i = 0; i< 7; i++)
        count[i] = 0;
      for ( i = 0; i< 7; i++){
        if ( elements.startuptype5[i].checked)
          count[i] ++;
        if ( elements.startuptype6[i].checked)
          count[i] ++;
        if ( elements.startuptype7[i].checked)
          count[i] ++;
        if ( elements.startuptype8[i].checked)
          count[i] ++;
      }
      for ( i = 0; i< 7; i++){
        if ( count[i] < (9-5))
          elements.startuptype1[i].checked = false;
        else
          elements.startuptype1[i].checked = true;
      }
    } else if ( index >=9 && index < 25){ // raid_core group
      // check each of 9to 25
      // if all of them checked, check 1
      // if not everyone checked, uncheck 1
      var count = new Array(7);
      for ( i = 0; i< 7; i++)
        count[i] = 0;
      for ( i = 0; i< 7; i++){    
        if ( elements.startuptype9[i].checked)
          count[i] ++;
        if ( elements.startuptype10[i].checked)
          count[i] ++;
        if ( elements.startuptype11[i].checked)
          count[i] ++;
        if ( elements.startuptype12[i].checked)
          count[i] ++;
        if ( elements.startuptype13[i].checked)
          count[i] ++;
        if ( elements.startuptype14[i].checked)
          count[i] ++;
        if ( elements.startuptype15[i].checked)
          count[i] ++;
        if ( elements.startuptype16[i].checked)
          count[i] ++;
        if ( elements.startuptype17[i].checked)
          count[i] ++;
        if ( elements.startuptype18[i].checked)
          count[i] ++;
        if ( elements.startuptype19[i].checked)
          count[i] ++;
        if ( elements.startuptype20[i].checked)
          count[i] ++;
        if ( elements.startuptype21[i].checked)
          count[i] ++;
        if ( elements.startuptype22[i].checked)
          count[i] ++;
        if ( elements.startuptype23[i].checked)
          count[i] ++;
        if ( elements.startuptype24[i].checked)
          count[i] ++;
        if ( elements.startuptype25[i].checked)
          count[i] ++;
        }
        for ( i = 0; i< 7; i++){
          if ( count[i] < (26-9))
            elements.startuptype2[i].checked = false;
          else
            elements.startuptype2[i].checked = true;
        }
    } else if ( index >=26 && index < 27){ //iSCSI group
      // check each of 26
      // if all of them checked, check 1
      // if not everyone checked, uncheck 1
      var count = new Array(7);
      for ( i = 0; i< 7; i++)
        count[i] = 0;
      for ( i = 0; i< 7; i++){
        if ( elements.startuptype26[i].checked)
          count[i] ++;
      }
      for ( i = 0; i< 7; i++){
        if ( count[i] < (27-26))
          elements.startuptype3[i].checked = false;
        else
          elements.startuptype3[i].checked = true;
      }
    } else if ( index >=27 && index < 28){ //SATA group
      // check each of 27
      // if all of them checked, check 1
      // if not everyone checked, uncheck 1
      var count = new Array(7);
      for ( i = 0; i< 7; i++)
        count[i] = 0;
      for ( i = 0; i< 7; i++){
        if ( elements.startuptype27[i].checked)
          count[i] ++;
      }
      for ( i = 0; i< 7; i++){
        if ( count[i] < (28-27))
          elements.startuptype4[i].checked = false;
        else
          elements.startuptype4[i].checked = true;
      }
    }
  }


<?php
echo 'var set = ' . $emailsetting->setbyuser . ';';
?>
  if ( !set){
    alert("<?= gjsalert('emailserversetting') ?>");
  }
</script>
</body>

</html>
