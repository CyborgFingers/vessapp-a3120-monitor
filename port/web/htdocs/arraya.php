<?php

/*******************************************************************************
 * Filename   :arraya.php
 * Description:islaVista disk array automatic configuration.
 * Created on :08/08/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_POST['cancel'])) {
  header('Location: arrays.php?tab=0&note=' . gresult($_GET['act'] == 'expr' ? 'expressconfigcancel' : 'autoconfigcancel'));
  exit;
}

if (isset($_GET['ssid']))
  SetSessionValue('ssid', $_GET['ssid']);

$server = getServer();
$subsystem = $server->getSubsystem();

$physicalDrives = $subsystem->getPhysicalDrives();
$pdCount = getAccessiblePDCount($physicalDrives);
if ($pdCount && $_GET['act'] == 'expr') {
  if (count($_POST)) {
    $aap_bRedundancy = isset($_POST['aap_bRedundancy']);
    $aap_bCapacity = isset($_POST['aap_bCapacity']);
    $aap_bPerformance = isset($_POST['aap_bPerformance']);
    $aap_bSpare = isset($_POST['aap_bSpare']);
    $aap_u16Application = $_POST['aap_u16Application'];
    $aap_u16NumConfigLD = $_POST['aap_u16NumConfigLD'];
    $aap_bMixingDriveType = false;
    $autoUpdate = isset($_POST['autoUpdate']);
  } else if (isset($_GET['autoUpdate'])) {
    $aap_bRedundancy = $_GET['aap_bRedundancy'] == 1 ? true : false;
    $aap_bCapacity = $_GET['aap_bCapacity'] == 1 ? true : false;
    $aap_bPerformance = $_GET['aap_bPerformance'] == 1 ? true : false;
    $aap_bSpare = $_GET['aap_bSpare'] == 1 ? true : false;
    $aap_u16Application = $_GET['aap_u16Application'];
    $aap_u16NumConfigLD = $_GET['aap_u16NumConfigLD'];
    $aap_bMixingDriveType = false;
    $autoUpdate = isset($_GET['autoUpdate']);
  } else {
    $aap_bRedundancy = true;
    $aap_bCapacity = true;
    $aap_bPerformance = true;
    $aap_bSpare = true;
    $aap_bMixingDriveType = false;
    $aap_u16Application = isset($_GET['aap_u16Application']) ? $_GET['aap_u16Application'] : APP_FILE_SERVER;
    $aap_u16NumConfigLD = isset($_GET['aap_u16NumConfigLD']) ? $_GET['aap_u16NumConfigLD'] : 1;
    $autoUpdate = isset($_GET['autoUpdate']) ? $_GET['autoUpdate'] : false;
  }
}

$file = htmlspecialchars($_SERVER['PHP_SELF']) . '?act=' . $_GET['act'];
$_SESSION['lastfile'] = $file;
SyncSession();

$note = isset($_GET['note']) ? $_GET['note'] : null;
$result = isset($_GET['result']) ? $_GET['result'] : 0;
if (count($_POST) && isset($_GET['act']) && !isset($_POST['update'])) {
  table_wait();
  if ($_GET['act'] == 'auto')
    $result = $subsystem->autoConfig();
  else if ($_GET['act'] == 'expr')
    $result = $subsystem->expressConfig($aap_bRedundancy, $aap_bPerformance, $aap_bCapacity, $aap_bSpare, $aap_u16Application, $aap_u16NumConfigLD, $aap_bMixingDriveType);
  if ($result)
    redirectPage($file . '?&result=' . $result . '&note=' . gresult($_GET['act'] == 'expr' ? 'expressconfigfail' : 'autoconfigfail'));
  else
    redirectPage('arrays.php?tab=0&golmm=1&note=' . gresult($_GET['act'] == 'expr' ? 'expressconfigok' : 'autoconfigok'), 1);
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
<form method=post>
  <?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center>
<?php
$title = gprop($_GET['act'] == 'expr' ? 'expressconfig' : 'automaticconfig');
table_title($title, 'diskArray', $note, $result, $_GET['act']);
table_tab_head();

$tabTitle = tabTitleArrays($subsystem);
table_tab($tabTitle);

if ($pdCount > 0) {
  if ($_GET['act'] == 'expr') {
    table_head_fold(gprop('expressconfig'), 'EC', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
    table_row_checkbox(gprop('redundancy'), $aap_bRedundancy, 'aap_bRedundancy');
    table_row_checkbox(gprop('capacity'), $aap_bCapacity, 'aap_bCapacity');
    table_row_checkbox(gprop('performance'), $aap_bPerformance, 'aap_bPerformance');
    table_row_checkbox(gprop('sparedrive'), $aap_bSpare, 'aap_bSpare');
    // table_row_checkbox(gprop('mixingdrivetype'),$aap_bMixingDriveType,'aap_bMixingDriveType');
    table_row_edit(
      gprop('numberoflogicaldrives'),
      $aap_u16NumConfigLD,
      'aap_u16NumConfigLD',
      2,
      30,
      '&nbsp;&nbsp;' . gprop('max') . ': ' . MAX_LOGDRV_PER_ARRAY,
      'msg_u16NumConfigLD'
    );
    $apps = array(
      array(APP_FILE_SERVER, gprop('fileserver')),
      array(APP_VIDEO_STREAM, gprop('videostram')),
      array(APP_TRANSACTION_DATA, gprop('transactiondata')),
      array(APP_TRANSACTION_LOG, gprop('transactionlog')),
      array(APP_OTHER, gprop('other'))
    );
    table_row_combo(gprop('applicationtype'), $apps, 'aap_u16Application', $aap_u16Application);
    $right = "<input type=checkbox name=autoUpdate " . ($autoUpdate ? 'checked ' : '') . " onclick='return autoUpdate0()'>&nbsp;" .
      "<input class=input-p type=submit value=" . gprop('update') . " name=update onclick='return checkNumConfigLD()'>";
    table_row(gprop('automaticupdate'), $right);
    $config = $subsystem->getExpressConfigData($aap_bRedundancy, $aap_bPerformance, $aap_bCapacity, $aap_bSpare, $aap_u16Application, $aap_u16NumConfigLD, $aap_bMixingDriveType);
    table_foot_fold();
    table_tab_foot();
  } else
    $config = $subsystem->getAutoConfigData();
}

if (isset($config) && count($config->_arrays ?? array()) != 0) {
  $arrays = $config->_arrays;
  for ($i = 0; $i < count($arrays); $i++) {
    $array = $arrays[$i];
    if ($_GET['act'] == 'auto' && $i == 0);
    else
      table_tab_head();

    table_head_fold(gprop('diskarray') . ' ' . ($i + 1) . ' - ' . gprop('information'), 'DA' . $i, null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

    $pds = $array->_PDs;
    table_row(gprop('numberofphysicaldrives'), count($pds));
    table_row(gprop('physicaldriveids'), getIDList($pds));
    table_row(gprop('totalconficapacity'), getStringSize(getPDConfiguableSize($physicalDrives, $pds)));
    $lds = $array->_LDs;
    table_row(gprop('numberoflogicaldrives'), count($lds));
    table_foot_fold();

    table_head_fold(gprop('diskarray') . ' ' . ($i + 1) . ' - ' . gprop('logicaldrives'), 'LD' . $i, null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
    $columns = array('#', gprop('raidlevel'), gprop('capacity'), gprop('stripe'), gprop('sector'), gprop('readpolicy'), gprop('writepolicy'));
    $widths = array(10, 15, 15, 15, 15, 15, 15);
    table_header_cols($columns, $widths);

    for ($k = 0; $k < count($lds); $k++) {
      $raid = $lds[$k]->rpal_u16RaidLevel;
      if ($raid == 'RAID30' || $raid == 'RAID50' || $raid == 'RAID51' || $raid == 'RAID55' || $raid == 'RAID60')
        $raid .= (': ' . $lds[$k]->rpal_u16NoOfAxle . 'axles');
      $columns = array(
        $k + 1, $raid, $lds[$k]->rpal_u64Size, $lds[$k]->rpal_u32StripeSize,
        getStringLogDrvSectorSize($lds[$k]->rpal_u8SectorSizeCode),
        getStringLogDrvReadPolicy($lds[$k]->rpal_u32LDAttrib), getStringLogDrvWritePolicy($lds[$k]->rpal_u32LDAttrib)
      );
      table_row_cols($columns);
    }
    table_foot_fold();

    // if($_GET['act']=='auto'&&$i==0)
    table_tab_foot();
  }

  table_head_fold(gprop('sparedrives'), 'SD', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
  $spares = $config->_spares;
  $columns = array('#', gprop('physicaldriveids'), gprop('sparetype'));
  $widths = array(15, 32, 53);
  table_header_cols($columns, $widths);

  for ($i = 0; $i < count($spares); $i++) {
    $spare = $spares[$i];
    if ($spare->rps_bDedicated) {
      $type = gprop('dedicatedtodas');
      for ($k = 0; $k < count($spare->_DAs); $k++)
        $type .= (' ' . $spare->_DAs[$k]);
    } else
      $type = gprop('global');
    $columns = array($i + 1, ($spare->rps_u32PdId + 1), $type);
    table_row_cols($columns);
  }
  if (count($spares) == 0)
    table_row_empty(gresult('nohotspare'), count($columns));

  table_foot_fold();
  if (count($arrays) == 0)
    table_tab_foot();
  table_row_submit(array('reset' => 0));
} else {
  table_head_fold($title, 'AC', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
  if ($pdCount == 0)
    table_row_empty(gresult('nounconfiguredpd'));
  else
    table_row_empty(galert('erroforconfig'));
  table_foot_fold();
  table_tab_foot();
}

page_foot();
?>

  </table>
</form>

<script>
<?php
if ($pdCount && $_GET['act'] == 'expr') {
  ?>
  elements=document.forms[0].elements;
  ylib_addEvt(elements['aap_bRedundancy'],'click',new Function('optionsChanged()'));
  ylib_addEvt(elements['aap_bCapacity'],'click',new Function('optionsChanged()'));
  ylib_addEvt(elements['aap_bPerformance'],'click',new Function('optionsChanged()'));
  ylib_addEvt(elements['aap_bSpare'],'click',new Function('optionsChanged()'));
  // ylib_addEvt(elements['aap_bMixingDriveType'],'click',new Function('optionsChanged()'));
  ylib_addEvt(elements['aap_u16NumConfigLD'],'change',new Function('optionsChanged()'));
  ylib_addEvt(elements['aap_u16Application'],'change',new Function('optionsChanged()'));

  function autoUpdate0()
  {
    if(!elements['update'].disabled && elements['autoUpdate'].checked)
      return optionsChanged();
    elements['update'].disabled=elements['autoUpdate'].checked;
  }

  function optionsChanged()
  {
    if(!elements['autoUpdate'].checked) return;
    if(!checkNumConfigLD()) return false;
    window.self.location.href='?act=expr&aap_bRedundancy='+(elements['aap_bRedundancy'].checked?1:0)+
      '&aap_bCapacity='+(elements['aap_bCapacity'].checked?1:0)+
      '&aap_bPerformance='+(elements['aap_bPerformance'].checked?1:0)+
      '&aap_bSpare='+(elements['aap_bSpare'].checked?1:0)+
      '&aap_u16Application='+elements['aap_u16Application'].value+
      '&aap_u16NumConfigLD='+elements['aap_u16NumConfigLD'].value+
      '&autoUpdate='+(elements['autoUpdate'].checked?1:0);
      elements['aap_u16Application'].disabled=true;
    return true;
  }

  function checkNumConfigLD()
  {
    if(elements['aap_u16NumConfigLD'].value<1||elements['aap_u16NumConfigLD'].value>32){
      document.getElementById("msg_u16NumConfigLD").innerHTML='<?= gjsalert('ldnumber') ?>';
      return false;
    }
    else {
      document.getElementById("msg_u16NumConfigLD").innerHTML='';
      return true;
    }
  }
<?php

}
?>
</script>
</body>

</html>
