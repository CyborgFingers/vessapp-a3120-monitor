<?php

/*******************************************************************************
 * Filename    : arrayd.php
 * Description : islaVista delete disk arrays.
 * Created on  : 07/18/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_POST['cancel'])) {
  header('Location: arrays.php?tab=0&note=' . gresult('deletedacancel'));
  exit;
}

$server = getServer();
$subsystem = $server->getSubsystem();
$diskArrays = $subsystem->getDiskArrays();

$note = isset($_GET['note']) ? $_GET['note'] : null;
$result = isset($_GET['result']) ? $_GET['result'] : 0;
if (count($_POST)) {
  for ($i = 0; $i < count($diskArrays); $i++)
    if (isset($_POST['items'][$i]))
    $das[] = $diskArrays[$_POST['items'][$i]]->acd_ArrayID;
  if (isset($das)) {
    table_wait();
    $result = $subsystem->deleteDiskArrays($das);
    if ($result)
      redirectPage(htmlspecialchars($_SERVER['PHP_SELF']) . '?result=' . $result . '&note=' . gresult('deletedafail'));
    else
      redirectPage('arrays.php?tab=0&note=' . gresult('deletedaok'), 1);
    exit;
  } else
    $note = galert('daalreadydelete');
}
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
table_title(gprop('diskarrays'), 'diskArray', $note, $result);
table_tab_head();

$tabTitle = tabTitleArrays($subsystem);
table_tab($tabTitle);

table_head_fold(gprop('deletedas'), 'DA', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
$columns = array('', gprop('device'), gprop('alias'), gprop('status'), gprop('configurablecapacity'), gprop('freecapacity'));
$widths = array(10, 18, 18, 18, 18, 18);
table_header_cb_cols($columns, $widths);

if (count($diskArrays) == 0)
  table_row_empty(gresult('nodainsubsystem'), count($columns));

for ($i = 0; $i < count($diskArrays); $i++) {
  $diskArray = $diskArrays[$i];
  $columns = array(0, $diskArray->acd_ArrayID, $diskArray->acd_ArrayName, $diskArray->_opStatus, $diskArray->_cfgSize, $diskArray->_availableSize);
  table_row_cb_cols($columns);
}

table_foot_fold();
table_tab_foot();

if (count($diskArrays))
  table_row_submit2(array('submitClick' => 'validateSubmit()'));
page_foot();
?>
  </table>
</form>

<script>
  form=document.forms[0];
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

  function validateSubmit()
  {
    if (document.getElementById('windowconfirm') != null)
      return false;
    var count=0;
    for(i=0;i<form.elements.length;i++)
      if(form.elements[i].name=="items[]"&&form.elements[i].checked) count++;
    if(count==0){
      alert('<?= gjsalert('selectdastodelete') ?>');
      return false;
    }else{
      var string='<?= gjsconfirm('deleteda') ?>';
      // return showConfirm(string);
      showConfirm(string, getreturn);
      return false;
    }
  }
</script>
</body>

</html>
