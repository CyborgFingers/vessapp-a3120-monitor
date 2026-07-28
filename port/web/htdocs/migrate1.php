<?php

/*******************************************************************************
 * Filename    : migrate1.php
 * Description : islaVista disk array migration step 1.
 * Created on  : 01/03/04
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_POST['cancel'])) {
  header('Location: array.php?tab=0&note=' . gresult('migrationcancel'));
  exit;
}

$server = getServer();
$subsystem = $server->getSubsystem();

if (isset($_GET['act']) && $_GET['act'] == 'migrate') {
  unset($GLOBALS['_SESSION']['lds']);
  unset($GLOBALS['_SESSION']['pds']);
}

$array = $subsystem->getDiskArray($_SESSION['daid']);
$LDs = $array->acd_LDs;
$hybrid = 0;
$note = null;
$pds = array();   // PHP 8: count(null) is a fatal TypeError - the display counts $pds
if (isset($_POST['next'])) {
  if (checkPDs($subsystem, $array, $pds, $note)) {
    header('Location: migrate2.php');
    exit;
  }
}

$phydrvs = $array->acd_PDs;
$minPhyConfig = $phydrvs[0]->_PDconfigurableSize;
for ($j = 1; $j < count($phydrvs); $j++) {
  if ($minPhyConfig > $phydrvs[$j]->_PDconfigurableSize)
    $minPhyConfig = $phydrvs[$j]->_PDconfigurableSize;
}
if (!$hybrid && count($LDs)) {
  $physicalDrives = $subsystem->getPhysicalDrives();
  $pdCount = getAccessiblePDCount($physicalDrives);
  if ($pdCount == 0) {
    header('Location: migrate2.php?tab=4');
    exit;
  }

  $num1 = 0;
  $num2 = 0;
  for ($i = 0; $i < count($physicalDrives); $i++) {
    $physicalDrive = $physicalDrives[$i];
    if ($physicalDrive->_cfgStatus == 'Unconfigured' && !($physicalDrive->pdi_Flags & PHYDRV_COND_PFA) && !isInArray($pds, $physicalDrive->pdi_FlatId)) {
      $num1++;
      if ($physicalDrive->_configurableSize < $minPhyConfig)
        $num2++;
    }
  }
  if ($num1 == $num2) {
    header('Location: migrate2.php?tab=4');
    exit;
  }
}

if (!$pds && isset($_SESSION['pds']))
  $pds = $_SESSION['pds'];
?>

<html>
<head>
<?php
page_head();

function changedPDs($pds0, $pds)
{
  // return true if pds0 and pds are not same
  if (count($pds0) != count($pds))
    return true;

  for ($i = 0; $i < count($pds); $i++) {
    if ($pds0[$i] != $pds[$i])
      return true;
  }
  return false;
}

function getTotalPartitionSize($LDs, $pdCount)
{
  $useSize = 0;
  for ($i = 0; $i < count($LDs); $i++)
    $useSize += logSizeToPartitionSize(
    $LDs[$i]->_LDSize,
    $pdCount,
    $LDs[$i]->_raidLevel,
    $LDs[$i]->ldd_LDNoOfAxles,
    $LDs[$i]->ldd_StripeSizeInBlocks * 512
  );
  return $useSize;
}

function checkPDSize($subsystem, $array, $pds)
{
  // return true if all pd size are big enough
  $useSize = getTotalPartitionSize($array->acd_LDs, count($array->acd_PDs ?? array()) + count($pds));

  $physicalDrives = $subsystem->getPhysicalDrives();
  $minPDSize = getPDConfiguableSize($physicalDrives, $pds) / count($pds);
  return ($useSize < $minPDSize) ? true : false;
}

function checkPDs($subsystem, $array, &$pds, &$note)
{
  // return true if all selected pd are OK for migration
  $pds0 = array();
  if (isset($_SESSION['pds']))
    $pds0 = $_SESSION['pds'];

  for ($i = 0; $i < count($_POST['list2'] ?? array()); $i++) {
    if (isset($_POST['list2'][$i]))
      $pds[] = setInteger($_POST['list2'][$i]);
  }

  sort($pds);

  if (changedPDs($pds0, $pds)) {
    if (checkPDSize($subsystem, $array, $pds)) {
      SetSessionValue('pds', $pds);
      unset($_SESSION['lds']);
      SyncSession();
    } else {
      $note = galert('migrationpdnotbigenough');
      return false;
    }
  }
  return true;
}
?>
<script>
  function fromListToListPD(src)
  {
    ldcount=0;
  <?php
  if (isset($_SESSION['lds']))
    echo 'ldcount=' . count($_SESSION['lds']) . ';';
  ?>
    if (ldcount!=0) {
      sure=confirm("<?= gjsalert('migrationldclear') ?>");
      if (sure!=true)
        return;
    }
    if (src == 1)
      fromListToList(document.forms[0].elements['list1[]'],document.forms[0].elements['list2[]']);
    else
      fromListToList(document.forms[0].elements['list2[]'],document.forms[0].elements['list1[]']);
  }
</script>
</head>

<body>
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center
    bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('diskarraymigration'), null, $note);
table_tab_head();
$s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner');
$tabTitle = tabTitleArray($subsystem, $_SESSION['daid']);
table_tab($tabTitle);

if (count($LDs) == 0) {
  table_head_fold(gprop('arraymigration'), 'MG', null, null, $s);
  table_row_empty(gresult('migrationnoldinda'));
} else if ($hybrid) {
  table_head_fold(gprop('arraymigration'), 'MG', null, null, $s);
  table_row_empty(gresult('migrationnohybridraid'));
} else {
  table_head_fold(gprop('migrationstep1'), 'MG', null, null, $s);
  ?>
    <tr>
      <td class=text4 valign=top><?= gprop('physicaldrives') ?></td>
      <td>
        <table width=100% cellpadding=0 cellspacing=0 border=0>
          <tr>
            <td width=20% class=text5><?= gprop('available') ?></td>
            <td width=10%></td>
            <td width=70% class=text5><?= gprop('selected') ?></td>
          </tr>
          <tr>
            <td class=text5>
              <select class=selectField size=7 multiple name="list1[]" ondblclick=fromListToListPD(1)>
<?php
for ($i = 0; $i < count($physicalDrives); $i++) {
  $physicalDrive = $physicalDrives[$i];
  if ($physicalDrive->_cfgStatus == 'Unconfigured' && !($physicalDrive->pdi_Flags & PHYDRV_COND_PFA) && !isInArray($pds, $physicalDrive->pdi_FlatId)) {
    if ($physicalDrive->_configurableSize >= $minPhyConfig)
      echo '
                <option value=' . $physicalDrive->pdi_FlatId . '>
                  ID' . ($physicalDrive->pdi_FlatId + 1) . ' : ' . $physicalDrive->pdi_ConfigurableSize . '
                </option>';
  }
}
?>
              </select>
            </td>
            <td class=text5 align=middle>&nbsp;
              <input class=text4b type=button value=" >> " onClick=fromListToListPD(1)>&nbsp;
              <p>&nbsp;
              <input class=text4b type=button value=" << " onClick=fromListToListPD(2)>&nbsp;
            </td>
            <td class=text5>
              <select class=selectField size=7 multiple name="list2[]" ondblclick=fromListToListPD(2)>
<?php
for ($i = 0; $i < count($pds); $i++) {
  $physicalDrive = getPDFromId($physicalDrives, $pds[$i]);
  if ($physicalDrive->_cfgStatus == 'Unconfigured') {
    echo '
                <option value=' . $physicalDrive->pdi_FlatId . '>
                  ID' . ($physicalDrive->pdi_FlatId + 1) . ' : ' . $physicalDrive->pdi_ConfigurableSize . '
                </option>';
  }
}
?>
              </select>
            </td>
          </tr>
        </table>
      </td>
    </tr>

<?php

}
table_foot_fold();
table_tab_foot();

if (!$hybrid && count($LDs))
  table_row_submit(array('submit' => 0, 'reset' => 0, 'next' => 1));

page_foot();
?>
  </table>
</form>

<script>
  elements=document.forms[0].elements;
  ylib_addEvt(elements['next'],'click',new Function('selectPD()'));

  function selectPD()
  {
    for (i=0; i < elements['list2[]'].length; i++)
      elements['list2[]'].options[i].selected=true;
  }
</script>

</body>
</html>
