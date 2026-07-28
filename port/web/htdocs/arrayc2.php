<?php

/*******************************************************************************
 * Filename    : arrayc2.php
 * Description : islaVista disk array creation step 2.
 * Created on  : 06/01/03
 *
 * (C) Copyright Promise Technology Inc.,2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';
include 'ldcfg.inc';

if (isset($_POST['back'])) {
  header('Location: arrayc1.php');
  exit;
} else if (isset($_POST['next'])) {
  header('Location: arrayc3.php');
  exit;
} else if (isset($_POST['cancel'])) {
  header('Location: arrays.php?tab=0&note=' . gresult('advancedconfigcancel'));
  exit;
}

if (isset($_GET['ssid']))
  SetSessionValue('ssid', $_GET['ssid']);

$server = getServer();
$subsystem = $server->getSubsystem(1);
$numberOfCtlPresent = $subsystem->ssi_NumberOfCtlPresent;
$subsystem->getDeviceCapabilities();
$physicalDrives = $subsystem->getPhysicalDrives();
$isSupportLUNAffinity = $subsystem->getLunAffinitySupport();
$subsystem = $server->getSubsystem();
$currentCtrlId = $subsystem->getCurrentCtrlId();
$ctrlId = $currentCtrlId->currentCtrlId;
$controller = $subsystem->getController($ctrlId);
/*
if (isset($_SESSION['mountpoints'])) {
  $mountpointstr = $_SESSION['mountpoints'];
  $mountcount = count($mountpointstr);
} else {
  $mountpointstr = array();
  $mountcount = 0;
}
 */
$selectline = '';
$pds = isset($_SESSION['pds']) ? $_SESSION['pds'] : array(); /* port: PHP 8 count(null) fatals */
$raidLevels = getSupportedRAIDLevels(count($pds));
$pdsize = getPDConfiguableSize($physicalDrives, $pds);
$Nasld = 0;
$logicalDrives = array(); /* port: was null - PHP 8 count(null) fatals */
if (isset($_SESSION['lds'])) {
  $logicalDrives = $_SESSION['lds'];
  /*
  for ($i = 0; $i < count($logicalDrives); $i++) {
    if ($logicalDrives[$i]->rpal_u32LDFlags == 'yes')
      $Nasld++;
  }
   */
}
global $sizeUnits;
?>
<html>

<head>
<?php
page_head();

$note = null;
$ldindex = -1;

if (isset($_POST['ldindex']))
  $ldindex = $_POST['ldindex'];

