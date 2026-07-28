<?php

/*******************************************************************************
 * Filename    : treeframe.php
 * Description : islaVista object leftside tree frame.
 * Created on  : 04/01/03
 *
 * (C) Copyright Promise Technology Inc., 2003
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'tree2.php';

$_SESSION['processing'] = 1;
if ($language == 'ar_AR') {
  $showbody = 'artreeBody';
} else {
  $showbody = 'treeBody';
}

$server = new CServer();

$server->update();
$subsystem = $server->subsystems[0]->instance;

$webserver = $subsystem->getWebserverSetting();
if ($webserver->errorcode == PIERR_NO_ERROR)
  $_SESSION['timeout'] = $webserver->sessiontimeout;
?>

<html>
<head>
  <?php
  $cssPostFix = "?time=" . time() . ".css";
  $jsPostFix = "?time=" . time() . ".js";
  echo '
    <link rel="stylesheet" href="css/text1.css' . $cssPostFix . '" type="text/css">
    <link rel="stylesheet" href="css/table.css' . $cssPostFix . '" type="text/css">
    <script src="js/plib.js' . $jsPostFix . '"></script>
    <script src="js/myua.js'.$jsPostFix.'"></script>';
  ?>

<script>
function Folder(folderDescription,hreference,icnsrc,state,uniqueId,className,drawBorder,textStyle,isOpen,treeId) //constructor
{
  //constant data
  this.desc=folderDescription
  this.uniqueId=uniqueId;
  this.className=className;
  this.id=-1
  this.navObj=0
  this.nodeImg=0
  this.textStyle=textStyle
  hreference+=((hreference.indexOf('?')==-1?'?':'&')+'tab=0');
  this.hreference=hreference

  //dynamic data
  this.isOpen=isOpen;
  this.iconSrc=icnsrc;
  this.state=state;
  this.children=new Array;
  this.nChildren=0;
  this.parent=null;
  this.treeId=treeId;
  this.level=0;

  //methods
  this.initialize=initializeFolder
  this.addChild=addChild
  this.createIndex=createEntryIndex
  this.hide=hideFolder
  this.display=display
  this.renderOb=drawFolder
  this.outputLink=outputFolderLink
  this.propagateChanges=propagateChanges

  // draw border around main folder
  this.drawBorder=drawBorder
}

function propagateChanges(isOpen)
{
  var i;
  if(isOpen){
    for(i=0;i<this.nChildren;i++){
      this.children[i].display();
      if(this.children[i].nChildren)
        this.children[i].propagateChanges(this.children[i].isOpen);
      }
  }else{
    for(i=0;i<this.nChildren;i++){
      this.children[i].hide();
      if(this.children[i].nChildren)
        this.children[i].propagateChanges(false);
    }
  }
}

function hideFolder()
{
  if(browserVersion==1||browserVersion==3){
    if(this.navObj.style.display=="none")
      return
    this.navObj.style.display="none"
  }else{
    if(this.navObj.visibility=="hidden")
      return
    this.navObj.visibility="hidden"
  }
}

function display()
{
  if(browserVersion==1||browserVersion==3)
    this.navObj.style.display="block"
  else
    this.navObj.visibility="show"
}

function getParentByLevel(which,level)
{
  var parent=which.parent;
  while(parent){
    if(parent.level==level) return parent;
    parent=parent.parent;
  }
  return null;
}

function getFolderLevel(which)
{
  var parent=which.parent;
  var level=-1;
  while(parent){
    level++;
    parent=parent.parent;
  }
  return(level>=0)?level:0;
}

function initializeFolder()
{
  var i=0
  var leftSide="";
  var image;

  this.createIndex()
  this.level=getFolderLevel(this);

  for(i=0;i<this.level;i++)
    leftSide+="<img src='icons/tab"+(isLastChild(getParentByLevel(this,i))?'0':'')+".gif' border=0 align=absmiddle>";
  this.renderOb(leftSide);

  for(i=0;i<this.nChildren;i++)
    this.children[i].initialize();
}

function drawFolder(leftSide)
{
  var idParam="id='folder"+this.id+"'"
  var tableidParam="id='tablefolder"+this.id+"'"
  var tdidParam="id='tdfolder"+this.id+"'"
  var moverstyle='treeStyleMove1';
  if(this.className=="treeStyleHome")
	  moverstyle="treeStyleMoveHome";
  else if(this.className=="treeStyleSubsystems")
	  moverstyle="treeStyleMoveSubsystems";
  else if(this.className=="treeStyleSubsystem")
	  moverstyle="treeStyleMoveSubsystem";
  else if(this.className=="treeStyleNode")
	  moverstyle="treeStyleMoveNode";

  var docW = "";
  if(browserVersion==2)
    if(!doc.yPos) doc.yPos=20

  if(browserVersion==2)
    docW += "<layer "+idParam+" top="+doc.yPos+" visibility=show>"
  if(browserVersion==3) //N6 has bug on display property with tables
    docW += "<div "+idParam+" style='display:block;'>"

  docW += "<table border="+(this.drawBorder==1?0:0)+" bordercolorlight=#4D4688 bordercolordark=#FFFFFF cellspacing=0 cellpadding=0 width=100% "+
    (browserVersion==1?(idParam+" style='display:block;position:block;'"):"")+">";

  docW += "<tr "+tdidParam+" class="+this.className+" onMouseOver=className='"+moverstyle+
	  "' onMouseOut=className='"+this.className+"' valign=center>";

  docW += "<td><table "+tableidParam+" class= width=100% border=0 cellspacing=0 cellpadding=0>"
  doc.write(docW);

  this.outputLink(leftSide)

  docW = "";
  docW += "</table></td></tr>"
  docW += "</table>";

  if(browserVersion==2)
    docW += "</layer>"
  if(browserVersion==3)
    docW += "</div>"

  doc.write(docW);

  if(browserVersion==1){
    this.navObj=doc.all["folder"+this.id]
    this.nodeImg=doc.all["nodeIcon"+this.id]
  }else if(browserVersion==2){
    this.navObj=doc.layers["folder"+this.id]
    this.nodeImg=this.navObj.document.images["nodeIcon"+this.id]
    doc.yPos=doc.yPos+this.navObj.clip.height
  }else if(browserVersion==3){
    this.navObj=doc.getElementById("folder"+this.id)
    this.nodeImg=doc.getElementById("nodeIcon"+this.id)
  }
}

function isLastChild(which)
{
  var parent=which.parent;
  if(parent&&parent.children[parent.nChildren-1]==which) return true;
  return false;
}

function outputFolderLink(leftSide)
{
  var docW = "";

  docW += "<tr valign=center>";
  docW += "<td nowrap>";

  docW += leftSide; // left side empty space

  if(this.nChildren&&this.uniqueId!=0){
    var image=(this.isOpen?'fdminus':'fdplus')+(isLastChild(this)?'1':'');
    docW += "<img src='icons/"+image+".gif' id=nodeIcon"+this.id+" onclick=openCloseFolder("+this.uniqueId+
      ") style=cursor:pointer border=0 align=absmiddle>";
  }else if(this.nChildren==0)
    docW += "<img src='icons/"+(isLastChild(this)?'tab1':'tab2')+".gif' border=0 align=absmiddle>";

  var sChar=(this.state&<? echo NODE_STATE_DEAD ?>)?'Dead':((this.state&<? echo NODE_STATE_CRITICAL ?>)?'Critical':'');

  if(browserVersion==3)
  {
      docW += "<a onclick=highlight(ylib_getObj('folder'+"+this.uniqueId+")) href='"+this.hreference+
         "' target=basefrm style='background-image:url("+this.iconSrc+");background-repeat: no-repeat;display: inline-block;'";  
      if(browserVersion>0)
        docW += " ondblclick=clickOnFolder("+this.uniqueId+")";
      docW += "><img id='folderIcon"+this.id+"' name='folderIcon"+this.id+"' src='icons/icon"+sChar+".gif' border=0 align=absmiddle></a>";
  }
  else
  {
      docW += "<a onclick=highlight(ylib_getObj('folder'+"+this.uniqueId+")) href='"+this.hreference+
         "' target=basefrm style='background-image:url("+this.iconSrc+")'";
      if(browserVersion>0)
        docW += " ondblclick=clickOnFolder("+this.uniqueId+")";
      docW += "><img id='folderIcon"+this.id+"' name='folderIcon"+this.id+"' src='icons/icon"+sChar+".gif' border=0 align=absmiddle></a>";
  }
  if(!this.isOpen && this.textStyle=="textTreeSubsystemNode")
    docW += "<a class="+this.textStyle+" id=fd"+this.uniqueId+" onclick=highlight(ylib_getObj('folder'+"+this.uniqueId+")) target=_black ondblclick=clickOnFolder("+
      this.uniqueId+") href="+this.hreference+">&nbsp;"+this.desc+"</a>";
  else
    docW += "<a class="+this.textStyle+" id=fd"+this.uniqueId+" onclick=highlight(ylib_getObj('folder'+"+this.uniqueId+")) target=basefrm ondblclick=clickOnFolder("+
      this.uniqueId+") href="+this.hreference+">&nbsp;"+this.desc+"</a>";
  docW += "</td>";
  docW += "<td width=90% style=cursor:pointer onclick=top.basefrm.location.href='"+this.hreference+"'></td>";
  docW += "</tr>";
  doc.write(docW);
}

function addChild(childNode)
{
  this.children[this.nChildren]=childNode;
  this.nChildren++;
  childNode.parent=this;
  return childNode;
}

function createEntryIndex()
{
  this.id=nEntries;
  indexOfEntries[this.uniqueId]=this;
  nEntries++;
}

function clickOnFolder(folderId)
{
  highlight(ylib_getObj('folder'+folderId));
  openCloseFolder(folderId)
}

function openCloseFolder(folderId)
{
  var folder=indexOfEntries[folderId]
  if(folder==null) return;
  if(folderId!=0){
    folder.isOpen=!(folder.isOpen);
    folder.propagateChanges(folder.isOpen)
  }
  if(folder.nodeImg)
    folder.nodeImg.src='icons/'+(folder.isOpen?'fdminus':'fdplus')+(isLastChild(folder)?'1':'')+'.gif';
}

function clickOnTreeId(treeId)
{
  var folder=findTreeId(treeId);
  if(folder==null) return;
  openParent(folder.parent);
  highlight(ylib_getObj('folder'+folder.uniqueId));
}

function findTreeId(treeId)
{
  var id=0;
  var folder=indexOfEntries[id++];
  while(folder){
    if(folder.treeId==treeId) return folder;
    folder=indexOfEntries[id++];
  }
  return null;
}

function openParent(folder)
{
  while(folder){
    if(folder.isOpen==false)
      openCloseFolder(folder.uniqueId);
    folder=folder.parent;
  }
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

  foldersTree.initialize();
  foldersTree.propagateChanges(true);

  if(browserVersion==2)
    doc.write("<layer top="+indexOfEntries[nEntries-1].navObj.top+">&nbsp;</layer>");

  if(browserVersion==0)
    doc.write("<table border=0><tr><td><br><br><font size=-1><?= galert('incapablebrowsers') ?></font></td></tr></table>");
}

// Auxiliary Functions for Folder-Treee backward compatibility

function gFld(description,hreference,icnsrc,state,uniqueId,className,drawBorder,textStyle,isOpen,treeId)
{
  folder=new Folder(description,hreference,icnsrc,state,uniqueId,className,drawBorder,textStyle,isOpen,treeId);
  return folder;
}

function insFld(parentFolder,childFolder)
{
  return parentFolder.addChild(childFolder)
}

// Global variables

indexOfEntries=new Array
nEntries=0
doc=document
browserVersion=0
prevlink=new String;
prevflag=0
prevStyle='treeStyle1';

function highlight(which)
{
  if(!which) return;

  if(document.all||document.getElementById){
    if(prevflag==1){
      prevlink.className=prevStyle;
    }
    prevflag=1;
    prevlink=which;
    prevStyle=which.className;
    which.className='treeHighlight';
  }
}

</script>

<title></title>
<base target="_self">

</head>

<body class=<?= $showbody ?>>
<table cellpadding=0 height=100% cellspacing=0>
<tr><td colspan=3 height=8><img src='images/empty.gif'></td></tr>
<tr><td width=8><img src='images/empty.gif'></td>
<td class="outborder">
<div>
<?php
$tree = new CTree($server);
unset($server);
$string = $tree->generateTree();
echo $string;
unset($tree);
?>

<script language='Javascript'>
  initializeDocument();
</script>
</div>
</td><td width=8><img src='images/empty.gif'></td></tr>
<tr><td colspan=3 height=8><img src='images/empty.gif'></td></tr>
</table>
</body>
</html>

<?php

function checkIncompleteArray($subsystem)
{
  $arrays = $subsystem->getDiskArrays();
  for ($i = 0; $i < count($arrays); $i++) {
    if ($arrays[$i]->_opStatus == 'Incomplete') {
      refreshBaseFrame('array.php?ssid=0&&daid=' . $arrays[$i]->acd_ArrayID);
      return true;
    }
  }
  unset($arrays);
  return false;
}

function checkConfiguration($subsystem)
{
  // check for unconfigured physical drives
  $phyDrvs = $subsystem->getPhysicalDrives();
  $pdCount = getAccessiblePDCount($phyDrvs);
  unset($phyDrvs);

  if ($pdCount > 0) {
    refreshBaseFrame('arrayc.php?ssid=0&tab=1&pdCount=' . $pdCount);
    return true;
  }
  return false;
}

function checkConflict($subsystem)
{
  /*
  $mountpoints = $subsystem->getnasconflictld();
  $conflictnum = count($mountpoints);

  if ($conflictnum > 0) {
    refreshBaseFrame('mountpoints.php?ssid=0');
    return true;
  }
  return false;
   */
}

if (!isset($_SESSION['lastfile']) && !isset($_GET['refresh'])) {
  if (isset($subsystem) && getUserPrivilege($subsystem) > USER_GROUP_POWER) {
    $redir = checkIncompleteArray($subsystem);
    if (!$redir)
      checkConfiguration($subsystem);
  }
}

if (isset($_SESSION['pegasus']))
  $server->logout();

$_SESSION['processing'] = 0;

unset($subsystem);
?>
