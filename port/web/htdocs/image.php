<?php

/*******************************************************************************
 * Filename    : table.php
 * Description : islaVista common functions for showing table.
 * Created on  : 04/01/03
 *
 * (C) Copyright Promise Technology Inc.,2020
 * All Rights Reserved
 ******************************************************************************/
include_once 'defense.php';
function showEnclImage($portsState)
{
  echo getEnclImage();
  echo '<script>';
  changeGreenLed($portsState);
  echo '</script>';
}

function getEnclImage()
{
  $imgpath = "";
  $enclImage = '';
  $enclImage = '
      <tr>
        <td>
          <table class="tableImageBackground" align="center" background="enclosureImages/poe_16p_bg.png" border="0" cellpadding="0" cellspacing="0" >
            <tr>
              <td valign="top" width="597" height="215" class="imageEnc">
                <div style="margin-left:60px;margin-top:0px;">
				  <img id="uplink" style="height: 31px; position: absolute; left: 46px; top: 27px; " src="' . $imgpath . 'enclosureImages/poe_black_up.png">
                  <img id="port1" style="height: 31px; position: absolute; left: 101px; top: 27px; " src="' . $imgpath . 'enclosureImages/poe_black_up.png">
				  <img id="port2" style="height: 31px; position: absolute; left: 152px; top: 27px; " src="' . $imgpath . 'enclosureImages/poe_black_up.png">
				  <img id="port3" style="height: 31px; position: absolute; left: 203px; top: 27px; " src="' . $imgpath . 'enclosureImages/poe_black_up.png">
				  <img id="port4" style="height: 31px; position: absolute; left: 254px; top: 27px; " src="' . $imgpath . 'enclosureImages/poe_black_up.png">
				  <img id="port5" style="height: 31px; position: absolute; left: 326px; top: 27px; " src="' . $imgpath . 'enclosureImages/poe_black_up.png">
				  <img id="port6" style="height: 31px; position: absolute; left: 377px; top: 27px; " src="' . $imgpath . 'enclosureImages/poe_black_up.png">
                  <img id="port7" style="height: 31px; position: absolute; left: 428px; top: 27px; " src="' . $imgpath . 'enclosureImages/poe_black_up.png">
                  <img id="port8" style="height: 31px; position: absolute; left: 479px; top: 27px; " src="' . $imgpath . 'enclosureImages/poe_black_up.png">
                </div>
				<div style="margin-left:60px;margin-top:47px;">
                  <img id="port9" style="height: 31px; position: absolute; left: 101px; top: 27px; " src="' . $imgpath . 'enclosureImages/poe_black_down.png">
				  <img id="port10" style="height: 31px; position: absolute; left: 152px; top: 27px; " src="' . $imgpath . 'enclosureImages/poe_black_down.png">
				  <img id="port11" style="height: 31px; position: absolute; left: 203px; top: 27px; " src="' . $imgpath . 'enclosureImages/poe_black_down.png">
				  <img id="port12" style="height: 31px; position: absolute; left: 254px; top: 27px; " src="' . $imgpath . 'enclosureImages/poe_black_down.png">
				  <img id="port13" style="height: 31px; position: absolute; left: 326px; top: 27px; " src="' . $imgpath . 'enclosureImages/poe_black_down.png">
				  <img id="port14" style="height: 31px; position: absolute; left: 377px; top: 27px; " src="' . $imgpath . 'enclosureImages/poe_black_down.png">
                  <img id="port15" style="height: 31px; position: absolute; left: 428px; top: 27px; " src="' . $imgpath . 'enclosureImages/poe_black_down.png">
                  <img id="port16" style="height: 31px; position: absolute; left: 479px; top: 27px; " src="' . $imgpath . 'enclosureImages/poe_black_down.png">
                </div>
				<div style="margin-left:60px;margin-top:139px;">
                  <img id="port17" style="height: 31px; position: absolute; left: -20px; top: 27px; " src="' . $imgpath . 'enclosureImages/poe_black_up.png">
				  <img id="port18" style="height: 31px; position: absolute; left: 45px; top: 27px; " src="' . $imgpath . 'enclosureImages/poe_black_up.png">
                </div>
              </td>
            </tr>
            <tr class="tableImageBackground">
              <td align="center" class="text2">Mouse over an icon to show information.
              </td>
            </tr>
          </table>
        </td>
      </tr>';

  return $enclImage;
}