if (isset($_POST['update'])) {
  $pow = pow(1024, $_POST['rpal_u64Size1']);
  $tempMax = $_POST['rpal_u64Size3'] / $pow;
  $stripeSizeStr = $_POST['rpal_u32StripeSize'];
  settype($stripeSizeStr, "integer");
  if (isset($_POST['rpal_u8SectorSizeCode']))
    $sectorstr = $sectorSize[$_POST['rpal_u8SectorSizeCode'] - 1][1];
  else
    $sectorstr = $sectorSize[0][1];
  $ret = substr($sectorstr, -1);
  $sectorSizeStr = null;
  $sectorUnitStr = null;
  if ($ret == 'B') // unit=kb
  {
    $sectorSizeStr = substr($sectorstr, 0, strlen($sectorstr) - 3); /* port: was count() - PHP 8 fatal */
    settype($sectorSizeStr, "integer");
    $sectorSizeStr = $sectorSizeStr * 1024;
    $sectorUnitStr = 'KB';
  } else {
    $sectorSizeStr = substr($sectorstr, 0, strlen($sectorstr) - 6); /* port: was count() - PHP 8 fatal */
    settype($sectorSizeStr, "integer");
    $sectorSizeStr = $sectorSizeStr;
    $sectorUnitStr = 'B';
  }


  if ($_POST['rpal_u64Size'] == 0)
    $note = galert('invalidcapacity');
  else if (($sizeUnits[$_POST['rpal_u64Size1']] == 'B') && ($_POST['rpal_u64Size'] < 2 * 1024 * $stripeSizeStr))
    $note = galert('invalidcapacity');
  else if (($sizeUnits[$_POST['rpal_u64Size1']] == 'KB') && ($_POST['rpal_u64Size'] < 2 * $stripeSizeStr))
    $note = galert('invalidcapacity');
  // else if($_POST['rpal_u64Size']-$_POST['rpal_u64Size3']/$pow>=0.01)
  else if ($_POST['rpal_u64Size'] > $tempMax)
    $note = galert('exceedmaxcapacity');
  else {
    $bconflict = false;
    if ($ldindex >= 0)
      $logicalDrive = $logicalDrives[$ldindex];
    /*
    if (isset($_POST['rpal_u32LDFlags'])) {
      if (!($Nasld > 4 || (($ldindex < 0) && ($Nasld == 4)))) {

        if ($_POST['rpal_u32LDFlags'] == 'NAS') {
          $mountpointtmp = $_POST['rpal_mountpoint'];
          $selectline = $_POST['mountpointhidden'];

          if ($ldindex >= 0)
            for ($i = 0; $i < count($mountpointstr); $i++) {
            if (strcmp($mountpointstr[$i], $selectline) == 0)
              array_splice($mountpointstr, $i, 1);
          }

          for ($mount = 0; $mount < count($mountpointstr); $mount++) {
            if (!(strcmp($mountpointtmp, $mountpointstr[$mount]) == 0)) {
              continue;
            } else {
              $bconflict = true;
              $note = galert('mountpointconflict');
            }
          }
          if (!$bconflict) {
            $mountpointstr[] = $_POST['rpal_mountpoint'];
            $logicalDrive->rpal_u8Name = $_POST['rpal_mountpoint'];
          }
        } else {
          $selectline = $_POST['mountpointhidden'];
          $mountpointtmp = $selectline;
          for ($deletcount = 0; $deletcount < $mountcount; $deletcount++) {
            if (strcmp($mountpointtmp, $mountpointstr[$deletcount]) == 0) {
              array_splice($mountpointstr, $deletcount, 1);
              break;
            }
          }
          $logicalDrive->rpal_u8Name = $_POST['rpal_u8Name'];
        }
        SetSessionValue('mountpoints', $mountpointstr);
      }
    }
     */
    if (!isset($logicalDrive))
      $logicalDrive = new stdclass();
    $logicalDrive->rpal_u8Name = $_POST['rpal_u8Name'];
    $logicalDrive->rpal_u16RaidLevel = setInteger($_POST['rpal_u16RaidLevel']);
    $logicalDrive->rpal_u16NoOfAxle = isset($_POST['rpal_u16NoOfAxle']) ? setInteger($_POST['rpal_u16NoOfAxle']) : 0;
    if ($logicalDrive->rpal_u16RaidLevel == RAID10 || $logicalDrive->rpal_u16RaidLevel == RAID51)
      $logicalDrive->rpal_u16NoOfAxle = 2;
    $logicalDrive->rpal_u32StripeSize = setInteger($_POST['rpal_u32StripeSize']);
    if (isset($_POST['rpal_u8SectorSizeCode']))
      $logicalDrive->rpal_u8SectorSizeCode = setInteger($_POST['rpal_u8SectorSizeCode']);
    else
      $logicalDrive->rpal_u8SectorSizeCode = 1;
    $logicalDrive->_writePolicy = isset($_POST['_writePolicy']) ? setInteger($_POST['_writePolicy']) : 0;
    $logicalDrive->_readPolicy = setInteger($_POST['_readPolicy']);
    if ($_POST['rpal_u64Size'] == floor($_POST['rpal_u64Size3'] / $pow * 100) / 100)
      $logicalDrive->rpal_u64Size = $_POST['rpal_u64Size3'];
    else
      $logicalDrive->rpal_u64Size = double2int($_POST['rpal_u64Size'] * $pow);
    $logicalDrive->_u64SizeDisplay = $_POST['rpal_u64Size'];
    $logicalDrive->_strSizeUnit = $_POST['_strSizeUnit'];
    $logicalDrive->rpal_u64Size1 = $_POST['rpal_u64Size1'];

    if (isset($_POST['rpal_u16PreferredCtrlId']))
      $logicalDrive->rpal_u16PreferredCtrlId = setInteger($_POST['rpal_u16PreferredCtrlId']);
    else
      $logicalDrive->rpal_u16PreferredCtrlId = 2;
    /*
    if (isset($_POST['rpal_u32LDFlags']) && $_POST['rpal_u32LDFlags'] == 'NAS') {
      // if($Nasld>4||(($ldindex<0)&&($Nasld==4)))
        // $note=galert('maxnasld').' 4'.'.';
      $logicalDrive->rpal_u32LDFlags = "yes";
    }
     */
    $logicalDrive->rpal_u32LDFlags = "no";
    /*
    if (abs($_POST['rpal_u64Size3'] / $pow - $_POST['rpal_u64Size']) < 0.01)
      $logicalDrive->rpal_u64Size = $_POST['rpal_u64Size3'];
    if (abs($_POST['rpal_u64Size3'] / $pow - $_POST['rpal_u64Size']) < 0.01)
      $logicalDrive->_u64SizeDisplay = $_POST['rpal_u64Size3'];
     */
    if ($ldindex >= 0)
      $logicalDrives[$ldindex] = $logicalDrive;
    else {
      if (count($logicalDrives) >= MAX_LOGDRV_PER_ARRAY)
        $note = galert('maxldis') . MAX_LOGDRV_PER_ARRAY . '.';
      else if ((!$bconflict)) /*&&(($logicalDrive->rpal_u32LDFlags=="no") || ($Nasld<4)))*/
      {
        $logicalDrives[] = $logicalDrive;
        // $logicalDrive->rpal_u8Name=getNextName($logicalDrive->rpal_u8Name);
        $_POST['rpal_u8Name'] = $logicalDrives[count($logicalDrives) - 1]->rpal_u8Name;
      }
    }
  }

  // unselect any row
  $ldindex = -1;
} else if (isset($_POST['delete'])) {
  if ($ldindex >= 0) {
    array_splice($logicalDrives, $ldindex, 1);
  }
  if ($ldindex >= 0 && !isset($logicalDrives[$ldindex]))
    $ldindex--;
}
$lastld = getLastLD($subsystem, $logicalDrives, $ldindex, $pdsize);
?>

