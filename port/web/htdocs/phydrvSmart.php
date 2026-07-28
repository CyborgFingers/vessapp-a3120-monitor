<?php
/*******************************************************************************
 * Filename    : phydrvSmart.php
 * Description : islaVista physical drive smart information.
 * Created on  : 03/25/03
 * CVS Version : $Id: phydrvSmart.php
 *
 * (C) Copyright Promise Technology Inc., 2020
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
  $phydrvsmartinfo = $subsystem->getPhysicalDriveSMARTInfo($_SESSION['pdid']);
  // error_log(print_r($phydrvsmartinfo, 1));
  SyncSession();
?>

<html>
<head>
<?php
  page_head();
?>
</head>

<body>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
  table_title(gprop('physicaldrive').' '.($_SESSION['pdid']+1));
  table_tab_head();

  $tabTitle = tabTitlePD($subsystem,$physicalDrive);
  table_tab($tabTitle);
  if (!isset($phydrvsmartinfo->status))
    table_row_empty('No SMART status information');
  else
  {
    if (1 == $phydrvsmartinfo->status->IsPDSAS)
    {
      table_head_fold('SMART Status','SMARTSTATUS', null, null, array('pad'=>2,'space'=>1,'border'=>0,'class'=>'tableContentInner'));
      table_row('Physical Drive ID', $phydrvsmartinfo->status->PhysicalDriveID);
      table_row('SMART Support', $phydrvsmartinfo->status->SMARTSupport == 1 ? 'Yes' : 'No');
      table_row('SMART Status', $phydrvsmartinfo->status->SMARTStatus == 1 ? 'Enabled' : 'Disabled');
      if (isset($phydrvsmartinfo->status->SMARTHealthStatus)){
        table_row('SMART Health Status', $phydrvsmartinfo->status->SMARTHealthStatus);
      }
      if (isset($phydrvsmartinfo->status->HealthPercent)){
        $hp = (int)$phydrvsmartinfo->status->HealthPercent;
        $hc = $hp >= 90 ? '#3c3' : ($hp >= 60 ? '#e90' : '#e33');
        $hw = $hp >= 90 ? 'Good' : ($hp >= 60 ? 'Warning' : 'Critical');
        table_row('Drive Health', '<span style="color:'.$hc.';font-weight:bold">'.$hp.'% ('.$hw.')</span>');
      }
      if (isset($phydrvsmartinfo->status->CurrentDrvTemperature)){
        table_row('Current Drive Temperature', $phydrvsmartinfo->status->CurrentDrvTemperature.('&#176;C'));
        table_row('Drive Trip Temperature', $phydrvsmartinfo->status->DrvTripTemperature.('&#176;C'));
      }
      if (isset($phydrvsmartinfo->status->ManufacturedYear))
      {
        table_row('Max Start Stop Count', $phydrvsmartinfo->status->MaxStartStopCount);
        table_row('Current Start Stop Count', $phydrvsmartinfo->status->CurrentStartStopCount);
        table_row('Manufactured Year', $phydrvsmartinfo->status->ManufacturedYear);
        table_row('Manufactured Week In Year', $phydrvsmartinfo->status->ManufacturedWeekInYear);
      }
    }
    else
    {
      table_head_fold('SMART Information','SMARTINFO', null, null, array('pad'=>2,'space'=>1,'border'=>0,'class'=>'tableContentInner'));
      table_row('Physical Drive ID', $phydrvsmartinfo->status->PhysicalDriveID);
      table_row('SMART Support', $phydrvsmartinfo->status->SMARTSupport == 1 ? 'Yes' : 'No');
      table_row('SMART Status', $phydrvsmartinfo->status->SMARTStatus == 1 ? 'Enabled' : 'Disabled');
      if (isset($phydrvsmartinfo->status->SMARTHealthStatus)){
        table_row('SMART Health Status', $phydrvsmartinfo->status->SMARTHealthStatus);
      }
      if (isset($phydrvsmartinfo->status->HealthPercent)){
        $hp = (int)$phydrvsmartinfo->status->HealthPercent;
        $hc = $hp >= 90 ? '#3c3' : ($hp >= 60 ? '#e90' : '#e33');
        $hw = $hp >= 90 ? 'Good' : ($hp >= 60 ? 'Warning' : 'Critical');
        table_row('Drive Health', '<span style="color:'.$hc.';font-weight:bold">'.$hp.'% ('.$hw.')</span>');
      }
      if(isset($phydrvsmartinfo->status->SCTStatusVersion)){
        table_row('SCT Status Version', $phydrvsmartinfo->status->SCTStatusVersion);
        table_row('SCT Version', $phydrvsmartinfo->status->SCTVersion);
        table_row('SCT Support Level', $phydrvsmartinfo->status->SCTSupportLevel);
        table_row('Current Temperature', $phydrvsmartinfo->status->CurrentTemperature.('&#176;C'));
        table_row('Power Cycle Max Temperature', $phydrvsmartinfo->status->PowerCycleMaxTemperature.('&#176;C'));
        table_row('Power Cycle Min Temperature', $phydrvsmartinfo->status->PowerCycleMinTemperature.('&#176;C'));
        table_row('Lifetime Max Temperature', $phydrvsmartinfo->status->LifetimeMaxTemperature.('&#176;C'));
        table_row('Lifetime Min Temperature', $phydrvsmartinfo->status->LifetimeMinTemperature.('&#176;C'));
      }
    }
    
    table_foot_fold();
    table_tab_foot();
  }

  table_head_fold('SMART Attributes', 'SMARTATTR', null, null, array('pad'=>2,'space'=>1,'border'=>0,'class'=>'tableContentInner'));

  if (!isset($phydrvsmartinfo->attr) || count($phydrvsmartinfo->attr) == 0) {
    table_row_empty('No SMART attribute');
  }
  else {
      $attr_names = array(
        1 => 'Read Error Rate',
        2 => 'Throughput Performance',
        3 => 'Spin-Up Time',
        4 => 'Start/Stop Count',
        5 => 'Reallocated Sectors Count',
        6 => 'Read Channel Margin',
        7 => 'Seek Error Rate',
        8 => 'Seek Time Performance',
        9 => 'Power-On Hours',
        10 => 'Spin Retry Count',
        11 => 'Recalibration Retries',
        12 => 'Device Power Cycle Count',
        13 => 'Soft Read Error Rate',
        170 => 'Grown Failing Block Count',
        171 => 'Program Fail Block Count',
        172 => 'Erase Fail Block Count',
        173 => 'Wear Leveling Count',
        174 => 'Unexpected Power Loss Count',
        177 => 'Wear Range Delta',
        180 => 'Unused Reserved Block Count Total',
        181 => 'Program Fail Count',
        182 => 'Erase Fail Count',
        183 => 'SATA Dowmshift Count',
        184 => 'Init Bad Block Count',
        185 => 'Head Stability',
        186 => 'Nduced Op-Vibration Detection',
        187 => 'Reported Uncorrectable Errors',
        188 =>  'Command Timeout',
        189 =>  'High Fly Writes',
        190 =>  'Airflow Temperature Celsius',
        191 =>  'G-sense error rate',
        192 =>  'Power-Off Retract Count',
        193 =>  'Load/Unload Cycle',
        194 =>  'Temperature',
        195 =>  'Hardware ECC Recovered',
        196 =>  'Reallocetion Events Count',
        197 =>  'Current Pending Sector Count',
        198 =>  'Uncorrectable Sector Count',
        199 =>  'UDMA CRC Error Count',
        200 =>  'Multi-Zone Error Rate',
        201 =>  'Soft Read Error Rate',
        202 =>  'Data Address Mark errors',
        203 =>  'Run Out Cancel',
        204 =>  'Soft ECC Correction',
        205 =>  'Thermal Asperity Rate',
        206 =>  'Flying Height',
        207 =>  'Spin High Current',
        208 =>  'Spin Buzz',
        209 =>  'Offline Seek Performance',
        210 =>  'Ramp Load Value',
        211 =>  'Vibration During Write',
        212 =>  'Shock During Write',
        220 =>  'Disk Shift',
        221 =>  'G-sense error rate',
        222 =>  'Loaded Hours',
        223 =>  'Load/Unload Retry Count',
        224 =>  'Load Friction',
        225 =>  'Load/Unload Cycle Count',
        226 =>  'Load In-time',
        227 =>  'Torque Amplification Count',
        228 =>  'Power-Off Retract Cycle',
        230 =>  'GMR Head Amplitude',
        231 =>  'Temperature',
        232 =>  'Endurance Remaining',
        233 =>  'Media Wareout Indicator',
        240 =>  'Head Flying Hours',
        241 =>  'Total LBAs Written',
        242 =>  'Total LBAs Read',
        250 =>  'Read Error Retry Rate',
        254 =>  'Free Fall Protection'
    );
    table_header_cols(array('ID', 'Description', 'Value', 'Worst', 'Threshold', "Raw Data"), array(10,35,10,10,15,20));

    foreach ($phydrvsmartinfo->attr as $key => $value) {
      $thres = 0;
      foreach ($phydrvsmartinfo->threshold as $id2 => $value2) {
        if ($value->id==$value2->id) {
          $thres=$value2->threshold;
        }
      }

      table_row_cols(array('#'.$value->id, ($attr_names[$value->id]??'not defined'), $value->current, $value->max, strval($thres), $value->raw));
    }
    table_foot_fold();
  }
  table_tab_foot();

  page_foot();
?>
  </table>

</body>
</html>
