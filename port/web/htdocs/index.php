<?php
ob_start();
?><!DOCTYPE html>
<head><meta http-equiv="Content-Type" content="text/html; charset=ISO-8859-1">
<meta http-equiv="X-UA-Compatible" content="IE=edge" />
<script src="js/jquery-3.6.0.min.js"></script>
<script src="js/md5.js"></script>
<?php

/*******************************************************************************
 * Filename    : index.php
 * Description : islaVista user login page.
 * Created on  : 04/01/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'common.php';

// _SESSION['sessionService'] is a object, in order to retreve it
// definition of the object has to be included first before call session_start

SetSessionValue('loginPage', 1);

include_once 'header.php';
include 'tree.php';

if(!isset($_SESSION))
  session_start();

function is_SSL()
{
  if (!isset($_SERVER['HTTPS']))
    return false;
  if ($_SERVER['HTTPS'] == 1) // Apache
  return true;
  if ($_SERVER['HTTPS'] == 'on') // IIS
  return true;
  if ($_SERVER['SERVER_PORT'] == 443) // Others
  return true;
  return false;
}

// PORT (Phase 4, Windows-ism): the original shelled out to Windows `findstr`
// against the bundled XAMPP Apache conf to read the SSL `Listen` port and detect
// mod_ssl. The Linux stack (php -S / php-fpm+nginx) has no Apache conf; derive
// host:port from the request and detect TLS from $_SERVER instead.
$httpport = '';
$https_host = isset($_SERVER['HTTP_HOST'])
  ? $_SERVER['HTTP_HOST']
  : ($_SERVER['SERVER_NAME'] . ':' . (isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : '80'));
$sslenabled = is_SSL() ? 1 : 0;
if (is_SSL()) {
  $protocol = 'https';
  $hasstunnel = 1;
  $https = 1;
} else {
  // PORT: the appliance always fronts PHP with an HTTPS stunnel; off-device the
  // fixture serves plain HTTP, so post the login form back over the same scheme.
  $protocol = 'http';
  $hasstunnel = 1;
  $https = 0;
}

if (isset($_POST['submit']) && isset($_SESSION['HTTP']) && $_SESSION['HTTP'] == 0);
else
  SetSessionValue('HTTP', $https);

if (isset($_GET['ssl']) && $_GET['ssl'] == 1)
  $ssl = 1;
else
  $ssl = 0;

?>
<head>
<title><?= gprop('title') ?></title>
  <?php
	if (!isset($_GET['logout']) && isset($_SESSION['sessionService'])) {
		if (isset($_POST['submit'])) {
  ?>
        <script>alert("<?=gprop('loginfaildupsession')?>");</script>
  <?php
      }
  ?>
  <meta http-equiv=refresh target=_top content="0; URL=screen.php">
  <?php
      exit();
    }
  $cssPostFix = "?time=" . time() . ".css";
  echo '
    <link rel="stylesheet" href="css/text1.css' . $cssPostFix . '" type="text/css">
    <link rel="stylesheet" href="css/main.css' . $cssPostFix . '" type="text/css">';
  ?>

<script type="text/javascript">
/*
* Function: onpageload()
*   Call by <body> while it's loading
*/
function onpageload()
{
  $('#nojswarning').css("display", "none"); /* Hide the warning message if javascript is enabled. */
}

function validate(form)
{
  if(form.login.value == ""){
    alert("<?=gprop("pleaseinputname")?>");
    form.login.focus();
    return false;
  }
  /* allow empty password
  if(form.password.value == ""){
    alert("Please enter password.");
    form.password.focus()
    return false;
  }
  */
  if(form.password.value == "password"){
    $('#defaultpw').val("1");
  }
  
  /* MD5 the password */
  pwd_in_md5 = $.MD5($("#loginForm").attr("dynccode"));
  // pwd_in_md5 = CryptoJS.MD5($("#loginForm").attr("dynccode"));
  pwd_in_md5 += form.password.value;
  pwd_in_md5 = $.MD5(pwd_in_md5);
  // pwd_in_md5 = CryptoJS.MD5(pwd_in_md5);
  pwd_in_md5 += form.login.value;
  pwd_in_md5 = $.MD5(pwd_in_md5);
  // pwd_in_md5 = CryptoJS.MD5(pwd_in_md5);
  $('#md5password').val(pwd_in_md5);
  /* Linux/PAM users are verified server-side against PAM, which needs the actual
   * password. Send it as plainpw ONLY over HTTPS so it is never exposed in the
   * clear (:8088 redirects to TLS); the server ignores it unless the account is
   * on the PAM allowlist. Local users' plaintext is discarded below. */
  if (window.location.protocol === 'https:') $('#plainpw').val(form.password.value);
  $('#lfPassword').val(""); /* Remove decrypted password from post form */
  return true;
}
</script>