<script>
  var logicalDrives=new Array;

<?php
  for ($i = 0; $i < count($logicalDrives); $i++) {
    $logicalDrive = $logicalDrives[$i];
    echo '
            var logicalDrive=new Array;
            logicalDrive[0]="' . $logicalDrive->rpal_u8Name . '";
            logicalDrive[1]=' . $logicalDrive->rpal_u16RaidLevel . ';
            logicalDrive[2]=' . $logicalDrive->rpal_u16NoOfAxle . ';
            logicalDrive[3]=' . $logicalDrive->rpal_u32StripeSize . ';
            logicalDrive[4]=' . $logicalDrive->_writePolicy . ';
            logicalDrive[5]=' . $logicalDrive->_readPolicy . ';
            logicalDrive[6]=' . $logicalDrive->_u64SizeDisplay . ';
            logicalDrive[7]=' . $logicalDrive->rpal_u8SectorSizeCode . ';
            logicalDrive[8]=0;
            logicalDrive[9]=' . $logicalDrive->rpal_u64Size . ';
            logicalDrive[10]=' . $logicalDrive->rpal_u64Size1 . ';
            logicalDrive[11]=' . $logicalDrive->rpal_u16PreferredCtrlId . ';
            logicalDrive[12]="' . $logicalDrive->rpal_u32LDFlags . '";
            logicalDrives[' . $i . ']=logicalDrive;';
  }
