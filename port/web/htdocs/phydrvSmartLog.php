<?php
/*******************************************************************************
 * Filename    : phydrvSmartLog.php
 * Description : islaVista physical drive smart information.
 * Created on  : 03/25/03
 * CVS Version : $Id: phydrvSmartLog.php
 *
 * (C) Copyright Promise Technology Inc., 2021
 * All Rights Reserved
 ******************************************************************************/

  include 'tree.php';
  include 'common.php';

  if (isset($_GET['pdid']))
    SetSessionValue('pdid', $_GET['pdid']);

  $server = getServer();
  $subsystem = $server->getSubsystem();
  $supportDualCtrl = $subsystem->supportdualcontroller();
  $physicalDrives = $subsystem->getPhysicalDrive($_SESSION['pdid']);
  $physicalDrive = $physicalDrives[0];
  $phydrvs = $subsystem->getPhyDrvsStatistics($_SESSION['pdid']);
  
  if (isset($_POST['save']))
  {
    $subsystem->exportPDSMART(apache_getenv('TMP') . '\\PDSMARTInfo.txt', $_SESSION['pdid']);
    $logfile = 'pdsmrt';
    header('Location: download.php?file=' . $logfile);
  }
  SyncSession();
?>

<html>
<head>
<?php
  page_head();
?>
</head>

<body>
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
  table_title(gprop('physicaldrive').' '.($_SESSION['pdid']+1));
  table_tab_head();

  $tabTitle = tabTitlePD($subsystem,$physicalDrive);
  table_tab($tabTitle);
  echo'<td>
         <span class=text1>SMART Log</span>
       </td>';
    
  table_foot_fold();
  table_tab_foot();

?>
  <table border=0 cellspacing=2 cellpadding=0 align=center>
    <tr>
      <td>
        <input class=input-p type=submit value="Save" name=save>
      </td>
    </tr>
  </table>
<?php

  page_foot();
?>
  </table>
</form>

</body>
</html>
