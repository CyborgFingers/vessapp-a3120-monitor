<?php

/*******************************************************************************
 * Filename    : arrayt.php
 * Description : islaVista disk array settings.
 * Created on  : 04/01/03
 *
 * (C) Copyright Promise Technology Inc.,2020
 * All Rights Reserved
 ******************************************************************************/

include 'tree.php';
include 'common.php';


$specific = 'empty';
if (isset($_GET['act']))
  $specific = 'transport';
else
  $specific = 'setting';

$server = getServer();
$subsystem = $server->getSubsystem();
$diskArrays = $subsystem->getArraySettings($_SESSION['daid']);
$diskArray = $diskArrays[0];

$note = null;
$result = 0;
$pv = getUserPrivilege($subsystem);
if ($pv < USER_GROUP_POWER)
  $note = 'Not authorized to modify disk array settings.';

if (isset($_POST['submit']) && isset($_GET['act'])) {
  $result = $subsystem->prepareArrayForTransport($_SESSION['daid']);
  if ($result == 0) {
    redirectPage('array.php?tab=0&note=' . gresult('transportdaok'));
    exit;
  } else
    $note = gresult('transportdafail');
} elseif (isset($_POST['submit']) || isset($_POST['as_ArrayName'])) {
  $diskArray->as_ArrayName = $_POST['as_ArrayName'];
  if (isset($_POST['ARRAY_FLAG_MP_ENABLE']))
    $diskArray->as_Flags |= ARRAY_FLAG_MP_ENABLE;
  else
    $diskArray->as_Flags &= ~ARRAY_FLAG_MP_ENABLE;
  if (isset($_POST['ARRAY_FLAG_PDM_ENABLE']))
    $diskArray->as_Flags |= ARRAY_FLAG_PDM_ENABLE;
  else
    $diskArray->as_Flags &= ~ARRAY_FLAG_PDM_ENABLE;
  if (isset($_POST['as_PowerManagement']))
    $diskArray->as_IgnorePowerManagement = false;
  else
    $diskArray->as_IgnorePowerManagement = true;

  $result = $subsystem->setArraySettings($diskArray);
  if ($result == 0) {
    redirectPage('array.php?tab=0&note=' . gresult('dasettingchangeok'));
    exit;
  } else
    $note = gresult('dasettingchangefail');
}
?>
<html>

<head>
<?php
page_head();
?>
</head>

<body>
<form method=post onsubmit="return validateForm()"  >
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center
    bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('diskarray') . ' ' . $diskArray->as_ArrayId, 'diskArray', $note, $result, $specific);
table_tab_head();

$tabTitle = tabTitleArray($subsystem, $diskArray->as_ArrayId);
table_tab($tabTitle);
$s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner');
if (!isset($_GET['act'])) {
  table_head_fold(gprop('diskarraysetting'), 'DA', null, null, $s);
  table_row_edit(gprop('diskarrayalias'), $diskArray->as_ArrayName, 'as_ArrayName', 32, 30, null, 'msg_ArrayName');
  table_row_checkbox(gprop('mediapatrolenabled'), $diskArray->as_Flags & ARRAY_FLAG_MP_ENABLE, 'ARRAY_FLAG_MP_ENABLE');
  table_row_checkbox(gprop('enabledrivehealthpolling'), $diskArray->as_Flags & ARRAY_FLAG_PDM_ENABLE, 'ARRAY_FLAG_PDM_ENABLE');
  table_row_checkbox(gprop('PowerManagementenable'), $diskArray->as_PowerManagement, 'as_PowerManagement');
} else {
  table_head_fold(gprop('preparedafortransport'), 'DA', null, null, $s);
  table_row_empty(galert('preparedafortransport'));
}

table_foot_fold();
table_tab_foot();

if (isset($_GET['act']))
  table_row_submit2(array('reset' => 0, 'submitClick' => 'validateSubmit()'));
else
  table_row_submit($pv<USER_GROUP_POWER?array('submit'=>2,'cancelClick'=>'doCancel()'):array('cancelClick'=>'doCancel()'));
page_foot();
?>
  </table>
</form>
<script>
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

  function validateSubmit()
  {
    if (document.getElementById('windowconfirm') != null)
      return false;
    showConfirm('<?= gjsconfirm('preparedafortransport') ?>',getreturn);
    return false;
  }

  function validateForm()
  {
    elements=document.forms[0].elements;
    as_ArrayName=elements['as_ArrayName'];
    if(as_ArrayName){
      if(!validateAlias(as_ArrayName.value)) {
        msg_ArrayName.innerHTML='<?= gjsalert('invalidalias') ?>';
        as_ArrayName.focus();
        return false;
      }
      msg_ArrayName.innerHTML='';
    }
    return true;
  }

  function doCancel() {
    window.location.href = 'array.php?tab=0&note=<?=gresult('dasettingcancel')?>';
  }
</script>
</body>

</html>
