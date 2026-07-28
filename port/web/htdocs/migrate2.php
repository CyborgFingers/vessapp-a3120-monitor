<?php

/*******************************************************************************
 * Filename    : migrate2.php
 * Description : islaVista disk array migration step 2.
 * Created on  : 01/03/04
 *
 * (C) Copyright Promise Technology Inc.,2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';
include 'ldcfg.inc';

if (isset($_POST['back'])) {
  header('Location: migrate1.php');
  exit;
} else if (isset($_POST['next'])) {
  header('Location: migrate3.php');
  exit;
} else if (isset($_POST['cancel'])) {
  header('Location: array.php?tab=0&note=' . gresult('migrationcancel'));
  exit;
}

$note = null;

$server = getServer();
$subsystem = $server->getSubsystem();
$physicalDrives = $subsystem->getPhysicalDrives();
$subsystem->getDeviceCapabilities();
$array = $subsystem->getDiskArray($_SESSION['daid'] ?? 0);
$PDs = $array->acd_PDs ?? array();   // PHP 8: count(null) is a fatal TypeError
$LDs = $array->acd_LDs ?? array();

// $pdCount=getUnconfigPDCount($physicalDrives);
$pdCount = getAccessiblePDCount($physicalDrives);


$pds = $_SESSION['pds'] ?? array();

for ($i = 0; $i < count($PDs); $i++)
  $pds[] = $PDs[$i]->pdd_PDFlatId;

$logicalDrives = array();
if (isset($_SESSION['lds']))
  $logicalDrives = $_SESSION['lds'];
else {
  for ($i = 0; $i < count($LDs); $i++) {
    $logicalDrives[$i] = new stdClass();   /* PHP 8: no auto-vivification of a null array element into an object */
    $logicalDrives[$i]->rpal_u16LdId = $LDs[$i]->ldd_LDFlatID;
    $logicalDrives[$i]->rpal_u8Name = $LDs[$i]->ldd_LDName;
    if (isset($_SESSION['pds']) && ($LDs[$i]->_raidLevel == RAID10 || $LDs[$i]->_raidLevel == RAID1)) { /* by default: force RAID 10 to RAID 1E when new PD added */
      $LDs[$i]->_raidLevel = RAID01;
      $LDs[$i]->ldd_LDNoOfAxles = 1;
    }
    $logicalDrives[$i]->rpal_u16RaidLevel = $LDs[$i]->_raidLevel;
    $logicalDrives[$i]->rpal_u16NoOfAxle = $LDs[$i]->ldd_LDNoOfAxles;
    $logicalDrives[$i]->rpal_u32StripeSize = $LDs[$i]->ldd_StripeSizeInBlocks / 2;
    $logicalDrives[$i]->rpal_u8SectorSizeCode = $LDs[$i]->ldd_SectorSizeCode;
    $logicalDrives[$i]->orgSize = $LDs[$i]->_LDSize;
    $logicalDrives[$i]->cdd_Start = $LDs[$i]->cdd_Start;
    setLDOrigSize($logicalDrives[$i]);
    $logicalDrives[$i]->_oce = 0;
    $logicalDrives[$i]->origRaidLevel = $LDs[$i]->_raidLevel;
  }
}

$raidLevels = getSupportedRAIDLevels(count($pds));
$raidLevelsfilt = null;
for ($k = 0; $k < count($raidLevels); $k++) {
  $raidLevelsfilt[] = $raidLevels[$k];
}
$raidLevels = $raidLevelsfilt;

$pdsize = getPDConfiguableSize($physicalDrives, $pds);

$ldindex = 0;
if (isset($_POST['ldindex']))
  $ldindex = $_POST['ldindex'];
if ($ldindex < 0)
  $ldindex = 0;

