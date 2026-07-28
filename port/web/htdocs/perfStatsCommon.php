<?php
include_once 'defense.php';

function tabTitlePerfStat()
{
  global $treeId;
  $treeId = $_SESSION['ssid'] . 'perfStats';
  $tabTitle = array(array(gprop('information'), 'perfStats.php'), array(gprop('performancerwstatics'), 'perfStatsForRW.php'));
  return $tabTitle;
}
function getMultipleSelect($options, $params)
{
  $width = isset($params['width']) ? $params['width'] : "100%";
  $height = isset($params['height']) ? $params['height'] : "50px";
  $id = isset($params['id']) ? $params['id'] : "multipleSelect";
  $selValue = isset($params['value']) ? $params['value'] : "-1";
  $str = "<div style='width:90%;font:13.3px sans-serif;border-left:0px solid #808080;border-top:0px solid #808080;border-bottom:0px solid #fff; border-right:0px solid #fff;'>
        <div id=$id  style='background:#f2f2f2;display:block;overflow-y:auto;height:$height;width:100%;border-left:1px solid #000000;border-top:1px solid #000000;border-bottom:1px solid #000000;border-right:1px solid #000000;'>";
  foreach ($options as $i => $option) {
    $value = $option['value'];
    $text = $option['text'];
    $className = "";
    if ($value == $selValue)
      $className = "highlight";
    $str = $str . "<label onclick='highlightDiv(this)' class=\"$className\" style='padding-right:3px;display:block;'>$text</label>";
  }
  $str = $str . "</div></div>";
  return $str;
}
function getDataTypeSelect($name, $types = null)
{
  if ($types == null) {
    $types = array(
      array("text" => gprop("bandwidthmbs"), "value" => "band"),
      array("text" => gprop("iospersecond"), "value" => "ios"),
      array("text" => gprop("avglatencyms"), "value" => "avgla"),
      array("text" => gprop("maxlantency"), "value" => "maxla"),
      array("text" => gprop("minlantency"), "value" => "minla")
    );
  }
  $str = "<select name=$name onchange='updateDispList()'>";
  foreach ($types as $i => $type) {
    $text = $type['text'];
    $value = $type['value'];
    $str = $str . "<option value=$value>$text</option>";
  }
  $str = $str . "</select>";
  return $str;
}
function getLdDataTypeSelect($name)
{
  $types = array(
    array("text" => gprop("bandwidthmbs"), "value" => "band"),
    array("text" => gprop("iospersecond"), "value" => "ios"),
    array("text" => gprop("avglatencyms"), "value" => "avgla"),
    array("text" => gprop("maxlantency"), "value" => "maxla"),
    array("text" => gprop("minlantency"), "value" => "minla"),
    array("text" => gprop("usedcache"), "value" => "cache"),
    array("text" => gprop("ofusedcachedirty"), "value" => "dirty")
  );
  return getDataTypeSelect($name, $types);
}
function getLdList()
{
  global $lds;
  $options = array();
  $params = array("width" => "95%", "id" => "ldList", "height" => "146px", "value" => 0);
  $options[] = array("value" => 0, "text" => gprop("all"));
  foreach ($lds as $i => $ld) {
    $ldId = gprop("ld") . $ld->ldi_FlatId;
    if (isset($ld->ldi_LDName) && $ld->ldi_LDName != "") {
      $ldId = $ldId . ":" . $ld->ldi_LDName;
    }
    $options[] = array("value" => $i + 1, "text" => $ldId);
  }
  return getMultipleSelect($options, $params);

}
function getPdList()
{
  global $pds;
  $options = array();
  $params = array("width" => "95%", "id" => "pdList", "height" => "146px", "value" => 0);
  $options[] = array("value" => 0, "text" => gprop("all"));
  foreach ($pds as $i => $pd) {
    $pdId = gprop("pd") . ($pd->pdi_FlatId + 1);
    if (isset($pd->pdi_DriveAlias) && $pd->pdi_DriveAlias != "") {
      $pdId = $pdId . ":" . $pd->pdi_DriveAlias;
    }
    $options[] = array("value" => $i + 1, "text" => $pdId);
  }
  return getMultipleSelect($options, $params);

}
function getPortList()
{
  global $ctrls;
  global $portPerCtrl;
  $options = array();
  $params = array("width" => "95%", "id" => "portList", "height" => "146px", "value" => 0);
  $options[] = array("value" => 0, "text" => gprop("all"));
  for ($i = 0; $i < count($ctrls); $i++) {
    for ($j = 0; $j < $portPerCtrl; $j++) {
      $id = $i * $portPerCtrl + $j + 1;
      $text = gprop("controller") . ($i + 1) . " " . gprop("port") . ($j + 1);
      $options[] = array("value" => $id, "text" => $text);
    }
  }
  return getMultipleSelect($options, $params);

}
ob_start();
?>
<script>
  function getValue(name)
  {
    var nodes = document.getElementsByName(name);
    var value = nodes[0].value;
    return value;
  }

  function getText(name)
  {
    var nodes = document.getElementsByName(name);
    if(isset(nodes[0]))
    {
      for(var i=0; i<nodes[0].childNodes.length; i++)
      {
        var childNode = nodes[0].childNodes[i];
        if(childNode.selected)
          return childNode.innerHTML;
      }
    }
    return "";
  }

  function getUnit(id)
  {   
    var unitList ={
        //for perfStats.php
        "band":"<?= gprop("mbs") ?>",
        "ios":"<?= gprop("timess") ?>",
        "avgla":"<?= gprop("ms") ?>",
        "maxla":"<?= gprop("ms") ?>",
        "minla":"<?= gprop("ms") ?>",
        "cache":"%",
        "dirty":"%",

        //for perfStatsForRW.php
        "readband":"<?= gprop("mbs") ?>",
        "writeband":"<?= gprop("mbs") ?>",
        "maxwritela":"<?= gprop("ms") ?>",
        "maxreadla":"<?= gprop("ms") ?>",
        "minwritela":"<?= gprop("ms") ?>",
        "minreadla":"<?= gprop("ms") ?>",
        "averwritela":"<?= gprop("ms") ?>",
        "averreadla":"<?= gprop("ms") ?>",
        "writereqs":"<?= gprop("timess") ?>",
        "readreqs":"<?= gprop("timess") ?>"   
    };
    if(isset(unitList[id]))
    {
      return unitList[id];
    }
    else
      return "";

  }

  function getMultipleValue(obj)
  {
    var values = [];
    if(typeof(obj) == "string")
    {
      obj = document.getElementById(obj);
    }
    for(var i=0; i<obj.childNodes.length; i++)
    {
      node = obj.childNodes[i];
      if(isset(node.tagName) && node.tagName.toLowerCase() == "label")
      {
        if(node.className == "highlight")
        {
          values.push({index:i, text:node.innerHTML});
        }
      }
    }
    return values;

  }

  function highlightDiv(node)
  {
    if (node.className != "highlight" )
    {
      if(getMultipleValue(node.parentNode).length>=4)
      {
        alert("<?php echo galert('maxselect'); ?>");
        return;
      }
      node.className = "highlight";
    }
    else
    {
      node.className = "";
    }
    updateDispList();
  }

  function drawChart(node)
  {
    var chart = new Chart(node);
    chart.setParams({enableAutoScale: true, height:200, width:500, despY:"<?php echo gprop("bandwidthmbs"); ?>"});
    chart.refresh();
    return chart;
  }

  function getChart(group)
  {
    switch(group)
    {
      case "pd":
        return pdChart;
      case "ld":
        return ldChart;
      case "port":
        return portChart;

    }
  }

  function refreshData()
  {
    if(!dataLoader.isReady)
      return;
    var dataList = dataLoader.getData();

    for(var i=0; i<dispList.length; i++)
    {
      var item = dispList[i];
      var group = item.group;
      var type = item.type;
      var id = item.id;
      var chart = getChart(group);
      var typeText = item.typeText;
      chart.setData(dataList[group][type][id], id, item.text);
      var unit = getUnit(type);
      chart.setData(dataList[group][type][id], id, item.text, unit);
    }
    pdChart.refreshData();
    ldChart.refreshData();
    // portChart.refreshData();
  }

  function updateDispList()
  {
    var ldType = getValue("ldType");
    var ldTypeText = getText("ldType");
    var pdType = getValue("pdType");
    var pdTypeText = getText("pdType");
    // var portType = getValue("portType");
    // var portTypeText = getText("portType");
    var ldList = getMultipleValue("ldList");
    var pdList = getMultipleValue("pdList");
    // var portList = getMultipleValue("portList");
    var list = [

    ];
    for(var i=0; i<ldList.length; i++)
    {
      list.push({group:"ld", type:ldType, id:ldList[i].index, text:ldList[i].text, typeText: ldTypeText});
    }
    for(var i=0; i<pdList.length; i++)
    {
      list.push({group:"pd", type:pdType, id:pdList[i].index, text:pdList[i].text, typeText: pdTypeText});
    }
    /* 
    for(var i=0; i<portList.length; i++)
    {
      list.push({group:"port", type:portType, id:portList[i].index, text:portList[i].text, typeText: portTypeText});
    }
     */
    dispList = list;
    ldChart.clearData();
    pdChart.clearData();
    // portChart.clearData();
    this.refreshData();
    ldChart.despY = ldTypeText;
    pdChart.despY = pdTypeText;
    // portChart.despY = portTypeText;
    ldChart.refreshAxes();
    pdChart.refreshAxes();
    // portChart.refreshAxes();

  }
</script>