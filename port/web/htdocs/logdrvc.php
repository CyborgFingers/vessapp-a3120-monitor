<?php
ob_start();
/*******************************************************************************
 * Filename    : logdrvc.php
 * Description : islaVista create logical drives.
 * Created on  : 08/21/03
 *
 * (C) Copyright Promise Technology Inc.,2003
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';
include 'ldcfg.inc';

if (isset($_POST['next'])) {
  header('Location: logdrvc2.php');
  exit;
} else if (isset($_POST['cancel'])) {
  session_start();
  header('Location: array.php?tab=0&daid=' . $_SESSION['daid'] . '&note=' . gresult('createldcancel'));
  exit;
}

if (isset($_GET['daid']))
  SetSessionValue('daid', $_GET['daid']);

if (isset($_GET['act']) && $_GET['act'] == 'create') {
  unset($GLOBALS['_SESSION']['lds']);
  if (isset($_SESSION['mountpoints']))
    unset($_SESSION['mountpoints']);
}
$server = getServer();
$subsystem = $server->getSubsystem(1);
if (PI_FB_HOST_INTERFACE_NAS && ($subsystem->frontendType == PI_FB_HOST_INTERFACE_ISCSI))
  $isnas = 1;
else
  $isnas = 0;

$numberOfCtlPresent = $subsystem->ssi_NumberOfCtlPresent;
$isSupportLUNAffinity = $subsystem->getLunAffinitySupport();
$currentCtrlId = $subsystem->getCurrentCtrlId();
$ctrlId = $currentCtrlId->currentCtrlId;
$controller = $subsystem->getController($ctrlId);


if (isset($_SESSION['mountpoints'])) {
  $mountpointstr = $_SESSION['mountpoints'];
  $mountcount = count($mountpointstr);
} else {
  $mountpointstr = array();
  $mountcount = 0;
}
$selectline = null;
$physicalDrives = $subsystem->getPhysicalDrives();
$subsystem->getDeviceCapabilities();
$diskArray = $subsystem->getDiskArray($_SESSION['daid']);
$PDs = $diskArray->acd_PDs;
$LDs = $diskArray->acd_LDs;
$Nasld = 0;
for ($i = 0; $i < count($LDs); $i++) {
  if ($LDs[$i]->ldd_isNasLd == 'yes')
    $Nasld++;
}

if (count($LDs) >= MAX_LOGDRV_PER_ARRAY)
  $noCreate = 1;

$allPDDead = true;
for ($i = 0; $i < count($PDs); $i++) {
  $pds[] = $PDs[$i]->pdd_PDFlatId;
  if ($PDs[$i]->pdd_PDFlags != 'Dead') $allPDDead = false;
}
if ($allPDDead)
  $noCreate = 1;

$logicalDrives = null;
if (isset($_SESSION['lds'])) {
  $logicalDrives = $_SESSION['lds'];
  for ($i = 0; $i < count($logicalDrives ?? []); $i++) {
    if ($logicalDrives[$i]->rpal_u32LDFlags == 'yes')
      $Nasld++;
  }
}

if (!isset($noCreate)) {
  $raidLevels = getSupportedRAIDLevels(count($pds));
  $pdsize = getPDConfiguableSize($physicalDrives, $pds);

  $freeFrags = $subsystem->getDiskArrayFreeFragments($_SESSION['daid']);
  if (count($freeFrags) == 0)
    $note = galert('nomorecapacityforcreateld');

  for ($i = 0; $i < count($freeFrags); $i++)
    $freeFrags[$i]->orgSize = $freeFrags[$i]->daff_u64Size;

  $maxFree = 0;
  global $sizeUnits;

  function getFreeFragSize($index)
  {
    global $freeFrags, $pds;
    if (isset($freeFrags[$index]))
      return $freeFrags[$index]->daff_u64Size * count($pds);
    return 0;
  }

  function updateFreeFrags($freeFrags)
  {
    global $logicalDrives;
    global $pds, $maxFree;

    for ($i = 0; $i < count($logicalDrives ?? []); $i++) {
      $freeFrag = $freeFrags[$logicalDrives[$i]->ffindex];

      $size = logSizeToPartitionSize(
        $logicalDrives[$i]->rpal_u64Size,
        count($pds),
        $logicalDrives[$i]->rpal_u16RaidLevel,
        $logicalDrives[$i]->rpal_u16NoOfAxle,
        $logicalDrives[$i]->rpal_u32StripeSize * 1024
      );
      $logicalDrives[$i]->rpal_u64FragmentEndAddr = $freeFrag->daff_u64Addr + $size;

      $freeFrag->daff_u64Addr = $logicalDrives[$i]->rpal_u64FragmentEndAddr;
      $freeFrag->daff_u64Size -= $size;
      if ($freeFrag->daff_u64Size < 0) $freeFrag->daff_u64Size = 0;
      $freeFrags[$logicalDrives[$i]->ffindex] = $freeFrag;
    }
    return $freeFrags;
  }

  function getMaxFFIndex($freeFrags)
  {
    $maxFree = 0;
    for ($i = 1; $i < count($freeFrags); $i++)
      if ($freeFrags[$i]->daff_u64Size > $freeFrags[$maxFree]->daff_u64Size)
      $maxFree = $i;
    return $maxFree;
  }
}
?>

<html>
<head>
<?php
page_head();

$note = null;

if (!isset($noCreate)) {
  $ldindex = -1;

  if (isset($_POST['ldindex']))
    $ldindex = $_POST['ldindex'];

  if (isset($_POST['update'])) {
    $pow = pow(1024, $_POST['rpal_u64Size1']);
    $tmpMax = $_POST['rpal_u64Size3'] / $pow;

    if (isset($_POST['rpal_u8SectorSizeCode']))
      $sectorstr = $sectorSize[$_POST['rpal_u8SectorSizeCode'] - 1][1];
    else
      $sectorstr = $sectorSize[0][1];
    $ret = substr($sectorstr, -1);
    $sectorSizeStr = null;
    $sectorUnitStr = null;
    if ($ret == 'B')//unit=kb
    {
      $sectorSizeStr = substr($sectorstr, 0, strlen($sectorstr) - 3);
      settype($sectorSizeStr, "integer");
      $sectorSizeStr = $sectorSizeStr * 1024;
      $sectorUnitStr = 'KB';
    } else {
      $sectorSizeStr = substr($sectorstr, 0, strlen($sectorstr) - 6);
      settype($sectorSizeStr, "integer");
      $sectorSizeStr = $sectorSizeStr;
      $sectorUnitStr = 'B';
    }
    if ($_POST['rpal_u64Size'] <= 0)
      $note = galert('invalidcapacity');

    else if (($sizeUnits[$_POST['rpal_u64Size1']] == 'B') && ($sectorUnitStr == 'B') && ($_POST['rpal_u64Size'] < $sectorSizeStr))
      $note = galert('invalidcapacity');
    else if (($sizeUnits[$_POST['rpal_u64Size1']] == 'B') && ($sectorUnitStr == 'KB') && ($_POST['rpal_u64Size'] < $sectorSizeStr))
      $note = galert('invalidcapacity');
    else if (($sizeUnits[$_POST['rpal_u64Size1']] == 'KB') && ($sectorUnitStr == 'B') && ($_POST['rpal_u64Size'] * 1024 < $sectorSizeStr))
      $note = galert('invalidcapacity');
    else if (($sizeUnits[$_POST['rpal_u64Size1']] == 'KB') && ($sectorUnitStr == 'KB') && ($_POST['rpal_u64Size'] * 1024 < $sectorSizeStr))
      $note = galert('invalidcapacity');
    else if ($_POST['rpal_u64Size'] > $tmpMax)
      $note = galert('exceedcapacity');
    else {
      $bconflict = false;
      if ($ldindex >= 0)
        $logicalDrive = $logicalDrives[$ldindex];
      if (!isset($logicalDrive))
        $logicalDrive = new stdclass();
      /*if (isset($_POST['rpal_u32LDFlags'])) {
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
      } else*/
        $logicalDrive->rpal_u8Name = $_POST['rpal_u8Name'];
      $logicalDrive->rpal_u16RaidLevel = setInteger($_POST['rpal_u16RaidLevel']);

      $logicalDrive->rpal_u16NoOfAxle = isset($_POST['rpal_u16NoOfAxle']) ? setInteger($_POST['rpal_u16NoOfAxle']) : 1;
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
      $ffindex = $_POST['ffindex'];
      $autoffindex = $_POST['autoffindex'];
      $size = logSizeToPartitionSize(
        $logicalDrive->rpal_u64Size,
        count($pds),
        $logicalDrive->rpal_u16RaidLevel,
        $logicalDrive->rpal_u16NoOfAxle,
        $logicalDrive->rpal_u32StripeSize * 1024
      );
      if (!$autoffindex) {
        for ($i = 0; $i < count($freeFrags); $i++)
          if ($freeFrags[$i]->daff_u64Size >= $size) {
          $ffindex = $i;
          break;
        }
      }
      $logicalDrive->ffindex = $ffindex;
      $logicalDrive->rpal_u64FragmentEndAddr = $freeFrags[$ffindex]->daff_u64Addr + $size;
      if (isset($_POST['rpal_u16PreferredCtrlId']))
        $logicalDrive->rpal_u16PreferredCtrlId = setInteger($_POST['rpal_u16PreferredCtrlId']);
      else
        $logicalDrive->rpal_u16PreferredCtrlId = 2;

      if (isset($_POST['rpal_u32LDFlags']) && $_POST['rpal_u32LDFlags'] == "NAS") {
             
        // if($Nasld>4||(($ldindex<0)&&($Nasld==4)))
        //   $note=galert('maxnasld').' 4'.'.';
        $logicalDrive->rpal_u32LDFlags = "yes";
      } else
        $logicalDrive->rpal_u32LDFlags = "no";
      if ($ldindex >= 0)
        $logicalDrives[$ldindex] = $logicalDrive;
      else {
        if ((count($logicalDrives ?? []) + count($LDs)) >= MAX_LOGDRV_PER_ARRAY)
          $note = galert('maxld') . MAX_LOGDRV_PER_ARRAY . '.';
        else if ((!$bconflict)) /*&&(($logicalDrive->rpal_u32LDFlags=="no") || ($Nasld<4)))*/
        $logicalDrives[] = $logicalDrive;
        // point to the last one
        $ldindex = count($logicalDrives ?? []) - 1;
      }
    }

    // unselect any row
    $ldindex = -1;
  } else if (isset($_POST['delete'])) {
    if ($ldindex >= 0) {
      if ($isnas)
        if ($logicalDrives[$ldindex]->rpal_u32LDFlags == "yes") {
        for ($deletcount = 0; $deletcount < $mountcount; $deletcount++) {
          if (strcmp($logicalDrives[$ldindex]->rpal_u8Name, $mountpointstr[$deletcount]) == 0) {
            array_splice($mountpointstr, $deletcount, 1);
            break;
          }
        }
        SetSessionValue('mountpoints', $mountpointstr);
      }
      array_splice($logicalDrives, $ldindex, 1);
    }
    if ($ldindex >= 0 && !isset($logicalDrives[$ldindex]))
      $ldindex--;
  }

  $freeFrags = updateFreeFrags($freeFrags);
  $maxFree = getMaxFFIndex($freeFrags);
  $lastld = getLastLD($subsystem, $logicalDrives, $ldindex, getFreeFragSize($maxFree));
  ?>

