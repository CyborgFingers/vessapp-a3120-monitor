<?php

/*******************************************************************************
 * Filename    : logdrvd.php
 * Description : islaVista logical drives deletion page.
 * Created on  : 04/01/03
 *
 * (C) Copyright Promise Technology Inc., 2003
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_POST['cancel'])) {
  header('Location: array.php?tab=0&daid=' . $_GET['daid'] . '&note=' . gresult('deleteldcancel'));
  exit;
}

$server = getServer();
$subsystem = $server->getSubsystem();
$logicalDrives = $subsystem->getLogicalDrives();
if (PI_FB_HOST_INTERFACE_NAS && ($subsystem->frontendType == PI_FB_HOST_INTERFACE_ISCSI))
  $isnas = 1;
else
  $isnas = 0;
if (isset($_GET['daid']))
  SetSessionValue('daid', $_GET['daid']);
SyncSession();

$LDs = null;
$diskArray = $subsystem->getDiskArray($_SESSION['daid']);
for ($i = 0; $i < count($logicalDrives); $i++)
  if ($logicalDrives[$i]->ldi_ArrayId == $diskArray->acd_ArrayID)
  $LDs[] = $logicalDrives[$i];

$note = isset($_GET['note']) ? $_GET['note'] : null;
$result = isset($_GET['result']) ? $_GET['result'] : 0;
if (count($_POST)) {
  for ($i = 0; $i < count($LDs); $i++)
    if (isset($_POST['items'][$i]))
    $lds[] = $LDs[$_POST['items'][$i]]->ldi_FlatId;
  table_wait();
  $result = $subsystem->deleteLogicalDrives($_SESSION['daid'], $lds);
  if ($result)
    redirectPage(htmlspecialchars($_SERVER['PHP_SELF']) . '?result=' . $result . '&note=' . gresult('deleteldfail'));
  else
    redirectPage('array.php?tab=0&note=' . gresult('deleteldok') . '&daid=' . $_SESSION['daid'], 1);
  exit;
}
?>

<html>
<head>
<?php
page_head();
?>
</head>

<body>
<form method="post">
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('logicaldrives'), 'logicaldrive', $note, $result);
table_tab_head();

$tabTitle = tabTitleArray($subsystem, $_SESSION['daid']);
table_tab($tabTitle);

table_head_fold(gprop('deletelogicaldrives'), 'LD', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
$columns = array('', gprop('device'), gprop('alias'), gprop('raidlevel'), gprop('capacity'), gprop('diskarrayid'), gprop('stripe'), gprop('sector'), gprop('status'));
$isnas ? array_push($columns, gprop('fornas')) : null;
$isnas ? $widths = array(6, 10, 12, 12, 10, 10, 10, 10, 12, 8) : $widths = array(6, 10, 14, 14, 10, 10, 12, 12, 12);
table_header_cb_cols($columns, $widths);

for ($i = 0; $i < count($LDs); $i++) {
  $logicalDrive = $LDs[$i];
  $columns = array(
    0, $logicalDrive->ldi_FlatId, $logicalDrive->ldi_LDName, $logicalDrive->ldi_RaidLevel, $logicalDrive->ldi_Size,
    $logicalDrive->ldi_ArrayId, $logicalDrive->ldi_StripeSize, $logicalDrive->ldi_SectorSize, array($logicalDrive->_opStatus, null, getStatusColor($logicalDrive->_opStatus))
  );
  $isnas ? array_push($columns, $logicalDrive->ldi_isNasLd) : null;
  table_row_cb_cols($columns);
}

if (count($logicalDrives) == 0)
  $string = gresult('noldinsubsystem');
else if (count($LDs) == 0)
  $string = gresult('noldinda');
if (isset($string))
  table_row_empty($string, count($columns));

table_foot_fold();
table_tab_foot();

if (count($LDs))
  table_row_submit2(array('submitClick' => 'validate()'));
page_foot();
?>
  </table>
</form>

<script>
  <?php
  if (count($LDs)) {
  ?>
    form=document.forms[0];

    function getreturn(ret)
    {
      var alertmsg="<? echo gjsalert('errconfirm') ?>";
      if(ret==1){
        document.forms[0].submit();
      }
      else if(ret=='cancelled')
        return false;
      else{
        alert(alertmsg);
        return false;
      }
    }

    function validate()
    {
      if (document.getElementById('windowconfirm') != null)
        return false;
      var count=0;
      if(<? echo count($logicalDrives) ?>==0){
        alert("<?= gjsalert('noldstodelete') ?>");
        return false;
      }
      for(i=0;i<form.elements.length;i++)
        if(form.elements[i].name=="items[]"&&form.elements[i].checked) count++;
      if(count==0){
        alert("<?= gjsalert('selectldstodelete') ?>");
        return false;
      } else{
        var string='<?= gjsconfirm('deletelds') ?>';
        // return showConfirm(string);
        showConfirm(string, getreturn);
        return false;
      }
    }
  <?php
  }
  ?>
</script>

</body>
</html>
