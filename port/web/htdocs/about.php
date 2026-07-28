<?php

/*******************************************************************************
 * Filename    : about.php
 * Description : islaVista Copyright page.
 * Created on  : 06/09/06
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$server = getServer();
$subsystem = $server->getSubsystem();
$controller = $subsystem->getController(0);
// $swVer = htmlspecialchars($controller->ci1_InstallPkgVersion);
$swVer = ' ';
$swDate = htmlspecialchars($controller->ci1_PriSWBuildDate);
$guiversion = htmlspecialchars($controller->ci1_PriSWBuildNo);
?>
<html>

<head>
<link rel="stylesheet" href="/css/help.css" type="text/css">
<link rel="stylesheet" href="/css/text1.css" type="text/css">
<link rel="stylesheet" href="/help/help.css" type="text/css">
</head>

<body>
  <table cellpadding="10" width="635" align="center">
  <tr>
    <td valign="middle">
      <table>
      <tr>
          <td>
            <span class="text7"><?=$swVer?></span><br/>
          </td>
        </tr>
        <tr>
          <td align="left" width="30%">
            <h3 align="right">GUI VERSION: <?=$guiversion?></h3>
            <h4 align="right">Build Data : <?=$swDate?></h4>
          </td>
        </tr>
      <table>
    </td>
  </tr>
  </table>

</body>
</html>