<script>
    minchange=1;
    var logicalDrives=new Array;
    var bars=new Array;
    var frags=new Array;
<?php
$index = 0;

for ($i = 0; $i < count($LDs); $i++) {
  echo '
      var bar=new Array;
      bar[0]="' . $LDs[$i]->ldd_RaidLevel . '";
      bar[1]=' . $LDs[$i]->cdd_Start . ';
      bar[2]=' . logSizeToPartitionSize($LDs[$i]->_LDSize, count($pds), $LDs[$i]->_raidLevel, $LDs[$i]->ldd_LDNoOfAxles, $LDs[$i]->ldd_StripeSizeInBlocks * 512) . ';
      bar[3]=' . $LDs[$i]->ldd_LDFlatID . ';
      bars[' . $index++ . ']=bar;';
}

for ($i = 0; $i < count($freeFrags); $i++) {
  echo '
      var frag=new Array;
      frag[0]="free";
      frag[1]=' . $freeFrags[$i]->daff_u64Addr . ';
      frag[2]=' . $freeFrags[$i]->daff_u64Size . ';
      frag[3]=' . $i . ';
      frag[4]=' . $freeFrags[$i]->orgSize . ';
      frags[' . $i . ']=frag;';

  echo '
      var bar=new Array;
      bar[0]="free";
      bar[1]=' . $freeFrags[$i]->daff_u64Addr . ';
      bar[2]=' . $freeFrags[$i]->daff_u64Size . ';
      bar[3]=' . $i . ';
      bars[' . $index++ . ']=bar;';
}