?>

  function getObjIndex(obj,name)
  {
    for(var i=0;i<<? echo MAX_LOGDRV_PER_ARRAY ?>;i++){
      id=name+i;
      if(ylib_getObj(id)==obj) return i;
    }
    return -1;
  }

  function recordaxles(index)
  {
    if(index<0) return;
    logicalDrive=logicalDrives[index];
    elements=document.forms[0].elements;
    if(elements['origRaidLevel']==null&&elements['rpal_u16NoOfAxle'])
      elements['rpal_u16NoOfAxle'].value=logicalDrive[2];
  }
  
  function selectLD(line)
  {
    var index;
    unselectAllRows();
    index=getObjIndex(line,'ld');

    // select current line
    line.style.background='#0078D8';

    updateLD(index);
    updateSizes(index);
    recordaxles(index);
    document.forms[0].elements['ldindex'].value=index;
    updateMaxSize(getMaxSize(index,logicalDrives[index][1],logicalDrives[index][2],logicalDrives[index][3]));
      // document.forms[0].elements['mountpointhidden'].value=document.forms[0].elements['rpal_mountpoint'].value;
  }

  function selectSize(obj)
  {
    id='ld'+getObjIndex(obj,'size');
    obj=ylib_getObj(id);
    if(obj) selectLD(obj);
  }

  function axlesChanged()
  {
    updateLDMaxSize();
  }

  function getPercentage(logicalDrive)
  {
    p=getPDSize(logicalDrive,<? echo count($pds) ?>)*100/<? echo $pdsize ?>;
    if(p<1) p=1;
    return Math.round(p);
  }

  function getUsedSize(index)
  {
    usedSize=0;
    for(i=0;i<logicalDrives.length;i++)
      if(i!=index) usedSize+=getPDSize(logicalDrives[i],<? echo count($pds) ?>);
    return usedSize;
  }

  function getMaxSize(index,raidLevel,axles,stripe)
  {
    pdCount=<? echo count($pds) ?>;
    size=<? echo $pdsize ?>-getUsedSize(index);
    var tmpMax = 0;

    if(!document.forms[0].elements['_numLDs'])
      tmpMax = getLDSize(size,raidLevel,axles,stripe,pdCount);
    else if(document.forms[0].elements['_numLDs'].value)
      tmpMax = getLDSize(size/document.forms[0].elements['_numLDs'].value,raidLevel,axles,stripe,pdCount);

    if (1024*1024 > tmpMax)
      tmpMax = 0;

    return tmpMax;
  }

  function updateSizes(index)
  {
    if(index<0) return;
    for(i=0;i<logicalDrives.length;i++){
      id='size'+i;
      if(!ylib_getObj(id)) continue;
      ylib_getObj(id).style.backgroundImage='';
      if(i==index)
        ylib_getObj(id).style.backgroundImage='url(images/select.gif)';
      else
        ylib_getObj(id).style.backgroundColor='<? echo $USED_COLOR; ?>';
    }
  }

  function numLDsChanged()
  {
    updateMaxSize(getMaxSize(elements['ldindex'].value,elements['rpal_u16RaidLevel'].value,
      elements['rpal_u16NoOfAxle'].value,elements['rpal_u32StripeSize'].value));
  }
</script>

</head>

<body onclick="unselectLD(event)">
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method="post" action="<?php echo $action_url; ?>">
<?php form_csrf(); ?>
  <table width="100%" border="0" cellspacing="0" cellpadding="0" align="center" class="tableMain">
<?php
table_title(gprop('createda'), 'array_create', $note);
table_tab_head();
$s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner');
$tabTitle = tabTitleArrays($subsystem);
table_tab($tabTitle);

table_head_fold(gprop('advstep2'), 'SEL', null, null, $s);

$leftWidth = 25;
table_row_edit(gprop('alias'), null, 'rpal_u8Name', 32, $leftWidth, null, 'msg_u8Name');
/*
for ($i = 0; $i < (MAX_LOGDRV_PER_ARRAY - count($logicalDrives)); $i++)
  $numLDs[] = array($i + 1, $i + 1);
if (MAX_LOGDRV_PER_ARRAY == count($logicalDrives))
  $numLDs[] = array(1, 1);
table_row_combo('Number of Same Logical Drives', $numLDs, '_numLDs', 1); 
 */ 
