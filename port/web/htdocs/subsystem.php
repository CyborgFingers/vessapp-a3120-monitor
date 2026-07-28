<?php

/*******************************************************************************
 * Filename   :subsystem.php
 * Description:islaVista subsystem information.
 * Created on :04/01/03
 *
 * (C) Copyright Promise Technology Inc.,2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_GET['ssid']))
  SetSessionValue('ssid', $_GET['ssid']);

$server = getServer();
$flag = 1;
$subsystem = $server->getSubsystem($flag);
$supportDualCtrl = $subsystem->supportdualcontroller();
$cliqueInfo = $subsystem->getCliqueInfo();
$note = isset($_GET['note']) ? $_GET['note'] : null;
SyncSession();
if (count($_POST)) {
  $result = 0;
  if (isset($_POST['saveconfiguration'])) {
    if (!isset($export))
      $export = new stdclass();
    $export->type = IMEXPORT_SUBSYSTEM;
    $export->username = unserialize($_SESSION['sessionService'])->username;
    $export->password = unserialize($_SESSION['sessionService'])->password;
    $result = $subsystem->export($export);
    if ($result == PIERR_NO_ERROR) {
      if ($supportDualCtrl->DualCtrlSupport)
        $logfile = '/islavista/flash/opaslog';
      else {
        if (PHP_OS == "WINNT")
          $logfile = 'opas';
        else
          $logfile = '/tmp/opaslog.zip';
      }
      header('Location: download.php?file=' . $logfile);
      $note = gresult('configsaveok');
    } else
      $note = gresult('configsavefail');
  }
  if ($result == 0) {
    redirectPage('subsystem.php?tab=1&note=' . $note);
    exit;
  }
}
?>

<!DOCTYPE html>
<html>
<head>
<?php
page_head();
?>
</head>

<body>
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center
    bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
table_title(gprop('subsystem'), 'subsystem', $note);
table_tab_head();

$tabTitle = tabTitleSubsystem($subsystem);
table_tab($tabTitle);

table_head_fold(gprop('subsysteminformation'), 'SS', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
if (isset($_SESSION['pegasus'])) {
  table_row(gprop('alias'), $subsystem->getProperty('UserDefinedName'));
  table_row(gprop('vendor'), $subsystem->getProperty('Vendor'));
  table_row(gprop('model'), $subsystem->getProperty('Model'));
  table_row(gprop('serialnumber'), $subsystem->getProperty('SerialNumber'));
  table_row(gprop('wwn'), $subsystem->getProperty('WWN'));
  table_row(gprop('partnumber'), $subsystem->getProperty('PartNumber'));
  table_row(gprop('revisionnumber'), $subsystem->getProperty('Version'));
  table_row(gprop('maxjdob'), $subsystem->getProperty('MaxJBODExpSupported'));
  table_row(gprop('maxnumctrl'), $subsystem->getProperty('MaxCtrlSupported'));
  table_row(gprop('iconntype'), $subsystem->getProperty('InterConnectionType'));
} else {
  $alias = str_replace(" ", "&nbsp;", $subsystem->ssi_UserName);
  table_row(gprop('alias'), $alias, 40);
  table_row(gprop('vendor'), $subsystem->ssi_CustName);
  table_row(gprop('model'), $subsystem->ssi_CustModel);
  table_row(gprop('serialnumber'), $subsystem->ssi_CustSerialNo);
  table_row(gprop('wwn'), $subsystem->ssi_WWN);
  table_row(gprop('partnumber'), $subsystem->ssi_CustPartNo);
  table_row(gprop('revisionnumber'), $subsystem->ssi_CustRev);
  if ($supportDualCtrl->DualCtrlSupport) {
    table_row(gprop('maxjbodenclsupport'), $subsystem->ssi_MaxJOBExpSSSupported);
    table_row(gprop('numberofctrlpresent'), $subsystem->ssi_NumberOfCtlPresent);
    table_row(gprop('redundancytype'), $cliqueInfo->cgi_RedundancyType);
    table_row(gprop('cachemirroring'), $cliqueInfo->cgi_CacheMirroring);

    table_row(gprop('redundancystatus'), $cliqueInfo->cgi_RedundancyStatus);
  }
  $dt = $subsystem->getDateAndTime();
  table_row(gprop('currenttime'), $dt->_strTime);
  $pv = getUserPrivilege($subsystem);
  $promptSave = gprop('save');
  if ($pv > USER_GROUP_POWER)
    $content = "<input type=button id='saveconfig' name=saveconfiguration value='$promptSave' class=input-p>";
  else
    $content = "<input type=button id='saveconfig' name=saveconfiguration value='$promptSave' disabled class=input-p>";
  table_row(gprop('savesystemservicereport'), $content);
}

table_foot_fold();
table_tab_foot();
page_foot();
?>
  </table>
</form>
<iframe id="ifm" src="" style="visibility: hidden;"></iframe>
</body>
</html>

<?php
if (isset($_SESSION['pegasus']))
  $server->logout();
?>
<script>
  $waitMsgObj = $('<span/>').text('<?= gprop('pleasewait', 'waitmsg') ?>').addClass('text6');
  $noteObj = $('<span/>').addClass('text6');
  $('#saveconfig').click(function() {
    $('#saveconfig').attr("disabled","disabled");
    $('.tableTitle span.text6').remove();
    $('#saveconfig').parent().after($waitMsgObj);
    $.ajax({
      type: "POST",
      url: "/api/opas.php",
      cache: false,
      success: function(data) {
          $noteObj.text(' - ' + '<?= gresult('configsaveok') ?>');
          $('iframe#ifm').attr("src", "/download.php?file=opas");
      },
      error: function(XMLHttpRequest, textStatus) {
          $noteObj.text(' - ' + '<?= gresult('configsavefail') ?>');
          $('.tableTitle .text1').after($span);
      },
      complete: function(XMLHttpRequest, textStatus) {
          $('#saveconfig').removeAttr("disabled");
          $waitMsgObj.remove();
          $('.tableTitle .text1').after($noteObj);
      }
    });
  });
</script>