for ($i = 0; $i < count($logicalDrives ?? []); $i++) {
  $logicalDrive = $logicalDrives[$i];
  // print $logicalDrive->rpal_u32LDFlags;
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
      logicalDrive[8]=' . $logicalDrive->ffindex . ';
      logicalDrive[9]=' . $logicalDrive->rpal_u64Size . ';
      logicalDrive[10]=' . $logicalDrive->rpal_u64Size1 . ';
      logicalDrive[11]=' . $logicalDrive->rpal_u16PreferredCtrlId . ';
      logicalDrive[12]="' . $logicalDrive->rpal_u32LDFlags . '";
      logicalDrives[' . $i . ']=logicalDrive;';

  echo '
      var bar=new Array;
      bar[0]="new";
      bar[2]=' . logSizeToPartitionSize(
    $logicalDrive->rpal_u64Size,
    count($pds),
    $logicalDrive->rpal_u16RaidLevel,
    $logicalDrive->rpal_u16NoOfAxle,
    $logicalDrive->rpal_u32StripeSize * 1024
  ) . ';
      bar[1]=' . $logicalDrive->rpal_u64FragmentEndAddr . '-bar[2];
      bar[3]=' . $i . ';
      bars[' . $index++ . ']=bar;';
}

?>

    for(var i=0;i<bars.length-1;i++){
      for(j=i+1;j<bars.length;j++){
        if(!bars[j]) break;
        if((bars[i][1]>bars[j][1])||(bars[i][1]==bars[j][1]&&bars[i][0]=='free')){
          t=bars[j];
          bars[j]=bars[i];
          bars[i]=t;
        }
      }
    }

    function getObjIndex(obj,name)
    {
      for(var i=0;i<<? echo (MAX_LOGDRV_PER_ARRAY * 2) ?>;i++){
        id=name+i;
        if(ylib_getObj(id)==obj) return i;
      }
      return -1;
    }

    function selectLD(line)
    {
      var ldindex;
      unselectAllRows();
      ldindex=getObjIndex(line,'ld');

      // select current line
      line.style.background='#0078D8';

      updateLD(ldindex);
      document.forms[0].elements['ldindex'].value=ldindex;
      document.forms[0].elements['barindex'].value=-1;
      document.forms[0].elements['ffindex'].value=logicalDrives[ldindex][8];
      // document.forms[0].elements['mountpointhidden'].value=document.forms[0].elements['rpal_mountpoint'].value;
      updateSizes();
      updateLDMaxSize();
    }

    function selectSize(obj)
    {
      var index=getObjIndex(obj,'size');
      document.forms[0].elements['barindex'].value=index;

      if(index>=0&&bars[index][0]=='new'){
        id='ld'+bars[index][3];
        selectLD(ylib_getObj(id));
      }else{
        document.forms[0].elements['ldindex'].value=-1;
        unselectAllRows();
        updateSizes();
        if(index>=0&&bars[index][0]=='free'){
          form.elements['ffindex'].value=bars[index][3];
          form.elements['autoffindex'].value=1;
          updateFreeMaxSize();
        }
      }
    }

    function axlesChanged()
    {
      updateLDMaxSize();
    }

    function getPercentage(size)
    {
      p=size*<? echo count($pds) * 100 / $pdsize ?>;
      if(p<1) p=1;
      return Math.round(p);
    }

    function getUsedSize(index)
    {
      usedSize=0;
      ffindex=(index<0)?document.forms[0].elements['ffindex'].value:logicalDrives[index][8];
      for(var i=0;i<logicalDrives.length;i++)
        if(i!=index&&ffindex==logicalDrives[i][8])
          usedSize+=getPDSize(logicalDrives[i],<? echo count($pds) ?>);
      return usedSize;
    }

    function getMaxSize(index,raidLevel,axles,stripe)
    {
      pdCount=<? echo count($pds) ?>;
      ffindex=(index<0)?document.forms[0].elements['ffindex'].value:logicalDrives[index][8];
      size=0;
      if(frags[ffindex])
        size=frags[ffindex][4]*pdCount-getUsedSize(index);
      tempMaxSize = getLDSize(size,raidLevel,axles,stripe,pdCount);
      if (tempMaxSize < 1024*1024)
		tempMaxSize = 0;
      return tempMaxSize;
    }

    function updateFreeMaxSize()
    {
      form=document.forms[0];
      maxSize=getMaxSize(-1,form.elements['rpal_u16RaidLevel'].value,form.elements['rpal_u16NoOfAxle'].value,form.elements['rpal_u32StripeSize'].value);
      updateMaxSize(maxSize);
    }

    function updateSizes()
    {
      ldindex=document.forms[0].elements['ldindex'].value;
      index=document.forms[0].elements['barindex'].value;

      for(var i=0;i<bars.length;i++){
        id='size'+i;
        if(!ylib_getObj(id)) continue;

        ylib_getObj(id).style.backgroundImage='';
        if(i==index||(bars[i][0]=='new'&&bars[i][3]==ldindex))
          ylib_getObj(id).style.backgroundImage='url(images/select.gif)';

        if(bars[i][0]=='new')
          ylib_getObj(id).style.backgroundColor='<? echo $USED_COLOR; ?>';
        else if(bars[i][0]=='free')
          ylib_getObj(id).style.backgroundColor='<? echo $FREE_COLOR; ?>';
        else{
        	switch( bars[i][0] ){
        		case 'RAID0':
        		 ylib_getObj(id).style.backgroundColor='<? echo $CONFIG_COLOR[0]; ?>';
        		 break;
        		case 'RAID1':
        		 ylib_getObj(id).style.backgroundColor='<? echo $CONFIG_COLOR[1]; ?>';
        		 break;
        		case 'RAID10':
        		 ylib_getObj(id).style.backgroundColor='<? echo $CONFIG_COLOR[2]; ?>';
        		 break;
        		case 'RAID1E':
        		 ylib_getObj(id).style.backgroundColor='<? echo $CONFIG_COLOR[3]; ?>';
        		 break;
        		case 'RAID3':
        		 ylib_getObj(id).style.backgroundColor='<? echo $CONFIG_COLOR[4]; ?>';
        		 break;
        		case 'RAID5':
        		 ylib_getObj(id).style.backgroundColor='<? echo $CONFIG_COLOR[5]; ?>';
        		 break;
        		case 'RAID30':
        		 ylib_getObj(id).style.backgroundColor='<? echo $CONFIG_COLOR[6]; ?>';
        		 break;
        		case 'RAID50':
        		 ylib_getObj(id).style.backgroundColor='<? echo $CONFIG_COLOR[7]; ?>';
        		 break;
        		case 'RAID6':
        		 ylib_getObj(id).style.backgroundColor='<? echo $CONFIG_COLOR[8]; ?>';
        		 break;
        		 case 'RAID60':
        		 ylib_getObj(id).style.backgroundColor='<? echo $CONFIG_COLOR[9]; ?>';
        		 break;
        	}
        }
      }
    }
