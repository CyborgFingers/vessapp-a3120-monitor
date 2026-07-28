<?php

/*******************************************************************************
 * Filename    : header.php
 * Description : islaVista header of each page.
 * Created on  : 04/01/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include_once 'language.php';
include_once 'common.php';

$noabout = false;

if ($language == 'ar_AR') {
  $showbody = 'arlogBody';
} else {
  $showbody = 'logBody';
}
if (isset($_GET['language'])) {
  refreshTreeFrame();
  refreshBaseFrame();
  if (isset($_SESSION['eventShow']) && $_SESSION['eventShow'])
    refreshEventFrame();
} else if (isset($_GET['network'])) {
  if (isset($_COOKIE['networkview'])) {
    setcookie('networkview', $_COOKIE['networkview'] ? 0 : 1, null, null, null, true, true);
    $_COOKIE['networkview'] = $_COOKIE['networkview'] ? 0 : 1;
  } else {
    setcookie('networkview', 1, null, null, null, true, true);
    $_COOKIE['networkview'] = 1;
  }
  // tree frame will call 'getmultibox' again and handle the return value.
  // if ($_COOKIE['networkview']) {
  //   getmultibox(0);
  // }
  $_SESSION['multi'] = 'Chinese';
  refreshTreeFrame();
  refreshBaseFrame();
}

if(!isset($_SESSION))
  session_start();

if (!isset($_SESSION['eventShow']))
  $_SESSION['eventShow'] = 0;
if (isset($_GET['eventShow']))
  $_SESSION['eventShow'] = $_SESSION['eventShow'] ? 0 : 1;
$_SESSION['buzzersounding'] = 0;

$seperator = '<td width=10></td><td width=1 class=headerseprater></td><td width=10></td>';
?>

<html>
<?php
$cssPostFix = "?time=" . time() . ".css";
$jsPostFix = "?time=" . time() . ".js";
echo '
  <link rel="stylesheet" href="css/text1.css' . $cssPostFix . '" type="text/css">
  <link rel="stylesheet" href="css/table.css' . $cssPostFix . '" type="text/css">
  <script src="js/plib.js' . $jsPostFix . '"></script>';
?>
<script language=javascript>
  if(top.topframeset)
    top.topframeset.rows="81,*,1"; // for IE
    myframeset=top.document.getElementById('myframeset');
  if(myframeset){
<?php
if ($_SESSION['eventShow']) {
  ?>
      myframeset.rows="*, 40%";
      document.MM_evt=1;
      setTimeout("top.eventfrm.location.href='event.php'", 200);
<?php

} else {
  ?>
      myframeset.rows="*, 0";
      document.MM_evt=0;
<?php

}
?>
  }

<?php
if (isset($_COOKIE['networkview']) && $_COOKIE['networkview']) {
  ?>
      document.MM_network=1;
<?php

} else {
  ?>
      document.MM_network=0;
<?php

}
?>

function MM_findObj(n, d){ // v4.0
  var p,i,x;  if(!d) d=document; if((p=n.indexOf("?"))>0&&parent.frames.length){
    d=parent.frames[n.substring(p+1)].document; n=n.substring(0,p);}
  if(!(x=d[n])&&d.all) x=d.all[n]; for(i=0;!x&&i<d.forms.length;i++) x=d.forms[i][n];
  for(i=0;!x&&d.layers&&i<d.layers.length;i++) x=MM_findObj(n,d.layers[i].document);
  if(!x && document.getElementById) x=document.getElementById(n); return x;
}

MM_timers=new Array;

function MM_showHideLayers()
{ // v3.0
  var i,p,v,obj,args=MM_showHideLayers.arguments;
  for(i=0;i<(args.length-2);i+=3)
    if((obj=MM_findObj(args[i]))!=null){
      if(args[i+2]=='show'){
          if(MM_timers[args[i]]){
            clearTimeout(MM_timers[args[i]]);
            MM_timers[args[i]]=null;
          }
      	ylib_show(obj);
      }
      else{
        if(args[i+1]!=1)
          MM_timers[args[i]]=setTimeout('MM_showHideLayers("'+args[i]+'",1,"hide")',1000);
        else{
          ylib_hide(obj);
          if(MM_timers[args[i]]){
            clearTimeout(MM_timers[args[i]]);
            MM_timers[args[i]]=null;
          }
        }
      }
   }
}

document.MM_sr=new Array;

function MM_swapEvtLabel()
{
  x=MM_findObj('evtLabel');
  x.innerHTML=document.MM_evt?'<?= str_replace('\'', '\\\'', gprop('hideeventframe')) ?>':'<?= str_replace('\'', '\\\'', gprop('showeventframe')) ?>';
}

function MM_swapNetLabel()
{
  x=MM_findObj('netLabel');
  x.innerHTML=document.MM_network?'<?= gprop('hidenetworksubsystems') ?>':'<?= gprop('shownetworksubsystems') ?>';
}

</script>

<head>

</head>

<body class=<?= $showbody ?> leftmargin="0" topmargin="0" marginheight="0" marginwidth="0">
<div id="overDiv" style="position: absolute; visibility: hidden; z-index: 1000; filter: progid:DXImageTransform.Microsoft.Shadow(color=#000000, Direction=135, Strength=4); box-shadow: 4px 4px 4px #000; -moz-box-shadow: 4px 4px 4px #000;-webkit-box-shadow: 4px 4px 4px #000; border: 1px solid #151515; margin-right: 8px; margin-top: -5px;"></div>
<script src="js/tip.js"></script>

<table cellspacing=0 cellpadding=0 width=100% border=0 style="" class="headbody">
  <tr valign=top>
    <td class=headerLogo rowspan=2>
      <img src="images/logo.gif" usemap=#home border=0></td>
    <td class=headerRow1 background="images/bg.gif" >
      <table cellspacing=0 cellpadding=0 border=0 hspace=0 vspace=0 class=headerMenu>
        <tr valign=middle>
          <?= $seperator ?>
          <td>
            <div id=language class=headerSubMenu >
              <table cellspacing=0 cellpadding=10 border=0 hspace=0 vspace=0 height=100%
                onMouseOver="MM_showHideLayers('language','','show')"
                onMouseOut="MM_showHideLayers('language','','hide')">
                <tr valign=bottom>
<? foreach ($language_array as $language => $name) { ?>
                  <td><a href="?language=<?= $language ?>" class=headerSubMenuText><?= $name ?></a></td>
<?
} ?>
                </tr>
              </table>
            </div>
            <a class=headerMenuText href="#"
              onMouseOver="MM_showHideLayers('language','','show');MM_showHideLayers('event',1,'hide','network',1,'hide')"
              onMouseOut="MM_showHideLayers('language','','hide')">
              <? echo gprop('language') ?>
            </a>
          </td>
          <?= $seperator ?>
<? if ($_SESSION['loginPage'] == 0) { ?>
          <td>
            <div id=event class=headerSubMenu>
              <table cellspacing=0 cellpadding=10 border=0 hspace=0 vspace=0 height=100%
                onMouseOver="MM_showHideLayers('event','','show')"
                onMouseOut="MM_showHideLayers('event','','hide')">
                <tr valign=bottom>
                  <td><a href="?eventShow" class=headerSubMenuText><span id=evtLabel></span></a></td>
                </tr>
              </table>
            </div>
            <a class=headerMenuText href="#"
              onMouseOver="MM_swapEvtLabel();MM_showHideLayers('event','','show');MM_showHideLayers('language',1,'hide','network',1,'hide')"
              onMouseOut="MM_showHideLayers('event','','hide')">
              <? echo gprop('view') ?>
            </a>
          </td>
          <?= $seperator ?>
          <td>
            <div id=network class=headerSubMenu>
              <table cellspacing=0 cellpadding=10 border=0 hspace=0 vspace=0 height=100%
                onMouseOver="MM_showHideLayers('network','','show')"
                onMouseOut="MM_showHideLayers('network','','hide')">
                <tr valign=bottom>
                  <td><a href="?network" class=headerSubMenuText><span id=netLabel></span></a></td>
                </tr>
              </table>
            </div>
            <a class=headerMenuText href="#"
              onMouseOver="MM_swapNetLabel();MM_showHideLayers('network','','show');MM_showHideLayers('event',1,'hide','language',1,'hide')"
              onMouseOut="MM_showHideLayers('network','','hide')">
              <? echo gprop('storagenetwork') ?>
            </a>
          </td>
          <?= $seperator ?>
          <td>
            <a class=headerMenuText target=_parent href="index.php?logout"><? echo gprop('logout') ?></a>
          </td>
          <?= $seperator ?>
          <td>
            <a class=headerMenuText target=basefrm href="help/HelpMenu.html"><? echo gprop('help') ?></a>
          </td>
			<?PHP if (!$noabout) { ?>
            <?= $seperator ?>
          <td>
            <a class=headerMenuText target=basefrm href="about.php"><? echo gprop('about') ?></a>
          </td>
		    <?PHP 
    } ?>
          <?= $seperator ?>
          <td>
            <a class=headerMenuText title='<? echo gprop("alarmtitle") ?>'  target=basefrm href="buzzer.php?tab=4"><img id=alarm src='icons/buzzer.gif' width=20 height=20 border=0></a>
          </td>
<?
} else { ?>

<?
} ?>
        </tr>
      </table>
    </td>
  </tr>
  <tr >
    <td class=headerRow2></td>
  </tr>
</table>

<map name=home>
  <area title="<?= gprop('logoname') ?>" shape=RECT alt="<?= gprop('logoname') ?>" coords=<?= gprop('logocoords') ?> href="<?= gprop('website') ?>" target="window.top">
</map>

<script>
var alarmIcon = document.getElementById('alarm');
if(alarmIcon!=null)
{
    ylib_hide(alarmIcon);
}
</script>

</body>

</html>