// RAID Level
$raidLevel = array(); /* port: PHP 8 count(null) fatals on a no-PD GET */
for ($i = 0; $i < count($raidLevels); $i++)
  $raidLevel[] = array($raidLevels[$i], getStringRAIDLevel($raidLevels[$i]));
?>
    <tr>
      <td class="tableRowCol1"><span><?= gprop('raidlevel') ?></span></td>
      <td class="tableRowColOther"><span>
        <select class="editField" name="rpal_u16RaidLevel" onchange="raidLevelChanged()">
<?php
for ($i = 0; $i < count($raidLevel); $i++)
  echo '<option value=' . $raidLevel[$i][0] . ($lastld[1] == $raidLevel[$i][0] ? ' selected' : '') . '>' . $raidLevel[$i][1] . '</option>';
echo '
        </select>';
// number of axles
$axles = 1;
$axlesRaid30 = array(); /* port: were null - PHP 8 count(null) fatals when no axle fits (<=8 PDs) */
$axlesRaid50 = array();
$axlesRaid55 = array();
$axlesRaid60 = array();
$minAxleRaid30 = $subsystem->getMinimumAxle(count($pds), RAID30);
$minAxleRaid50 = $subsystem->getMinimumAxle(count($pds), RAID50);
$minAxleRaid55 = $subsystem->getMinimumAxle(count($pds), RAID55);
$minAxleRaid60 = $subsystem->getMinimumAxle(count($pds), RAID60);
for ($i = $minAxleRaid30->minAxle; $i <= (count($pds) / 3) && $i <= 16; $i++) $axlesRaid30[] = $i;
for ($i = $minAxleRaid50->minAxle; $i <= (count($pds) / 3) && $i <= 16; $i++) $axlesRaid50[] = $i;
for ($i = $minAxleRaid55->minAxle; $i <= (count($pds) / 3) && $i <= 16; $i++) $axlesRaid55[] = $i;
for ($i = $minAxleRaid60->minAxle; $i <= (count($pds) / 4) && $i <= 16; $i++) $axlesRaid60[] = $i;
?>
<script>
	var axlesRaid30=new Array;
	<?php
  for ($i = 0; $i < count($axlesRaid30); $i++) {
    echo '
                axlesRaid30[' . $i . ']="' . $axlesRaid30[$i] . '";';
  }
  if (count($axlesRaid30) == 0) {
    echo '
                axlesRaid30[0]="0";';
  }

?>

	var axlesRaid50=new Array;
	<?php
  for ($i = 0; $i < count($axlesRaid50); $i++) {
    echo '
              axlesRaid50[' . $i . ']="' . $axlesRaid50[$i] . '";';
  }
  if (count($axlesRaid50) == 0) {
    echo '
              axlesRaid50[0]="0";';
  }

?>

	var axlesRaid55=new Array;
	<?php
  for ($i = 0; $i < count($axlesRaid55); $i++) {
    echo '
              axlesRaid55[' . $i . ']="' . $axlesRaid55[$i] . '";';
  }
  if (count($axlesRaid55) == 0) {
    echo '
              axlesRaid55[0]="0";';
  }
?>
	
	var axlesRaid60=new Array;
	<?php
  for ($i = 0; $i < count($axlesRaid60); $i++) {
    echo '
              axlesRaid60[' . $i . ']="' . $axlesRaid60[$i] . '";';
  }
  if (count($axlesRaid60) == 0) {
    echo '
              axlesRaid60[0]="0";';
  }
?>
</script>

<?php
echo '
        <span id=rpal_u16NoOfAxle1 style="visibility:hidden" nowrap>&nbsp;Number of Axles
        </span>
        <select class=editField1 name=rpal_u16NoOfAxle style="visibility:hidden" onchange=axlesChanged()>';
for ($i = 0; isset($axles) && $i < count((array)$axles); $i++) /* port: count(int) - PHP 8 fatal; (array) keeps the PHP 7 result */
  echo '<option value=' . $axles . ($lastld[2] == $axles ? ' selected' : '') . '>' . $axles . '</option>';