</script>
<?php

}
?>

</head>
<body onclick="unselectLD(event)">
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method="post" action="<?php echo $action_url; ?>">
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center>
<?php
table_title(gprop('createlogicaldrives'), 'array_create', $note);
table_tab_head();
$s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner');
$tabTitle = tabTitleArray($subsystem, $_SESSION['daid']);
table_tab($tabTitle);

table_head_fold(gprop('addlogicaldrives'), 'SEL', null, null, $s);

if (isset($noCreate)) {
  table_row_empty($allPDDead ? galert('cannotcreateld') : galert('nomoreldtocreate'));
  table_foot_fold();
  table_tab_foot();
} else {
  $leftWidth = 25;
  table_row_edit(gprop('alias'), null, 'rpal_u8Name', 32, $leftWidth, null, 'msg_u8Name');
  if ($isnas) {
    table_row_edit(gprop('mountpointalias'), $lastld[14], 'rpal_mountpoint', 20, $leftWidth, null, 'msg_mountpoint');
  }
  // RAID Level
  for ($i = 0; $i < count($raidLevels); $i++)
    $raidLevel[] = array($raidLevels[$i], getStringRAIDLevel($raidLevels[$i]));
  ?>
    <tr>      
      <td class=tableRowCol1><span class=text4><?= gprop('raidlevel') ?></span></td>
      <td class=tableRowColOther><span class=text5>
        <select class=editField name=rpal_u16RaidLevel onchange=raidLevelChanged()>
<?php
for ($i = 0; $i < count($raidLevel); $i++)
  echo '<option value=' . $raidLevel[$i][0] . ($lastld[1] == $raidLevel[$i][0] ? ' selected' : '') . '>' . $raidLevel[$i][1] . '</option>';
?>
        </select></span>
<?php
// number of axles
$axles = 1;
$axlesRaid30 = array();
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


        <span id=rpal_u16NoOfAxle1 style="visibility:hidden" nowrap>&nbsp;Number of Axles
        </span>
        <select class=editField1 name=rpal_u16NoOfAxle style="visibility:hidden" onchange=axlesChanged()>';
<?php
for ($i = 0; isset($axles) && $i < (is_array($axles) ? count($axles) : 1); $i++)
  echo '<option value=' . $axles . ($lastld[2] == $axles ? ' selected' : '') . '>' . $axles . '</option>';
?>
        </select>
      </td>
    </tr>

    <tr>      
      <td class=tableRowCol1><span class=text4><?= gprop('capacity') ?></span></td>
      <td class=tableRowColOther><span class=text5>
        <input class=editField2 type=text name=rpal_u64Size value='<? echo $lastld[6] ?>' onChange="modified=1">
        <input type=hidden name=rpal_u64Size0 value='<? echo $lastld[8] ?>'>
        <input type=hidden name=_strSizeUnit value=' '>
        <select class=editField1 name=rpal_u64Size1 onchange=sizeUnitChanged()>
<?php
for ($i = 0; $i < count($sizeUnits); $i++)
  echo '<option value=' . $i . '>' . $sizeUnits[$i] . '</option>';
?>
        </select>
        <span id=rpal_u64Size2 nowrap></span>
        <input type=hidden name=rpal_u64Size3 value=0>
      </span></td>
    </tr>
<?php
table_row_combo(gprop('stripe'), $stripeSize, 'rpal_u32StripeSize', $lastld[3], 'false', $leftWidth);
table_row_combo(gprop('sector'), $sectorSize, 'rpal_u8SectorSizeCode', $lastld[7], 'false', $leftWidth);


table_row_combo(gprop('readpolicy'), $readPolicy, '_readPolicy', $lastld[5], 'false', $leftWidth);
table_row_combo(gprop('writepolicy'), $writePolicy, '_writePolicy', $lastld[4], 'false', $leftWidth);
$PreferredOwnerControllerIdCreate = array(array(0, 1), array(1, 2), array(2, gprop('automatic')));
if ($isSupportLUNAffinity->isLunAffinitySupport) {
  if (($controller->ci_LUNAffinityEnable == 'Enabled') && ($numberOfCtlPresent == 2))
    table_row_combo(gprop('preferredctrlid'), $PreferredOwnerControllerIdCreate, 'rpal_u16PreferredCtrlId', $lastld[11], 'false', $leftWidth);
  else
    table_row_combo(gprop('preferredctrlid'), $PreferredOwnerControllerIdCreate, 'rpal_u16PreferredCtrlId', $lastld[11], 'true', $leftWidth);
}
$isnas ? table_row_radio_nas(gprop('ldtype'), 'DAS', isset($lastld[13]) ? $lastld[13] : null, "rpal_u32LDFlags") : null;
// $isnas?($controller->ci_LUNMasking?table_row_checkbox(gprop('fornasonly'), 0, "rpal_u32LDFlags"):table_row_checkbox(gprop('fornasonly'), 0, "rpal_u32LDFlags","", "disabled")):null;

?>
    <tr id=numdrives style="display:none">
      <td class=tableRowCol1><?= gprop('numberofparitydrives') ?></td>
      <td class=tableRowColOther>
        <select class=editField name=numdriveraid6 disabled>
			<option value=2>2<option>
        </select>
      </td>
    <tr class=tableHeader>
      <td colspan=2 class=text3>
        <input type=submit class=input-p value=' <?= gprop('update') ?> ' name=update onClick="return validateForm()">&nbsp;
        <input type=submit class=input-p value=' <?= gprop('delete') ?> ' name=delete>
        <input type=hidden value='-1' name=ldindex>
        <input type=hidden value='-1' name=barindex>
        <input type=hidden value=<? echo $maxFree ?> name=ffindex>
        <input type=hidden value=0 name=autoffindex>
        <!-- input type=hidden name=mountpointhidden value='<? //echo $lastld[14]?>'-->
      </td>
    </tr>
<?php
table_foot_fold();
table_tab_foot();
table_head_fold_usage(gprop('capacityusage'), 'CAP', null, null, $s);

table_head(array('pad' => 0, 'space' => 0, 'border' => 0));
?>
    <tr class=text5>
<script>
    for(var i=0;i<bars.length;i++){
      if(bars[i][2]>0){
        if(bars[i][0].substring(0,4)=='RAID')
          document.write('<td id=size'+i+' width='+getPercentage(bars[i][2])+'%>&nbsp;</td>');
        else{
          document.write('<td id=size'+i+' width='+getPercentage(bars[i][2])+'% onclick=selectSize(this) style="cursor:hand">');
          if(bars[i][0]=='new')
            document.write(bars[i][3]+1);
          else if(bars[i][0]=='free'&&i==0&&bars.length==1)
            document.write('<?= gprop('freecapacity') ?>');
          document.write('</td>');
        }
      }
    }
</script>
    </tr>
<?php
table_foot();

table_head();
?>
    <tr class=tableImageBackground>
    <td class=text5 noWrap ><?= gprop('existinglogicadrive') ?>&nbsp;&nbsp;</td>
<!--
      <td bgcolor=<? echo $FREE_COLOR; ?>
        style=background-image:url(images/select.gif)>&nbsp;&nbsp;&nbsp;&nbsp;
      </td>
      <td nowrap>Logical Drive Selected&nbsp;&nbsp;</td>
-->
    <td bgColor=<? echo $CONFIG_COLOR[0]; ?> >&nbsp;&nbsp;&nbsp;&nbsp;</td>
    <td  class=text5 noWrap ><?= gprop('raid0') ?>&nbsp;&nbsp;</td>
    <td bgColor=<? echo $CONFIG_COLOR[1]; ?> >&nbsp;&nbsp;&nbsp;&nbsp;</td>
    <td  class=text5 noWrap ><?= gprop('raid1') ?>&nbsp;&nbsp;</td>
    <td bgColor=<? echo $CONFIG_COLOR[2]; ?> >&nbsp;&nbsp;&nbsp;&nbsp;</td>
    <td  class=text5 noWrap ><?= gprop('raid10') ?>&nbsp;&nbsp;</td>
    <td bgColor=<? echo $CONFIG_COLOR[3]; ?> >&nbsp;&nbsp;&nbsp;&nbsp;</td>
    <td  class=text5 noWrap ><?= gprop('raid1E') ?>&nbsp;&nbsp;</td>                     
    <td bgColor=<? echo $CONFIG_COLOR[4]; ?> >&nbsp;&nbsp;&nbsp;&nbsp;</td>
    <td  class=text5 noWrap ><?= gprop('raid3') ?>&nbsp;&nbsp;</td>
    <td bgColor=<? echo $CONFIG_COLOR[5]; ?> >&nbsp;&nbsp;&nbsp;&nbsp;</td>
    <td  class=text5 noWrap ><?= gprop('raid5') ?>&nbsp;&nbsp;</td>
    <td bgColor=<? echo $CONFIG_COLOR[6]; ?> >&nbsp;&nbsp;&nbsp;&nbsp;</td>
    <td  class=text5 noWrap ><?= gprop('raid30') ?>&nbsp;&nbsp;</td>
    <td bgColor=<? echo $CONFIG_COLOR[7]; ?> >&nbsp;&nbsp;&nbsp;&nbsp;</td>
    <td  class=text5 noWrap ><?= gprop('raid50') ?>&nbsp;&nbsp;</td>
    <td bgColor=<? echo $CONFIG_COLOR[8]; ?> >&nbsp;&nbsp;&nbsp;&nbsp;</td>
    <td  class=text5 noWrap ><?= gprop('raid6') ?>&nbsp;&nbsp;</td>
    <td bgColor=<? echo $CONFIG_COLOR[9]; ?> >&nbsp;&nbsp;&nbsp;&nbsp;</td>
    <td  class=text5 noWrap ><?= gprop('raid60') ?>&nbsp;&nbsp;</td>
    <td width=90%></td>
  </tr>
  <tr class=tableImageBackground>             
    <td  class=text5 noWrap ><?= gprop('logicaldriveentered') ?>&nbsp;&nbsp;</td>                                        
    <td bgColor=<? echo $USED_COLOR; ?> >&nbsp;&nbsp;&nbsp;&nbsp;</td>
    <td noWrap ></td>
    <td >&nbsp;&nbsp;&nbsp;&nbsp;</td>
    <td noWrap ></td>
    <td >&nbsp;&nbsp;&nbsp;&nbsp;</td>
    <td noWrap ></td>
    <td >&nbsp;&nbsp;&nbsp;&nbsp;</td>
    <td  class=text5 noWrap ><?= gprop('freecapacity') ?>&nbsp;&nbsp;</td>                     
    <td bgColor=<? echo $FREE_COLOR; ?> >&nbsp;&nbsp;&nbsp;&nbsp;</td>
    <td noWrap ></td>
    <td >&nbsp;&nbsp;&nbsp;&nbsp;</td>
    <td noWrap ></td>
    <td >&nbsp;&nbsp;&nbsp;&nbsp;</td>
    <td noWrap ></td>
    <td >&nbsp;&nbsp;&nbsp;&nbsp;</td>
    <td noWrap ></td>
    <td >&nbsp;&nbsp;&nbsp;&nbsp;</td>
    <td noWrap ></td>
    <td >&nbsp;&nbsp;&nbsp;&nbsp;</td>
    <td width=90%></td>
    </tr>

<?php
table_foot();
table_foot_fold();

table_head_fold(gprop('newlogicaldrives'), 'LD', null, null, $s);
$columns = null;
$widths = null;
$aliastitle = $isnas ? gprop('aliasnas') : gprop('alias');
if ($isSupportLUNAffinity->isLunAffinitySupport && $controller->ci_LUNAffinityEnable == 'Enabled') {
  $columns = array('#', $aliastitle, gprop('raidlevel'), gprop('capacity'), gprop('stripe'), gprop('sector'), gprop('readpolicy'), gprop('writepolicy'), gprop('preferredctrlid'));
  $isnas ? array_push($columns, gprop('fornas')) : null;
  $isnas ? $widths = array(6, 17, 10, 10, 8, 8, 10, 10, 16, 8) : $widths = array(6, 12, 10, 12, 10, 10, 12, 12, 16);
} else {
  $columns = array('#', $aliastitle, gprop('raidlevel'), gprop('capacity'), gprop('stripe'), gprop('sector'), gprop('readpolicy'), gprop('writepolicy'));
  $isnas ? array_push($columns, gprop('fornas')) : null;
  $isnas ? $widths = array(6, 17, 12, 12, 10, 10, 10, 10, 10) : $widths = array(10, 14, 14, 14, 10, 10, 14, 14);

}

table_header_cols($columns, $widths, 'listh');

for ($i = 0; isset($logicalDrives) && $i < count($logicalDrives ?? []); $i++) {
  $logicalDrive = $logicalDrives[$i];
  $raid = getStringRAIDLevel($logicalDrive->rpal_u16RaidLevel);
  if ($logicalDrive->rpal_u16RaidLevel == RAID30 || $logicalDrive->rpal_u16RaidLevel == RAID50 || $logicalDrive->rpal_u16RaidLevel == RAID51 || $logicalDrive->rpal_u16RaidLevel == RAID55 || $logicalDrive->rpal_u16RaidLevel == RAID60)
    $raid .= (': ' . $logicalDrive->rpal_u16NoOfAxle . ' axles');
  if ($isSupportLUNAffinity->isLunAffinitySupport && ($controller->ci_LUNAffinityEnable == 'Enabled')) {
    echo '
	      <tr id=ld' . $i . '  class=tableRow1Cols onclick=selectLD(this) style="cursor:hand' . ($i == $ldindex ? ';background-image:url(images/select.gif)' : '') . '">
	        <td width=6%>' . ($i + 1) . '</td>
	        <td width=17%>' . $logicalDrive->rpal_u8Name . '</td>
	        <td width=10%>' . $raid . '</td>';
    if ($isnas)
      echo '  
	        <td width=10%>' . $logicalDrive->_u64SizeDisplay . $logicalDrive->_strSizeUnit . '</td>
	        <td width=8%>' . $logicalDrive->rpal_u32StripeSize . 'KB</td>
	        <td width=8%>' . getStringLogDrvSectorSize($logicalDrive->rpal_u8SectorSizeCode) . '</td>
	        <td width=10%>' . getStringLogDrvReadPolicyValue($logicalDrive->_readPolicy) . '</td>
	        <td width=10%>' . getStringLogDrvWritePolicy(getLogDrvWritePolicyFromValue($logicalDrive->_writePolicy) |
      getLogDrvReadPolicyFromValue($logicalDrive->_readPolicy)) . '</td>
	       <td width=16%>' . getLunAffinityValue($logicalDrive->rpal_u16PreferredCtrlId) . '</td>
	       <td width=8%>' . gprop("$logicalDrive->rpal_u32LDFlags") . '</td>
	      </tr>';
    else
      echo '  
	        <td width=12%>' . $logicalDrive->_u64SizeDisplay . $logicalDrive->_strSizeUnit . '</td>
	        <td width=10%>' . $logicalDrive->rpal_u32StripeSize . 'KB</td>
	        <td width=10%>' . getStringLogDrvSectorSize($logicalDrive->rpal_u8SectorSizeCode) . '</td>
	        <td width=12%>' . getStringLogDrvReadPolicyValue($logicalDrive->_readPolicy) . '</td>
	        <td width=12%>' . getStringLogDrvWritePolicy(getLogDrvWritePolicyFromValue($logicalDrive->_writePolicy) |
      getLogDrvReadPolicyFromValue($logicalDrive->_readPolicy)) . '</td>
	       <td width=16%>' . getLunAffinityValue($logicalDrive->rpal_u16PreferredCtrlId) . '</td>
	      </tr>';
  } else {
    echo '
      <tr id=ld' . $i . ' class=tableRow1Cols class=text5 onclick=selectLD(this) style="cursor:hand' . ($i == $ldindex ? ';background-image:url(images/select.gif)' : '') . '">';
    if ($isnas)
      echo '
          <td width=6%>' . ($i + 1) . '</td>
        <td width=17%>' . $logicalDrive->rpal_u8Name . '</td>
          <td width=12%>' . $raid . '</td>
          <td width=12%>' . $logicalDrive->_u64SizeDisplay . $logicalDrive->_strSizeUnit . '</td>
          <td width=10%>' . $logicalDrive->rpal_u32StripeSize . 'KB</td>
          <td width=10%>' . getStringLogDrvSectorSize($logicalDrive->rpal_u8SectorSizeCode) . '</td>
	    <td width=10%>' . getStringLogDrvReadPolicyValue($logicalDrive->_readPolicy) . '</td>
	    <td width=10%>' . getStringLogDrvWritePolicy(getLogDrvWritePolicyFromValue($logicalDrive->_writePolicy) |
      getLogDrvReadPolicyFromValue($logicalDrive->_readPolicy)) . '</td>
          <td width=10%>' . gprop("$logicalDrive->rpal_u32LDFlags") . '</td>
        </tr>';
    else
      echo '
        <td width=10%>' . ($i + 1) . '</td>
        <td width=17%>' . $logicalDrive->rpal_u8Name . '</td>
        <td width=14%>' . $raid . '</td>
        <td width=14%>' . $logicalDrive->_u64SizeDisplay . $logicalDrive->_strSizeUnit . '</td>
        <td width=10%>' . $logicalDrive->rpal_u32StripeSize . 'KB</td>
        <td width=10%>' . getStringLogDrvSectorSize($logicalDrive->rpal_u8SectorSizeCode) . '</td>
	<td width=10%>' . getStringLogDrvReadPolicyValue($logicalDrive->_readPolicy) . '</td>
	<td width=10%>' . getStringLogDrvWritePolicy(getLogDrvWritePolicyFromValue($logicalDrive->_writePolicy) |
      getLogDrvReadPolicyFromValue($logicalDrive->_readPolicy)) . '</td>
      </tr>';
  }

}

