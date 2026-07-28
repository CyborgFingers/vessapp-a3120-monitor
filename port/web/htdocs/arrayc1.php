<?php

/*******************************************************************************
 * Filename    : arrayc1.php
 * Description : islaVista disk array creation step 1.
 * Created on  : 06/01/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_POST['next']))
  header('Location: arrayc2.php');

if (isset($_GET['ssid']))
  SetSessionValue('ssid', $_GET['ssid']);

$server = getServer();
$subsystem = $server->getSubsystem();
$physicalDrives = $subsystem->getPhysicalDrives();

if (isset($_GET['act']) && $_GET['act'] == 'create') {
  unset($GLOBALS['_SESSION']['lds']);
  unset($GLOBALS['_SESSION']['pds']);
  unset($GLOBALS['_SESSION']['diskArray']);
}

if (isset($_SESSION['mountpoints']) && (!(isset($_SESSION['lds']))))
  unset($_SESSION['mountpoints']);
function changedPDs($pds0, $pds)
{
  // return true if pds0 and pds are not same
  if (!is_array($pds0)) /* port: first pass has no previous selection (PHP 8: count(null) fatals) */
    return true;
  if (count($pds0) != count($pds))
    return true;
  for ($i = 0; $i < count($pds); $i++) /* port: was count(pds) - PHP 8 fatal */
    if ($pds0[$i] != $pds[$i])
    return true;
  return false;
}

if (isset($_POST['next'])) {
  $pds0 = null;
  if (isset($_SESSION['pds']))
    $pds0 = $_SESSION['pds'];

  for ($i = 0; $i < count($_POST['list2']); $i++)
    if (isset($_POST['list2'][$i]))
    $pds[] = setInteger($_POST['list2'][$i]);
  sort($pds);
  SetSessionValue('pds', $pds);

  if (changedPDs($pds0, $pds))
    unset($_SESSION['lds']);

  $diskArray->rpad_bMediaPatrlEnable = isset($_POST['rpad_bMediaPatrlEnable']) ? 1 : 0;
  $diskArray->rpad_bPDMEnable = isset($_POST['rpad_bPDMEnable']) ? 1 : 0;
  $diskArray->rpad_bPowerManagementIgnored = isset($_POST['rpad_bPowerManagement']) ? 0 : 1;
  $diskArray->rpad_bPowerManagement = isset($_POST['rpad_bPowerManagement']) ? 1 : 0;
  $diskArray->rpad_u8Name = $_POST['rpad_u8Name'];
  SetSessionValue('diskArray', $diskArray);
  SyncSession();
  header('Location: arrayc2.php'); /* port: advance the wizard (the vendor page exited blank) */
  exit;
}
SyncSession();

$pds = array(); /* port: was null - PHP 8 count(null) fatals in the selected-PDs loop */
if (isset($_SESSION['pds']))
  $pds = $_SESSION['pds'];
if (!isset($diskArray))
  $diskArray = new stdclass();
$diskArray->rpad_bMediaPatrlEnable = 1;
$diskArray->rpad_bPDMEnable = 1;
$diskArray->rpad_bPowerManagement = 1;
$diskArray->rpad_u8Name = 'DA' . count($subsystem->getDiskArrays());
if (isset($_SESSION['diskArray']))
  $diskArray = $_SESSION['diskArray'];

$pdCount = getAccessiblePDCount($physicalDrives);
?>
<html>

<head>
<?php
page_head();
?>
<script>
  function fromListToListPD(src)
  {
    ldcount=0;
<?php
    if (isset($_SESSION['lds']))
      echo 'ldcount=' . count($_SESSION['lds']) . ';';
?>
    if (ldcount!=0) {
        sure=confirm(gjsalert('clearlds'));
        if (sure!=true) // drive lists not changed
            return;
    }
    if (src==1)
        fromListToList(document.forms[0].elements['list1[]'],document.forms[0].elements['list2[]']);
    else
        fromListToList(document.forms[0].elements['list2[]'],document.forms[0].elements['list1[]']);
  }
</script>
</head>

<body>
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>" onSubmit="return validateForm()">
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center>
<?php
table_title(gprop('createda'));
table_tab_head();

