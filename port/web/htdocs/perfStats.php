<?php

/*******************************************************************************
 * Filename    : perfStatistics.php
 * Description : performance statistics page
 * Created on  : 02/23/09
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';
include 'perfStatsCommon.php';

if (isset($_GET['ssid']))
  SetSessionValue('ssid', $_GET['ssid']);

SyncSession();
$server = getServer();
$subsystem = $server->getSubsystem();
$pds = $subsystem->getPhysicalDrives();
$lds = $subsystem->getLogicalDrives();
$ctrls = $subsystem->getControllers();
$portPerCtrl = 2;
if ($subsystem->frontendType == PI_FB_HOST_INTERFACE_SAS) {
  if (count($ctrls) > 0) {
    $ports = $subsystem->getsasport($ctrls[0]->ci_DeviceID + 1);
    if (isset($ports))
      $portPerCtrl = count($ports);
  }
}
   
$subsystem->startperfstats();
$note = null;

function getLdTable()
{
  echo "
            <tr class='tableRow1Cols' style='height:212px;width:100%'>
              <td style='width:25%;align:center;border:0'>
                <div style='width:100%;left:0;top:0;height:34px;position:relative'>
                    " . getLdDataTypeSelect("ldType") . "
                </div>
                <div style='width:100%;left:0;top:0;position:relative'>
                    " . getLdList() . "
                </div>
              </td>
              <td style='width:75%;border:0'>
                <div style='width:100%;height:200px;float:left;position:relative' id=ldDiv>
                    <canvas width='200' height='200' ></canvas>
                </div>
              </td>
            </tr>
        ";
}
function getPdTable()
{
  echo "
            <tr class='tableRow1Cols' style='height:212px;width:100%'>
              <td style='width:25%;align:center;border:0'>
                <div style='width:100%;left:0;top:0;height:34px;position:relative'>
                    " . getDataTypeSelect("pdType") . "
                </div>
                <div style='width:100%;left:0;top:0;position:relative'>
                    " . getPdList() . "
                </div>
              </td>
              <td style='width:75%;border:0'>
                <div style='width:100%;height:200px;float:left;position:relative' id=pdDiv>
                    <canvas width='200' height='200' ></canvas>
                </div>
              </td>
            </tr>
        ";
}
function getPortTable()
{
  echo "
            <tr class='tableRow1Cols' style='height:212px;width:100%'>
              <td style='width:25%;align:center;border:0'>
                <div style='width:100%;left:0;top:0;height:34px;position:relative'>
                    " . getDataTypeSelect("portType") . "
                </div>
                <div style='width:100%;left:0;top:0;position:relative'>
                    " . getPortList() . "
                </div>
              </td>
              <td style='width:75%;border:0'>
                <div style='width:100%;height:200px;float:left;position:relative' id=portDiv>
                    <canvas width='200' height='200' ></canvas>
                </div>
              </td>
            </tr>
        ";
}
?>

<html>
<head>
<style>
.highlight{
     background-color:#c9c9c9;
     color:#fff;
}
</style>
<script type="text/xaml" id="xaml">
  <Canvas xmlns="http://schemas.microsoft.com/client/2007"></Canvas>
</script>
<?php
$jsPostFix = "?time=" . time() . ".js";
echo '
	<script type="text/javascript" src="js/dojo.js"></script>
	<script type="text/javascript" src="js/chart.js' . $jsPostFix . '"></script>
	<script type="text/javascript" src="js/graf.js"></script>';
?>

<?php
page_head();
ob_end_flush();
?>
<script>
  /*selected lds*/
  var ldChart;
  var pdChart;
  //var portChart;
  var dataLoader;
  var dataList = {

  };
  /*display data list*/
  var dispList = [

  ];

  function onLoad()
  {
    ldChart = drawChart(document.getElementById("ldDiv"));
    pdChart = drawChart(document.getElementById("pdDiv"));
    //portChart = drawChart(document.getElementById("portDiv"));
    dataLoader = new DataLoader();
    dojo.connect(dataLoader, "onNewData", null, "refreshData");
    dataLoader.loadData();
    setInterval(dojo.hitch(dataLoader, "loadData"), 5000);
    updateDispList();
  }


</script>
</head>

<body onLoad="onLoad()">
<form method=post  >
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('performancestatistics'), 'performancestatistics', $note);
table_tab_head();

$tabTitle = tabTitlePerfStat();
table_tab($tabTitle);


table_head_fold(gprop("ldstatistics"), "ldPerf", null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
getLdTable();
table_foot_fold();
table_head_fold(gprop("pdstatistics"), "pdPerf", null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
getPdTable();
table_foot_fold();
table_tab_foot();
page_foot();
?>
  </table>
</form>
</body>
</html>

<?php
if (isset($_SESSION['pegasus']))
  $server->logout();
?>