if (isset($_POST['update'])) {
  $logicalDrive = $logicalDrives[$ldindex];
  $logicalDrive->_oce = isset($_POST['_oce']) ? 1 : 0;
  if ($logicalDrive->_oce == 1) {
    $pow = pow(1024, $_POST['rpal_u64Size1']);
    if ($_POST['rpal_u64Size'] == floor($logicalDrive->orgSize / $pow * 100) / 100)
      $newSize = $logicalDrive->orgSize;
    else
      $newSize = double2int($_POST['rpal_u64Size'] * $pow);
  }
  if ($logicalDrive->_oce == 1 && $newSize < $logicalDrive->orgSize)
    $note = galert('migrationinvalidcapacity');
  else if ($logicalDrive->_oce == 1 && $newSize > $_POST['rpal_u64Size3'])
    $note = galert('migrationexceedcapacity');
  else {
    $logicalDrive->rpal_u16RaidLevel = setInteger($_POST['rpal_u16RaidLevel']);
    $logicalDrive->rpal_u16NoOfAxle = isset($_POST['rpal_u16NoOfAxle']) ? setInteger($_POST['rpal_u16NoOfAxle']) : 1;
    if ($logicalDrive->rpal_u16RaidLevel == RAID10 || $logicalDrive->rpal_u16RaidLevel == RAID51)
      $logicalDrive->rpal_u16NoOfAxle = 2;
    if ($logicalDrive->_oce == 1) {
      $logicalDrive->rpal_u64Size = $newSize;
      $logicalDrive->_u64SizeDisplay = $_POST['rpal_u64Size'];
      $logicalDrive->_strSizeUnit = $_POST['_strSizeUnit'];
      $logicalDrive->rpal_u64Size1 = $_POST['rpal_u64Size1'];
      $logicalDrive->_u64ComputeSize = $newSize + 1;
    } else {
      setLDOrigSize($logicalDrive);
    }

    $logicalDrives[$ldindex] = $logicalDrive;
  }
}

updateLDStartAndSize($logicalDrives);

if (isset($logicalDrives)) {
  $freeFrags = getFreeFragments($pdsize / count($pds), $logicalDrives);
  if (count($freeFrags) == 0)
    $note = galert('migrationnocapacitytenlarge');
  for ($i = 0; $i < count($freeFrags); $i++)
    $freeFrags[$i]->orgSize = $freeFrags[$i]->daff_u64Size;

  $maxFree = getMaxFFIndex($freeFrags);
  $lastld = getLastLD($subsystem, $logicalDrives, $ldindex, getFreeFragSize($maxFree));
  $lastld[8] = $logicalDrives[$ldindex]->_u64ComputeSize;
}

function setLDOrigSize($logicalDrive)
{
  $logicalDrive->rpal_u64Size = $logicalDrive->orgSize;
  $logicalDrive->_u64ComputeSize = $logicalDrive->orgSize;
  $logicalDrive->rpal_u64Size1 = 0;
  $logicalDrive->_u64SizeDisplay = $logicalDrive->orgSize;
  while ($logicalDrive->_u64SizeDisplay > 1024) {
    $logicalDrive->rpal_u64Size1 += 1;
    $logicalDrive->_u64SizeDisplay /= 1024;
  }

  $logicalDrive->_u64SizeDisplay = setInteger($logicalDrive->_u64SizeDisplay * 100);
  $logicalDrive->_u64SizeDisplay /= 100;

  $logicalDrive->_strSizeUnit = '';
}

function getFreeFragSize($index)
{
  global $freeFrags, $pds;
  if (isset($freeFrags[$index]))
    return $freeFrags[$index]->daff_u64Size * count($pds);
  return 0;
}

function getMaxFFIndex($freeFrags)
{
  $maxFree = 0;
  for ($i = 1; $i < count($freeFrags); $i++) {
    if ($freeFrags[$i]->daff_u64Size > $freeFrags[$maxFree]->daff_u64Size)
      $maxFree = $i;
  }
  return $maxFree;
}

function updateLDStartAndSize($logicalDrives)
{
  global $pds;
  for ($i = 0; $i < count($logicalDrives); $i++) {
    $logicalDrives[$i]->cdd_ComponentSize = logSizeToPartitionSize(
      $logicalDrives[$i]->_u64ComputeSize,
      count($pds),
      $logicalDrives[$i]->rpal_u16RaidLevel,
      $logicalDrives[$i]->rpal_u16NoOfAxle,
      $logicalDrives[$i]->rpal_u32StripeSize * 1024
    );
    $start = ($i == 0) ? 0 : ($logicalDrives[$i - 1]->cdd_Start + $logicalDrives[$i - 1]->cdd_ComponentSize);
    if (isset($_SESSION['pds']) || ($start != $logicalDrives[$i]->cdd_Start))
    // new PD added,LDs move up. or move down
    $logicalDrives[$i]->cdd_Start = $start;
  }
}
?>