?>
        </select>
      </td></span>
    </tr>

<?php
$content = "
        <input class=editField2 type=text name=rpal_u64Size value='" . $lastld[6] . "' onchange='modified=1'>
        <input type=hidden name=rpal_u64Size0 value='" . $lastld[8] . "'>
		<input type=hidden name=_strSizeUnit value=''>
        <select class=editField1 name=rpal_u64Size1 onchange=sizeUnitChanged()>";
for ($i = 0; $i < count($sizeUnits); $i++)
  $content = $content . '<option value=' . $i . '>' . $sizeUnits[$i] . '</option>';

$content = $content . "</select>
        <span id=rpal_u64Size2 nowrap></span>
        <input type=hidden name=rpal_u64Size3 value=0>";

table_row(gprop('capacity'), $content);

table_row_combo(gprop('stripe'), $stripeSize, 'rpal_u32StripeSize', $lastld[3], 'false', $leftWidth);
table_row_combo(gprop('sector'), $sectorSize, 'rpal_u8SectorSizeCode', $lastld[7], 'false', $leftWidth);
table_row_combo(gprop('readpolicy'), $readPolicy, '_readPolicy', $lastld[5], 'false', $leftWidth);
table_row_combo(gprop('writepolicy'), $writePolicy, '_writePolicy', $lastld[4], 'false', $leftWidth);
$PreferredOwnerControllerIdCreate = array(array(0, 1), array(1, 2), array(2, gprop('automatic')));
if ($isSupportLUNAffinity->isLunAffinitySupport) {
  if (($controller->ci_LUNAffinityEnable == 'Enabled') && ($numberOfCtlPresent == 2))
    table_row_combo(gprop('preferredctrlid'), $PreferredOwnerControllerIdCreate, 'rpal_u16PreferredCtrlId', $lastld[11], 'false', $leftWidth);
  else
    table_row_combo(gprop('preferredctrlid'), $PreferredOwnerControllerIdCreate, 'rpal_u16PreferredCtrlId', 2, 'true', $leftWidth);
}
?>

    <tr id="numdrives" style="display:none">
      <td class="tableRowCol1"><?= gprop('numberofparitydrives') ?></td>
      <td class="tableRowColOther">
        <select class="editField" name="numdriveraid6" disabled>
			<option value="2">2<option>
        </select>
      </td>
    </tr>

    <tr class="tableHeader">
      <td colspan="2" class="text3">
        <input type="submit" class="input-p" value=' <?= gprop('update') ?> ' name="update" onClick="return validateForm()">&nbsp;
        <input type="submit" class="input-p" value=' <?= gprop('delete') ?> ' name="delete">
        <input type="hidden" value='-1' name="ldindex">
        <input type="hidden" value="0" name="usedSize">
        <!--input type="hidden" name="mountpointhidden" value="<? echo $lastld[14] ?>"-->
      </td>
    </tr>

<?php
table_foot_fold();
table_tab_foot();

table_head_fold_usage(gprop('capacityusage'), 'CAP', null, null, $s);
table_head(array('pad' => 0, 'space' => 0, 'border' => 0));
?>
    <tr class="text5">
<script>
  used=0;
  for(i=0;i<logicalDrives.length;i++){
    document.write('<td id="size'+i+'" width="'+getPercentage(logicalDrives[i])+
      '%" bgcolor="<? echo $USED_COLOR; ?>" onclick="selectSize(this)" style="cursor:hand">'+(i+1)+'</td>');
    used+=getPDSize(logicalDrives[i],<? echo count($pds) ?>);
  }
  // don't show free bar if all capacity used
  if(Math.round(used/<? echo $pdsize ?>*100)<100)
    document.write('<td bgcolor="<? echo $FREE_COLOR; ?>" width="' + Number(100-Math.round(used/<? echo $pdsize ?>*100)).toString() + '%"><span id="free"></span></td>'); 
</script>
    </tr>
<?php
table_foot();

