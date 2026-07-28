<?php

/*******************************************************************************
 * Filename    : sparec.php
 * Description : Spare drive creation.
 * Created on  : 06/01/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_POST['cancel'])) {
  header('Location: spares.php?tab=0&note=' . gresult('createsparecancel'));
  exit;
}

$server = getServer();
$subsystem = $server->getSubsystem();
$physicalDrives = $subsystem->getPhysicalDrives();
$result2 = getLastError();
if ($result2 == PIERR_NO_ERROR) {
  $diskArrays = $subsystem->getDiskArrays();
  $result2 = getLastError();
}

$spareDrives = array();   // PHP 8: count(null) is a fatal TypeError - keep it an array
if (isset($_GET['act']) && $_GET['act'] == 'create')
  unset($GLOBALS['_SESSION']['sds']);
else if (isset($_SESSION['sds']) && is_array($_SESSION['sds']))
  $spareDrives = $_SESSION['sds'];

if ($result2 == PIERR_NO_ERROR) {
  $pdCount = getAccessiblePDCount($physicalDrives);
  $result2 = getLastError();
}

if ($result2 == PIERR_NO_ERROR) {
  $note = isset($_GET['note']) ? $_GET['note'] : null;
  $result = isset($_GET['result']) ? $_GET['result'] : 0;
  if (count($_POST) && !isset($_POST['update']) && !isset($_POST['delete'])) {
    $result = $subsystem->createSpareDrives($spareDrives);
    table_wait();
    if ($result)
      redirectPage(htmlspecialchars($_SERVER['PHP_SELF']) . '?result=' . $result . '&note=' . gresult('createsparefail'));
    else
      redirectPage('spares.php?tab=0&note=' . gresult('createspareok'), 1);
    exit;
  }
}

if ($result2 == PIERR_INACTIVE_SESSION) {
  errorOnInvalidSession();
}
?>

<html>
<head>
<?php
page_head();

$sdindex = -1;
if (isset($_POST['sdindex']) && $_POST['sdindex'] != '')
  $sdindex = $_POST['sdindex'];

if (isset($_POST['update'])) {
  if ($sdindex >= 0)
    $spareDrive = $spareDrives[$sdindex];

  for ($i = 0; $i < count($_POST['plist2']); $i++) {
    if (isset($_POST['plist2'][$i])) {
      $id = setInteger($_POST['plist2'][$i]);
      $physicalDrive = getPDFromId($physicalDrives, $id);
    }
    $spareDrive['rps_u32PdId'] = $id;
    $spareDrive['pdd_PDSize'] = $physicalDrive->pdi_ConfigurableSize;
    $spareDrive['rps_bRevertible'] = isset($_POST['rps_bRevertible']);
    $spareDrive['rps_bDedicated'] = ($_POST['rps_bDedicated'] == 'Dedicated') ? true : false;
    $spareDrive['rps_u32ArrayIds'] = null;
    if ($spareDrive['rps_bDedicated']) {
      for ($j = 0; $j < count($_POST['dlist2']); $j++) {
        if (isset($_POST['dlist2'][$j]))
          $spareDrive['rps_u32ArrayIds'][] = setInteger($_POST['dlist2'][$j]);
      }
    }
    if ($sdindex >= 0 && $i == 0)
      $spareDrives[$sdindex] = $spareDrive;
    else
      $spareDrives[] = $spareDrive;
  }
  $sdindex = -1;
} else if (isset($_POST['delete'])) {
  if ($sdindex >= 0)
    array_splice($spareDrives, $sdindex, 1);
  if ($sdindex >= 0 && !isset($spareDrives[$sdindex]))
    $sdindex--;
}

$pds = null;
for ($i = 0; $i < count($spareDrives); $i++)
  $pds[] = $spareDrives[$i]['rps_u32PdId'];
if ($pds)
  sort($pds);
?>

<script>
  var spareDrives=new Array();
<?php
  for ($i = 0; $i < count($spareDrives); $i++) {
    $spareDrive = $spareDrives[$i];

    echo '
        var spareDrive=new Array(5);
        spareDrive[0]=' . $spareDrive['rps_u32PdId'] . ';
        spareDrive[1]="' . $spareDrive['pdd_PDSize'] . '";
        spareDrive[2]=' . ($spareDrive['rps_bRevertible'] ? 1 : 0) . ';
        spareDrive[3]="' . $spareDrive['rps_bDedicated'] . '";';

    $daCount = count($spareDrive['rps_u32ArrayIds']);
    if ($daCount > 0) {
      echo '
          var daids=new Array(' . $daCount . ');';
      for ($j = 0; $j < $daCount; $j++)
        echo '
            daids[' . $j . ']=' . $spareDrive["rps_u32ArrayIds"][$j] . ';';
      echo '
          spareDrive[4]=daids;';
    }
    echo '
        spareDrives[' . $i . ']=spareDrive;';
  }
?>
  function isSpare(devId)
  {
    for(var i=0;i<spareDrives.length;i++){
      if(spareDrives[i][0]==devId)
        return true;
    }
    return false;
  }

  function updateSD(index)
  {
    document.forms[0].sdindex.value=index;
    elements=document.forms[0].elements;
    // init first
    elements['rps_bRevertible'].checked=false;
    plist2=elements['plist2[]'];
    dlist1=elements['dlist1[]'];
    dlist2=elements['dlist2[]'];
    for(i=0;i<dlist2.length;i++)
      dlist2.options[i].selected=true;
    fromListToList(dlist2,dlist1);

    if(index!=-1){
      for(var i=0;i<plist2.length;i++){
        if(!isSpare(plist2.options[i].value))
          plist2.options[i].selected=true;
      }
      fromListToList(plist2,elements['plist1[]']);
    }

    for(var i=plist2.length-1;i>=0;i--)
      plist2.remove(i);

    if(index==-1){
      typeChanged(elements['rps_bDedicated'][0]);
      return;
    }

    spareDrive=spareDrives[index];
    elements['rps_bRevertible'].checked=spareDrive[2]?true:false;

    if(spareDrive[3]){
      elements['rps_bDedicated'][1].checked=true;
      typeChanged(elements['rps_bDedicated'][1]);
    }else{
      elements['rps_bDedicated'][0].checked=true;
      typeChanged(elements['rps_bDedicated'][0]);
    }

    option=new Option('ID'+spareDrive[0]+':'+spareDrive[1]);
    plist2.options.add(option,0);
    plist2.options[0].value=spareDrive[0];

    if(spareDrive[4]){
      dlist1=elements['dlist1[]'];
      for(i=0;i<spareDrive[4].length;i++){
        for(j=0;j<dlist1.length;j++){
          if(spareDrive[4][i]==dlist1.options[j].value)
            dlist1.options[j].selected=true;
        }
      }
      fromListToList(dlist1,elements['dlist2[]']);
    }
  }

  function getObjIndex(obj,name)
  {
    for(var i=0;i<<? echo MAX_SPARES ?>;i++){
      id=name+i;
      if(ylib_getObj(id)==obj)
        return i;
    }
    return -1;
  }

  function unselectAllRows()
  {
    for(var i=0;i<<? echo MAX_SPARES ?>;i++){
      id='sd'+i;
      if(ylib_getObj(id))
        ylib_getObj(id).style.backgroundImage='';
    }
  }

  function selectSD(line)
  {
    var index;
    unselectAllRows();
    index=getObjIndex(line,'sd');
    // select current line
    line.style.background='#0078D8';
    updateSD(index);
    handleSelectIssueXX(document.forms[0].elements['plist1[]']);
    handleSelectIssueXX(document.forms[0].elements['plist2[]']);
    handleSelectIssueXX(document.forms[0].elements['dlist1[]']);
    handleSelectIssueXX(document.forms[0].elements['dlist2[]']);
  }

  function validateUpdate()
  {
    form=document.forms[0];
    if(form.elements['plist2[]'].length==0){
      if(form.elements['plist1[]'].length!=0)
        alert("<?= gjsalert('selectphysicaldrives') ?>")
      else
        alert("<?= gjsalert('nomoresparetoadd') ?>")
      return false;
    }

    for(i=0;i<form.elements['plist2[]'].length;i++)
      form.elements['plist2[]'].options[i].selected=true;

    if(form.sparetype.value=='Dedicated'){
      if(form.elements['dlist2[]'].length==0){
        alert("<?= gjsalert('selectdedicateddiskarray') ?>");
        return false;
      }
      for(i=0;i<form.elements['dlist2[]'].length;i++)
        form.elements['dlist2[]'].options[i].selected=true;
    }
    return true;
  }

  function validateDelete()
  {
    if(<? echo count($spareDrives) ?>==0){
      alert("<?= gjsresult('nosparefordeletion') ?>")
      return false;
    }
    if(document.forms[0].sdindex.value==-1){
      alert("<?= gjsalert('selectsparefordeletion') ?>")
      return false;
    }
    return true;
  }

  function fromListToListPD(src)
  {
    var form=document.forms[0];
    var list1=form.elements['plist1[]'];
    var list2=form.elements['plist2[]'];

    if(src==1){
      if(!checkSpareSize(list1,form.elements['dlist2[]'],1))
        return;
      fromListToList(list1,list2);
    }else
      fromListToList(list2,list1);
  }

  function fromListToListDA(src)
  {
    var form=document.forms[0];
    var list1=form.elements['dlist1[]'];
    var list2=form.elements['dlist2[]'];

    if(src==1){
      if(!checkSpareSize(form.elements['plist2[]'],list1,0))
        return;
      fromListToList(list1,list2);
    }else
      fromListToList(list2,list1);
  }

  function u64Compare(n1,n2)
  {
    var h1=n1/Math.pow(2,32);
    var h2=n2/Math.pow(2,32);
    if(h1!=h2)
      return h1-h2;
    // this only compare lower 32bits
    return n1-n2;
  }

  function checkSpareSize(plist,dlist,pdSelected)
  {
    for(i=0;i<dlist.length;i++){
      if(dlist.options[i]==null) continue;
      if(!pdSelected&&!dlist.options[i].selected) continue;
      for(k=0;k<plist.length;k++){
        if(plist.options[k]==null) continue;
        if(pdSelected&&!plist.options[k].selected) continue;
        if(u64Compare(plist.options[k].className,dlist.options[i].className)<0){
          var pdId=0;
          if(!isNaN(plist.options[k].value))
            pdId = new Number(plist.options[k].value)+1;
          else 
            pdId=plist.options[k].value;
          <?php
          if ($language == 'zh_CN' || $language == 'zh_TW') {
            echo "var string='" . gprop('physicaldriveid') . " '+pdId+' " . gjsalert('toosmalltoreplace') . "'.replace(/ID/g, 'ID '+dlist.options[i].value);\n";
          } else {
            echo "var string='" . gprop('physicaldriveid') . " '+pdId+' " . gjsalert('toosmalltoreplace') . " '+dlist.options[i].value+'.';\n";
          }
          ?>
    alert(string);
          return false;
        }
      }
    }
    return true;
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

  function getRowIndex(line)
  {
    for(i=0;i<<? echo MAX_SPARES ?>;i++){
      id='sd'+i;
      if(ylib_getObj(id)==line)
        return i;
    }
    return-1;
  }

  function unselectSD(e)
  {
    if(document.forms[0].sdindex.value==-1) return;
    // windows event is only for IE
    if(window.event&&((window.event.srcElement.tagName=='INPUT')||(window.event.srcElement.tagName=='SELECT')||
      window.event.srcElement.parentElement.id||window.event.srcElement.id))
      return;
    if(e.target&&((e.target.tagName=='INPUT')||(e.target.tagName=='SELECT')||(e.target.tagName=='OPTION')||e.target.parentNode.id||e.target.id))
      return;
    unselectAllRows();
    updateSD(-1);
  }
</script>
</head>

<body onclick="unselectSD(event)">
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center>
<?php
table_title(gprop('addsparedrives'), 'sparedrive', $note, $result);
table_tab_head();

$tabTitle = tabTitleSpares($subsystem);
table_tab($tabTitle);
if ($result2 == PIERR_NO_ERROR) {
  table_head_fold(gprop('createsparedrives'), 'SEL', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

  if ($pdCount == 0)
    table_row_empty(gresult('nounconfiguredpdforcreat'));
  else {
    ?>
      <tr>
        <td width=30% class=tableRowCol1><span><?= gprop('sparetype') ?></span></td>
        <td width=70% class=tableRowColOther><span>
          <input type=radio checked name=rps_bDedicated value=Global onClick=typeChanged(this)>Global&nbsp;&nbsp;
          <input type=radio name=rps_bDedicated value=Dedicated onClick=typeChanged(this)>Dedicated
          </span><input type=hidden value=Global name=sparetype>
        </td>
      </tr>
<?php
table_row_checkbox(gprop('revertible'), 0, 'rps_bRevertible')
?>
      <tr>
        <td class=tableRowCol1 valign=top><span><?= gprop('physicaldrives') ?></span></td>
        <td class=tableRowColOther><span>
          <table width=100% cellpadding=0 cellspacing=0 border=0>
            <tr>
              <td width=20% class=text5><?= gprop('available') ?></td>
              <td width=10%></td>
              <td width=70% class=text5><?= gprop('selected') ?></td>
            </tr>
            <tr>
              <td class=text5>
                <select class=selectField size=4 multiple name="plist1[]" ondblclick=fromListToListPD(1)>
<?php
for ($i = 0; $i < count($physicalDrives); $i++) {
  $physicalDrive = $physicalDrives[$i];

  $flag = 1;
  if (isset($spareDrives)) {
    for ($j = 0; $j < count($spareDrives); $j++) {
      if ($physicalDrive->pdi_FlatId == $spareDrives[$j]['rps_u32PdId']) {
        $flag = 0;
        break;
      }
    }
  }

  if ($physicalDrive->_cfgEnable == 0 && $flag == 1) {
    echo '
                  <option value=' . $physicalDrive->pdi_FlatId . ' CLASS="' . $physicalDrive->_configurableSize . '">
                      ID' . ($physicalDrive->pdi_FlatId + 1) . ':' . $physicalDrive->pdi_ConfigurableSize .
      '</option>';
  }
}
?>
                </select>
              </td>
              <td class=text5 align=middle>
                &nbsp;<input class=text4b type=button value=" >> " onClick=fromListToListPD(1)>&nbsp;<p>
                &nbsp;<input class=text4b type=button value=" << " onClick=fromListToListPD(2)>&nbsp;
              </td>
              <td class=text5>
                <select class=selectField size=4 multiple name="plist2[]" ondblclick=fromListToListPD(2)>
                </select>
              </td>
            </tr>
          </table></span>
        </td>
      </tr>
      <tr>
        <td class=tableRowCol1 valign=top id=dedicate0><span><?= gprop('dedicatedtodiskarray') ?></span>
        </td>
        <td class=tableRowColOther><span>
          <table width=100% cellpadding=0 cellspacing=0 border=0>
            <tr>
              <td width=20% class=text5 id=dedicate1><?= gprop('available') ?></td>
              <td width=10%></td>
              <td width=70% class=text5 id=dedicate2><?= gprop('selected') ?></td>
            </tr>
            <tr>
              <td class=text5>
                <select class=selectField size=4 multiple disabled name="dlist1[]" ondblclick=fromListToListDA(1)>
<?php
for ($i = 0; $i < count($diskArrays); $i++) {
  $diskArray = $diskArrays[$i];
  $psize = null;
  for ($j = 0; $j < count($diskArray->acd_PDs); $j++) {
    $physicalDrive = getPDFromId($physicalDrives, $diskArray->acd_PDs[$j]->pdd_PDFlatId);
    if ($physicalDrive != null && $psize == null) {
      $psize = $physicalDrive->_configurableSize;
    }
    if ($physicalDrive != null && $physicalDrive->_configurableSize < $psize) {
      $psize = $physicalDrive->_configurableSize;
    }
  }
  echo '
                  <option value=' . $diskArray->acd_ArrayID . ' class="' . $psize .
    '">ID' . $diskArray->acd_ArrayID . ':' . $diskArray->acd_ArrayName .
    '</option>';
}
?>
                </select>
              </td>
              <td class=text5 align=middle>
                &nbsp;<input class=text4b type=button value=" >> " onClick=fromListToListDA(1) id=dedicate3>&nbsp;<p>
                &nbsp;<input class=text4b type=button value=" << " onClick=fromListToListDA(2) id=dedicate4>&nbsp;
              </td>
              <td class=text5>
                <select class=selectField size=4 multiple disabled name="dlist2[]" ondblclick=fromListToListDA(2)>
                </select>
              </td>
            </tr>
          </table></span>
        </td>
      </tr>
      <tr class=tableHeader>
        <td colspan=2 class=text3>
          <input type=submit class=input-p value=' <?= gprop('update') ?> ' name=update onclick="return validateUpdate()">&nbsp;
          <input type=submit class=input-p value=' <?= gprop('delete') ?> ' name=delete onclick="return validateDelete()">
          <input type=hidden value='' name=sdindex>
        </td>
      </tr>
<?php

}

table_foot_fold();
table_tab_foot();

if ($pdCount > 0) {

  table_head_fold(gprop('newhotsparedrives'), 'SD', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

  $columns = array('#', gprop('physicaldriveid'), gprop('capacity'), gprop('revertible'), gprop('sparetype'), gprop('dedicatedtodiskarray'));
  $widths = array(15, 15, 15, 15, 15, 25);

  table_header_cols($columns, $widths, 'listh');


  for ($i = 0; isset($spareDrives) && $i < count($spareDrives); $i++) {
    $spareDrive = $spareDrives[$i];
    // $daString=getSpareAssociatedArrays($subsystem,$spareDrive);
    $daids = $spareDrive['rps_u32ArrayIds'];
    $daString = '';
    if (count($daids) == 0)
      $daString = 'N/A';
    for ($j = 0; $j < count($daids); $j++) {
      $array = $subsystem->getDiskArray($daids[$j]);
      $daString .= ('ID' . $daids[$j] . ':' . $array->acd_ArrayName);
      if ($j != count($daids) - 1)
        $daString .= ', ';
    }

    echo '
        <tr id=sd' . $i . ' class=tableRow1Cols onclick=selectSD(this) style="cursor:hand' . ($i == $sdindex ? ';background-image:url(images/select.gif)' : '') . '">
          <td width=15%>' . ($i + 1) . '</td>
          <td width=15%>' . ($spareDrive['rps_u32PdId'] + 1) . '</td>
          <td width=15%>' . $spareDrive['pdd_PDSize'] . '</td>
          <td width=15%>' . ($spareDrive['rps_bRevertible'] ? 'Yes' : 'No') . '</td>
          <td width=15%>' . ($spareDrive['rps_bDedicated'] ? 'Dedicated' : 'Global') . '</td>
          <td width=25%>' . $daString . '</td>
        </tr>';
  }

  if (!isset($spareDrives) || count($spareDrives) == 0)
    table_row_empty(gresult('nonewspare'), count($columns));


  table_foot_fold();
  if (isset($_GET['result']))
    table_row_submit(array('reset' => 0, 'submit' => 0));
  else
    table_row_submit(array('reset' => 0, 'submit' => (count($spareDrives) ? 1 : 0)));
}
} //NO_ERROR
else {
  table_head_fold(gprop('createsparedrives'), 'SEL', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
  table_row_empty(gerror('displaycreatespare'));
  table_foot_fold();
  table_tab_foot();
}
page_foot();
?>
  </table>
</form>
<script>
  if(document.forms[0].sdindex){
    document.forms[0].sdindex.value=<? echo $sdindex; ?>;
    updateSD(document.forms[0].sdindex.value);
    adjustListCellWidth('listh','sd0');
  }
  handleSelectIssueXX(document.forms[0].elements['plist1[]']);
  handleSelectIssueXX(document.forms[0].elements['plist2[]']);
  handleSelectIssueXX(document.forms[0].elements['dlist1[]']);
  handleSelectIssueXX(document.forms[0].elements['dlist2[]']);
</script>

</body>
</html>

<?php
SetSessionValue('sds', $spareDrives);
SyncSession();
?>

