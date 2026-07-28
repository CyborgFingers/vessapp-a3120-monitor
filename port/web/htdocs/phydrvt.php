<?php

/*******************************************************************************
 * Filename    : phydrvt.php
 * Description : islaVista physical drive settings.
 * Created on  : 09/21/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';


$specific = 'empty';
if (isset($_GET['force']))
  $specific = 'offline';
else if (isset($_GET['clear']))
  $specific = 'clear';

$server = getServer();
$subsystem = $server->getSubsystem();
$physicalDrives = $subsystem->getPhysicalDrive($_SESSION['pdid']);

$physicalDrive = null;
if (isset($physicalDrives[0]))
  $physicalDrive = $physicalDrives[0];

$phydrv = $subsystem->getPhyDrvSettings($_SESSION['pdid']);

$note = isset($_GET['note']) ? $_GET['note'] : null;
$result = isset($_GET['result']) ? $_GET['result'] : 0;
$pv = getUserPrivilege($subsystem);
if ($pv < USER_GROUP_POWER)
  $note = galert('noauthmodifypdsettings');
if (count($_POST)) {
  if (isset($_GET['force'])) {
    if ($_GET['force'] == 'off')
      $phydrv->pdsp_Flags &= (~PHYDRV_STATE_ONLINE);
    else if ($_GET['force'] == 'on')
      $phydrv->pdsp_Flags |= PHYDRV_STATE_ONLINE;
    $phydrv->pdsp_Flags |= PHYDRV_COND_FORCED;
    $note = gresult('pdforceok') . ' ' . gprop($_GET['force']) . '.';
  } else if (isset($_GET['clear'])) {
    if ($phydrv->pdsp_Flags & PHYDRV_CONFIG_STALE) {
      $phydrv->pdsp_Flags &= (~PHYDRV_CONFIG_STALE);
      $note = gresult('pdsettingsclearok');
    } elseif ($phydrv->pdsp_Flags & PHYDRV_COND_PFA && ($phydrv->pdsp_Flags & DEVICE_CONFIG_MASK) == PHYDRV_CONFIG_UNCONFIGURED) {
      $phydrv->pdsp_Flags &= (~PHYDRV_COND_PFA);
      $note = gresult('pfaclearok');
    }
  } else {
    $alias = $_POST['pdsp_PDAlias'];
    if (isset($alias)) {     
    }
    $phydrv->pdsp_PDAlias = $alias;
    $note = gresult('pdsettingschangeok');
  }
  table_wait();
  $result = $subsystem->setPhyDrvSettings($_SESSION['pdid'], $phydrv);
  if ($result)
    redirectPage($_SERVER['PHP_SELF'] . '?result=' . $result . (isset($_GET['force']) ? ('&force=' . $_GET['force']) : '') . (isset($_GET['clear']) ? '&clear' : '') . '&note=' . gresult('pdssettingschangefail'));
  else
    redirectPage('phydrv.php?tab=0&note=' . $note, isset($_GET['force']) ? 1 : 0);
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
<form method=post onsubmit="return validateForm()"  >
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('physicaldrive') . ' ' . ($_SESSION['pdid'] + 1), null, $note, $result, $specific);
table_tab_head();

$tabTitle = tabTitlePD($subsystem, $physicalDrive);
table_tab($tabTitle);

if ($physicalDrive == null) {
  table_head_fold(gprop('physicaldrivesettings'), 'PD', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
  table_row_empty(galert('nopdinfo'));
} else {
  if (isset($_GET['force'])) {
    if ($phydrv->pdsp_Flags & PHYDRV_STATE_ONLINE) {
      $state = gresult('forceoffline');
      $title = gprop('forcephysicaldriveoffline');
    } else {
      $state = gresult('forceonline');
      $title = gprop('forcephysicaldriveonline');
    }
    table_head_fold($title, 'PD');
    table_row_empty($state);
    echo '<input name="pdstatus" type="text" value="submit" style="display:none"/>';
  } else if (isset($_GET['clear'])) {
    table_head_fold(gprop('clearpdcondition'), 'PD', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
    if ($phydrv->pdsp_Flags & PHYDRV_CONFIG_STALE)
      table_row_empty(gresult('clearstale'));
    elseif ($phydrv->pdsp_Flags & PHYDRV_COND_PFA && ($phydrv->pdsp_Flags & DEVICE_CONFIG_MASK) == PHYDRV_CONFIG_UNCONFIGURED)
      table_row_empty(gresult('clearpfa'));
  } else {
    table_head_fold(gprop('physicaldrivesettings'), 'PD', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
    table_row_edit(gprop('physicaldrivealias'), $phydrv->pdsp_PDAlias, 'pdsp_PDAlias', 32, 30, null, 'msg_PDAlias');
  }
}

table_foot_fold();
table_tab_foot();

if ($physicalDrive) {
  if (isset($_GET['force']))
    table_row_submit2(array('reset' => 0, 'submitClick' => 'validateSubmit()'));
  else if (isset($_GET['clear']))
    table_row_submit(array('reset'=>0,'cancelClick'=>'doCancel()'));
  else
    table_row_submit($pv<USER_GROUP_POWER?array('submit'=>2,'cancelClick'=>'doCancel()'):array('cancelClick'=>'doCancel()'));
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

  function validateSubmit()
  {
    if (document.getElementById('windowconfirm') != null)
      return false;
    <?php
    if ($phydrv->pdsp_Flags & PHYDRV_STATE_ONLINE)
      $string = gconfirm('forceoffline');
    else
      $string = gconfirm('forceonline');
    ?>

    showConfirm("<? echo $string ?>",getreturn);
    return false;
      	
  }

  function validateForm()
  {
    elements=document.forms[0].elements;
    pdsp_PDAlias=elements['pdsp_PDAlias'];     
    // pdsp_PDAlias.value= singleSpace(pdsp_PDAlias.value) ;  
    if (pdsp_PDAlias == null)
      return true;
    if (!validateAlias(pdsp_PDAlias.value)) {
      msg_PDAlias.innerHTML="<?= gjsalert('invalidalias') ?>";
      pdsp_PDAlias.focus();
      return false;
    }
    msg_PDAlias.innerHTML='';
    return true;
  }

  function doCancel() {
    window.location.href = 'phydrv.php?tab=0&note=<?=gprop('physicaldrivesetting').' '.gprop('cancelled')?>';
  }

</script>

</body>
</html>
