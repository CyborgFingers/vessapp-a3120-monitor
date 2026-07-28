<?php

/*******************************************************************************
 * Filename    : event0.php
 * Description : islaVista event common functions.
 * Created on  : 11/11/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

function cmp_integer($a, $b)
{
  if ($a == $b)
    return 0;
  $ret = ($a < $b) ? -1 : 1;
  if ($_SESSION['reverse'])
    return (0 - $ret);
  else
    return $ret;
}

function cmp_string($a, $b)
{
  $ret = strcmp($a, $b);
  if ($_SESSION['reverse'])
    return (0 - $ret);
  else
    return $ret;
}

function cmp_seq($ev1, $ev2)
{
  return cmp_integer($ev1->evt_SequenceNumber, $ev2->evt_SequenceNumber);
}

function cmp_dev($ev1, $ev2)
{
  $ret = cmp_integer($ev1->evt_DeviceId, $ev2->evt_DeviceId);
  if ($ret)
    return $ret;
  return cmp_integer($ev1->evt_SequenceNumber, $ev2->evt_SequenceNumber);
}

function cmp_evid($ev1, $ev2)
{
  $ret = cmp_integer($ev1->evt_VendorEventId, $ev2->evt_VendorEventId);
  if ($ret)
    return $ret;
  return cmp_integer($ev1->evt_SequenceNumber, $ev2->evt_SequenceNumber);
}

function cmp_sev($ev1, $ev2)
{
  $ret = cmp_string($ev1->_Severity, $ev2->_Severity);
  if ($ret)
    return $ret;
  return cmp_integer($ev1->evt_SequenceNumber, $ev2->evt_SequenceNumber);
}

function cmp_time($ev1, $ev2)
{
  $ret = cmp_integer($ev1->evt_Timestamp, $ev2->evt_Timestamp);
  if ($ret)
    return $ret;
  return cmp_integer($ev1->evt_SequenceNumber, $ev2->evt_SequenceNumber);
}

function cmp_desc($ev1, $ev2)
{
  $ret = cmp_string($ev1->evt_Desc, $ev2->evt_Desc);
  if ($ret)
    return $ret;
  return cmp_integer($ev1->evt_SequenceNumber, $ev2->evt_SequenceNumber);
}

function getSeverityColor($severity)
{
  if ($severity == 2)
    return '#262626';
  if ($severity == 3)
    return '#ffa800';
  if ($severity == 4)
    return 'darkorange';
  if ($severity == 5)
    return 'brown';
  if ($severity == 6)
    return 'darkred';
  if ($severity == 7)
    return 'red';
  return 'gray';
}

function closeEvtIndicator()
{
  SetSessionValue('evtView', $_SESSION['eventLast']);
  echo '
    <script>
    function isOverDivVisible(o) {
      if (!o) return false;
      if (o.visibility) {
        if (o.visibility=="hide"||o.visibility=="hidden") return false;
        else return true;
      } else if (o.style.visibility) {
        if (o.style.visibility=="hidden") return false;
        else return true;
      } else return false;
    }
    if (top.headfrm&&top.headfrm.over&&isOverDivVisible(top.headfrm.over))
      top.headfrm.nd();
    </script>';
}

function table_header_cols_anchor($columns, $widths, $acts, $id = null)
{
  echo '
  <tr class=tableRowHeader' . ($id ? (' id=' . $id) : '') . '>';
  for ($i = 0; $i < count($columns); $i++) {
    echo '
    <td class=text4b width=' . $widths[$i] . '%>
      <a href=' . htmlspecialchars($_SERVER['PHP_SELF']) . '?evsort=' . $acts[$i] . (isset($_GET['nvram']) ? '&nvram' : (isset($_GET['mdd']) ? '&mdd' : '')) . '>' . $columns[$i] . '</a>';
    if (isset($_SESSION['evsort']) && $_SESSION['evsort'] == $acts[$i])
      echo space(4) . '<img src="images/' . ($_SESSION['reverse'] ? 'sortdn' : 'sortup') . '.gif">';
    echo '</td>';
  }
  echo '
  </tr>';
  SetSessionValue('row', 0);
}
?>