<html>

<head>
<?php
page_head();
?>
<script>
  var logicalDrives=new Array;
  var bars=new Array;
  var frags=new Array;
  var newPDs=0;
  <?php
  if (isset($_SESSION['pds']))
    echo 'newPDs=1;';

  $index = 0;

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

  for ($i = 0; $i < count($logicalDrives); $i++) {
    $logicalDrive = $logicalDrives[$i];
    echo '
          var logicalDrive=new Array;
          logicalDrive[0]="' . $logicalDrive->rpal_u8Name . '";
          logicalDrive[1]=' . $logicalDrive->rpal_u16RaidLevel . ';
          logicalDrive[2]=' . $logicalDrive->rpal_u16NoOfAxle . ';
          logicalDrive[3]=' . $logicalDrive->rpal_u32StripeSize . ';
          logicalDrive[4]=' . $logicalDrive->cdd_Start . ';
          logicalDrive[5]=' . $logicalDrive->cdd_ComponentSize . ';
          logicalDrive[6]=' . $logicalDrive->_u64SizeDisplay . ';
          logicalDrive[7]=' . $logicalDrive->rpal_u8SectorSizeCode . ';
          logicalDrive[8]=' . $logicalDrive->orgSize . ';
          logicalDrive[9]=' . $logicalDrive->_u64ComputeSize . ';
          logicalDrive[10]=' . $logicalDrive->rpal_u64Size1 . ';
          logicalDrive[11]=' . $logicalDrive->_oce . ';
          logicalDrive[12]=' . $logicalDrive->origRaidLevel . ';
          logicalDrives[' . $i . ']=logicalDrive;';

    echo '
          var bar=new Array;
          bar[0]="new";
          bar[1]=' . $logicalDrive->cdd_Start . ';
          bar[2]=' . $logicalDrive->cdd_ComponentSize . ';
          bar[3]=' . $i . ';
          bar[4]=' . $logicalDrive->rpal_u16LdId . ';
          bars[' . $index++ . ']=bar;';
  }
  ?>

  for (var i=0; i < bars.length-1; i++) {
    for (j=i+1; j < bars.length; j++) {
      if (!bars[j]) break;
      if ((bars[i][1] > bars[j][1])||(bars[i][1]==bars[j][1]&&bars[i][0]=='free')) {
        t=bars[j];
        bars[j]=bars[i];
        bars[i]=t;
      }
    }
  }


  function oceChanged()
  {
    elements=document.forms[0].elements;
    if (elements['_oce'].checked == true){
      elements['rpal_u64Size'].disabled=false;
      elements['rpal_u64Size1'].disabled=false;
    } else{
      elements['rpal_u64Size'].disabled=true;
      elements['rpal_u64Size1'].disabled=true;
    }
  }

  function getObjIndex(obj,name)
  {
    for (var i=0; i < <? echo (MAX_LOGDRV_PER_ARRAY * 2) ?>; i++) {
      id=name+i;
      if (ylib_getObj(id)==obj)
        return i;
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

    updateSizes();
    updateRAIDLevel();
    migrateUpdateLDMaxSize();

    oceChanged();
    updateAxles();
  }
	 var raidLevelValue=new Array;

	<?php

  for ($i = 0; $i < count($raidLevels); $i++) {
    echo '
            raidLevelValue[' . $i . ']="' . $raidLevels[$i] . '";';
  }

  ?>
  var raidLevelText=new Array;

	<?php

  for ($i = 0; $i < count($raidLevels); $i++) {
    echo '
            raidLevelText[' . $i . ']="' . getStringRAIDLevel($raidLevels[$i]) . '";';
  }

  ?>

	function getPhyCap(getRaidLDCap,raidLevel,axles,pdCount)
	{
		var getRaidPhyCap=0;
		switch(raidLevel){
		      case <? echo RAID1 ?>:
		      case <? echo RAID10 ?>:
		      case <? echo RAID01 ?>:
		      case '<? echo RAID1 ?>':
		      case '<? echo RAID10 ?>':
		      case '<? echo RAID01 ?>':
		        getRaidPhyCap=2*getRaidLDCap;
		        break;
		      case <? echo RAID3 ?>:
		      case '<? echo RAID3 ?>':
		      case <? echo RAID5 ?>:
		      case '<? echo RAID5 ?>':
		        getRaidPhyCap=Math.floor(getRaidLDCap/(pdCount-1))*pdCount;
		        break;
		      case <? echo RAID30 ?>:
		      case '<? echo RAID30 ?>':
		      case <? echo RAID50 ?>:
		      case '<? echo RAID50 ?>':
		        getRaidPhyCap=Math.floor(getRaidLDCap/(pdCount-axles))*pdCount;
		        break;
		      case <? echo RAID51 ?>:
		      case '<? echo RAID51 ?>':
		        getRaidPhyCap=Math.floor(getRaidLDCap/(pdCount-2))*2*pdCount;
		        break;
		      case <? echo RAID6 ?>:
		      case '<? echo RAID6 ?>':
		        getRaidPhyCap=Math.floor(getRaidLDCap/(pdCount-2))*pdCount;
		        break;
		      case <? echo RAID60 ?>:
		      case '<? echo RAID60 ?>':
		        getRaidPhyCap=Math.floor(getRaidLDCap/(pdCount-2*axles))*pdCount;
		        break;
		      case <? echo RAID0 ?>:
		      case <? echo JBOD ?>:
		      default:
		        getRaidPhyCap=getRaidLDCap;
		        break;
		}
		return getRaidPhyCap;
	}

	function updateRAIDLevel()
	{
	  elements=document.forms[0].elements;
    index=elements['ldindex'].value;
	  var powsize=Math.pow(1024,elements['rpal_u64Size1'].value);
    var raidlevel=document.getElementsByName('rpal_u16RaidLevel');
    var raidindex=new Array();
    var count=0;
    var usedSize=0;
    var ldRaidLevel = logicalDrives[index][1];
    for (var i=0; i<logicalDrives.length;i++) {
      usedSize+=logicalDrives[i][5]; // comp size
    }
    var pdCount=<? echo count($pds) ?>;
	  var getOrgPhyCap=getPDSize(logicalDrives[index],<? echo count($pds) ?>);
	  var getfreePhyCap=<? echo $pdsize ?>-usedSize*pdCount;
	  var getRaidLDCap=elements['rpal_u64Size'].value*powsize;
	  var phyOrgFreeCap=getOrgPhyCap+getfreePhyCap;

	  for(i=0;i<raidLevelValue.length;i++){
      var getRaidPhyCap=getPhyCap(getRaidLDCap,raidLevelValue[i],logicalDrives[index][2],pdCount);
      if( phyOrgFreeCap >= getRaidPhyCap){
        raidindex[count]=i;
        count++;
	 	  }
	  }
    /*remove options*/
    while(typeof(raidlevel[0].options)!='undefined' && raidlevel[0].options.length>0){
      raidlevel[0].removeChild(raidlevel[0].options[0]);
	  }
	  /*add options*/
	  var newflag=1;
	  for(i=0;i<raidindex.length;i++){
      if( (ldRaidLevel==<? echo RAID1; ?> || ldRaidLevel==<? echo RAID01; ?>) 
        && (raidLevelValue[raidindex[i]]==<? echo RAID6; ?> ||raidLevelValue[raidindex[i]]==<? echo RAID60; ?>) ){
        continue;
      }
      if( (ldRaidLevel==<? echo RAID6; ?> || ldRaidLevel==<? echo RAID60; ?>) 
        && raidLevelValue[raidindex[i]]!=<? echo RAID6; ?> && raidLevelValue[raidindex[i]]!=<? echo RAID60; ?>) {
        continue;
      }
      if(!checkLDMaxSize(raidLevelValue[raidindex[i]])){
        continue;
      }
      ylib_addOption(raidlevel[0], raidLevelText[raidindex[i]], raidLevelValue[raidindex[i]]);
	  }
 	  elements['rpal_u16RaidLevel'].value=logicalDrives[index][1];
	}

  function selectSize(obj)
  {
    var index=getObjIndex(obj,'size');
    document.forms[0].elements['barindex'].value=index;

    if (index >= 0 && bars[index][0]=='new') {
      id='ld'+bars[index][3];
      selectLD(ylib_getObj(id));
    }
  }

  function checkLDMaxSize(raidLevel)
  {
    elements=document.forms[0].elements;
    index=elements['ldindex'].value;
    usedSize=0;
    for (var i=0; i<logicalDrives.length;i++) {
      if (i!=index) {
        usedSize+=logicalDrives[i][5]; // comp size
      }
    }

    var pdCount=<? echo count($pds) ?>;
    var size = <? echo $pdsize ?>-usedSize*pdCount;
    var minSize=logicalDrives[index][8]; // orignal size
    var stripe = logicalDrives[index][3];
    var axles = elements['rpal_u16NoOfAxle'].value;
    if(axles == ""){
      if(raidLevel == <? echo RAID30 ?> ||raidLevel == <? echo RAID50 ?> || raidLevel == <? echo RAID60 ?>){
        axles = 2;
      }
    }

    var maxSize=getLDSize(size,raidLevel,axles,stripe,pdCount);
    if(maxSize < minSize){
      return false;
    } else
      return true;
  }

  function migrateUpdateLDMaxSize()
  {
    elements=document.forms[0].elements;
    index=elements['ldindex'].value;
    usedSize=0;
    for (var i=0; i<logicalDrives.length;i++) {
      if (i!=index) {
        usedSize+=logicalDrives[i][5]; // comp size
      }
    }

    var pdCount=<? echo count($pds) ?>;
    var size = <? echo $pdsize ?>-usedSize*pdCount;
    var minSize=logicalDrives[index][8]; // orignal size
    var raidLevel = elements['rpal_u16RaidLevel'].value;
    var axles = elements['rpal_u16NoOfAxle'].value;
    var stripe = logicalDrives[index][3];

    var maxSize=getLDSize(size,raidLevel,axles,stripe,pdCount);

    updateMinMaxSize(minSize, maxSize);
  }

  function axlesChanged()
  {
    migrateUpdateLDMaxSize();
  }

  function getPercentage(size)
  {
    p=size * <? echo count($pds) * 100 / $pdsize ?>;
    if (p < 1) p=1;
      return Math.round(p);
  }

  function getLaterUsedSize(index)
  {
    var usedSize=0;
    for (var i=Math.round(index)+1;i<logicalDrives.length;i++)
      usedSize+=getPDSize(logicalDrives[i],<? echo count($pds) ?>);
    return usedSize;
  }

  function getMaxSize(index,raidLevel,axles,stripe)
  {
      var pdCount=<? echo count($pds) ?>;
      var size=<? echo $pdsize ?>-((index>=0)?logicalDrives[index][4]*pdCount:0)-getLaterUsedSize(index);
      return getLDSize(size,raidLevel,axles,stripe,pdCount);
  }

  function updateSizes()
  {
    ldindex=document.forms[0].elements['ldindex'].value;
    index=document.forms[0].elements['barindex'].value;

    for (var i=0; i < bars.length; i++) {
      id='size'+i;
      if (!ylib_getObj(id))
        continue;

      ylib_getObj(id).style.backgroundImage='';
      if (i==index || (bars[i][0]=='new' && bars[i][3]==ldindex))
        ylib_getObj(id).style.backgroundImage='url(images/select.gif)';

      if (bars[i][0]=='new')
        ylib_getObj(id).style.backgroundColor='<? echo $USED_COLOR; ?>';
      else if (bars[i][0]=='free')
        ylib_getObj(id).style.backgroundColor='<? echo $FREE_COLOR; ?>';
    }
  }
</script>

</head>

<body>
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center>
<?php
table_title(gprop('diskarraymigration'), '', $note);
table_tab_head();
$s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner');
$tabTitle = tabTitleArray($subsystem, $_SESSION['daid']);
table_tab($tabTitle);

table_head_fold(gprop('migrationstep2'), 'MLD', null, null, $s);

if ($logicalDrives == null) {
  table_row_empty(gresult('migrationnold'));
  table_foot_fold();
  table_tab_foot();
} else {
  $leftWidth = 25;
  table_row(gprop('alias'), $lastld[0], $leftWidth, 0, 'rpal_u8Name');

  // RAID Level
  for ($i = 0; $i < count($raidLevels); $i++)
    $raidLevel[] = array($raidLevels[$i], getStringRAIDLevel($raidLevels[$i]));
  //table_row_combo(gprop('raidlevel'),$raidLevel,'rpal_u16RaidLevel',$lastld[1]);
  ?>
    <tr>
      <td class=tableRowCol1><span><?= gprop('raidlevel') ?></span></td>
      <td class=tableRowColOther><span>
        <select class=editField name=rpal_u16RaidLevel onchange=raidLevelChanged()>
<?php
for ($i = 0; $i < count($raidLevel); $i++) {
  echo '<option value=' . $raidLevel[$i][0];
  if ($lastld[1] == $raidLevel[$i][0])
    echo ' selected';
  echo '>' . $raidLevel[$i][1] . '</option>';
}
echo '
        </select>';
?>
<?php

// number of axles
$axles = 0;
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

<?php
echo '
        <span id=rpal_u16NoOfAxle1 style="visibility:hidden" nowrap>
          &nbsp;Number of Axles
        </span>
        <select class=editField1 name=rpal_u16NoOfAxle style="visibility:hidden"
             onchange=axlesChanged()>';
for ($i = 0; isset($axles) && $i < (is_array($axles) ? count($axles) : 1); $i++) {
  echo '<option value=' . $axles[$i];
  if ($lastld[2] == $axles[$i])
    echo ' selected';
  echo '>' . $axles[$i] . '</option>';
}
?>
        </select>
      </span></td>
    </tr>
<?php
table_row_checkbox(gprop('expandcapacity'), $lastld[10], '_oce', $leftWidth);
?>
    <tr>
      <td class=tableRowCol1><span><?= gprop('capacity') ?></span></td>
      <td class=tableRowColOther><span>
        <input class=editField2 type=text name=rpal_u64Size value='<? echo $lastld[6] ?>' onchange="modified=1">
        <input type=hidden name=rpal_u64Size0 value='<? echo $lastld[8] ?>'>
        <input type=hidden name=_strSizeUnit value=' '>
        <select class=editField1 name=rpal_u64Size1 onchange=sizeUnitChanged()>
<?php
for ($i = 0; $i < count($sizeUnits); $i++)
  echo '<option value=' . $i . '>' . $sizeUnits[$i] . '</option>';
?>
        </select>
        <span id=rpal_u64Size2></span>
        <input type=hidden name=rpal_u64Size3 value=0>
        <input type=hidden name=rpal_u32StripeSize value=<?= $lastld[3] ?>>
        <input type=hidden name=rpal_u8SectorSizeCode value=<?= $lastld[7] ?>>
        <input type=hidden name=origRaidLevel value=<?= $lastld[1] ?>>
      </span></td>
    </tr>
<?php
// table_row_combo('Stripe',$stripeSize,'rpal_u32StripeSize',$lastld[3],$leftWidth);
// table_row_combo('Sector',$sectorSize,'rpal_u8SectorSizeCode',$lastld[7],$leftWidth);
?>
    <tr class=tableHeader>
      <td colspan=2 class=text3>
        <input type=submit class=input-p value=' Update ' name=update>
        &nbsp;
        <input type=hidden value=0 name=ldindex>
        <input type=hidden value='-1' name=barindex>
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

  for (var i=0; i < bars.length; i++) {
    if (bars[i][2] > 0) {
      document.write('<td border=1px bordercolor=#121212 height=19px id=size'+i+' width='+
          getPercentage(bars[i][2]) +
          '% onclick=selectSize(this)');
      if (bars[i][0]=='new')
        document.write(' style="cursor:hand"');
      document.write('>');
      if (bars[i][0]=='new')
        document.write(bars[i][4]);
      else if (bars[i][0]=='free' && i==0 && bars.length==1)
        document.write('Free Capacity');
      document.write('</td>');
    }
  }

</script>
    </tr>
<?php
table_foot();

table_head();
?>
    <tr class=tableImageBackground>
      <td bgcolor=<? echo $USED_COLOR; ?>>&nbsp;&nbsp;&nbsp;&nbsp;</td>
      <td nowrap><?= gprop('logicaldrives') ?>&nbsp;&nbsp;</td>
      <td bgcolor=<? echo $FREE_COLOR; ?>>&nbsp;&nbsp;&nbsp;&nbsp;</td>
      <td nowrap><?= gprop('freecapacity') ?></td>
      <td width=90%></td>
    </tr>
<?php
table_foot();
table_foot_fold();

table_head_fold(gprop('logicaldrives'), 'LD', null, null, $s);
/**table_head_fold_list(gprop('logicaldrives'),'LD', $s);**/

$columns = array(gprop('logicaldriveid'), gprop('alias'), gprop('raidlevel'), gprop('capacity'), gprop('stripe'), gprop('sector'));
$widths = array(15, 20, 20, 15, 15, 15);
table_header_cols($columns, $widths, 'listh');



for ($i = 0; isset($logicalDrives) && $i < count($logicalDrives); $i++) {
  $logicalDrive = $logicalDrives[$i];
  $raid = getStringRAIDLevel($logicalDrive->rpal_u16RaidLevel);
  if ($logicalDrive->rpal_u16RaidLevel == RAID30 ||
    $logicalDrive->rpal_u16RaidLevel == RAID50 ||
    $logicalDrive->rpal_u16RaidLevel == RAID60 ||
    $logicalDrive->rpal_u16RaidLevel == RAID55)
    $raid .= (': ' . $logicalDrive->rpal_u16NoOfAxle . ' axles');

  echo '
        <tr id=ld' . $i . ' class=tableRow1Cols
          onclick=selectLD(this) style="cursor:hand';
  if ($i == $ldindex)
    echo ';background:#0078D8';
  echo '">
          <td width=15%>' . $logicalDrive->rpal_u16LdId . '</td>
          <td width=20%>' . $logicalDrive->rpal_u8Name . '</td>
          <td width=20%>' . $raid . '</td>
		  <td width=15%>' . $logicalDrive->_u64SizeDisplay . $sizeUnits[$logicalDrive->rpal_u64Size1] . '</td>
          <td width=15%>' . $logicalDrive->rpal_u32StripeSize . 'KB</td>
          <td width=15%>' . getStringLogDrvSectorSize($logicalDrive->rpal_u8SectorSizeCode) . '</td>
        </tr>';
}

if (!isset($logicalDrives) || count($logicalDrives) == 0)
  table_row_empty('There is no new logical drive.', count($columns));


table_foot_fold();

if ($pdCount)
  table_row_submit(array('submit' => 0, 'back' => 1, 'next' => 1, 'reset' => 0));
else
  table_row_submit(array('submit' => 0, 'next' => 1, 'reset' => 0));
}
page_foot();
?>

  </table>
</form>

<script>
  minchang=1;
  elements=document.forms[0].elements;
  elements['ldindex'].value=<? echo $ldindex ?>;
  ylib_addEvt(document.forms[0].elements['_oce'],'click',new Function('oceChanged()'));

  // elements['rpal_u16RaidLevel'].disabled=true;
  // elements['rpal_u32StripeSize'].style.visibility='hidden';
  // elements['rpal_u8SectorSizeCode'].style.visibility='hidden';
  modified=0;

  sizeChanged();
  sizeUnitChanged();
  updateAxles();
  migrateUpdateLDMaxSize();
  updateSizes();

  oceChanged();
  updateRAIDLevel();
  // adjustListDivHeight(400);
  adjustListCellWidth('listh','ld0');
</script>

</body>
</html>

<?php
SetSessionValue('lds', $logicalDrives);
SyncSession();
?>