if (!isset($logicalDrives) || count($logicalDrives ?? []) == 0)
  table_row_empty(gresult('nonewlds'), count($columns));

table_foot_fold();
table_row_submit(array('submit' => 0, 'reset' => 0, 'next' => (count($logicalDrives ?? []) ? 1 : 0)));
}
page_foot();
?>
  </table>
</form>

<script>
  <?php
  if (!isset($noCreate)) {
  ?>
    elements=document.forms[0].elements;
    <?php
    if ($isnas) {
    ?>
      nasEnabled=elements['rpal_u32LDFlags'];
      sector=elements['rpal_u8SectorSizeCode'];
      mountpoint=elements['rpal_mountpoint'];
      aliasname=elements['rpal_u8Name'];
        
      if(nasEnabled[1].checked == true){
        sector.value = 1;
        sector.disabled = true;
        aliasname.disabled = true;
        aliasname.style.background=aliasname.disabled?'#dadada':'#ffffff';
        mountpoint.disabled = false;
        mountpoint.style.background=mountpoint.disabled?'#dadada':'#ffffff';
      } else{
        sector.disabled = false;
        aliasname.disabled = false;
        aliasname.style.background=aliasname.disabled?'#dadada':'#ffffff';
        mountpoint.disabled = true;
        mountpoint.value = '';
        mountpoint.style.background=mountpoint.disabled?'#dadada':'#ffffff';
      }
    <?php

    }
    ?>
    form=document.forms[0];
    form.elements['ldindex'].value=<? echo $ldindex; ?>;
    form.elements['ffindex'].value=<? echo $maxFree; ?>;

    sizeChanged();
    sizeUnitChanged();
    updateAxles();
    updateLDMaxSize();
    updateSizes();

    adjustListCellWidth('listh','ld0');

    ylib_addEvt(elements['_readPolicy'],'change',new Function('RPChanged()'));
    RPChanged();

  <?php

  }
  ?>

  function validateForm()
  {
    elements=document.forms[0].elements;
    rpal_u8Name=elements['rpal_u8Name'];
    rpal_mountpoint=elements['rpal_mountpoint'];
    focusItem = null;        
    if (!validateAlias(rpal_u8Name.value)) {
      msg_u8Name.innerHTML="<?= gjsalert('invalidalias') ?>";
      focusItem=rpal_u8Name;
    } else
      msg_u8Name.innerHTML="";
    if(!rpal_mountpoint.disabled)    
      if ((!validateAlias(rpal_mountpoint.value)) ||(rpal_mountpoint.value == '')) {
        msg_mountpoint.innerHTML="<?= gjsalert('invalidmountpoint') ?>";
        focusItem=rpal_mountpoint;
      } else
        msg_mountpoint.innerHTML="";
          
      if (focusItem!=null) {
        focusItem.focus();
        return false;
      }
    return true;
  }

  function check_radiovalue()
  {
    elements=document.forms[0].elements;
    nasEnabled=elements['rpal_u32LDFlags'];
    sector=elements['rpal_u8SectorSizeCode'];
    mountpoint=elements['rpal_mountpoint'];
    aliasname=elements['rpal_u8Name'];

    if(nasEnabled[0].checked == true){
      sector.disabled = false;
      aliasname.disabled = false;
      aliasname.style.background=aliasname.disabled?'#dadada':'#ffffff';
      mountpoint.disabled = true;
      mountpoint.value = "";
      mountpoint.style.background=mountpoint.disabled?'#dadada':'#ffffff';
    } else{
      sector.value = 1;
      sector.disabled = true;
      aliasname.disabled = true;
      aliasname.style.background=aliasname.disabled?'#dadada':'#ffffff';
      mountpoint.disabled = false;
      mountpoint.style.background=mountpoint.disabled?'#dadada':'#ffffff';
    }
  }

showNumDrives();
</script>

</body>
</html>

<?php
SetSessionValue('lds', $logicalDrives);
SyncSession();
ob_end_flush();
?>
