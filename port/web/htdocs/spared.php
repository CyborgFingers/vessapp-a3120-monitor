<?php

/*******************************************************************************
 * Filename    : spared.php
 * Description : Spare drive deletion.
 * Created on  : 06/05/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_POST['cancel'])) {
  header('Location: spares.php?tab=0&note=' . gresult('deletesparecancel'));
  exit;
}

if (isset($_GET['ssid'])) {
  SetSessionValue('ssid', $_GET['ssid']);
  SyncSession();
}

$server = getServer();
$subsystem = $server->getSubsystem();
$spareDrives = $subsystem->getSpareDrives();
$result2 = getLastError();
if ($result2 == PIERR_INACTIVE_SESSION) {
  errorOnInvalidSession();
}
$note = isset($_GET['note']) ? $_GET['note'] : null;
$result = isset($_GET['result']) ? $_GET['result'] : 0;
if (count($_POST)) {
  for ($i = 0; $i < count($spareDrives); $i++)
    if (isset($_POST['items'][$i]))
    $sds[] = $spareDrives[$_POST['items'][$i]]->acd_ArrayID;
  if (isset($sds)) {
    table_wait();
    $result = $subsystem->deleteSpareDrives($sds);
    if ($result)
      redirectPage(htmlspecialchars($_SERVER['PHP_SELF']) . '?result=' . $result . '&note=' . gresult('deletesparefail'));
    else
      redirectPage('spares.php?tab=0&note=' . gresult('deletespareok'), 1);
    exit;
  } else
    $note = galert('sparedrivealreadydelete');
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
table_title(gprop('sparedrives'), 'sparedrive', $note, $result);
table_tab_head();
$s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner');
$tabTitle = tabTitleSpares($subsystem);
table_tab($tabTitle);
if ($result2 == PIERR_NO_ERROR) {
  table_head_fold(gprop('deletesparedrives'), 'SD', null, null, $s);
  $columns = array('', gprop('id'), gprop('opstatus'), gprop('physicaldriveid'), gprop('capacity'), gprop('revertible'), gprop('type'), gprop('dedicatedtodiskarray'));
  $widths = array(6, 4, 12, 12, 10, 8, 8, 20);
  table_header_cb_cols($columns, $widths);

  if (count($spareDrives) == 0)
    table_row_empty(gresult('nospareinsubsystem'), count($columns));
  else {
    for ($i = 0; $i < count($spareDrives); $i++) {
      $spareDrive = $spareDrives[$i];
      $daString = getSpareAssociatedArrays($subsystem, $spareDrive);
      $columns = array(
        0, $spareDrive->acd_ArrayID, $spareDrive->_OpStatus,
        $spareDrive->pdd_PDFlatId + 1, $spareDrive->pdd_PDConfigurableSize,
        $spareDrive->acd_ArrayFlags ? 'Yes' : 'No', $spareDrive->acd_ArrayAttributes, $daString
      );
      table_row_cb_cols($columns);
    }
  }

  table_foot_fold();
  table_tab_foot();

  if (count($spareDrives))
    table_row_submit2(array('submitClick' => 'validate()'));
} //NO_ERROR
else {
  table_head_fold(gprop('deletesparedrives'), 'SD', null, null, $s);
  table_row_empty(gerror('displaydeletesparedrive'));
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
    if(ret==1)
    {
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
    form=document.forms[0];
    var count=0;
    for(i=0;i<form.elements.length;i++)
      if(form.elements[i].name=="items[]"&&form.elements[i].checked) count++;
    if(count==0){
      alert("<?= gjsalert('selectsparetodelete') ?>");
      return false;
    }else{
      var string='<?= gjsconfirm('deletesparedrive') ?>';
      showConfirm(string, getreturn);
      return false;
    }
  }
</script>

</body>
</html>

