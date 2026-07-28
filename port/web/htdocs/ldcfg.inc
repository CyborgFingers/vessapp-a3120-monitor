<?php

/*******************************************************************************
 * Filename    : ldcfg.php
 * Description : islaVista create and add logical drives common functions.
 * Created on  : 12/10/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include_once 'defense.php';

$stripeSize = array(
  array(64, '64KB'),
  array(128, '128KB'),
  array(256, '256KB'),
  array(512, '512KB'),
  array(1024, '1MB')
);

$sectorSize = array(
  array(1, getStringLogDrvSectorSize(1)),
  array(2, getStringLogDrvSectorSize(2)),
  array(3, getStringLogDrvSectorSize(3)),
  array(4, getStringLogDrvSectorSize(4))
);

$writePolicy = array(
  array(0, getStringLogDrvWritePolicyValue(0)),
  array(1, getStringLogDrvWritePolicyValue(1))
);

$readPolicy = array(
  array(0, getStringLogDrvReadPolicyValue(0)),
  array(1, getStringLogDrvReadPolicyValue(1)),
  array(2, getStringLogDrvReadPolicyValue(2))
);

$PreferredOwnerControllerId = array(array(0, 1), array(1, 2));


function getNextName($name)
{
  $c = substr($name, strlen($name) - 1, 1);
  if ($c == '9')
    return getNextName(substr($name, 0, strlen($name) - 1)) . '0';
  if ($c >= '0' && $c < '9')
    return substr($name, 0, strlen($name) - 1) . ($c + 1);
  return $name . '1';
}

function getLastLD($subsystem, $logicalDrives, $ldindex, $pdsize)
{
  if ($ldindex >= 0) {
    $lastld[] = $logicalDrives[$ldindex]->rpal_u8Name;
    $lastld[] = $logicalDrives[$ldindex]->rpal_u16RaidLevel;
    $lastld[] = $logicalDrives[$ldindex]->rpal_u16NoOfAxle;
    $lastld[] = $logicalDrives[$ldindex]->rpal_u32StripeSize;
    $lastld[] = isset($logicalDrives[$ldindex]->_writePolicy) ? $logicalDrives[$ldindex]->_writePolicy : 1;
    $lastld[] = isset($logicalDrives[$ldindex]->_readPolicy) ? $logicalDrives[$ldindex]->_readPolicy : 2;
    //$lastld[]=$logicalDrives[$ldindex]->rpal_u64Size;
    $lastld[] = $logicalDrives[$ldindex]->_u64SizeDisplay;
    $lastld[] = $logicalDrives[$ldindex]->rpal_u8SectorSizeCode;
    $lastld[] = $logicalDrives[$ldindex]->rpal_u64Size;
    $lastld[] = $logicalDrives[$ldindex]->rpal_u64Size1;
    if (isset($logicalDrives[$ldindex]->_oce))
      $lastld[] = $logicalDrives[$ldindex]->_oce;
    else
      $lastld[] = 0;
    if (isset($logicalDrives[$ldindex]->rpal_u16PreferredCtrlId))
      $lastld[] = $logicalDrives[$ldindex]->rpal_u16PreferredCtrlId;
    else
      $lastld[] = 0;
    if (isset($logicalDrives[$ldindex]->origRaidLevel))
      $lastld[] = $logicalDrives[$ldindex]->origRaidLevel;
    else
      $lastld[] = $logicalDrives[$ldindex]->rpal_u16RaidLevel;
  } else if (isset($_POST['update']) || isset($_POST['add'])) {
    $lastld[] = isset($_POST['rpal_u8Name']) ? getNextName($_POST['rpal_u8Name']) : '';
    $lastld[] = isset($_POST['rpal_u16RaidLevel']) ? $_POST['rpal_u16RaidLevel'] : RAID0;
    $lastld[] = isset($_POST['rpal_u16NoOfAxle']) ? $_POST['rpal_u16NoOfAxle'] : 1;
    $lastld[] = isset($_POST['rpal_u32StripeSize']) ? $_POST['rpal_u32StripeSize'] : 64;
    $lastld[] = isset($_POST['_writePolicy']) ? $_POST['_writePolicy'] : 1;
    $lastld[] = isset($_POST['_readPolicy']) ? $_POST['_readPolicy'] : 2;
    $lastld[] = $_POST['rpal_u64Size'];
    $lastld[] = isset($_POST['rpal_u8SectorSizeCode']) ? $_POST['rpal_u8SectorSizeCode'] : 1;
    $lastld[] = $_POST['rpal_u64Size'] * pow(1024, $_POST['rpal_u64Size1']);
    $lastld[] = $_POST['rpal_u64Size1'];
    $lastld[] = isset($_POST['_oce']) ? 1 : 0;
    $lastld[] = isset($_POST['rpal_u16PreferredCtrlId']) ? $_POST['rpal_u16PreferredCtrlId'] : 2;
    $lastld[] = isset($_POST['origRaidLevel']) ? $_POST['origRaidLevel'] : RAID0;
  } else {
    $numLDs = count($subsystem->getLogicalDrives() ?? []) + count($logicalDrives ?? []);
    // default settings
    $lastld[] = 'LD' . $numLDs;
    $lastld[] = RAID0;
    $lastld[] = 1;
    $lastld[] = 64; // stripe: 64KB
    $lastld[] = 1;  // WP: write back
    $lastld[] = 1;  // RP: read ahead
    // $lastld[]=$pdsize/pow(1024,2);
    $lastld[] = $pdsize;
    $lastld[]=4;  // sector: 4 (4KB) [RB-261093]
    $lastld[] = $pdsize;
    $lastld[] = 2; // size unit
    $lastld[] = 0; // oce
    $lastld[] = 2; // rpal_u16PreferredCtrlId
    $lastld[] = RAID0; // orig raid level
  }

  return $lastld;
}

function getLunAffinityValue($param)
{
  if ($param == 2)
    return gprop('automatic');
  else
    return $param + 1;
}
ob_start();
?>

<script>
  var sizeUnits=new Array;
  sizeUnits[0]='B';
  sizeUnits[1]='KB';
  sizeUnits[2]='MB';
  sizeUnits[3]='GB';
  sizeUnits[4]='TB';

  var SectorSizesmin=new Array;
   <?php
  echo '
          SectorSizesmin[0]="' . $sectorSize[0][1] . '";';
  echo '
          SectorSizesmin[1]="' . $sectorSize[1][1] . '";';
  echo '
          SectorSizesmin[2]="' . $sectorSize[2][1] . '";';
  echo '
          SectorSizesmin[3]="' . $sectorSize[3][1] . '";';

  ?>

  modified=0;
  minchange=0;

  function floor2d(s)
  {
    return Math.floor(s*100)/100;
  }

  function size2d(size,unit)
  {
    return floor2d(size/Math.pow(1024,unit));
  }

  function size2du(size,unit)
  {
    return size2d(size,unit)+sizeUnits[unit];
  }

  function unselectAllRows()
  {
    for(var i=0;i<<? echo MAX_LOGDRV_PER_ARRAY ?>;i++){
      id='ld'+i;
      if(ylib_getObj(id))
        ylib_getObj(id).style.background='';
    }
  }

  function sizeChanged()
  {
    elements=document.forms[0].elements;
    // set size unit
    // size=elements['rpal_u64Size0'].value/1024/1024;
    size=elements['rpal_u64Size0'].value;
    elements['rpal_u64Size1'].value = 0;
    while(size>=1024&&elements['rpal_u64Size1'].value<sizeUnits.length){
      elements['rpal_u64Size1'].value++;
      size/=1024;
     // elements['rpal_u64Size'].value = elements['rpal_u64Size'].value/1024;
    }
    elements['_strSizeUnit'].value = sizeUnits[elements['rpal_u64Size1'].value];
  }

  function sizeUnitChanged()
  {
    elements=document.forms[0].elements;
    unit=elements['rpal_u64Size1'];
    // elements['_strSizeUnit'].value = sizeUnits[unit.selectedIndex+2];
    // var msize=size2d(elements['rpal_u64Size0'].value,unit.selectedIndex+2);
    elements['_strSizeUnit'].value = sizeUnits[unit.selectedIndex];
    var msize=size2d(elements['rpal_u64Size0'].value,unit.selectedIndex);
    if (modified==0 || elements['rpal_u64Size'].value>(msize+0.01))
      elements['rpal_u64Size'].value=msize;
  }

  function sectorChanged()
  {
    updateLDMaxSize()
  }

  function raidLevelChanged()
  {
    updateAxles();
    axlesChanged();
    showNumDrives();

  }
  function showNumDrives()
  {
  	form=document.forms[0];
    selectedLevel=form.elements['rpal_u16RaidLevel'].options[form.elements['rpal_u16RaidLevel'].selectedIndex].text;
	  aa=document.getElementById('numdrives');
    if(aa!=null){
      if(selectedLevel=='RAID6'){
        // numdrives.style.display='inline';
        ylib_show(aa);
      } else{
        // numdrives.style.display='none';
        ylib_hide(aa);
      }
    }
  }

  function updateAxles()
  {
    form=document.forms[0];

    selectedLevel=form.elements['rpal_u16RaidLevel'].value;
    origLevel=form.elements['origRaidLevel'];
    if(((origLevel&&origLevel.value!=selectedLevel)||(origLevel==null))
      &&(selectedLevel==<? echo RAID60; ?>||selectedLevel==<? echo RAID30; ?>||selectedLevel==<? echo RAID50; ?>||selectedLevel==<? echo RAID55; ?>)){
      ylib_show(form.elements['rpal_u16NoOfAxle']);
      ylib_show(ylib_getObj('rpal_u16NoOfAxle1'));
      updateAxlesRaidLevel(selectedLevel);
    } else{
      ylib_hide(form.elements['rpal_u16NoOfAxle']);
      ylib_hide(ylib_getObj('rpal_u16NoOfAxle1'));
    }
  }

  function updateAxlesRaidLevel(selectedLevel)
  {
    elements=document.forms[0].elements;
    var axles=new Array;
    if(selectedLevel==80)
      axles=axlesRaid50;
    else if(selectedLevel==85)
      axles=axlesRaid55;
    else if(selectedLevel==96)
      axles=axlesRaid60;
    else if(selectedLevel==48)
      axles=axlesRaid30;
      
    var sel=document.getElementsByName('rpal_u16NoOfAxle');
    while(typeof(sel[0].options)!='undefined' && sel[0].options.length>0){
      sel[0].removeChild(sel[0].options[0]);
    }
    for(i=0;i<axles.length;i++){
      ylib_addOption(sel[0], axles[i], axles[i]);
    }
  }

  function updateLD(index)
  {
    logicalDrive=logicalDrives[index];
    elements=document.forms[0].elements;
    if(elements['rpal_u32LDFlags']){
      if(logicalDrive[12]=='yes'){
        if(elements['rpal_mountpoint'])
          elements['rpal_mountpoint'].value=logicalDrive[0];
        else if(ylib_getObj('rpal_mountpoint'))
          ylib_getObj('rpal_mountpoint').innerHTML=logicalDrive[0]
      } else{
        if(elements['rpal_u8Name'])
          elements['rpal_u8Name'].value=logicalDrive[0];
        else if(ylib_getObj('rpal_u8Name'))
          ylib_getObj('rpal_u8Name').innerHTML=logicalDrive[0]
      }
    } else{
      if(elements['rpal_u8Name'])
        elements['rpal_u8Name'].value=logicalDrive[0];
      else if(ylib_getObj('rpal_u8Name'))
        ylib_getObj('rpal_u8Name').innerHTML=logicalDrive[0]
    }
    elements['rpal_u16RaidLevel'].value=logicalDrive[1];
    if(elements['origRaidLevel']==null&&elements['rpal_u16NoOfAxle'])
      elements['rpal_u16NoOfAxle'].value=logicalDrive[2];
    elements['rpal_u32StripeSize'].value=logicalDrive[3];
    if(elements['_writePolicy'])
      elements['_writePolicy'].value=logicalDrive[4];
    if(elements['_readPolicy'])
      elements['_readPolicy'].value=logicalDrive[5];
    if(elements['_PreferredOwnerControllerId'])
      elements['_PreferredOwnerControllerId'].value=logicalDrive[13];
    if(elements['rpal_u32LDFlags']){
      if(logicalDrive[12]=='yes')
        elements['rpal_u32LDFlags'][1].checked = true;
      else
        elements['rpal_u32LDFlags'][0].checked = true;
    }
    elements['rpal_u64Size0'].value=logicalDrive[9];
    elements['rpal_u64Size'].value=logicalDrive[6];
    // elements['rpal_u64Size1'].value=2; //logicalDrive[10];
    elements['rpal_u64Size1'].value=0;
    elements['rpal_u8SectorSizeCode'].value=logicalDrive[7];
    if (elements['_oce']){
      if (logicalDrive[11]==1)
        elements['_oce'].checked = true;
      else
        elements['_oce'].checked = false;
    }
    if (elements['origRaidLevel'])
      elements['origRaidLevel'].value = logicalDrive[12];

    if (elements['rpal_u32LDFlags']){
      if(elements['rpal_u32LDFlags'][0].checked == true){
        elements['rpal_u8SectorSizeCode'].disabled = false;
        elements['rpal_u8Name'].disabled = false;
        elements['rpal_u8Name'].style.background=elements['rpal_u8Name'].disabled?'#dadada':'#ffffff';
        elements['rpal_mountpoint'].disabled = true;
        elements['rpal_mountpoint'].value = '';
        elements['rpal_mountpoint'].style.background=elements['rpal_mountpoint'].disabled?'#dadada':'#ffffff';

      } else{
        elements['rpal_u8SectorSizeCode'].value = 1;
        elements['rpal_u8SectorSizeCode'].disabled = true;
        elements['rpal_u8Name'].disabled = true;
        elements['rpal_u8Name'].value = '';
        elements['rpal_u8Name'].style.background=elements['rpal_u8Name'].disabled?'#dadada':'#ffffff';
        elements['rpal_mountpoint'].disabled = false;
        elements['rpal_mountpoint'].style.background=elements['rpal_mountpoint'].disabled?'#dadada':'#ffffff';
      }
    }
    sizeChanged();
    sizeUnitChanged();
    updateAxles();
  }

  function updateMaxSize(maxSize)
  {
    updateMinMaxSize(0,maxSize);
  }

  function updateMinMaxSize(minSize,maxSize)
  {
    elements=document.forms[0].elements;
    if(modified==0)
      elements['rpal_u64Size3'].value=maxSize;

    unit=elements['rpal_u64Size1'].value;
    if(minSize){
      if( (sizeUnits[unit]=='KB') && minchange==1)
        ylib_getObj('rpal_u64Size2').innerHTML='Min: '+SectorSizesmin[[elements['rpal_u8SectorSizeCode'].value]-1]+', Max: '+size2du(maxSize,unit);
      else if( sizeUnits[unit]=='B'&& minchange==1)
        ylib_getObj('rpal_u64Size2').innerHTML='Min: '+SectorSizesmin[[elements['rpal_u8SectorSizeCode'].value]-1]+', Max: '+size2du(maxSize,unit);
      else
        ylib_getObj('rpal_u64Size2').innerHTML='Min: '+size2du(minSize,unit)+', Max: '+size2du(maxSize,unit);
    } else
      ylib_getObj('rpal_u64Size2').innerHTML='Maximum: '+size2du(maxSize,unit);
    // size should not be larger than max size
    if(elements['rpal_u64Size0'].value>maxSize)
      elements['rpal_u64Size0'].value=maxSize;
    else if(elements['rpal_u64Size0'].value<minSize)
      elements['rpal_u64Size0'].value=minSize;
    else 
      elements['rpal_u64Size0'].value=maxSize;

    // update size
    sizeUnitChanged();
  }

  function updateLDMaxSize()
  {
    elements=document.forms[0].elements;
    maxSize=getMaxSize(elements['ldindex'].value,elements['rpal_u16RaidLevel'].value,
      elements['rpal_u16NoOfAxle'].value,elements['rpal_u32StripeSize'].value);
    updateMinMaxSize((elements['ldindex'].value>=0&&logicalDrives[elements['ldindex'].value][7])?
      logicalDrives[elements['ldindex'].value][7]:0,maxSize);
  }

  function getPartitionSize(ldSize,raidLevel,axles,pdCount)
  {
    switch(raidLevel){
    case <? echo RAID1 ?>:
    case <? echo RAID10 ?>:
    case <? echo RAID01 ?>:
      size=ldSize*2/pdCount;
      break;
    case <? echo RAID3 ?>:
      size=ldSize/(pdCount-1);
      break;
    case <? echo RAID5 ?>:
      size=ldSize/(pdCount-1);
      break;
    case <? echo RAID30 ?>:
      size=ldSize/(pdCount-axles);
      break;
    case <? echo RAID50 ?>:
      size=ldSize/(pdCount-axles);
      break;
    case <? echo RAID51 ?>:
      size=ldSize/(pdCount/2-1);
      break;
    case <? echo RAID6 ?>:
      size=ldSize/(pdCount-2);
      break;
    case <? echo RAID60 ?>:
      size=ldSize/(pdCount-2*axles);
      break;
    case <? echo RAID0 ?>:
    case <? echo JBOD ?>:
    default:
      size=ldSize/pdCount;
      break;
    }
    return size;
  }

  function getPDSize(logicalDrive,pdCount)
  {
    var size=getPartitionSize(logicalDrive[9],logicalDrive[1],logicalDrive[2],pdCount);
    var stripe=logicalDrive[3]*1024;
    if(logicalDrive[1]==<? echo RAID01 ?>||logicalDrive[1]=='<? echo RAID01 ?>')
      stripe*=2;
    if(size%stripe)
      size=Math.ceil((size+stripe)/stripe)*stripe;
    return size*pdCount;
  }

  function getLDSize(size,raidLevel,axles,stripe,pdCount)
  {
    size=Math.floor(size/pdCount);
    stripe*=1024;
    if(raidLevel==<? echo RAID01 ?>) stripe*=2;
    if(size%stripe) size=Math.floor(size/stripe)*stripe;

    switch(raidLevel){
      case <? echo RAID1 ?>:
      case <? echo RAID10 ?>:
      case <? echo RAID01 ?>:
      case '<? echo RAID1 ?>':
      case '<? echo RAID10 ?>':
      case '<? echo RAID01 ?>':
        size=Math.floor(size/2)*pdCount;
        break;
      case <? echo RAID3 ?>:
      case '<? echo RAID3 ?>':
      case <? echo RAID5 ?>:
      case '<? echo RAID5 ?>':
        size=size*(pdCount-1);
        break;
      case <? echo RAID30 ?>:
      case '<? echo RAID30 ?>':
      case <? echo RAID50 ?>:
      case '<? echo RAID50 ?>':
        size=size*(pdCount-axles);
        break;
      case <? echo RAID51 ?>:
      case '<? echo RAID51 ?>':
        size=size*(pdCount/2-1);
        break;
      case '<? echo RAID6 ?>':
        size=size*(pdCount-2);
        break;
      case <? echo RAID60 ?>:
      case '<? echo RAID60 ?>':
        size=size*(pdCount-2*axles);
        break;
      case <? echo RAID0 ?>:
      case <? echo JBOD ?>:
      default:
        size*=pdCount;
        break;
    }
    return size;
  }

  function unselectLD(e)
  {
    if(!document.forms[0].ldindex||document.forms[0].ldindex.value==-1) return;

    // windows event is only for IE
    if(window.event&&
      ((window.event.srcElement.tagName=='INPUT')||
      (window.event.srcElement.tagName=='SELECT')||
      window.event.srcElement.parentElement.id||
      window.event.srcElement.id))
      return;

    if(e.target&&
      ((e.target.tagName=='INPUT')||
      (e.target.tagName=='SELECT')||
      (e.target.tagName=='OPTION')||
      e.target.parentNode.id||
      e.target.id))
      return;

    document.forms[0].elements['ldindex'].value=-1;
    if(document.forms[0].elements['barindex'])
      document.forms[0].elements['barindex'].value=-1;
    unselectAllRows();
    updateSizes();
    updateLDMaxSize();
  }

  function RPChanged()
  {
    elements=document.forms[0].elements;
    if(elements['_readPolicy'].value==2){
      elements['_writePolicy'].saved=elements['_writePolicy'].selectedIndex;
      elements['_writePolicy'].selectedIndex=0;
      elements['_writePolicy'].disabled=true;
    }else if(elements['_writePolicy'].disabled){
      elements['_writePolicy'].selectedIndex=elements['_writePolicy'].saved?elements['_writePolicy'].saved:0;
      elements['_writePolicy'].disabled=false;
    }
  }
</script>