<!-- hide the login form if javascript is disabled by web browser. -->
<noscript>
<style type="text/css">
#loginLayout {display:none;}
</style>
</noscript>

</head>

<body onload="javascript:onpageload();">
<?php
if (isset($_GET['i2']))
  SetSessionValue('i2', 1);

//if do not unset $_GET['logout'], browser BACK button will remember it
if (!isset($_SESSION['sessionService']))
  unset($_GET['logout']);

if (isset($_GET['logout'])) {
  $server = getServer();
  $server->logout();
  if (!isset($session))
    $session = new stdclass();
  $session->statusStr = 'logout';
  // unset($_SESSION['sessionService']) to prevent use browser back button to go back
  if (isset($_SESSION['sessionService']))
    unset($_SESSION['sessionService']);
}
if (isset($_POST['submit'])) {
  session_regenerate_id();
  // if (isset($_POST['username']) && isset($_POST['password2'])) {
  //   if (isset($_POST['language']))
  //     setcookie('language', $_POST['language'], null, null, null, true, true);
  //   setcookie('networkview', 1, null, null, null, true, true);

  //   $password = decryptpwd($_POST['password2']);
  //   $password = $password->decrypt;
  //   $session = new CSessionService($_POST['username'], $password);
  //   SetSessionValue('username', $_POST['username']);
  //   unset($_SESSION['redirect']);
  // } else 
  if (isset($_POST['login']) && isset($_POST['md5password'])) {
    $session = new CSessionService($_POST['login'], $_POST['md5password'], $_POST['hn1']);
    SetSessionValue('username', $_POST['login']);
    unset($_SESSION['redirect']);
  } else {
    $session = new CSessionService($_POST['login'], $_POST['password']);
    SetSessionValue('username', $_POST['login']);
    unset($_SESSION['redirect']);
  }
}

if (isset($session) && isset($session->status) && (0 == $session->status)) {
  setcookie('forcepw', 0, NULL, NULL, NULL, TRUE, TRUE);
  SetSessionValue('sessionService', serialize($session));
  /* A fresh login always lands on Home. Pin lastfile to Home rather than unset it:
   * home.php redirects there, AND treeframe.php's setup-wizard redirects
   * (checkIncompleteArray/checkConfiguration, guarded by !isset($_SESSION['lastfile']))
   * stay OFF - otherwise they bounce the base frame to the array pages on login. */
  $_SESSION['lastfile'] = 'home.php?home';
  if($_POST['defaultpw'] == "1"){
	  setcookie('forcepw', 1, NULL, NULL, NULL, TRUE, TRUE);
      $url = 'indexp.php';
    }
    else if($ssl){
      $url = 'screen.php';
    }
    else{
      if($https && isset($_POST['password2']))
        $url = 'https://'.$_SERVER['HTTP_HOST'].$httpport.'/screen.php';
      else
        $url = 'http://'.$_SERVER['HTTP_HOST'].$httpport.'/screen.php';
    }

?>
          <script>
            top.location.href="<?= $url ?>";
          </script>
    <!--
    <meta http-equiv="refresh" target="_parent" content="0; URL=<? echo $url ?>">
    -->
<?php
exit();
}

if (isset($_GET['contactus']))
  include 'contact.html';
