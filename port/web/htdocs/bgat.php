<?php

/*******************************************************************************
 * Filename    : bgat.php
 * Description : islaVista background activity settings.
 * Created on  : 06/12/03
 *
 * (C) Copyright Promise Technology Inc.,2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_POST['cancel'])) {
  header('Location: bga.php?note=' . gresult('bgasettingcancel'));
  exit;
}

$server = getServer();
$subsystem = $server->getSubsystem();
$ctrl = $subsystem->getControllerSettings(0);

$note = null;
$result = 0;
$pv = getUserPrivilege($subsystem);
if ($pv < USER_GROUP_POWER)
  $note = gresult('bgasettingsnoauth');
if (count($_POST)) {
  // $ctrl->cs_MediaPatrolRate=$_POST['cs_MediaPatrolRate'];
  $ctrl->cs_RebuildRate = $_POST['cs_RebuildRate'];
  $ctrl->cs_BkSyncRate = $_POST['cs_BkSyncRate'];
  $ctrl->cs_LDInitRate = $_POST['cs_LDInitRate'];
  $ctrl->cs_RedCheckRate = $_POST['cs_RedCheckRate'];
  $ctrl->cs_MigrationRate = $_POST['cs_MigrationRate'];
  $ctrl->cs_PDMRate = $_POST['cs_PDMRate'];
  $ctrl->cs_TransitionRate = $_POST['cs_TransitionRate'];

  $ctrl->cs_ReassignedBlockThreshold = $_POST['cs_ReassignedBlockThreshold'];
  $ctrl->cs_ErrorBlockThreshold = $_POST['cs_ErrorBlockThreshold'];

  $ctrl->cs_MediaPatrolEnable = isset($_POST['cs_MediaPatrolEnable']) ? 1 : 0;
  $ctrl->cs_AutoRebuildEnable = isset($_POST['cs_AutoRebuildEnable']) ? 1 : 0;

  $result = $subsystem->setControllerSettings(0, $ctrl);
  if ($result == 0) {
    redirectPage('bga.php?note=' . gresult('bgasettingschangeok'));
    exit;
  } else
    $note = gresult('bgasettingschangefail');
}
?>

<html>
<head>
<?php
page_head();
function selectBGARate($left, $rate, $fieldname, $leftWidth = null)
{
  $rt = array(array(25, gprop('low')), array(50, gprop('medium')), array(75, gprop('high')));
  table_row_combo($left, $rt, $fieldname, $rate <= 30 ? 25 : ($rate <= 70 ? 50 : 75), 'false', $leftWidth);
}
?>
</head>

<body>
<form method=post onsubmit='return validateForm()'  >
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('backgroundactivities'), 'bga', $note, $result);
table_tab_head();

$tabTitle = tabTitleSubsystem($subsystem);
table_tab($tabTitle);

table_head_fold(gprop('bgasettings'), 'CT', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

// selectBGARate('Media Patrol Rate',$ctrl->cs_MediaPatrolRate,'cs_MediaPatrolRate',40);
selectBGARate(gprop('rebuildrate'), $ctrl->cs_RebuildRate, 'cs_RebuildRate', 40);
selectBGARate(gprop('bgsynrate'), $ctrl->cs_BkSyncRate, 'cs_BkSyncRate');
selectBGARate(gprop('ldinitializationrate'), $ctrl->cs_LDInitRate, 'cs_LDInitRate');
selectBGARate(gprop('redundancycheckrate'), $ctrl->cs_RedCheckRate, 'cs_RedCheckRate');
selectBGARate(gprop('migrationrate'), $ctrl->cs_MigrationRate, 'cs_MigrationRate');
selectBGARate(gprop('pdmrate'), $ctrl->cs_PDMRate, 'cs_PDMRate');
selectBGARate(gprop('transitionrate'), $ctrl->cs_TransitionRate, 'cs_TransitionRate');

table_row_edit(gprop('reassignedblockthreshold') . ' [1-512]', $ctrl->cs_ReassignedBlockThreshold, 'cs_ReassignedBlockThreshold', 20, null, ' ' . gprop('blocks'), 'msg_ReassignedBlockThreshold');
table_row_edit(gprop('errorblockthreshold') . ' [1-2048]', $ctrl->cs_ErrorBlockThreshold, 'cs_ErrorBlockThreshold', 20, null, ' ' . gprop('blocks'), 'msg_ErrorBlockThreshold');

table_row_checkbox(gprop('mediapatrolenabled'), $ctrl->cs_MediaPatrolEnable, 'cs_MediaPatrolEnable');
table_row_checkbox(gprop('autorebuildenabled'), $ctrl->cs_AutoRebuildEnable, 'cs_AutoRebuildEnable');

table_foot_fold();
table_tab_foot();

table_row_submit($pv<USER_GROUP_POWER?array('submit'=>2,'cancelClick'=>'doCancel()'):array('cancelClick'=>'doCancel()'));
page_foot();
?>
  </table>
</form>

<script>
  function validateForm()
  {
    elements=document.forms[0].elements;
    cs_ReassignedBlockThreshold=elements['cs_ReassignedBlockThreshold'];
    cs_ErrorBlockThreshold=elements['cs_ErrorBlockThreshold'];
    focusItem=null;

    if(!validateNumber(cs_ReassignedBlockThreshold.value)||cs_ReassignedBlockThreshold.value<1||cs_ReassignedBlockThreshold.value>512) {
      msg_ReassignedBlockThreshold.innerHTML='<?= gjsalert('invalidreassignedthreshold') ?>';
      if(!focusItem) focusItem=cs_ReassignedBlockThreshold;
    }
    else
      msg_ReassignedBlockThreshold.innerHTML='';
    if(!validateNumber(cs_ErrorBlockThreshold.value)||cs_ErrorBlockThreshold.value<1||cs_ErrorBlockThreshold.value>2048) {
      msg_ErrorBlockThreshold.innerHTML='<?= gjsalert('invaliderrorblockthreshold') ?>';
      if(!focusItem) focusItem=cs_ErrorBlockThreshold;
    }
    else
      msg_ErrorBlockThreshold.innerHTML='';

    if (focusItem) {
      focusItem.focus();
      return false;
    }

    return true;
  }


  function doCancel() {
    var redir = '/bga.php?tab=3&note=<?=htmlspecialchars(gresult('bgasettingcancel'))?>';
    // console.log(redir);
    window.location.href = redir;
  }
</script>

</body>
</html>
