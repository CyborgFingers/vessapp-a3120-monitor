<?php

/*******************************************************************************
 * Filename    : buzzer.php
 * Description : islaVista buzzer info.
 * Created on  : 01/29/04
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';


function SetBuzzerEnable()
{
  global $subsystem;
  global $buzzers;
  $buzzerEnable = isset($_POST['cs_BuzzerEnable']) ? 1 : 0;
  $result = $subsystem->setBuzzerEnable($buzzerEnable);
  if ($result) {
    $note = gresult('buzzerchangefail');
  } else {
    redirectPage('buzzer.php?note=' . gresult('buzzermodifyok'));
    exit;
  }
}

function TurnBuzzerOnOff()
{
  global $subsystem;
  global $buzzers;
  if (isset($_POST['buzzerOff'])) {
    $buzzerStatus = false;
    $onoff = 'off';
  } else {
    $buzzerStatus = true;
    $onoff = 'on';
  }
  $result = $subsystem->turnBuzzerOnOff($buzzerStatus);
  if ($result) {
    $note = gresult('buzzerturn' . $onoff . 'fail');
  } else {
    redirectPage('buzzer.php?note=' . gresult('buzzerturn' . $onoff . 'ok'));
    exit;
  }
}

$_SESSION['ecid'] = 0;
$server = getServer();
$subsystem = $server->getSubsystem();
$buzzers = $subsystem->getBuzzers();
$encls = $subsystem->getEnclosures($_SESSION['ecid']);
$note = isset($_GET['note']) ? $_GET['note'] : null;
$result = PIERR_NO_ERROR;
$sound = false;
$enable = false;

if (count($_POST)) {
  for ($i = 0; $i < count($buzzers); $i++) {
    $ctrl = $subsystem->getControllerSettings($buzzers[$i]->_ctrlId);
    if (isset($_POST['submit']) &&
      $ctrl->cs_BuzzerEnable != (isset($_POST['cs_BuzzerEnable']) ? 1 : 0)) {
      SetBuzzerEnable();
    }
    if (isset($_POST['buzzerOff']) ||
      isset($_POST['buzzerOn'])) {
      if ($ctrl->cs_BuzzerEnable) {
        TurnBuzzerOnOff();
      }
    }
  }
  $buzzers = $subsystem->getBuzzers();
}
?>
<html>

<head>
<?php
page_head();
?>
</head>

<body>
<form method=post  >
  <?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('buzzers'), null, $note, $result);
table_tab_head();

$tabTitle = tabTitleEncl($subsystem, $encls[0]);
table_tab($tabTitle);

if (count($buzzers) == 0) {
  table_head_fold(gprop('buzzerlist'), 'BZ', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
  table_row_empty(gresult('nobuzzer'));
  table_foot_fold();
  table_tab_foot();
}
for ($i = 0; $i < count($buzzers); $i++) {
  if ($buzzers[$i]->bs_Sounding) {
    $sound = true;
  }
  table_head_fold(gprop('buzzer') . ' ' . ($i + 1), 'BZ' . $i, null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
  if (isset($_GET['set'])) {
    $ctrl = $subsystem->getControllerSettings($buzzers[$i]->_ctrlId);
    table_row_checkbox(gprop('buzzerenabled'), $ctrl->cs_BuzzerEnable, 'cs_BuzzerEnable');
  } else {
    $ctrl = $subsystem->getController($buzzers[$i]->_ctrlId);
    if ($ctrl->ci_BuzzerEnable) {
      $enable = true;
    }
    table_row(gprop('buzzer'), getEnableString($ctrl->ci_BuzzerEnable));
    table_row(gprop('buzzerstatus'), gprop($buzzers[$i]->bs_Sounding ? 'sounding' : 'silent'));
  }
  table_foot_fold();
  if ($i == 0) table_tab_foot();
}
if (isset($_GET['set']))
  table_row_submit(array('cancel' => 0));
else if ($sound && $enable) {
  echo '
        <table border=0 cellspacing=2 cellpadding=0 align=center>
          <tr>
            <td>
              <input class=input-p type=submit value="' . gprop('mute') . '" name=buzzerOff>
            </td>
          </tr>
        </table>';
} else if ($enable) {
  echo '
        <table border=0 cellspacing=2 cellpadding=0 align=center>
          <tr>
            <td>
              <input class=input-p type=submit value="' . gprop('sound') . '" name=buzzerOn>
            </td>
          </tr>
        </table>';
}
page_foot();
?>
  </table>
</form>

</body>
</html>