$tabTitle = tabTitleArrays($subsystem);
table_tab($tabTitle);
$s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner');
if ($pdCount > 0) {
  table_head_fold(gprop('advstep1'), 'AC', null, null, $s);
  table_row_edit(gprop('diskarrayalias'), null, 'rpad_u8Name', 32, 30, null, 'msg_u8Name');
  table_row_checkbox(gprop('mediapatrolenabled'), $diskArray->rpad_bMediaPatrlEnable, 'rpad_bMediaPatrlEnable');
  table_row_checkbox(gprop('enabledrivehealthpolling'), $diskArray->rpad_bPDMEnable, 'rpad_bPDMEnable');
  table_row_checkbox(gprop('PowerManagementenable'), $diskArray->rpad_bPowerManagement, 'rpad_bPowerManagement');
  ?>
      <tr>
        <td class=tableRowCol1 valign=top>
          <span class=text4>
          <?= gprop('physicaldrives') ?>
          </span>
        </td>
        <td class=tableRowColOther>
          <table width=100% cellpadding=0 cellspacing=0 border=0>
            <tr>
              <td width=20% class=text5><?= gprop('available') ?></td>
              <td width=10%></td>
              <td width=70% class=text5><?= gprop('selected') ?></td>
            </tr>
            <tr>
              <td class=text5>
                <select class=selectField size=7 multiple name="list1[]" ondblclick=fromListToListPD(1)>
<?php
for ($i = 0; $i < count($physicalDrives); $i++) {
  $physicalDrive = $physicalDrives[$i];
  //if ($physicalDrive->_cfgStatus=='Unconfigured'&&!($physicalDrive->pdi_Flags&PHYDRV_COND_PFA)&&!isInArray($pds,$physicalDrive->pdi_FlatId))
  if (!$physicalDrive->_cfgEnable && $physicalDrive->_cfgStatus == 'Unconfigured' && !isInArray($pds, $physicalDrive->pdi_FlatId))
    echo '
                  <option value=' . $physicalDrive->pdi_FlatId . '>
                    ID' . ($physicalDrive->pdi_FlatId + 1) . ' : ' . $physicalDrive->pdi_ConfigurableSize . '
                  </option>';
}
?>
                </select>
              </td>
              <td class=text5 align=middle>
                &nbsp;<input class=text4b type=button value=" >> " onClick=fromListToListPD(1)>&nbsp;
                <p>
                &nbsp;<input class=text4b type=button value=" << " onClick=fromListToListPD(2)>&nbsp;
              </td>
              <td class=text5>
                <select class=selectField size=7 multiple name="list2[]" ondblclick=fromListToListPD(2)>
<?php
for ($i = 0; $i < count($pds); $i++) {
  $physicalDrive = getPDFromId($physicalDrives, $pds[$i]);
  if ($physicalDrive->_cfgStatus == 'Unconfigured')
    echo '
                  <option value=' . $physicalDrive->pdi_FlatId . '>
                    ID' . ($physicalDrive->pdi_FlatId + 1) . ' : ' . $physicalDrive->pdi_ConfigurableSize . '
                  </option>';
}
?>
                </select>
              </td>
            </tr>
          </table>
        </td>
      </tr>
<?php

} else {
  table_head_fold(gprop('advancedconfig'), 'AC', null, null, $s);
  table_row_empty(gresult('nounconfiguredpd'));
}

table_foot_fold();
table_tab_foot();

if ($pdCount)
  table_row_submit(array('submit' => 0, 'next' => 1, 'nextClick' => 'validateNext()', 'cancelClick'=>'doCancel()'));
page_foot();
?>
  </table>
</form>

<script>
  function validateNext()
  {
    form=document.forms[0];
    for (i=0; i<form.elements['list2[]'].length; i++)
      form.elements['list2[]'].options[i].selected=true;
    if (form.elements['list2[]'].length>0)
      return true;
    alert('<?= gjsalert('selectpds') ?>');
    return false;
  }

  function validateForm()
  {
    elements=document.forms[0].elements;
    rpad_u8Name=elements['rpad_u8Name'];
    if (!validateAlias(rpad_u8Name.value)) {
      msg_u8Name.innerHTML='<?= gjsalert('invalidalias') ?>';
      rpad_u8Name.focus();
      return false;
    }
    msg_u8Name.innerHTML='';
    return true;
  }

  function doCancel() {
    var redir = 'arrays.php?tab=0&note=<?=gresult('advancedconfigcancel')?>';
    // console.log(redir);
    window.location.href = redir;
  }
</script>
</body>

</html>
