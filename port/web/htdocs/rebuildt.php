<?php

/*******************************************************************************
 * Filename    : rebuildt.php
 * Description : islaVista start rebuild.
 * Created on  : 06/15/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$userAct = isset($_GET['act']) ? $_GET['act'] : '';

if (isset($_GET['daid'])) {
  SetSessionValue('rebuildDAID', $_GET['daid']);
}

$act = ($userAct == RBD_ACTION_PDM ? 'pdm' : ($userAct == RBD_ACTION_TRANSITION ? 'transition' : 'rebuild'));

if (isset($_POST['cancel'])) {
  header('Location: bga.php?note=' . gresult($act . 'startcancel') . (isset($_GET['daid']) ? ('&daid=' . $_GET['daid']) : ''));
  exit;
}

$server = getServer();
$subsystem = $server->getSubsystem();

$note = isset($_GET['note']) ? $_GET['note'] : null;
$result = isset($_GET['result']) ? $_GET['result'] : 0;
if (count($_POST)) {
  $result = $subsystem->startRebuild($userAct, $_POST['arrayId'], $_POST['sequenceNo'], $_POST['pdi_FlatId']);
  table_wait();
  if ($result)
    redirectPage(htmlspecialchars($_SERVER['PHP_SELF']) . '?result=' . $result . '&act=' . $userAct . '&note=' . gresult($act . 'startfail') . ':');
  else
    redirectPage('bga.php?note=' . gresult($act . 'startok') . (isset($_GET['daid']) ? ('&daid=' . $_GET['daid']) : ''), 1);
  exit;
}

$physicalDrives = $subsystem->getPhysicalDrives();

if ($userAct == '') {
  redirectPage('bga.php?' . (isset($_SESSION['rebuildDAID']) ? ('&daid=' . $_SESSION['rebuildDAID']) : ''), 1);
  exit;
} else
  $rebuilds = $subsystem->getRebuilds($userAct);
foreach ($rebuilds as $rebuild)
  $rebuildList[] = 'Array ' . $rebuild->rbpd_ArrayID . ' SeqNo ' . $rebuild->rbpd_SeqNumber;
?>

<html>
<head>
<?php
function filtDSPAREPd($subsystem, $pd)
{
  $spareDrives = $subsystem->getSpareDrives();
  for ($i = 0; $i < count($spareDrives); $i++) {
    $spareDrive = $spareDrives[$i];
    $sparePDID = $spareDrive->pdd_PDFlatId;
    if ($sparePDID == $pd->pdi_FlatId) {
      $daids = $spareDrive->acd_NoOfArraysAssociated;
      for ($j = 0; $j < count($daids); $j++) {
        $spareArrayID = $daids[$j];
        if (!isset($_GET['daid']) || $spareArrayID == $_GET['daid'])
          return true;
      }
    }
  }
  return false;
}
page_head();
$k = 0;
for ($i = 0; $i < count($physicalDrives); $i++) {
  $pd = $physicalDrives[$i];
  // rebuild target
  if (($userAct == RBD_ACTION_RBD
    && (($pd->pdi_Flags & DEVICE_CONFIG_MASK) == PHYDRV_CONFIG_UNCONFIGURED ||
    $pd->pdi_Flags & PHYDRV_CONFIG_GLOBALSPARE ||
    $pd->pdi_Flags & PHYDRV_CONFIG_DEDICATEDSPARE ||
    $pd->_opStatus == 'Dead')
    && !($pd->pdi_Flags & PHYDRV_COND_PFA) && ($pd->pdi_Flags & PHYDRV_COND_ACCESSIBLE))
    || ($userAct == RBD_ACTION_PDM && (($pd->pdi_Flags & DEVICE_CONFIG_MASK) == PHYDRV_CONFIG_UNCONFIGURED ||
    $pd->pdi_Flags & PHYDRV_CONFIG_GLOBALSPARE || $pd->pdi_Flags & PHYDRV_CONFIG_DEDICATEDSPARE) &&
    !($pd->pdi_Flags & PHYDRV_COND_PFA) && ($pd->pdi_Flags & PHYDRV_COND_ACCESSIBLE))
    || ($userAct == RBD_ACTION_TRANSITION && (($pd->pdi_Flags & DEVICE_CONFIG_MASK) == PHYDRV_CONFIG_UNCONFIGURED ||
    $pd->pdi_Flags & PHYDRV_CONFIG_GLOBALSPARE || $pd->pdi_Flags & PHYDRV_CONFIG_DEDICATEDSPARE) &&
    !($pd->pdi_Flags & PHYDRV_COND_TEMPSPARE) && !($pd->pdi_Flags & PHYDRV_COND_PFA) && ($pd->pdi_Flags & PHYDRV_COND_ACCESSIBLE))) {
    if ($userAct == RBD_ACTION_PDM && $pd->pdi_Flags & PHYDRV_CONFIG_DEDICATEDSPARE) {
      $ret = filtDSPAREPd($subsystem, $pd);
      if (!$ret)
        continue;
      else
        $pdId[] = array($pd->pdi_FlatId, 'ID' . ($pd->pdi_FlatId + 1) . ': ' . $pd->pdi_ConfigurableSize);
    } else if ($userAct == RBD_ACTION_RBD && $pd->_opStatus == 'Dead') {
      $pdId[] = array($pd->pdi_FlatId, 'ID' . ($pd->pdi_FlatId + 1) . ': ' . $pd->pdi_ConfigurableSize, 'dead');
    } else
      $pdId[] = array($pd->pdi_FlatId, 'ID' . ($pd->pdi_FlatId + 1) . ': ' . $pd->pdi_ConfigurableSize);
  }
  if (!isset($pd->pdi_ArrayId) || (isset($_GET['daid']) && ($_GET['daid'] != $pd->pdi_ArrayId))) continue;
  // rebuild source
  if ((($userAct == RBD_ACTION_RBD &&
    $pd->pdi_Flags & PHYDRV_STATE_ONLINE == 0)
    || ($userAct == RBD_ACTION_PDM &&
    $pd->pdi_Flags & PHYDRV_CONFIG_CONFIGURED && $pd->pdi_Flags & PHYDRV_STATE_ONLINE && ($pd->pdi_Flags & PHYDRV_OPERATION_MASK) != PHYDRV_OPERATION_PDM)
    || ($userAct == RBD_ACTION_TRANSITION &&
    $pd->pdi_Flags & PHYDRV_COND_TEMPSPARE && $pd->pdi_Flags & PHYDRV_CONFIG_CONFIGURED &&
    $pd->pdi_Flags & PHYDRV_STATE_ONLINE)) && (empty($rebuildList) || !in_array($pd->_cfgStatus, $rebuildList))) {
    $source[] = array($k++, gprop('array') . ' ' . $pd->pdi_ArrayId . ' ' . gprop('seqno') . ' ' . $pd->pdi_SequenceNo . ': ' . $pd->pdi_ConfigurableSize);
    $sequenceNo[] = $pd->pdi_SequenceNo;
    $arrayId[] = $pd->pdi_ArrayId;
    $sourcePdId[] = $pd->pdi_FlatId;
  }
}
if ($userAct == RBD_ACTION_RBD) {
  $arrays = $subsystem->getDiskArrays();
  for ($i = 0; $i < count($arrays); $i++) {
    if ($arrays[$i]->_opStatus == 'Incomplete') continue;
    $PDs = $arrays[$i]->acd_PDs;
    for ($j = 0; $j < count($PDs); $j++) {
      if (strstr($PDs[$j]->pdd_PDFlags, 'Dead')) {
        $source[] = array($k++, 'Array ' . $arrays[$i]->acd_ArrayID . ' SeqNo ' . $PDs[$j]->pdd_SequenceNo . ': ' . $PDs[$j]->pdd_PDConfigurableSize);
        $sequenceNo[] = $PDs[$j]->pdd_SequenceNo;
        $arrayId[] = $arrays[$i]->acd_ArrayID;
        $sourcePdId[] = $PDs[$j]->pdd_PDFlatId;
      }
    }
  }
}

if (isset($arrayId)) {
  echo '
    <script>
      sequenceNo=new Array(' . count($sequenceNo) . ');';
  for ($i = 0; $i < count($sequenceNo); $i++)
    echo 'sequenceNo[' . $i . ']=' . $sequenceNo[$i] . ';';

  echo '
      arrayId=new Array(' . count($arrayId) . ');';
  for ($i = 0; $i < count($arrayId); $i++)
    echo 'arrayId[' . $i . ']=' . $arrayId[$i] . ';';

  echo '
      sourcePdId=new Array(' . count($sourcePdId) . ');';
  for ($i = 0; $i < count($sourcePdId); $i++)
    echo 'sourcePdId[' . $i . ']=' . $sourcePdId[$i] . ';';
  echo '
    </script>';
}
if (isset($pdId)) {
  echo "
    <script>
      pdiFlatId=new Array(" . count($pdId) . ");\n";
  for ($i = 0; $i < count($pdId); $i++) {
    echo "pdiFlatId[" . $i . "]=new Array(2);\n";
    echo "pdiFlatId[" . $i . "][0]=" . $pdId[$i][0] . ";\n";
    echo "pdiFlatId[" . $i . "][1]=\"" . $pdId[$i][1] . "\";\n";
    if (isset($pdId[$i][2])) {
      echo "pdiFlatId[" . $i . "][2]=\"" . $pdId[$i][2] . "\";\n";
    }
  }

  echo '
    </script>';
}
?>
</head>

<body>
<form method=post  >
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('start' . $act), null, $note, $result, $userAct);
table_tab_head();

if (isset($_GET['daid']))
  $tabTitle = tabTitleArray($subsystem, $_GET['daid']);
else
  $tabTitle = tabTitleSubsystem($subsystem);
table_tab($tabTitle);

table_head_fold(gprop($act) . ' ' . gprop('parameters'), 'RB', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
$emptyRecord = null;
if ($userAct == RBD_ACTION_RBD)
  $emptyRecord = gresult('rebuildnounconfiguredpds');
else if ($userAct == RBD_ACTION_PDM)
  $emptyRecord = gresult('pdmnopd');
else if ($userAct == RBD_ACTION_TRANSITION)
  $emptyRecord = gresult('transitionnopd');

if (!isset($arrayId)) {
  if ($userAct == RBD_ACTION_RBD)
    $empty = gresult(isset($_GET['daid']) ? 'rebuildinda' : 'rebuildinsubsystem');
  else if ($userAct == RBD_ACTION_PDM)
    $empty = gresult(isset($_GET['daid']) ? 'pdminda' : 'pdminsubsystem');
  else if ($userAct == RBD_ACTION_TRANSITION)
    $empty = gresult(isset($_GET['daid']) ? 'transitioninda' : 'transitioninsubsystem');
} else if (!isset($pdId)) {
  if ($userAct == RBD_ACTION_RBD)
    $empty = gresult('rebuildnounconfiguredpds');
  else if ($userAct == RBD_ACTION_PDM)
    $empty = gresult('pdmnopd');
  else if ($userAct == RBD_ACTION_TRANSITION)
    $empty = gresult('transitionnopd');
}
if (isset($empty))
  table_row_empty($empty);
else {
  table_row_combo(gprop('sourcephysicaldrive'), $source, 'source', 0);
  table_row_combo(gprop('targetphysicaldrive'), $pdId, 'pdi_FlatId', 0);

  echo '
		<tr width=100% id=hideEmpty style="display:none">
			<td class=tableRowCol1 width=30% ><span class=text4>' . gprop('targetphysicaldrive') . '</span>
			</td>
			<td class=tableRowColOther><span class=text5  >' . $emptyRecord . '</span>
			</td>
		</tr>';
  table_hidden('sequenceNo');
  table_hidden('arrayId');
}

table_foot_fold();
table_tab_foot();

if (isset($arrayId) && isset($pdId))
  table_row_submit();
page_foot();
?>
  </table>
</form>

<script>
  elements=document.forms[0].elements;
  var browserVersion=0;
  function hideInput(input)
  {
    if(browserVersion==1||browserVersion==3){
      if(input.id=="hideEmpty")
        input.style.display="none";
      else
        input.parentNode.parentNode.parentNode.parentNode.style.display="none";
    }else{
      if(input=="hideEmpty")
        input.style.display="hidden";
      else
        input.parentNode.parentNode.parentNode.parentNode.visibility="hidden";
    }

  }

  function showInput(input)
  {
    if(browserVersion==1||browserVersion==3)
      if(input.id=="hideEmpty")
        input.style.display="";
      else
        input.parentNode.parentNode.parentNode.parentNode.style.display="";
    else
      if(input=="hideEmpty")
        input.style.display="show";
      else
        input.parentNode.parentNode.parentNode.parentNode.visibility="show";
  }

  function initializeDocument()
  {
    switch(navigator.family){
      case 'ie4':
        browserVersion=1 //IE4
        break;
      case 'nn4':
        browserVersion=2 //NS4
        break;
      case 'gecko':
        browserVersion=3 //NS6
        break;
      case 'nn5':
        browserVersion=3 //safari
        break;
      default:
        browserVersion=0 //other
        break;
    }
  }

  function sizeunitvalue(size)
  {
		var sizeUnits=new Array;
		sizeUnits[0]='MB';
		sizeUnits[1]='GB';
		sizeUnits[2]='TB';
		
    for(i=0;i<=2;i++)
    {
      if( size==sizeUnits[i])
        return i;
    }
  }

  function cmpArrayValue(index1,index2)
  {
		var min;
    var leng1=elements['source'].options[index1].text.length;
    var sourcetext1=elements['source'].options[index1].text;
    var sourceunitsize1=sourcetext1.substring(leng1-2,leng1);
    var sourcPDsize1=sourcetext1.substring(sourcetext1.indexOf(": ")+2,leng1-2);
    var numSourcePDsize1=parseFloat(sourcPDsize1);

    var leng2=elements['source'].options[index2].text.length;
    var sourcetext2=elements['source'].options[index2].text;
    var sourceunitsize2=sourcetext2.substring(leng2-2,leng2);
    var sourcPDsize2=sourcetext2.substring(sourcetext2.indexOf(": ")+2,leng2-2);
    var numSourcePDsize2=parseFloat(sourcPDsize2);            

    if( sizeunitvalue(sourceunitsize1) < sizeunitvalue(sourceunitsize2) )
    {
      return min=numSourcePDsize1;
    }
    else if(sizeunitvalue(sourceunitsize1) > sizeunitvalue(sourceunitsize2))
    {
      return min=numSourcePDsize2;
    }
    if ( sizeunitvalue(sourceunitsize1) == sizeunitvalue(sourceunitsize2) )
    {
      if( numSourcePDsize1 < numSourcePDsize2 )
        return min=numSourcePDsize1;
      else
        return min=numSourcePDsize2;
    }
  }

	function cmpArrayUnit(index1,index2)
	{
		var min;
    var leng1=elements['source'].options[index1].text.length;
    var sourcetext1=elements['source'].options[index1].text;
    var sourceunitsize1=sourcetext1.substring(leng1-2,leng1);

    var leng2=elements['source'].options[index2].text.length;
    var sourcetext2=elements['source'].options[index2].text;
    var sourceunitsize2=sourcetext2.substring(leng2-2,leng2);         

    if( sizeunitvalue(sourceunitsize1) < sizeunitvalue(sourceunitsize2) )
			return min=sourceunitsize1;
    else 
      return min=sourceunitsize2;
	}

  function sourceMinValue()
  {
    var min;
    if( elements['source'].options.length ==1 )
    {
      var leng=elements['source'].options[0].text.length;
      var sourcetext=elements['source'].options[0].text;
      var sourceunitsize=sourcetext.substring(leng-2,leng);
      var sourcPDsize=sourcetext.substring(sourcetext.indexOf(": ")+2,leng-2);
      var numSourcePDsize=parseFloat(sourcPDsize);
      min=numSourcePDsize;
      return min;
    }
  	else
  	{
  		for(var i=0;i<elements['source'].options.length ;i++)
  		{

        if( i< (elements['source'].options.length-1) )
        {
          min=cmpArrayValue(i,i+1);
          return min;
        }
  		}
  	}
  	
  }

  function sourceMinUnit()
  {
    var min;
    if(elements['source'].options.length ==1 )
    {
      var leng=elements['source'].options[0].text.length;
      var sourcetext=elements['source'].options[0].text;
      var sourceunitsize=sourcetext.substring(leng-2,leng);
      min=sourceunitsize;
      return min;
    }
  	else
  	{
  		for(var i=0;i<elements['source'].options.length ;i++)
  		{

        if( i< (elements['source'].options.length-1) )
        {
          min=cmpArrayUnit(i,i+1);
          return min;
        }
  		}
  	}
  	
  }

  function sourceChanged()
  {
    var sourceIndex = elements['source'].selectedIndex;
    elements['arrayId'].value=arrayId[sourceIndex];
    elements['sequenceNo'].value=sequenceNo[sourceIndex];
    var pdId = sourcePdId[sourceIndex];

    if( elements['source'].options.length >0 )
    { 
      var sourceunitsize=sourceMinUnit();
      var numSourcePDsize=sourceMinValue();
    }
    var newpdiFlatId=new Array();
    var count=0;
    for(var i=0;i<pdiFlatId.length;i++)
    {
      var targettext=pdiFlatId[i][1];
      var leng=targettext.length;
      var targetunitsize=targettext.substring(leng-2,leng);
      var targetPDsize=targettext.substring(targettext.indexOf(": ")+2,leng-2);
      var numTargetPDsize=parseFloat(targetPDsize); 	
      if(pdiFlatId[i].length>2)
      {
        if(pdiFlatId[i][2]=='dead' && pdiFlatId[i][0]!=pdId)
        {
          continue;
        }
      }
      if( sizeunitvalue(sourceunitsize) < sizeunitvalue(targetunitsize) )
      {
        newpdiFlatId[count]=new Array(2);
        newpdiFlatId[count][0]= pdiFlatId[i][0];
        newpdiFlatId[count][1]= pdiFlatId[i][1]; 	  		
        count++;
      }
      if ( sizeunitvalue(sourceunitsize) == sizeunitvalue(targetunitsize) )
      {
        if( numSourcePDsize <= numTargetPDsize )
        {
          newpdiFlatId[count]=new Array(2);
          newpdiFlatId[count][0]= pdiFlatId[i][0];
          newpdiFlatId[count][1]= pdiFlatId[i][1]; 	  		
          count++;
        }
      }
    }

    if(  newpdiFlatId.length!= 0 )
    {
      var sel=document.getElementsByName('pdi_FlatId');
      while(typeof(sel[0].options)!='undefined' && sel[0].options.length>0)
        sel[0].removeChild(sel[0].options[0]);
      for(i=0; i<newpdiFlatId.length ;i++)
      {
        ylib_addOption(sel[0], newpdiFlatId[i][1], newpdiFlatId[i][0]);
      }
      showInput(document.forms[0].pdi_FlatId[0]);
      showInput(document.forms[0].submit);
      hideInput(hideEmpty);
    }
    else
    {
      hideInput(document.forms[0].pdi_FlatId[0]);		
      showInput(hideEmpty);
      hideInput(document.forms[0].submit);
    }
  }

  obj=document.forms[0].elements['source'];
  initializeDocument();
  if(obj){
    ylib_addEvt(obj,'change',new Function('sourceChanged()'));
    sourceChanged();
  }
</script>

</body>
</html>
