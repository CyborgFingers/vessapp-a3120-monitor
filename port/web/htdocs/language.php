<?php

/*******************************************************************************
 * Filename    : language.php
 * Description : islaVista language switch.
 * Created on  : 03/01/04
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/

include_once 'defense.php';
require('languages/langList.php');

if (!isset($_SESSION))
  session_start();

  if(!empty($_GET['language']))
  {
  $language = $_GET['language'];
  }
  else if(!empty($_COOKIE['language']))
  {
  $language = $_COOKIE['language'];
  // $_SESSION['language'] = $language;
  }
  else
  {
  preg_match('/^([a-z\-]+)/i', $_SERVER['HTTP_ACCEPT_LANGUAGE'], $matches);
  $lang = $matches[1];
    switch(substr($lang,0,2))
    {
    case 'en':
      $language = 'en_US';
      break;
    case 'pt':
      $language = 'pt_PT';
      break;
    case 'tr':
      $language = 'tr_TR';
      break;
    case 'ar':
      $language = 'ar_AR';
      break;
    case 'fr':
      $language = 'fr_FR';
      break;
    case 'de':
      $language = 'de_DE';
      break;
    case 'it':
      $language = 'it_IT';
      break;
    case 'ja':
      $language = 'ja_JP';
      break;
    case 'ko':
      $language = 'ko_KR';
      break;
    case 'zh':
	    // compare the last two characters, case insensitive.
      if (strcasecmp(substr($lang, -2), 'cn') == 0)
        $language = 'zh_CN';
      else
        $language = 'zh_TW';
      break;
    case 'es':
      $language = 'es_ES';
      break;
    case 'ru':
      $language = 'ru_RU';
      break;
    default:
      $language = 'en_US';
      break;
  }
}
// remove trailing '/' from URL
$language = str_replace('/', '', $language);
if (!array_key_exists($language, $charset_array))
  $language = 'en_US';

$pagefile = htmlspecialchars($_SERVER['PHP_SELF']);
$page = substr($pagefile, 1, -4);
if ($page == '') $page = 'index';
$module = file2mod($page);

$langupdate = false;
  if(empty($_SESSION['langprops']) || empty($_SESSION['language']) || $language!=$_SESSION['language'])
  {
  $langupdate = true;
  $_SESSION['language'] = $language;
}
  if($langupdate)
  {
  $dirlevel = '';
  $dirlevelnum = 0;
  for ($i = 0; $i < strlen($pagefile); $i++) {
    if (strcmp(substr($pagefile, $i, 1), '/') === 0)
      $dirlevelnum++;
  }
  for ($j = 1; $j < $dirlevelnum; $j++)
    $dirlevel = '../' . $dirlevel;

    if(($fprop=@fopen($dirlevel.'languages/'.$language.'_Messages.properties','r')) == FALSE)
    $fprop = fopen($dirlevel . 'languages/en_US_Messages.properties', 'r');

    while($fprop!=NULL&&!feof($fprop)){
    $line = trim(fgets($fprop));
    if (empty($line) || $line[0] == '#')
      continue;
    $list = explode('=', $line, 2);
    if (count($list) == 2) {
      $tags = explode('.', trim($list[0]), 4);
      $taglen = count($tags);
      if ($taglen > 2 && $tags[0] == 'v')
        if ($taglen == 4)
        $props[$tags[1]][$tags[2]][$tags[3]] = str_replace('\\\\', '\\', trim($list[1]));
      else
        $props[$tags[1]][$tags[2]] = str_replace('\\\\', '\\', trim($list[1]));
    }
  }

    if ($fprop!=NULL) fclose($fprop);
  $_SESSION['langprops'] = $props;
}
$props = $_SESSION['langprops'];

function gprop($tag, $group = null)
{
  global $props, $module;

  if (empty($group)) {
    if (isset($props[$module][$tag]))
      return $props[$module][$tag];
    else if (isset($props['common'][$tag]))
      return $props['common'][$tag];
    else
	  	  return NULL;
	 }
	 else {
    if (isset($props[$module][$group][$tag]))
      return $props[$module][$group][$tag];
    else if (isset($props['common'][$group][$tag]))
      return $props['common'][$group][$tag];
    else
	  	  return NULL;
  }
}

function galert($tag)
{
  return gprop($tag, 'alert');
}
function gconfirm($tag)
{
  return gprop($tag, 'confirm');
}
function gresult($tag)
{
  return gprop($tag, 'result');
}
function getASCQ($ec)
{
  return (($ec & 0xFF00) >> 8);
}
function getASC($ec)
{
  return (($ec & 0xFF0000) >> 16);
}
function gerror($tag)
{
  if (!is_numeric($tag)) {
    return gprop($tag, 'error');
  }

  // is PMIC error code?
  if (($tag & 0xF0000000) == 0x40000000) {
    $ascq = getASCQ($tag);
    $asc = getASC($tag);
    if ($ascq <= 0x4F) {
      if ($asc == 0) {
        return gerror('mic_0_0');
      } else {
        return gerror(sprintf('mic_0_%d', $ascq));
      }
    } else {
      return gerror(sprintf('mic_%d_%d', $asc, $ascq));
    }
  } else {
    return gprop($tag, 'error');
  }
}
function gtooltip($tag)
{
  return gprop($tag, 'tooltip');
}
function gwaitmsg($tag)
{
  return gprop($tag, 'waitmsg');
}

  function gjsformat($str) {
  $str = str_replace('&#34;', '"', $str);
  $str = str_replace('"', '\\"', $str);
  $str = str_replace('\'', '\\\'', $str);
  return $str;
}

  function gjsprop($tag) {
  return gjsformat(gprop($tag));
}

  function gjsalert($tag) {
  return gjsformat(galert($tag));
}

  function gjsresult($tag) {
  return gjsformat(gresult($tag));
}

  function gjsconfirm($tag) {
  return gjsformat(gconfirm($tag));
}

  //header('Content-type: text/html; charset='.$charset_array[$language]);
  header('Content-type: text/html; charset=utf-8');
  setcookie('language',$language,time()+15552000, NULL, NULL, TRUE, TRUE);
  /*echo '
  <script>
    function getCookie( name ) {
        var start = document.cookie.indexOf( name + "=" );
        var len = start + name.length + 1;
        if ( ( !start ) && ( name != document.cookie.substring( 0, name.length ) ) ) {
            return null;
        }
        if ( start == -1 ) return null;
        var end = document.cookie.indexOf( ";", len );
        if ( end == -1 ) end = document.cookie.length;
        return unescape( document.cookie.substring( len, end ) );
    }

    function setCookie( name, value, expires, path, domain, secure ) {
        var today = new Date();
        today.setTime( today.getTime() );
        var defaultExpires = 180 * 1000 * 60 * 60 * 24;
        if ( expires ) {
            expires = expires * 1000 * 60 * 60 * 24;
        }
        var expires_date = new Date( today.getTime() + ((expires) ? expires : defaultExpires));
        document.cookie = name+"="+escape( value )
            + ( ";expires="+expires_date.toGMTString() )
            + ( ( path ) ? ";path=" + path : "" )
            + ( ( domain ) ? ";domain=" + domain : "" )
            + ( ( secure ) ? ";secure=" : "" );
    }

    function deleteCookie( name, path, domain ) {
        if ( getCookie( name ) ) {
            document.cookie = name + "=" + ( ( path ) ? ";path=" + path : "")
                + ( ( domain ) ? ";domain=" + domain : "" ) + ";expires=Thu, 01-Jan-1970 00:00:01 GMT";
        }
    }
    setCookie("language","'.$language.'");
  </script>';*/

?>