table_head();
?>
    <tr class="tableImageBackground">
      <td bgcolor="<? echo $USED_COLOR; ?>">&nbsp;&nbsp;&nbsp;&nbsp;</td>
      <td nowrap><?= gprop('ldentered') ?>&nbsp;&nbsp;</td>
      <td bgcolor="<? echo $FREE_COLOR ?>">&nbsp;&nbsp;&nbsp;&nbsp;</td>
      <td nowrap><?= gprop('freecapacity') ?></td>
      <td width="90%"></td>
    </tr>
<?php
table_foot();
table_foot_fold();

table_head_fold(gprop('newlogicaldrives'), 'LD', null, null, $s);
$aliastitle=gprop('alias');
if ($isSupportLUNAffinity->isLunAffinitySupport && ($controller->ci_LUNAffinityEnable = 'Enabled')) {

  $columns = array('#', $aliastitle, gprop('raidlevel'), gprop('capacity'), gprop('stripe'), gprop('sector'), gprop('readpolicy'), gprop('writepolicy'), gprop('preferredctrlid'));
  $widths = array(6, 12, 10, 12, 10, 10, 12, 12, 16);
} else {
  $columns = array('#', $aliastitle, gprop('raidlevel'), gprop('capacity'), gprop('stripe'), gprop('sector'), gprop('readpolicy'), gprop('writepolicy'));
  $widths = array(10, 14, 14, 14, 10, 10, 14, 14);

}

table_header_cols($columns, $widths, 'listh');

for ($i = 0; isset($logicalDrives) && $i < count($logicalDrives); $i++) {
  $logicalDrive = $logicalDrives[$i];
  $raid = getStringRAIDLevel($logicalDrive->rpal_u16RaidLevel);
  if ($logicalDrive->rpal_u16RaidLevel == RAID30 || $logicalDrive->rpal_u16RaidLevel == RAID50 || $logicalDrive->rpal_u16RaidLevel == RAID51 || $logicalDrive->rpal_u16RaidLevel == RAID55 || $logicalDrive->rpal_u16RaidLevel == RAID60)
    $raid .= (': ' . $logicalDrive->rpal_u16NoOfAxle . ' axles');
  if ($isSupportLUNAffinity->isLunAffinitySupport && ($controller->ci_LUNAffinityEnable = 'Enabled')) {
    echo '
	      <tr id="ld' . $i . '" class="tableRow1Cols" onclick="selectLD(this)" style="cursor:hand' . ($i == $ldindex ? ';background-image:url(images/select.gif)' : '') . '">
	        <td width="6%">' . ($i + 1) . '</td>
	        <td width="17%">' . $logicalDrive->rpal_u8Name . '</td>
	        <td width="10%">' . $raid . '</td>';
    echo '  
	        <td width="12%">' . $logicalDrive->_u64SizeDisplay . $logicalDrive->_strSizeUnit . '</td>
	        <td width="10%">' . $logicalDrive->rpal_u32StripeSize . 'KB</td>
	        <td width="10%">' . getStringLogDrvSectorSize($logicalDrive->rpal_u8SectorSizeCode) . '</td>
	        <td width="12%">' . getStringLogDrvReadPolicyValue($logicalDrive->_readPolicy) . '</td>
	        <td width="12%">' . getStringLogDrvWritePolicy(getLogDrvWritePolicyFromValue($logicalDrive->_writePolicy) |
      getLogDrvReadPolicyFromValue($logicalDrive->_readPolicy)) . '</td>
	       <td width="16%">' . getLunAffinityValue($logicalDrive->rpal_u16PreferredCtrlId) . '</td>
	      </tr>';
  } else {
    echo '
      <tr id="ld' . $i . '" class="tableRow1Cols" onclick="selectLD(this)" style="cursor:hand' . ($i == $ldindex ? ';background-image:url(images/select.gif)' : '') . '">';
    echo '
        <td width="10%">' . ($i + 1) . '</td>
        <td width="17%">' . $logicalDrive->rpal_u8Name . '</td>
        <td width="14%">' . $raid . '</td>
        <td width="14%">' . $logicalDrive->_u64SizeDisplay . $logicalDrive->_strSizeUnit . '</td>
        <td width="10%">' . $logicalDrive->rpal_u32StripeSize . 'KB</td>
        <td width="10%">' . getStringLogDrvSectorSize($logicalDrive->rpal_u8SectorSizeCode) . '</td>
        <td width="10%">' . getStringLogDrvReadPolicyValue($logicalDrive->_readPolicy) . '</td>
        <td width="10%">' . getStringLogDrvWritePolicy(getLogDrvWritePolicyFromValue($logicalDrive->_writePolicy) |
      getLogDrvReadPolicyFromValue($logicalDrive->_readPolicy)) . '</td>
      </tr>';
  }

}

