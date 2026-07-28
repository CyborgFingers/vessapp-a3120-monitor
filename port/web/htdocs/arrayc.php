<?php

/*******************************************************************************
 * Filename    : arrayc.php
 * Description : islaVista disk array creation.
 * Created on  : 09/22/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_POST['next'])) {
  if ($_POST['config'] == 'auto')
    header('Location: arraya.php?act=auto');
  else if ($_POST['config'] == 'expr')
    header('Location: arraya.php?act=expr');
  else if ($_POST['config'] == 'adv')
    header('Location: arrayc1.php?start&act=create');
  exit;
} else if (isset($_POST['cancel'])) {
  header('Location: arrays.php?tab=0&note=' . gresult($_POST['config'] == 'auto' ? 'autoconfigcancel' : ($_POST['config'] == 'expr' ? 'expressconfigcancel' : 'advancedconfigcancel')));
  exit;
}

if (isset($_GET['ssid']))
  SetSessionValue('ssid', $_GET['ssid']);
if (isset($_GET['pdCount']))
  $pdCount = $_GET['pdCount'];

$server = getServer();
$subsystem = $server->getSubsystem();

if (!isset($pdCount)) {
  $phyDrvs = $subsystem->getPhysicalDrives();
  $pdCount = getAccessiblePDCount($phyDrvs);
}

$note = null;
if ($pdCount)
  $note = galert('unconfigpds');
SyncSession();
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
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center>
<?php
table_title(gprop('arrayconfig'), 'config', $note);
table_tab_head();

$tabTitle = tabTitleArrays($subsystem);
table_tab($tabTitle);

table_head_fold(gprop('configmethod'), 'CFG', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

if ($pdCount == 0)
  table_row_empty(gresult('nounconfiguredpd'));
else {
  $content = "&nbsp;&nbsp;" .
    "<input type=radio name=config value=auto checked>&nbsp;" . gprop('autoconfigdesc') .
    "<br>&nbsp;&nbsp;" .
    "<input type=radio name=config value=expr>&nbsp;" . gprop('expressconfigdesc') .
    "<br>&nbsp;&nbsp;" .
    "<input type=radio name=config value=adv>&nbsp;" . gprop('advconfigdesc');
  table_row_nocaption($content);

}
table_foot_fold();
table_tab_foot();

if ($pdCount)
  table_row_submit(array('submit' => 0, 'next' => 1));
page_foot();
?>
  </table>
</form>
</body>

</html>

