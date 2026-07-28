<?php

/*******************************************************************************
 * Filename    : subsystems.php
 * Description : islaVista subsystems information.
 * Created on  : 04/01/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$server = getServer();
$subsystems = $server->getSubsystems();

function tabTitleSubsystems()
{
  global $treeId;
  $treeId = 'subsyss';
  $tabTitle = array(array(gprop('information'), 'subsystems.php'));
  return $tabTitle;
}
?>

<html>
<head>
<?php
page_head();

if (isset($_POST['submit'])) {
  getmultibox(0);
  refreshTreeFrame();
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
table_title(gprop('subsystems'));
table_tab_head();

$tabTitle = tabTitleSubsystems();
table_tab($tabTitle);

table_head_fold(gprop('subsyslist'), 'SS', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

$columns = array(gprop('ipaddress'), gprop('alias'), gprop('model'), gprop('wwn'));
$widths = array(25, 25, 25, 25);

table_header_cols($columns, $widths);

if (count($subsystems) == 0)
  table_row_empty(gresult('nosubsystem'), count($columns));
else {

  if (isset($_COOKIE['networkview']) && $_COOKIE['networkview']) {
    $multibox = getmultibox();
    $index = 0;
    $match = false;
    for ($i = 0; $i < count($multibox); $i++) {
      if (!strcmp($multibox[$i]->ip, $subsystems[0]->name)) {
        $match = true;
        break;
      }
    }
    if (!$match) {
      $tmp->ip = $subsystems[0]->name;
      $multibox[] = $tmp;
    }
    usort($multibox, "cmp");

    $index = 0;
    $match = false;
    for ($i = 0; $i < count($multibox); $i++) {
      if (!strcmp($multibox[$i]->ip, $subsystems[0]->name)) {
        $match = true;
        break;
      }
    }
    if ($match) {
      $index = $i;
    }

    for ($i = 0; $i < $index; $i++) {
      $columns = array(
        $multibox[$i]->ip, $multibox[$i]->alias,
        $multibox[$i]->model, $multibox[$i]->WWN
      );
      table_row_cols($columns);
    }
  }
  for ($i = 0; $i < count($subsystems); $i++) {
    $subsystem = $subsystems[$i]->instance;
    $columns = array(
      $subsystems[$i]->name . ' (current)', $subsystem->ssi_UserName,
      $subsystem->ssi_CustModel, $subsystem->ssi_WWN
    );
    table_row_cols($columns);
  }
  if (isset($_COOKIE['networkview']) && $_COOKIE['networkview']) {
    for ($i = $index + 1; $i < count($multibox); $i++) {
      $columns = array(
        $multibox[$i]->ip, $multibox[$i]->alias, $multibox[$i]->model,
        $multibox[$i]->WWN
      );
      table_row_cols($columns);
    }
  }
}

table_foot_fold();
table_tab_foot();
if (isset($_COOKIE['networkview']) && $_COOKIE['networkview'])
  table_row_submit(array('reset' => 0, 'cancel' => 0), $submit = gprop('discover'));
page_foot();
?>
  </table>
</form>

</body>
</html>

<?php
if (isset($_SESSION['pegasus']))
  $server->logout();
?>