if (!isset($logicalDrives) || count($logicalDrives) == 0)
  table_row_empty(gresult('nonewlds'), count($columns));

table_foot_fold();
table_row_submit(array('submit' => 0, 'back' => 1, 'next' => (count($logicalDrives) ? 1 : 0), 'reset' => 0, 'nextClick' => 'validateNext()'));
page_foot();
?>
  </table>
</form>

<script>
  form=document.forms[0];
  elements=form.elements;
  elements['ldindex'].value=<? echo $ldindex ?>;
  sizeChanged();
  updateAxles();
  numLDsChanged();
  updateSizes(elements['ldindex'].value);
  elements['rpal_u16NoOfAxle'].value=<? echo $lastld[2] ?>;
  form.usedSize.value=getUsedSize(-1);

  obj=ylib_getObj('free');
  if(obj)
    obj.innerHTML=(<? echo count($logicalDrives) ?>)>0?"":"<?= gprop('freecapacity') ?>";

  adjustListCellWidth('listh','ld0');

  ylib_addEvt(elements['_readPolicy'],'change',new Function('RPChanged()'));
  RPChanged();

  ylib_addEvt(elements['_numLDs'],'change',new Function('numLDsChanged()'));
  if(<? echo MAX_LOGDRV_PER_ARRAY - count($logicalDrives) ?>==0&&elements['_numLDs'])
  elements['_numLDs'].disabled=true;

  function validateForm()
  {
    elements=document.forms[0].elements;
    rpal_u8Name=elements['rpal_u8Name'];
    focusItem = null;        
    if (!validateAlias(rpal_u8Name.value)) {
      msg_u8Name.innerHTML="<?= gjsalert('invalidalias') ?>";
      focusItem=rpal_u8Name;
    }
    else
      msg_u8Name.innerHTML="";
    if (focusItem!=null) {
      focusItem.focus();
      return false;
    }      
    return true;
  }

  function validateNext()
  {
    for(var i=0; i<logicalDrives.length; i++)
    {
      if(logicalDrives[i][9]>2*1024*1024*1024*1024)
      {
        var ret = confirm("<?= gjsalert('sectorsize') ?>");
        if (!ret)
          return false;
      }
    }
    return true;
  }

  showNumDrives();

  function check_radiovalue()
  {
    elements=document.forms[0].elements;
    nasEnabled=elements['rpal_u32LDFlags'];
    sector=elements['rpal_u8SectorSizeCode'];
    aliasname=elements['rpal_u8Name'];

    if(nasEnabled[0].checked == true)
    {
      sector.disabled = false;
      aliasname.disabled = false;
      aliasname.style.background=aliasname.disabled?'#dadada':'#ffffff';
      mountpoint.disabled = true;
      mountpoint.value = "";
      mountpoint.style.background=mountpoint.disabled?'#dadada':'#ffffff';
    }
    else
    {
      sector.value = 1;
      sector.disabled = true;
      aliasname.disabled = true;
      aliasname.style.background=aliasname.disabled?'#dadada':'#ffffff';
      mountpoint.disabled = false;
      mountpoint.style.background=mountpoint.disabled?'#dadada':'#ffffff';
    }
  }
</script>

</body>

</html>

<?php
SetSessionValue('lds', $logicalDrives);
SyncSession();
?>
