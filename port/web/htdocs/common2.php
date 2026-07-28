<?php

/*******************************************************************************
 * Filename    : common.php
 * Description : islaVista another common php functions.
 * Created on  : 05/25/04
 *
 * (C) Copyright Promise Technology Inc.,2020
 * All Rights Reserved
 ******************************************************************************/
include_once 'defense.php';
//echo '<script src="js/plib.js"></script>' ;      
function getCurrentUser($subsystem)
{
  $users = $subsystem->getUsers();
  $result = $users->errorcode;
  if ($result == PIERR_INACTIVE_SESSION) {
    errorOnInvalidSession();
  }
  if ($result == PIERR_NO_ERROR) {
    $users = $users->userlist;
    for ($i = 0; $i < count($users); $i++)
      if ($users[$i]->username == $_SESSION['username'])
      return $users[$i];
    return null;
  } else
    return null;

}

function GetLinkPortName($index)
{
  $linkPortNames = array(
    gprop('none'),
    gprop('port1'),
    gprop('port2')
  );
  $unknownPortName = gprop('unknown');
  if ($index >= count($linkPortNames))
    return $unknownPortName;
  else
    return $linkPortNames[$index];
}
function getUserPrivilege($subsystem)
{
  $user = getCurrentUser($subsystem);
  if ($user != null)
    return $user->userPrivilege;
  return 0;
}

function refreshBaseFrame($file = null)
{
  echo '
  <script>';
  if ($file == null)
    echo '
    if (top.basefrm)
    {
      var frame =top.basefrm;
      var url = top.basefrm.location.href ;
      var file = ["restore.php","arrayc3.php","logdrvc2.php", "sparec.php"];
      setTimeout("isEnableRefresh(frame,url,file)",2000);
    }';
  else
    echo 'if(top.basefrm) top.basefrm.location.href="' . $file . '";';
  echo '
  </script>';
}

function getUnconfigPDCount($physicalDrives)
{
  $count = 0;
  for ($i = 0; $i < count($physicalDrives); $i++) {
    $physicaldrive = $physicalDrives[$i];
    if ($physicaldrive->_cfgStatus == 'Unconfigured')
      $count++;
  }
  return $count;
}
function getAccessiblePDCount($physicalDrives)
{
  $count = 0;
  for ($i = 0; $i < count($physicalDrives); $i++) {
    $physicaldrive = $physicalDrives[$i];
    if (isset($physicaldrive->_cfgEnable) && !$physicaldrive->_cfgEnable)
      $count++;
  }
  return $count;
}
function getlinkAggrUsedPorts($linkAggrs)
{
  $usedPorts = array();
  for ($i = 0; $i < count($linkAggrs); $i++) {
    $portId = $linkAggrs[$i]->mlaisd_MasterPort - 1;
    if ($portId >= 0 &&
      !in_array($portId, $usedPorts)) {
      $usedPorts[] = $portId;
    }
    $portId = $linkAggrs[$i]->mlaisd_SlavePortList - 1;
    if ($portId >= 0 &&
      !in_array($portId, $usedPorts)) {
      $usedPorts[] = $portId;
    }
  }
  return $usedPorts;
}
function getVlanAvaiablePorts($linkAggrs)
{
  $avaiablePorts = array();
  $usedPorts = getlinkAggrUsedPorts($linkAggrs);
  if (!in_array(0, $usedPorts)) {
    $avaiablePorts[] = 0;
  }
  if (!in_array(1, $usedPorts)) {
    $avaiablePorts[] = 1;
  }
  return $avaiablePorts;

}
function getMemberInterface($linkAggrs)
{
  $memberInterfaces = array();

  $mi->miString = gprop('none');
  $mi->miType = ISCSI_VLAN_IF_NONE;
  $mi->miId = 0;
  $memberInterfaces[] = $mi;

  $avaiablePorts = getVlanAvaiablePorts($linkAggrs);
  for ($i = 0; $i < count($avaiablePorts); $i++) {
    $portId = $avaiablePorts[$i];
    $mi->miString = gprop('port') . ($portId + 1);
    $mi->miType = ISCSI_VLAN_IF_TYPE_PHY;
    $mi->miId = $portId + 1;
    $memberInterfaces[] = $mi;
  }

  for ($i = 0; $i < count($linkAggrs); $i++) {
    $trunkId = $linkAggrs[$i]->mlaisd_TrunkID;
    $mi->miString = gprop('trunk') . $trunkId;
    $mi->miType = ISCSI_VLAN_IF_TYPE_TRUNK;
    $mi->miId = $trunkId;
    $memberInterfaces[] = $mi;
  }
  return $memberInterfaces;
}        
