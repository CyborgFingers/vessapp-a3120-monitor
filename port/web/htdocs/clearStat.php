<?php

/*******************************************************************************
 * Filename    : clearStat.php
 * Description : islaVista clear statistics.
 * Created on  : 02/16/05
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_GET['ssid']))
  SetSessionValue('ssid', $_GET['ssid']);

$server = getServer();
$subsystem = $server->getSubsystem();
$pv = getUserPrivilege($subsystem);
// $controllers=$subsystem->getControllers();

if (isset($_POST['cancel'])) {
  header('Location: admin.php?tab=0');
  exit;
}

if (isset($_POST['submit'])) {
  $cleared = true;
  $ret = $subsystem->resetStats();
  $result = $ret ? gresult('statisticsclearfail') . ': ' . gerror($ret) : gresult('statisticsclearok');
}
SyncSession();
?>
<html>

<head>
<?php
page_head();
?>
</head>

<body>
<form method=post>
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('clearstatistics'), 'clearstat');

table_tab_head();
$tabTitle = array(array(gprop('clearstatistics'), 'clearStat.php'));
table_tab($tabTitle);
$s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner');
if (!isset($cleared)) {
  table_head_fold(gprop('clearstatistics'), 'CS', null, null, $s);
  table_row_empty(galert('clearstatistics'));
  table_foot_fold();
  table_tab_foot();
  // table_row_submit(array('reset'=>0,'submit'=>($pv<USER_GROUP_SUPER?2:1),
  // 'submitClick'=>'showConfirm(\''.gconfirm('clearstatistics').'\')'));
  table_row_submit2(array(
    'reset' => 0, 'submit' => ($pv < USER_GROUP_SUPER ? 2 : 1),
    'submitClick' => 'validateSubmit()'
  ));
} else {
  table_head_fold(gprop('clearstatistics'), 'CS', null, null, $s);
  table_row_empty($result);
  table_foot_fold();
  table_tab_foot();
}

page_foot();
?>
  </table>
</form>

<script>
  function getreturn(ret)
  {
    var alertmsg="<? echo gjsalert('errconfirm') ?>";
    if(ret==1) {
      var submitbutton = document.createElement('input');
      submitbutton.type = "submit";
      submitbutton.name = "submit";
      submitbutton.style.display = "none";
      document.forms[0].appendChild(submitbutton);
      submitbutton.click();
    } 
    else if (ret == 'cancelled')
      return false;
    else {
      alert(alertmsg);
      return false;
    }
  }

  function validateSubmit()
  {
    if (document.getElementById('windowconfirm') != null)
       return false;
    showConfirm('<?= gjsconfirm('clearstatistics') ?>',getreturn);
    return false;
  }
 </script>
</body>

</html>