else {
  ?>
<DIV id="outer">
  <DIV id="middle">
      <DIV id="logtab1" >
      <table class="logtab1">
		<tr>
   		 <td>
            <DIV id="logtab2" >
			 <table class="logtab2">
             <tr>
             <td>
             <div id ="login">
             <table align="center" class="login">
<form id="loginForm" dynccode="<?php 
                              $dc = getdynamiccode();
                              if (isset($dc) && $dc['err'] != 0) {
                                unset($dc);
                              }
                              if (isset($dc) && isset($dc['code'])) {
                                print($dc['code']);
                              } ?>"
  onsubmit="return validate(this)" method=post action=<? echo $protocol . '://' . $https_host ?>/index.php?ssl=<? echo $https ?>>
  <?php form_csrf(); ?>
  <table id="loginLayout" align="center" border="0" cellpadding="0" cellspacing="0">
    <tr><td>
    <table cellpadding="0" cellspacing="0" width="100%">
      <tbody><tr>
        <td colspan="2"></td>
      </tr>
      <tr height="">
        <td>
          <table width="100%" cellpadding="4" cellspacing="0" style="margin-bottom:14px;">
            <tr>
              <td class="rightText"><img class="icoimg" src="images/logico1.png"><? echo gprop('username') ?></td>
              <td>
                <input size=20 maxlength=31 type=text name=login class="input-login" TITLE="<?= gtooltip('username') ?>">
              </td>
            </tr>
            <tr>
              <td class=rightText><img class="icoimg" src="images/logico2.png"><? echo gprop('password') ?></td>
              <td>
                <input size=20 maxlength=31 type=password name=password id="lfPassword" class="input-login" TITLE="<?= gtooltip('password') ?>">
                <input type="hidden" id="hn1" value="<?PHP if (isset($dc) && isset($dc['id'])) {
                                                      print($dc['id']);
                                                    } ?>" name="hn1" />
                <input type="hidden" id="md5password" value="p1" name="md5password" />
                <!-- plaintext for Linux/PAM login; set by validate() over HTTPS only -->
                <input type="hidden" id="plainpw" name="plainpw" value="" />
				<input type="hidden" id="defaultpw" value="0" name="defaultpw" />
              </td>
            </tr>
            <tr>
              <td></td>
              <td align=left nowrap>
                <input class="input-p-login" type="submit" value="<? echo gprop('login') ?>" name="submit" />
              </td>
              <td class="rightText"></td>
            </tr>
            <tr>
              <td></td>
              <td align=left nowrap>
                <a href="certhelp.php" style="font-size:11px;color:#888;text-decoration:none;">Certificate / “not secure” warning?</a>
              </td>
              <td></td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
    </td></tr>
    <tr>
      <td class="noteText">
<?php
if (isset($_GET['note']))
  echo '<b class=warning>' . $_GET['note'] . '</b>';
else if (isset($session->statusStr) && ('logout' == $session->statusStr)) {
  echo '<b class=blueText>' . gresult('logoutok') . '</b>';
  unset($session);
} else if (isset($session->status) || isset($_GET['fail'])) {
  if (isset($session->status))
    $errorStr = gerror($session->status);
  if (isset($_GET['fail']))
    $errorStr = gerror((int)$_GET['fail']);
  echo '<b class=blueText>' . gresult('loginfail') . ': </b>
          <b class=warning>' . $errorStr . '</b>';

  if (!isset($_GET['fail'])) {
    if (isset($_SESSION['HTTP']) && $_SESSION['HTTP'] == 0)
      $url = 'http://' . $_SERVER['HTTP_HOST'] . '/index.php?fail=';
    else {
      $url = 'https://' . $_SERVER['HTTP_HOST'] . '/index.php?fail=';
    }
    $url = $url . $session->status;
    unset($session);

    ?>
          <script>
            top.location.href="<?= $url ?>";
          </script>
<?php

}
} else if (isset($_SESSION['hastimeout'])) {
  echo '<b class=blueText>' . gresult('loginfail') . ': </b>
          <b class=warning>' . gresult('lastsessiontimeout') . '</b>';
  unset($session);
}
if ($hasstunnel == 0 && $sslenabled != 1)
  echo '<BR><b class=warning>' . galert('loginnotsecure') . '</b>';
?>
      </td>
    </tr>
  </table>
  <!-- Display the warning message if web browser disabled javascript. -->
  <div align="center" id="nojswarning">You must enable Javascript in order to use this site.</div>
</form>
</table>
              </div>
             </td>
             </tr>
              </table>
		    </DIV>
          </td>
 	     </tr>

</table>
      </DIV>
  </DIV>
</DIV>
<table border="0">

</table>

<?
unset($_SESSION['lastfile']);
SetSessionValue('tab', 0);

if (isset($_SESSION['hastimeout']))
  unset($_SESSION['hastimeout']);
if (isset($_SESSION['time']))
  unset($_SESSION['time']);
}
?>

<script>
  form=document.forms[0];
  if(form && form.login)
    form.login.focus();
</script>

</body>
</html>
<?php ob_end_flush(); ?>
