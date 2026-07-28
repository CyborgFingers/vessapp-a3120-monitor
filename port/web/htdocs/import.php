<?php

/*******************************************************************************
 * Filename    : import.php
 * Description : islaVista import file
 * Created on  : 12/16/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$server = getServer();
$subsystem = $server->getSubsystem();
$supportDualCtrl = $subsystem->supportdualcontroller();
if (PHP_OS == "WINNT")
  $scriptFileName = apache_getenv('TMP') . '/conf.script';
else
  $scriptFileName = "/tmp/conf.script";
if (!isset($import))
  $import = new stdclass();
$importInfo = null;

if (isset($_SESSION['ImportInfo'])) {
  $importInfo = $_SESSION['ImportInfo'];
}
if (isset($_POST['submit'])) {
  // from HTTP
  if ($supportDualCtrl->DualCtrlSupport) {
    if (PHP_OS == "WINNT")
    $dir = apache_getenv('TMP') . '/';
    else
      $dir = '/islavista/flash/';
  } else {
    if (PHP_OS == "WINNT")
    $dir = apache_getenv('TMP') . '/';
    else
      $dir = '/tmp/';
  }
  if (!isset($importInfo))
    $importInfo = new stdclass();
  $importInfo->type = $_POST['type'];
  if ($_FILES['userfile']['error'] != 0) {
    header('Location: import.php?checkfile=toolong');
    exit;
  }
  if ( $importInfo->type == IMPORT_TYPE_USERDATABASE) {
    $import->filename = apache_getenv('TMP') . '\\userdb.bin';
    move_uploaded_file($_FILES['userfile']['tmp_name'], $import->filename);
    //$import->method = IMEXPORT_HTTP;

    $import->type = IMEXPORT_USERDATABASE;
    $import->decryption = true;
    $import->immediate = true;
    $result = $subsystem->importTransfer($import);
    unlink($import->filename);
    if ( $result == PIERR_INACTIVE_SESSION ) {
      errorOnInvalidSession();
    }
    $importstatus = $subsystem->importGetInfo();
    if ($importstatus->errorcode != PIERR_NO_ERROR) {
      $importInfo->status = false;
      $importInfo->warnString = gerror($importstatus->errorcode);
      $importInfo->filetype = gprop('unknown');
    } else if ($importstatus->cmdstatus != PIERR_NO_ERROR) {
      $importInfo->status = false;
      $importInfo->warnString = gprop('commanderror');
      $importInfo->filetype = gprop('unknown');
    } else if ($importstatus->status >= IMPORT_ERR_DATAERROR) {
      $importInfo->status = false;
                $importInfo->warnString = _getErrorString($importstatus->status);
                $importInfo->filetype = gprop('unknown');
    } else {
                // error_log(print_r($importstatus, 1));
      $importInfo->status = true;
                $importInfo->statusString = _getStatusString($importstatus->status);
                $importInfo->warnString = _getErrorString($importstatus->status);
                $importInfo->filetype = gprop('userdatabase');
    }
    if ($importInfo->status == false) {
      $importInfo->statusString = gprop('failed');
    }
  } else {
    move_uploaded_file($_FILES['userfile']['tmp_name'], $scriptFileName);
    if (checkimportfiletype() != PIERR_NO_ERROR) {
      header('Location: import.php?checkfile=error');
      exit;
    }
    $importInfo->status = true;
    $importInfo->statusString = gprop('fileisreadytobeimported');
    $importInfo->warnString = gprop('noerrororwarning');
    $importInfo->filetype = gprop('configurationscript');
  }
  SetSessionValue('ImportInfo', $importInfo);

  $_SESSION['importprogress'] = 'Transfered';
} else if (isset($_POST['cancel'])) {
  SetSessionValue('Importerror', null);
  unset($_SESSION['importerror']);
  $result = $subsystem->importAbort();
  if ($result == PIERR_INACTIVE_SESSION) {
    errorOnInvalidSession();
  }
  unset($_SESSION['importprogress']);
  if (file_exists($scriptFileName))
    unlink($scriptFileName);
  header('Location: ' . htmlspecialchars($_SERVER['PHP_SELF']));
  exit;
} else if (isset($_POST['next'])) {
  unset($_SESSION['importprogress']);
  $importInfo = $_SESSION['ImportInfo'];
  $import->type = $importInfo->type;
  $import->username = unserialize($_SESSION['sessionService'])->username;
  $import->password = unserialize($_SESSION['sessionService'])->password;
  $import->importfile = $scriptFileName;
  $result = $subsystem->importApply($import);
  if ($result->errorcode == PIERR_INACTIVE_SESSION) {
    errorOnInvalidSession();
  }
  if ($importInfo->type == IMPORT_TYPE_USERDATABASE) {
    if ($result->errorcode == PIERR_NO_ERROR)
      header('Location: import.php?act=applyok');
    else {
      header('Location: import.php?act=applyerror');
    }
    exit;
  } else {
    unlink($scriptFileName);
    if ($result->errorcode == PIERR_NO_ERROR
      && $result->cmderrorcode == PIERR_NO_ERROR) {
      header('Location: import.php?act=applyok');
      exit;
    } else if ($result->errorcode != PIERR_NO_ERROR) {
      header('Location: import.php?act=applyerror');
      exit;
    } else if ($result->cmderrorcode != PIERR_NO_ERROR) {
      SetSessionValue('importerror', 'Geterror');
      SetSessionValue('importerrornum', $result->linenum);
      SetSessionValue('importerrorcode', $result->cmderrorcode);
    }
  }
}

function _getStatusString($status) {
    return $status == 0 ? gprop('fileisreadytobeimported') : gprop('failed');
}

function _getErrorString($status) {
    if ($status < 0 || $status > 5) {
        $status = 5;
    }
    $statusStringArray = array(gprop('noerrororwarning'), gprop('filehassyntaxwarning'),
        gprop('filehasdataerror'), gprop('filetypeisnotsupported'),
        gprop('fileformatisnotrecongnizable'), gprop('unknownimporterror'));
    return $statusStringArray[$status];
}
?>
<html>

<head>
<?php
page_head();
$file = htmlspecialchars($_SERVER['PHP_SELF']);
$_SESSION['lastfile'] = $file;
$note = null;
if (isset($_GET['act']) && $_GET['act'] == 'applyok')
  $note = gresult('fileimportok');
else if (isset($_GET['act']) && $_GET['act'] == 'applyerror')
  $note = gerror('importfile');
else if (isset($_GET['checkfile']) && $_GET['checkfile'] == 'error')
  $note = gerror('checkfile');
else if (isset($_GET['checkfile']) && $_GET['checkfile'] == 'toolong')
  $note = gerror('toolong');
?>
</head>

<body>
<form enctype="multipart/form-data" method=post action="<?php echo $file; ?>"  >
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center>
<?php
table_title(gprop('importfile'), 'Import', $note);
table_tab_head();
$tabTitle = tabTitleAdminTool($subsystem);
table_tab($tabTitle);
table_tab_foot();

$title = gprop('importfromlocalfile');
table_head_fold($title, 'import', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));

if (isset($_SESSION['importerror'])) {
  if (isset($_SESSION['importerrornum'])) {
    $importerrornum = $_SESSION['importerrornum'];
  }
  if (isset($_SESSION['importerrorcode'])) {
    $importerrorcode = $_SESSION['importerrorcode'];
  }
  table_row(gprop('importstatus'), gprop('failed'));
  table_row(gprop('scripterrorlinenum'), $importerrornum);
  table_row(gprop('importerrorwarningdescription'), gerror($importerrorcode));
  table_foot_fold();
  table_row_submit(array('submit' => 0, 'reset' => 0, 'nextClick' => 'validateNext()'));
} else if (!isset($_SESSION['importprogress'])) {
  $type = array(
    array(IMPORT_TYPE_USERDATABASE, gprop('userdatabase')),
    array(IMPORT_TYPE_CONFIGURATION, gprop('configurationscript'))
  );
  table_row_combo(gprop('type'), $type, 'type', 0);
  table_row_browse(gprop('localfilename'), 8000000);
  table_row_checkbox(gprop('descryption'), true, 'decryption');
  table_foot_fold();
  table_row_submit(array('nextClick' => 'validateNext()', 'submitClick' => 'validateSubmit()'));
} else if ($_SESSION['importprogress'] == 'Transfered') {
  table_row(gprop('importstatus'), $importInfo->statusString);
  table_row(gprop('importerrorwarningdescription'), $importInfo->warnString);
  table_row(gprop('importfiletype'), $importInfo->filetype);
  table_foot_fold();
  if ($importInfo->status) {
    if ($importInfo->type == IMPORT_TYPE_USERDATABASE) {
      table_row_submit(array('submit' => 0, 'reset' => 0, 'next' => 1, 'nextClick' => 'validateNext()'));
    } else {
      table_row_submit(array('submit' => 0, 'reset' => 0, 'next' => 1, 'nextClick' => 'validateNextScript()'));
    }
  } else {
    table_row_submit(array('submit' => 0, 'reset' => 0, 'nextClick' => 'validateNext()'));
  }
}
page_foot();
?>

  </table>
</form>

<script>
  var form = document.forms[0];
  var obj = form.type;
  if ( obj != null && obj.options[obj.selectedIndex].value == '0')
    form.decryption.disabled = true;
  if ( obj != null && obj.options[obj.selectedIndex].value == '1')
    form.decryption.disabled = true;
    
  ylib_addEvt(form.type,'change',new Function('typeChanged()'));
  
  function typeChanged()
  {
    if ( obj != null && obj.options[obj.selectedIndex].value == '0')
      form.decryption.checked = true;
    if ( obj != null && obj.options[obj.selectedIndex].value == '1')
      form.decryption.checked = false;
  }

  function validateNextScript()
  {
    var ret = confirm('<?= gjsalert('importfileoverwritescript') ?>');
    if (!ret)
        return false;
    return true;
  }

  function validateNext()
  {
    var ret = confirm('<?= gjsalert('importfileoverwrite') ?>');
    if (!ret)
        return false;
    return true;
  }

  function validateSubmit()
  {
    obj = form.elements['userfile'];
    if (obj && obj.value == '')
    {
        alert('<?= gjsalert('inputlocalfilename') ?>');
        return false;
    }
    return true;
  }

</script>
</body>

</html>
