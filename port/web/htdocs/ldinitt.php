<?php

/*******************************************************************************
 * Filename    : ldinitt.php
 * Description : islaVista start logical drive initialization.
 * Created on  : 06/15/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

function getBGAFile($note)
{
  return (isset($_GET['ldid']) ? 'ldbga.php' : 'bga.php') . '?note=' . $note . (isset($_GET['daid']) ? ('&daid=' . $_GET['daid']) : '');
}

if (isset($_POST['cancel'])) {
  header('Location: ' . getBGAFile(gresult('initializationcancel')));
  exit;
}

$server = getServer();
$subsystem = $server->getSubsystem();
$logicalDrives = $subsystem->getLogicalDrives();

if (isset($_GET['ldid'])) {
  $ldid = $_GET['ldid'];
  $logicalDrive = $subsystem->getLogicalDrive($ldid);
}

$note = null;
$result = 0;
if (count($_POST)) {
  if (!isset($ldid))
    $ldid = $_POST['_devId'];
  $result = $subsystem->startInitialization(
    $ldid,
    (isset($_POST['rpli_bQuickInit']) ? true : false),
    (isset($_POST['rpli_u16QuickInitSize']) ? setInteger($_POST['rpli_u16QuickInitSize']) : 10),
    (isset($_POST['rpli_u8Pattern']) ? $_POST['rpli_u8Pattern'] : '')
  );
  if ($result == 0) {
    redirectPage(getBGAFile(gresult('initializationstartok')));
    exit;
  } else
    $note = gresult('initializationstartfail');
}
?>

<html>
<head>
<?php
page_head();
$file = getLogDrvBGAFile(htmlspecialchars($_SERVER['PHP_SELF']));
$_SESSION['lastfile'] = $file;
?>
</head>

<body>
<?php $action_url = validateActionUrl($file); ?>
<form method=post action="<?php echo $action_url; ?>">
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('startinitialization'), 'init', $note, $result);
table_tab_head();

if (isset($_GET['bga']))
  $tabTitle = tabTitleSubsystem($subsystem);
else
  $tabTitle = tabTitleLD($subsystem, isset($_GET['daid']) ? $_GET['daid'] : null);
table_tab($tabTitle);

table_head_fold(gprop('initializationparameters'), 'LD', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
if (count($logicalDrives)) {
  if (isset($_GET['ldid']))
    table_row(gprop('logicaldriveid'), '&nbsp;&nbsp;' . $ldid);
  else {
    for ($i = 0; $i < count($logicalDrives); $i++) {
      $logicalDrive = $logicalDrives[$i];
      $ldids[] = array($logicalDrive->ldi_FlatId, 'ID' . $logicalDrive->ldi_FlatId . '-' . $logicalDrive->ldi_RaidLevel);
    }
    table_row_combo(gprop('logicaldrive'), $ldids, '_devId', 0);
  }

  table_row_checkbox(gprop('quickinitialization'), 0, 'rpli_bQuickInit');
  table_row_edit(gprop('quickinitializationsize') . ' [1-1024]', '64', 'rpli_u16QuickInitSize', 20, null, ' MB', 'msg_u16QuickInitSize');

  table_row_edit(gprop('initializationpatterninhex'), '00000000', 'rpli_u8Pattern', 1024, null, null, 'msg_u8Pattern');
} else
  table_row_empty(gresult('initializationnold'));

table_foot_fold();
table_tab_foot();

if (count($logicalDrives))
  table_row_submit2(array('submitClick' => 'validate()'));

page_foot();
?>
  </table>
</form>

<script>
<?php
if (count($logicalDrives)) {
  ?>
  form=document.forms[0];

  ylib_addEvt(form.elements['rpli_bQuickInit'],'click', new Function('quickChanged()'));

  if (form.elements['rpli_bQuickInit'])
    quickChanged();

  function quickChanged()
  {
    elements=document.forms[0].elements;
    elements['rpli_u16QuickInitSize'].disabled=!(elements['rpli_bQuickInit'].checked);
    elements['rpli_u8Pattern'].disabled=elements['rpli_bQuickInit'].checked;

    var rpli_u8Pattern=elements['rpli_u8Pattern'].value;
    if (elements['rpli_u8Pattern'].disabled)
      elements['rpli_u8Pattern'].value='';
    else
      // elements['rpli_u8Pattern'].value=rpli_u8Pattern?rpli_u8Pattern:'';
	  elements['rpli_u8Pattern'].value='00000000';
  }

  function getreturn(ret)
  {
    var alertmsg="<? echo gjsalert('errconfirm') ?>";
    if(ret==1){
      document.forms[0].submit();
    } else if(ret=='cancelled')
      return false;
    else{
      alert(alertmsg);
      return false;
    }
  }

  function validate()
  {
    if (document.getElementById('windowconfirm') != null)
        return false;
    var string="<?= gconfirm('initilizationstart') ?>";
    var error=false;
    elements=document.forms[0].elements;
    rpli_u16QuickInitSize=elements['rpli_u16QuickInitSize'];
    rpli_u8Pattern=elements['rpli_u8Pattern'];
    if(rpli_u8Pattern.disabled==false &&
      (rpli_u8Pattern.value == '' || !validateHex(rpli_u8Pattern.value))) {
      msg_u8Pattern.innerHTML="<?= gjsalert('initializationinvalidpattern') ?>";
      rpli_u8Pattern.focus();
      error=true;
    } else
      msg_u8Pattern.innerHTML='';
    if(rpli_u16QuickInitSize.disabled==false &&
      (rpli_u16QuickInitSize.value == '' || !validateNumber(rpli_u16QuickInitSize.value) || rpli_u16QuickInitSize.value<1 || rpli_u16QuickInitSize.value>1024)) {
      msg_u16QuickInitSize.innerHTML="<?= gjsalert('initiailizationinvalidsize') ?>";
      rpli_u16QuickInitSize.focus();
      error=true;
    } else
      msg_u16QuickInitSize.innerHTML='';
    if(error)
      return false;
    // return showConfirm(string);
    showConfirm(string, getreturn);
    return false;
  }

<?php

}
?>
</script>

</body>
</html>
