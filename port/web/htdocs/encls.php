<?php

/*******************************************************************************
 * Filename    : encls.php
 * Description : islaVista enclosures information.
 * Created on  : 09/03/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_GET['ssid']))
  $_SESSION['ssid'] = $_GET['ssid'];

$server = getServer();
$subsystem = $server->getSubsystem(1);
$note = null;
$enclnum = $subsystem->getEnclosures();
$enclnum = count($enclnum);
$numEncl = $subsystem->ssi_JOBExpSSPresent + 1;
if (isset($_GET['locate']) && $encls[0]->mpvs_EnclosureType != 0x57) {
  $subsystem->locateEnclosure($_GET['locate']);
  $note = gresult('locateenclmsg');
  header('Refresh: 60;url=encls.php?ssid=' . $_SESSION['ssid'] . '&tab=0');
}
?>
<html>

<head>
<?php
page_head();
function table_row_col($title, $context, $col)
{
  echo '
            <tr>
            <td  class=tableRowColOther>' . $title . '</td>
            <td colspan=' . $col . ' class=tableRowColOther >' . $context . '</td>
            </tr>';
}
?>
</head>

<body>
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center>
<?php
table_title(gprop('enclosures'), 'controllers', $note);
table_tab_head();

$tabTitle = tabTitleEncls($subsystem);
table_tab($tabTitle);

// table_head(array('class'=>'tableBorder'));
// table_head(array('pad'=>0,'space'=>1,'border'=>0));

$columns = array(gprop('enclosureid'), gprop('enclosuretype'), gprop('overallstatus'), gprop('description'), gprop('enclosurelocate'));
$widths = array(20, 20, 20, 25, 15);

// table_header(gprop('enclosuresummary'), count($columns));
// table_head(array('pad'=>2,'space'=>1,'border'=>0,'class'=>'tableContentInner'));
table_head_fold(gprop('enclosuresummary'), 'encl', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
table_header_cols($columns, $widths);

if ($enclnum == 0) {
  table_row_empty(gresult('notaccessiblemsg'), count($columns));
} else {
  for ($i = 0; $i < $numEncl; $i++) {
    $encls = $subsystem->getEnclosures($i);
    if (count($encls) != 0) {
      $encl = $encls[0];
      $columns = array(
        array(
          gprop('enclosure') . ' ' . ($i + 1) . (($i == 0 && $numEncl > 1) ? (' (' . gprop('headunit') . ')') : ''),
          'encl.php?ssid=0&tab=0&ecid=' . $i
        ), $encl->_EnclosureTypeString, $encl->_EnclosureOverallStatus, $encl->_EnclosureDescription,
        '<input type=button value="' . gprop('locateencl') . ' ' . ($i + 1) . '" class=input-p onclick="location.href=\'encls.php?locate=' . $i . '\'">'
      );
      table_row_cols($columns);
    } else
      table_row_col(gprop('enclosure') . ' ' . ($i + 1), gresult('notaccessiblemsg'), count($columns) - 1);

  }
}
table_foot_fold();
table_foot_fold();
table_tab_foot();
table_tab_foot();
page_foot();
?>
  </table>
</form>
</body>

</html>
