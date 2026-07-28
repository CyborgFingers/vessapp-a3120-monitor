<?php

/*******************************************************************************
 * Filename    : logdrv.php
 * Description : islaVista logical drive information.
 * Created on  : 04/01/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';
include 'front.php';

if (isset($_GET['ldid']))
  SetSessionValue('ldid', $_GET['ldid']);

$server = getServer();
$subsystem = $server->getSubsystem();
if (PI_FB_HOST_INTERFACE_NAS && ($subsystem->frontendType == PI_FB_HOST_INTERFACE_ISCSI))
  $isnas = 1;
else
  $isnas = 0;

$encls = $subsystem->getEnclosures();
$noencl = null;
if (count($encls) == 0)
  $noencl = gresult('nosep');

$flats = 0;
foreach ($encls as $eid => $encl) {
  $numslots = count($encl->_slots);
  foreach ($encl->_slots as $sid => $slot) {
    $encls[$eid]->_flats[$sid] = $flats;
    $flats++;
  }
}

$logicalDrive = $subsystem->getLogicalDrive($_SESSION['ldid']);

$physicalDrives = $subsystem->getPhysicalDrives();
$diskArrays = $subsystem->getDiskArrays();
$unplugPDs = getUnplugPDs($physicalDrives, $diskArrays);
$note = isset($_GET['note']) ? $_GET['note'] : null;
SyncSession();
?>

<html>
<head>
<?php
page_head();
$fileparam = isset($_GET['daid']) ? ('?daid=' . $_GET['daid']) : '';
if (isset($_GET['locate'])) {
  $note = gresult('locateldmsg');
}

?>
<script language="javascript" type="text/javascript">
function onLoad()
{
    <? if (!isset($logicalDrive) || $logicalDrive == null || $logicalDrive->ldi_FlatId != $_GET['ldid']) { ?>
    self.location.href="logdrvs.php?ssid=<?= $_GET['ssid'] ?>&daid=<?= $_GET['daid'] ?>&tab=0";
    <?
  } ?>
	return;
}
</script>
</head>

<body onload="javascript:onLoad()">
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url . $fileparam; ?>">
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
if (isset($_SESSION['pegasus']))
  table_title(gprop('logicaldrive') . ' ' . $logicalDrive->getProperty('VolumeId'), 'logicaldrive');
else
  table_title(gprop('logicaldrive') . ' ' . $logicalDrive->ldi_FlatId, 'logicaldrive', $note);
table_tab_head();
$s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner');
$tabTitle = tabTitleLD($subsystem, isset($_GET['daid']) ? $_GET['daid'] : null);
table_tab($tabTitle);

$string = gprop('locatelddesc');
//exist encl
if (!isset($noencl))
  showFrontImage($encls, $physicalDrives, $unplugPDs, $string, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => ''));
else {
  table_head_fold(gprop('enclosurefrontview'), 'FV', null, null, $s);
  table_row_empty($noencl);
  table_foot_fold();
}
table_tab_foot();

if (isset($_GET['locate']))
  locateLogDrv($subsystem, $logicalDrive);

table_head_fold(gprop('logicaldriveinfo'), 'LD', null, null, $s);

if (isset($_SESSION['pegasus'])) {
  table_row(gprop('logicaldriveid'), $logicalDrive->getProperty('VolumeId'));
  table_row(gprop('raidlevel'), getStringRAIDLevel($logicalDrive->getProperty('RaidLevel')));
  table_row(gprop('opstatus'), getStringOpStatusArray($logicalDrive->getProperty('OperationalStatus')));
  table_row(gprop('size'), getStringDriveSize($logicalDrive->getProperty('Size'), 512));
  table_row(gprop('rawsize'), getStringDriveSize($logicalDrive->getProperty('NumberOfRawBlocks'), 512));
  table_row(gprop('numberofaxles'), $logicalDrive->getProperty('NumberOfAxles'));
  table_row(gprop('numberofusedphysicaldrives'), $logicalDrive->getProperty('NumberOfUnderLyingExtents'));
  table_row(gprop('stripesize'), getStringDriveSize($logicalDrive->getProperty('StripeSize'), 512));
  table_row(gprop('diskarrayid'), $logicalDrive->getProperty('PoolId'));
  $readCache = $logicalDrive->getProperty('ReadCacheState') ? 'Read Ahead Enabled' : 'Read Ahead Disabled';
  $writeCache = $logicalDrive->getProperty('WriteCacheState') ? 'Write Back' : 'Write Through';
  table_row(gprop('attributes'), $readCache . ',' . $writeCache);
  table_row(gprop('serialnumber'), $logicalDrive->getProperty('SerialNumber'));
  table_row(gprop('wwn'), $logicalDrive->getProperty('WWN'));
  table_row(gprop('readcount'), $logicalDrive->getProperty('NumberOfReads'));
  table_row(gprop('writecount'), $logicalDrive->getProperty('NumberOfWrites'));
  table_row(gprop('errorcount'), $logicalDrive->getProperty('NumberOfErrors'));
} else {
  table_row(gprop('logicaldriveid'), $logicalDrive->ldi_FlatId);
  $aliastitle = gprop('alias');
  if ($isnas)
    if ($logicalDrive->ldi_isNasLd == "yes")
    $aliastitle = gprop('aliasnasonly');
  table_row($aliastitle, preg_replace("/\s/", "&nbsp;", $logicalDrive->ldi_LDName));
  table_row(gprop('raidlevel'), $logicalDrive->ldi_RaidLevel);
  table_row(gprop('opstatus'), $logicalDrive->_opStatusEx, null, getStatusColor($logicalDrive->_opStatus));
  table_row(gprop('capacity'), $logicalDrive->ldi_Size);
  table_row(gprop('physicalcapacity'), $logicalDrive->ldi_RawSize);
  table_row(gprop('numberofaxles'), $logicalDrive->ldi_NoOfAxles);
  table_row(gprop('numberofusedphysicaldrives'), $logicalDrive->ldi_NoOfUsedPD);
  table_row(gprop('stripesize'), $logicalDrive->ldi_StripeSize);
  table_row(gprop('sectorsize'), $logicalDrive->ldi_SectorSize);
  table_row(gprop('diskarrayid'), $logicalDrive->ldi_ArrayId);
  table_row(gprop('readpolicy'), getStringLogDrvReadPolicy($logicalDrive->ldi_Attributes));
  table_row(gprop('writepolicy'), getStringLogDrvWritePolicy($logicalDrive->ldi_Attributes));
  table_row(gprop('currentwritepolicy'), getstringlogdrvcurrentwritepolicyvalue($logicalDrive->ldi_Attributes));
  // table_row(gprop('preferredctrlid'),$logicalDrive->ldi_PreferredOwnerControllerId);
  table_row(gprop('serialnumber'), $logicalDrive->ldi_SerialNo);
  table_row(gprop('wwn'), $logicalDrive->ldi_WWN);
  table_row(gprop('synchronized'), $logicalDrive->ldi_Flags & LOGDRV_COND_SYNCHRONIZED ? 'Yes' : 'No');
}

function getMinNumDrives($logicalDrive)
{
  $min = '';
  $raidlevel = $logicalDrive->ldi_RaidLevel;
  if ($raidlevel == 'RAID0')
    $min = 0;
  else if ($raidlevel == 'RAID1')
    $min = 1;
  else if ($raidlevel == 'RAID10')
    $min = 1;
  else if ($raidlevel == 'RAID50')
    $min = 1;
  else if ($raidlevel == 'RAID6')
    $min = $logicalDrive->ldi_NumofParityDrives;
  else if ($raidlevel == 'RAID60')
    $min = $logicalDrive->ldi_NumofParityDrives;
  else if ($raidlevel == 'RAID5')
    $min = 1;
  else if ($raidlevel == 'RAID3')
    $min = 1;
  else if ($raidlevel == 'RAID30')
    $min = 1;
  else if ($raidlevel == 'RAID1E')
    $min = 1;

  return $min;
}

function getMaxNumDrives($logicalDrive)
{
  $max = '';
  $raidlevel = $logicalDrive->ldi_RaidLevel;
  if ($raidlevel == 'RAID0')
    $max = 0;
  else if ($raidlevel == 'RAID1')
    $max = 1;
  else if ($raidlevel == 'RAID10')
    $max = $logicalDrive->ldi_NoOfUsedPD;//number of drives in the axle
  else if ($raidlevel == 'RAID50')
    $max = $logicalDrive->ldi_NoOfAxles;//number of axles
  else if ($raidlevel == 'RAID30')
    $max = $logicalDrive->ldi_NoOfAxles;//number of axles
  else if ($raidlevel == 'RAID6')
    $max = $logicalDrive->ldi_NumofParityDrives;
  else if ($raidlevel == 'RAID60')
    $max = $logicalDrive->ldi_NumofParityDrives;
  else if ($raidlevel == 'RAID5')
    $min = 1;
  else if ($raidlevel == 'RAID3')
    $min = 1;
  else if ($raidlevel == 'RAID1E')
    $min = 1;

  return $max;
}

// table_row(gprop('maxtolerantfaultydrives'),getMaxNumDrives($logicalDrive));
// table_row(gprop('mintolerantfaultydrives'),getMinNumDrives($logicalDrive));

table_row(gprop('tolerablenumdeaddrivesperaxle'), $logicalDrive->_tolerableNumofDeadDrivesPerAxle);
if ($logicalDrive->ldi_RaidLevel == 'RAID6' || $logicalDrive->ldi_RaidLevel == 'RAID60') {
  table_row(gprop('paritypace'), $logicalDrive->ldi_ParityPace);
  table_row(gprop('codecscheme'), $logicalDrive->ldi_CodecScheme);
} else {
  table_row(gprop('paritypace'), gprop('na'));
  table_row(gprop('codecscheme'), gprop('na'));
}
$isnas ? table_row(gprop('fornas'), gprop($logicalDrive->ldi_isNasLd)) : null;
table_foot_fold();
page_foot();
addFrontViewEvents($encls, $physicalDrives);
?>

  </table>
</form>

<script>
<?php
$array = $subsystem->getDiskArray($logicalDrive->ldi_ArrayId);
$PDs = $array->acd_PDs;
for ($i = 0; $i < count($PDs); $i++) {
  ?>
      highlightPD(<? echo $PDs[$i]->pdd_PDFlatId ?>);
      var obj=ylib_getObj('pdimg<? echo $PDs[$i]->pdd_PDFlatId ?>');
      ylib_addEvt(obj,'click',new Function('locateLogDrv()'));
      if(obj){obj.style.cursor="hand";
        ylib_addEvt(obj,'mouseover',new Function('overArray(<? echo $PDs[$i]->pdd_PDFlatId ?>)'));
        ylib_addEvt(obj,'mouseout',new Function('outArray(<? echo $PDs[$i]->pdd_PDFlatId ?>)'));}
<?php

}
?>

    function locateLogDrv()
    {
<?php
$file = 'logdrv.php?locate';
if (isset($_GET['daid']))
  $file .= '&daid=' . $_GET['daid'];
?>
      window.location.href='<?= $file ?>';
    }
</script>

</body>
</html>

<?php
if (isset($_SESSION['pegasus']))
  $server->logout();
?>
