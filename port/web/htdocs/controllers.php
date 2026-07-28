<?php

/*******************************************************************************
 * Filename    : controllers.php
 * Description : islaVista controllers list.
 * Created on  : 04/01/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_GET['ssid']))
  SetSessionValue('ssid', $_GET['ssid']);

SyncSession();
$server = getServer();
$subsystem = $server->getSubsystem();
$controllers = $subsystem->getControllers();
$supportDualCtrl = $subsystem->supportdualcontroller();
$note = null;
if (isset($_GET['locate']) && $encls[0]->mpvs_EnclosureType != 0x57) {
  $locateCtrl = $_GET['locate'] - 1;
  $subsystem->locateFRU(0, FRU_TYPE_MB, $locateCtrl);
  $note = gresult('locatectrlmsg');
  header('Refresh: 60;url=controllers.php?ssid=' . $_SESSION['ssid'] . '&tab=0');
}

function tabTitleControllers()
{
  global $treeId;
  $treeId = $_SESSION['ssid'] . 'ctrls';
  $tabTitle = array(array(gprop('information'), 'controllers.php'));
  return $tabTitle;
}
?>

<html>
<head>
<?php
page_head();
function table_row_col($col)
{
  echo '<tr>';
  echo ' <td  bgcolor=#FFFFFF class=textAnchor>' . $col[0] . '</td>';
  for ($i = 1; $i < count($col); $i++) {
    echo ' <td  bgcolor=#FFFFFF class=text5>' . $col[$i] . '</td>';
  }
  echo ' </tr>';
}
?>
</head>

<body>
<form method=post  >
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('controllers'), 'controllers', $note);
table_tab_head();

$tabTitle = tabTitleControllers();
table_tab($tabTitle);

// table_head(array('class'=>'tableBorder'));
// table_head(array('pad'=>0,'space'=>1,'border'=>0));
if ($supportDualCtrl->DualCtrlSupport) {
  $columns = array(gprop('controllerid'), gprop('alias'), gprop('opstatus'), gprop('readinessstatus'), gprop('controllerlocate'));
  $widths = array(15, 22, 15, 18, 15, 15);
} else {
  $columns = array(gprop('controller'), gprop('alias'), gprop('opstatus'));
  $widths = array(15, 22, 23, 24, 16);
}
// table_header(gprop('controllersum'), count($columns));
// table_head(array('pad'=>2,'space'=>1,'border'=>0,'class'=>'tableContentInner'));
table_head_fold(gprop('controllersum'), 'ucontrollersum', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
table_header_cols($columns, $widths);
if ($supportDualCtrl->DualCtrlSupport) {
  if (count($controllers) == 0) {
    $columns = array(gprop('controller') . ' 1', gprop('na'), $controller->_RaidState, $controller->ci_ReadinessStatus, '');
    table_row_col($columns);
    $columns = array(gprop('controller') . ' 2', gprop('na'), $controller->_RaidState, $controller->ci_ReadinessStatus, '');
    table_row_col($columns);
  } else if (count($controllers) == 1) {
    $controller = $controllers[0];
    $ctrlid = $controller->ci_DeviceID + 1;
    if ($ctrlid == 1) {
      $columns = array(
        array(gprop('controller') . '  1', 'controller.php?ssid=0&tab=0&ctid=0'), $controller->ci_UserName,
        $controller->_RaidState, $controller->ci_ReadinessStatus, '<input type=button value="' . gprop('locatecontroller') . ' 1' . '" class=input-p onclick="location.href=\'controllers.php?locate=0\'">'
      );
      table_row_cols($columns);
      // table_row_col(gprop('controller').'  2', gresult('notaccessiblemsg'), count($columns)-1);
      $columns = array(gprop('controller') . '  2', gprop('na'), $controller->_RaidState, $controller->ci_ReadinessStatus, '');
      table_row_col($columns);
    }
    if ($ctrlid == 2) {
      // table_row_col(gprop('controller').'  1', gresult('notaccessiblemsg'), count($columns)-1);
      $columns = array(gprop('controller') . '  2', gprop('na'), $controller->_RaidState, $controller->ci_ReadinessStatus, '');
      table_row_col($columns);
      $columns = array(
        array(gprop('controller') . '  2', 'controller.php?ssid=0&tab=0&ctid=1'), $controller->ci_UserName,
        $controller->_RaidState, $controller->ci_ReadinessStatus, '<input type=button value="' . gprop('locatecontroller') . ' 2" class=input-p onclick="location.href=\'controllers.php?locate=1\'">'
      );
      table_row_cols($columns);
    }
  } else if (count($controllers) == 2) {
    for ($i = 0; $i < 2; $i++) {
      $controller = $controllers[$i];
      if ($controller->ci_ReadinessStatus == 'Active' || $controller->ci_ReadinessStatus == 'Standby') {
        $columns = array(
          array(gprop('controller') . ' ' . ($i + 1), 'controller.php?ssid=0&tab=0&ctid=' . $i),
          preg_replace("/\s/", "&nbsp;", $controller->ci_UserName),
          $controller->_RaidState, $controller->ci_ReadinessStatus, '<input type=button value="' . gprop('locatecontroller') . ' ' . ($i + 1) . '" class=input-p onclick="location.href=\'controllers.php?locate=' . $i . '\'">'
        );
        table_row_cols($columns);
      } else {
        // table_row_col(gprop('controller').' '.($i+1), gresult('notaccessiblemsg'), count($columns)-1);
        $columns = array(gprop('controller') . ' ' . ($i + 1), gprop('na'), $controller->_RaidState, $controller->ci_ReadinessStatus, '');
        table_row_col($columns);
      }
    }
  }
  table_foot_fold();
  table_foot_fold();
  table_foot_fold();
  table_tab_foot();
} else {
  $controller = $controllers[0];
  $ctrlid = $controller->ci_DeviceID + 1;
  $columns = array(
    array(gprop('controller') . ' 1', 'controller.php?ssid=0&tab=0&ctid=0'), $controller->ci_UserName,
    $controller->_RaidState
  );
  table_row_cols($columns);
  table_foot_fold();
  table_tab_foot();
}
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

