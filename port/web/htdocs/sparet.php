<?php

/*******************************************************************************
 * Filename    : sparet.php
 * Description : Spare drive settings.
 * Created on  : 09/05/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_POST['cancel'])) {
  header('Location: spare.php?tab=0&note=' . gresult('sparesettingcancel'));
  exit;
}

function getArrayMaxConfigSize($array, $physicalDrives)
{
  $psize = 0;
  for ($j = 0; $j < count($array->acd_PDs); $j++) {
    $physicalDrive = getPDFromId($physicalDrives, $array->acd_PDs[$j]->pdd_PDFlatId);
    if ($physicalDrive != null && $physicalDrive->pdi_ConfigurableSize > $psize) {
      $psize = $physicalDrive->_configurableSize;
    }
  }
  return $psize;
}

$server = getServer();
$subsystem = $server->getSubsystem();

$arrays = $subsystem->getDiskArrays();
$physicalDrives = $subsystem->getPhysicalDrives();
$result2 = getLastError();
if ($result2 == PIERR_INACTIVE_SESSION) {
  errorOnInvalidSession();
}

if ($result2 == PIERR_NO_ERROR) {
  $spares = $subsystem->getSpareDrive($_SESSION['spid']);
  $result2 = getLastError();
}

if ($result2 == PIERR_NO_ERROR) {
  if (count($spares))
    $spare = $spares[0];
  $sparePd = getPDFromId($physicalDrives, $spare->pdd_PDFlatId);
  $sparePsize = $sparePd->_configurableSize;
}

$note = isset($_GET['note']) ? $_GET['note'] : null;
$result = isset($_GET['result']) ? $_GET['result'] : 0;
if (count($_POST)) {
  if (!isset($newSpare))
    $newSpare = new stdclass();
  $newSpare->rps_u16SpareId = $_SESSION['spid'];
  $newSpare->rps_bDedicated = ($_POST['rps_bDedicated'] == 'Dedicated') ? 1 : 0;
  $newSpare->rps_bRevertible = isset($_POST['rps_bRevertible']) ? 1 : 0;
  $newSpare->rps_u32PdId = $spare->pdd_PDFlatId;
  $newSpare->rps_u32ArrayIds = null;
  if ($newSpare->rps_bDedicated) {
    for ($j = 0; $j < count($_POST['dlist2']); $j++)
      if (isset($_POST['dlist2'][$j]))
      $newSpare->rps_u32ArrayIds[] = setInteger($_POST['dlist2'][$j]);
  }
  $newSpare->rps_u32NumberOfAssciatedArrays = count($newSpare->rps_u32ArrayIds);

  table_wait();
  $result = $subsystem->setSpareDriveSettings($newSpare);
  if ($result)
    redirectPage(htmlspecialchars($_SERVER['PHP_SELF']) . '?result=' . $result . '&note=' . gresult('sparesettingfail'));
  else
    redirectPage('spare.php?tab=0&note=' . gresult('sparesettingok'), 1);
  exit;
}
?>

<html>
<head>
<?php
page_head();
?>
<script>
  
<?php
echo "var disksize = new Array();\n";
for ($i = 0; $i < count($arrays); $i++) {
  $array = $arrays[$i];
  $psize = getArrayMaxConfigSize($array, $physicalDrives);
  echo "disksize[" . $i . "] = " . $psize . ";\n";
}
?>
  function u64Compare(n1,n2)
  {
    var h1=n1/Math.pow(2,32);
    var h2=n2/Math.pow(2,32);
    if(h1!=h2)
      return h1-h2;
    // this only compare lower 32bits
    return n1-n2;
  }

  function checkSpareSize(psize,dlist)
  {
    for(i=0;i<dlist.length;i++){
      if(dlist.options[i]==null) continue;
      if(!dlist.options[i].selected) continue;
      if(u64Compare(psize,disksize[i])<0)
      {
        <?php
        if ($language == 'zh_CN' || $language == 'zh_TW') {
          echo "var string='" . gjsprop('physicaldrive') . gjsalert('toosmalltoreplace') . "'.replace(/ID/g, 'ID '+dlist.options[i].value);\n";
        } else {
          echo "var string='" . gjsprop('physicaldrive') . " " . gjsalert('toosmalltoreplace') . " '+dlist.options[i].value+'.';\n";
        }
        ?>
        alert(string);
        return false;
      }
    }
    return true;
  }

  function fromListToListDA(src)
  {
    list1=document.forms[0].elements['dlist1[]'];
    list2=document.forms[0].elements['dlist2[]'];
    if(src==1)
    {
      if(!checkSpareSize(<?= $sparePsize ?>,list1))
        return;
      fromListToList(list1,list2);
    }
    else
      fromListToList(list2,list1);
  }

  function typeChanged(x)
  {
    var bDedicate=(x.value=='Dedicated')?true:false;

    document.forms[0].sparetype.value=x.value;

    document.forms[0].elements['dlist1[]'].disabled=!bDedicate;
    document.forms[0].elements['dlist2[]'].disabled=!bDedicate;

    obj=ylib_getObj('dedicate0');
    obj.style.color='#888888';
    obj=ylib_getObj('dedicate1');
    obj.style.color=bDedicate?'#000000':'#888888';
    obj=ylib_getObj('dedicate2');
    obj.style.color=bDedicate?'#000000':'#888888';
    obj=ylib_getObj('dedicate3');
    obj.disabled=!bDedicate;
    obj.style.color=bDedicate?'#000000':'#888888';
    obj=ylib_getObj('dedicate4');
    obj.disabled=!bDedicate;
    obj.style.color=bDedicate?'#000000':'#888888';

    obj=document.forms[0].elements['dlist1[]'];
    obj.style.color=obj.disabled?'#888888':'#000000';
    obj=document.forms[0].elements['dlist2[]'];
    obj.style.color=obj.disabled?'#888888':'#000000';
    
    handleSelectIssueXX(document.forms[0].elements['dlist1[]']);
    handleSelectIssueXX(document.forms[0].elements['dlist2[]']);
  }
</script>
</head>

<body>
<form method=post>
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('sparedrivesettings'), 'spare', $note, $result);
table_tab_head();

$tabTitle = tabTitleSpare();
table_tab($tabTitle);
if ($result2 == PIERR_NO_ERROR) {
  table_head_fold(gprop('sparedrivesettings'), 'SD', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
  ?>
    <tr>
      <td width=30% class=tableRowCol1><span><?= gprop('sparetype') ?></span></td>
      <td width=70% class=tableRowColOther><span>
        <input type=radio name=rps_bDedicated value=Global onClick=typeChanged(this) <? echo ($spare->acd_ArrayAttributes == 'Global' ? 'checked' : '') ?>>
          Global&nbsp;&nbsp;
        <input type=radio name=rps_bDedicated value=Dedicated onClick=typeChanged(this) <? echo ($spare->acd_ArrayAttributes == 'Dedicated' ? 'checked' : '') ?>>
          Dedicated
        </span><input type=hidden value=Global name=sparetype>
      </td>
    </tr>
<?php
table_row_checkbox(gprop('revertible'), $spare->acd_ArrayFlags, 'rps_bRevertible')
?>
    <tr>
      <td class=tableRowCol1 valign=top id=dedicate0><span>
        <?= gprop('dedicatedtodiskarray') ?></span>
      </td>
      <td class=tableRowColOther>
        <table width=100% cellpadding=0 cellspacing=0 border=0>
          <tr>
            <td width=20% class=text5 id=dedicate1><?= gprop('available') ?></td>
            <td width=10%></td>
            <td width=70% class=text5 id=dedicate2><?= gprop('selected') ?></td>
          </tr>
          <tr>
            <td class=text5>
              <select class=selectField size=4 multiple name="dlist1[]" ondblclick=fromListToListDA(1)
                <? echo ($spare->acd_ArrayAttributes == 'Global' ? 'disabled' : '') ?>>
<?php
for ($i = 0; $i < count($arrays); $i++) {
  $array = $arrays[$i];
  if (!isInArray($spare->acd_NoOfArraysAssociated, $array->acd_ArrayID))
    echo '
                <option value=' . $array->acd_ArrayID . '>ID' . $array->acd_ArrayID . ':' . $array->acd_ArrayName .
    '</option>';
}
?>
              </select>
            </td>
            <td class=text5 align=middle>
              &nbsp;<input class=text4b type=button value=" >> " onClick=fromListToListDA(1) id=dedicate3>&nbsp;
              <p>
              &nbsp;<input class=text4b type=button value=" << " onClick=fromListToListDA(2) id=dedicate4>&nbsp;
            </td>
            <td class=text5>
              <select class=selectField size=4 multiple name="dlist2[]" ondblclick=fromListToListDA(2)
                <? echo ($spare->acd_ArrayAttributes == 'Global' ? 'disabled' : '') ?>>
<?php
for ($i = 0; $i < count($arrays); $i++) {
  $array = $arrays[$i];
  if (isInArray($spare->acd_NoOfArraysAssociated, $array->acd_ArrayID))
    echo '
                <option value=' . $array->acd_ArrayID . '>ID' . $array->acd_ArrayID . ':' . $array->acd_ArrayName .
    '</option>';
}
?>
              </select>
            </td>
          </tr>
        </table>
      </td>
    </tr>

<?php
table_foot_fold();
table_tab_foot();

table_row_submit(array('reset' => 0, 'submitClick' => 'validateSubmit()'));
} //NO_ERROR
else {
  table_head_fold(gprop('sparedrivesettings'), 'content');
  table_row_empty(gerror('displaysparesettings'));
  table_foot_fold();
  table_tab_foot();
}
page_foot();
?>
  </table>
</form>

<script>
  function validateSubmit()
  {
    form=document.forms[0];
    if(form.sparetype.value=='Dedicated'){
      if(form.elements['dlist2[]'].options.length==0){
        alert("<?= gjsalert('selectdedicateddiskarray') ?>");
        return false;
      }
      for(i=0;i<form.elements['dlist2[]'].options.length;i++)
        form.elements['dlist2[]'].options[i].selected=true;
    }
    return true;
  }
<? if (isset($spare)) { ?>
  typeChanged(document.forms[0].elements['rps_bDedicated'][<? echo $spare->acd_ArrayAttributes == 'Dedicated' ? 1 : 0 ?>]);
<?
} ?>
</script>

</body>
</html>