function changeGreenLed($portsState)
{
  foreach ($portsState as $portIndex => $portState) {
    if ($portState->PortNum > 16) {
      switch ($portState->PortLink_Str) {
        case 'Down':
          break;
        default:
          echo 'changeImage("port' . $portState->PortNum . '","enclosureImages/poe_green_up.png");';
          break;
      }
    }
    if (9 <= $portState->PortNum && $portState->PortNum <= 16) {
      switch ($portState->PortLink_Str) {
        case 'Down':
          break;
        default:
          echo 'changeImage("port' . $portState->PortNum . '","enclosureImages/poe_green_down.png");';
          break;
      }
    }
    if (1 <= $portState->PortNum && $portState->PortNum <= 8) {
      switch ($portState->PortLink_Str) {
        case 'Down':
          break;
        default:
          echo 'changeImage("port' . $portState->PortNum . '","enclosureImages/poe_green_up.png");';
          break;
      }
    }
  }
}

function poeShowPortStatistics($portID, $portStatistiscs)
{
  $portStatistiscs = json_decode(json_encode($portStatistiscs), true);
  table_head_fold('Detailed Port Statistics', 'Detailed_Port_Statistics', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
  table_header_cols_one(array('Receive Total'));
  table_row('Rx Packets', $portStatistiscs[$portID]['Rx_Packets']);
  table_row('Rx Octets', $portStatistiscs[$portID]['Rx_Octets']);
  table_row('Rx Unicast', $portStatistiscs[$portID]['Rx_Unicast']);
  table_row('Rx Multicast', $portStatistiscs[$portID]['Rx_Multicast']);
  table_row('Rx Broadcast', $portStatistiscs[$portID]['Rx_Broadcast']);
  table_row('Rx Pause', $portStatistiscs[$portID]['Rx_Pause']);
  table_header_cols_one(array('Transmit Total'));
  table_row('Tx Packets', $portStatistiscs[$portID]['Tx_Packets']);
  table_row('Tx Octets', $portStatistiscs[$portID]['Tx_Packets']);
  table_row('Tx Unicast', $portStatistiscs[$portID]['Tx_Packets']);
  table_row('Tx Multicast', $portStatistiscs[$portID]['Tx_Packets']);
  table_row('Tx Reroadcast', $portStatistiscs[$portID]['Tx_Packets']);
  table_row('Tx Pause', $portStatistiscs[$portID]['Tx_Packets']);
  table_header_cols_one(array('Receive Size Counters'));
  table_row('Rx 64 Bytes', $portStatistiscs[$portID]['Rx_64']);
  table_row('Rx 65-127 Bytes', $portStatistiscs[$portID]['Rx_65_127']);
  table_row('Rx 128-255 Bytes', $portStatistiscs[$portID]['Rx_128_255']);
  table_row('Rx 256-511 Bytes', $portStatistiscs[$portID]['Rx_256_511']);
  table_row('Rx 512-1023 Bytes', $portStatistiscs[$portID]['Rx_512_1023']);
  table_row('Rx 1024-1526 Bytes', $portStatistiscs[$portID]['Rx_1024_1526']);
  table_row('Rx 1527- Bytes', $portStatistiscs[$portID]['Rx_1527']);
  table_header_cols_one(array('Transmit Size Counters'));
  table_row('Tx 64 Bytes', $portStatistiscs[$portID]['Tx_64']);
  table_row('Tx 65-127 Bytes', $portStatistiscs[$portID]['Tx_65_127']);
  table_row('Tx 128-255 Bytes', $portStatistiscs[$portID]['Tx_128_255']);
  table_row('Tx 256-511 Bytes', $portStatistiscs[$portID]['Tx_256_511']);
  table_row('Tx 512-1023 Bytes', $portStatistiscs[$portID]['Tx_512_1023']);
  table_row('Tx 1024-1526 Bytes', $portStatistiscs[$portID]['Tx_1024_1526']);
  table_row('Tx 1527- Bytes', $portStatistiscs[$portID]['Tx_1527']);
  table_header_cols_one(array('Receive Queue Counters'));
  table_row('Rx Q0', $portStatistiscs[$portID]['Rx_0']);
  table_row('Rx Q1', $portStatistiscs[$portID]['Rx_1']);
  table_row('Rx Q2', $portStatistiscs[$portID]['Rx_2']);
  table_row('Rx Q3', $portStatistiscs[$portID]['Rx_3']);
  table_row('Rx Q4', $portStatistiscs[$portID]['Rx_4']);
  table_row('Rx Q5', $portStatistiscs[$portID]['Rx_5']);
  table_row('Rx Q6', $portStatistiscs[$portID]['Rx_6']);
  table_row('Rx Q7', $portStatistiscs[$portID]['Rx_7']);
  table_header_cols_one(array('Transmit Queue Counters'));
  table_row('Tx Q0', $portStatistiscs[$portID]['Tx_0']);
  table_row('Tx Q1', $portStatistiscs[$portID]['Tx_1']);
  table_row('Tx Q2', $portStatistiscs[$portID]['Tx_2']);
  table_row('Tx Q3', $portStatistiscs[$portID]['Tx_3']);
  table_row('Tx Q4', $portStatistiscs[$portID]['Tx_4']);
  table_row('Tx Q5', $portStatistiscs[$portID]['Tx_5']);
  table_row('Tx Q6', $portStatistiscs[$portID]['Tx_6']);
  table_row('Tx Q7', $portStatistiscs[$portID]['Tx_7']);
  table_header_cols_one(array('Receive Error Counters'));
  table_row('Rx Drops', $portStatistiscs[$portID]['Rx_Drops']);
  table_row('Rx CRC/Alignment', $portStatistiscs[$portID]['Rx_CRC_Alignment']);
  table_row('Rx Undersize', $portStatistiscs[$portID]['Rx_Undersize']);
  table_row('Rx Oversize', $portStatistiscs[$portID]['Rx_Oversize']);
  table_row('Rx Fragments', $portStatistiscs[$portID]['Rx_Fragments']);
  table_row('Rx Jabber', $portStatistiscs[$portID]['Rx_Jabbers']);
  table_row('Rx Filtered', $portStatistiscs[$portID]['Rx_Filtered']);
  table_header_cols_one(array('Transmit Error Counters'));
  table_row('Tx Drops', $portStatistiscs[$portID]['Tx_Drops']);
  table_row('Tx Late/Exc.Coll', $portStatistiscs[$portID]['Tx_Late_Exc_Coll']);
}

function poePort($port, $portNumber)
{
  echo '
	 	<tr class="tableRow1Cols ">
		  <td>
		    <select name="portID" onchange="function(s){alert(s);};">';
  for ($i = 0; $i < $portNumber; $i++) {
    if ($port == ($i - 1)) {
      echo '
		<option value="' . ($i - 1) . '" selected="selected">Port ' . ($i + 1) . '</option>';
    } else {
      echo '
			<option value="' . ($i - 1) . '">Port ' . ($i + 1) . '</option>';
    }
  }
  echo '
		</select>
		    <input type="submit" name="button" id="button" value="Refresh" />
		  </td>
		</tr>';
}

function poeDHCPServerGlobalMode($mode)
{
  echo '
	 	<tr class="tableRow1Cols ">
		  <td width=30% class=tableRowCol1>
      		<span class=text4>Global Mode</span>
    	  </td>
		  <td>
		    <select name="GlobalMode" >';
  switch ($mode) {
    case '0':
      echo '
			<option value=0 selected="selected">Disable</option>
			<option value=1 >Enable</option>';
      break;
    case '1':
      echo '
			<option value=0 >Disable</option>
			<option value=1 selected="selected">Enable</option>';
      break;
  }
}

function poePowerOverEthernetConfiguration($mode)
{
  $propChecked = ' checked="checked"';
  echo PHP_EOL;
  echo '			<tr class="tableRow1Cols" width="100%">' . PHP_EOL;
  echo '			  <td class=tableHeader width="27%">' . PHP_EOL;
  echo '				<span class="text4b">Power Management Mode</span>' . PHP_EOL;
  echo '			  </td>' . PHP_EOL;
  echo '			  <td class=tableRowColOther>' . PHP_EOL;
  echo '				<input style="margin-left:10px;" type="radio" name="radio" id="radio1" value="1"' . ($mode == '1' ? $propChecked : '') . ' /><label for="radio1" style="color:#111">Normal Mode</label>' . PHP_EOL;
  echo '				<input style="margin-left:10px;" type="radio" name="radio" id="radio2" value="2"' . ($mode == '2' ? $propChecked : '') . ' /><label for="radio2" style="color:#111">Power Saving Mode</label>' . PHP_EOL;
  echo '				<input style="margin-left:10px;" type="radio" name="radio" id="radio3" value="3"' . ($mode == '3' ? $propChecked : '') . ' /><label for="radio3" style="color:#111">User Define Mode</label>' . PHP_EOL;
  echo '			  </td>' . PHP_EOL;
  echo '			</tr>' . PHP_EOL;
}

function poePoEPortConfiguration($mode)
{
  for ($i = 1; $i <= 16; $i++) {
    if ($mode == '1') {
      $poeMode = 1;
      $poePower = '30';
      $disableCtrl = true;
    } else if ($mode == '2') {
      $poeMode = 0;
      $poePower = '15.4';
      $disableCtrl = true;
    } else {
      $poeMode = $_POST['poeMode' . $i];
      $poePower = $_POST['Power' . $i];
      $disableCtrl = false;
    }
    echo PHP_EOL;
    echo '				<tr class="tableRow' . ($i % 2 + 1) . 'Cols ">' . PHP_EOL;
    echo '					<td>' . $i . '</td>' . PHP_EOL;
    echo '					<td>' . PHP_EOL;
    echo '						<select style="width:90px;"' . ($disableCtrl ? ' disabled="disabled"' : '') . ' class="input-poe-mode" name="poeMode' . $i . '">' . PHP_EOL;
    echo '							<option value="0"' . ($poeMode == 0 ? ' selected' : '') . '>PoE</option>' . PHP_EOL;
    echo '							<option value="1"' . ($poeMode == 1 ? ' selected' : '') . '>PoE+</option>' . PHP_EOL;
    echo '						</select>' . PHP_EOL;
    echo '					</td>' . PHP_EOL;
    echo '					<td>' . PHP_EOL;
    echo '						<input' . ($disableCtrl ? ' disabled="disabled"' : '') . ' class="input-power" type="text" name="Power' . $i . '" value="' . $poePower . '" align="right">' . PHP_EOL;
    echo '					</td>' . PHP_EOL;
    echo '				</tr>' . PHP_EOL;
  }
}

function addFrontViewEvents($portsState)
{
  foreach ($portsState as $portIndex => $portState) {
    $str = 'NAT: ' . $portState->PortNum . '<br>Mode: ' . $portState->Mode_Str . '<br>Speed & Duplex: ' . $portState->SpeedDuplex_Str . '<br>Flow Control: ' . $portState->FlowCtrl_Str . '<br>Max Frame: ' . $portState->PortMaxFrame . '<br>Excessive: ' . $portState->PortExcessive_Str . '<br>Link: ' . $portState->PortLink_Str . '';
    echo '<script>
        	    showTooltip("port' . $portState->PortNum . '", "' . $str . '");
    		  </script>';
  }
}
?>
<script>
  function changeImage(id, src)
  {
    obj = ylib_getObj(id);
    if (obj)
      obj.src = src;
  }

  function showTooltip(id,str)
  {
    o=ylib_getObj(id);
    ylib_addEvt(o,'mouseover',new Function("return overlib('"+str+"',HAUTO)"));
    ylib_addEvt(o,'mouseout',new Function("return nd()"));
  }
</script>