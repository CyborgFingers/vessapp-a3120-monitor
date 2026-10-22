<?php

/*******************************************************************************
 * Filename    : home.php
 * Description : islaVista quick start page
 * Created on  : 10/09/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';
if ($language == 'ar_AR') {
  $showbody = 'arhomeBody';
} else {
  $showbody = 'homeBody';
}
if (!isset($_SESSION))
  session_start();
// logInfoString('home start');
if (!isset($_SESSION['processing']) || $_SESSION['processing'] == 1)
  echo '<meta http-equiv=refresh target=_self content="1;URL=home.php?home">';
else {
  if (!isset($_GET['home'])) {
    if (isset($_SESSION['lastfile'])) {
      $lastfile = $_SESSION['lastfile'];
      if (!strpos($lastfile, '/home.php'))
        $lastfile = 'home.php?home';
      else {
        if (isset($_SESSION['tab']))
          $lastfile .= (strpos($lastfile, '?') ? '&' : '?') . 'tab=' . $_SESSION['tab'];
        else
          $lastfile .= (strpos($lastfile, '?') ? '&' : '?') . 'tab=0';
      }
      table_wait();
      redirectPage($lastfile);
      exit;
    }
  }
?>
<html>

<head>
<?php
$cssPostFix = "?time=" . time() . ".css";
echo '
  <link rel="stylesheet" href="css/text1.css' . $cssPostFix . '" type="text/css">
  <link rel="stylesheet" href="css/table.css' . $cssPostFix . '" type="text/css">';

  $server = getServer();
  $subsystem = $server->getSubsystem();
  $controller = $subsystem->getController(0);    
  $swDate = $controller->ci1_PriSWBuildDate;

SetSessionValue('lastfile', 'home.php?home');
SyncSession();

function oneFeature($text, $ref)
{
  echo '
    <tr>
      <td>
        <a class=textHomeLink href="' . $ref . '">' . $text . '</a>
      </td>
    </tr>';
}
?>
</head>

<body class=<?= $showbody ?> >
<?php require_once 'winupdate.php'; winupdate_banner(); ?>

<table align="center" width="500">
	<tr>
    	<td ><img src="images/title.gif" style="margin-top:60px"></td>
    </tr>
	    <tr>
        <td>
        	<table border="0" cellpadding="0" cellspacing="0" style="margin-top:60px">
        	<tbody>
            	<tr>
         			<td>
         				<span class="textHomeLinkHeader">Quick Links</span></td></tr>
                <tr>
                	<td class="tableHomeLink" align="center">

                        <table cellspacing="0" cellpadding="0">

  <?php
  oneFeature(gprop('homefeatureslogdrvs'), 'logdrvs.php?ssid=0&tab=0');
  oneFeature(gprop('homefeaturesphydrvs'), 'phydrvs.php?ssid=0&tab=0');
  oneFeature(gprop('homefeaturesarray'), 'arrays.php?ssid=0&tab=0');
  oneFeature(gprop('homefeaturesnet'), 'net.php?act=ether&ssid=0&tab=0');
  oneFeature(gprop('homefeaturesevents'), 'events.php?ssid=0&tab=2');
  oneFeature(gprop('homefeaturesbga'), 'bga.php?ssid=0&tab=3');

  if (isset($_SESSION['froentendType'])) {
    if (defined('PI_FB_HOST_INTERFACE_ISCSI') && ($_SESSION['froentendType'] == PI_FB_HOST_INTERFACE_ISCSI))
      oneFeature(gprop('homefeaturesiscsi'), 'iNode.php?ssid=0&tab=0');
    else if (defined('PI_FB_HOST_INTERFACE_FC') && ($_SESSION['froentendType'] == PI_FB_HOST_INTERFACE_FC))
      oneFeature(gprop('homefeaturesfc'), 'fcNode.php?ssid=0&tab=0');
    else if (defined('PI_FB_HOST_INTERFACE_SAS') && ($_SESSION['froentendType'] == PI_FB_HOST_INTERFACE_SAS))
      oneFeature(gprop('homefeaturessas'), 'sasPortList.php?ssid=0&tab=0');
    else if (defined('PI_FB_HOST_INTERFACE_SCSI') && ($_SESSION['froentendType'] == PI_FB_HOST_INTERFACE_SCSI))
      oneFeature(gprop('homefeaturesscsi'), 'pPort.php?ssid=0&tab=0');
  }
  ?>
            </table>

                  </td>
                </tr>
      		 </table>
        </td>
    </tr>
    <tr height="100px">
      <td valign="bottom">
        Copyright &copy;<?php $a = array_reverse(explode(",", $swDate)); print(trim($a[0]));?> Promise Technology Inc.
        All rights reserved.
      </td>
    </tr>
 </table>   
</body></html>
<?php

}
//  logInfoString('home complete');
?>
