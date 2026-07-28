<?php

/*******************************************************************************
 * Filename    : prevtlogd.php
 * Description : islaVista evtlog setting.
 * Created on  : 
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$server = getServer();
$subsystem = $server->getSubsystem();
$severity =  $subsystem->evtCusGetStrSeverity($language);

$http_fwd_enabled = false;
$daemons = $subsystem->getAllDaemons();
if ($daemons->errorcode == PIERR_NO_ERROR) {
  foreach ($daemons = $daemons->daemonlist as $daemon) {
    if ($daemon->daemonname === 'prevtlogd') {
      $http_fwd_enabled = true;
    }
  }
}

$note=null;
if(isset($_POST['submit']))
{
  $old_config = $subsystem->getEvtlogConfig();

  $config = new stdclass();

  $config->severity = $_POST['evtfilter']; // Windows Log Filter Level

  if (isset($_POST['httpfwdenabled'])) {
    $config->http_fwd_enabled = 1;
    $config->http_fwd_spec = $_POST['httpspec'];
    if (isset($_POST['httpip'])) {
      $config->http_fwd_ip = $_POST['httpip'];
    }
    if (isset($_POST['httpport'])) {
      $config->http_fwd_port = (int)$_POST['httpport'];
    }
    if (isset($_POST['httpurl'])) {
      $config->http_fwd_url = $_POST['httpurl'];
    }

    if ($config->http_fwd_spec == 'nx')
      $config->http_fwd_authorized = 1; // default 1
    else
      $config->http_fwd_authorized = (isset($_POST['httpauth']) && $_POST['httpauth'] == 'on') ? 1 : 0;
    
    if (isset($_POST['httpname'])) {
      $config->http_fwd_username = $_POST['httpname'];
    }
    if (isset($_POST['httppassword'])) {
      $config->http_fwd_password = $_POST['httppassword'];
    }
    if (isset($_POST['httpEvtFilter'])) {
      $config->http_fwd_filter = (int)$_POST['httpEvtFilter'];
    }
    if (isset($_POST['httpmethod'])) {
      $config->http_fwd_method = $_POST['httpmethod'];
    }
    if (isset($_POST['httpProtocol'])) {
      $config->http_fwd_secured = $_POST['httpProtocol'];
    }
  } else {
    $config->http_fwd_enabled = 0;
  }

  // error_log(print_r($config, 1));
  $result=$subsystem->setEvtlogConfig($config);
  if($result->errorcode==PIERR_NO_ERROR) {
    if ($config->http_fwd_enabled && isset($_POST['httptest']) && $_POST['httptest']) {
      $result = $subsystem->testHttpFwd();
      if ($result->errorcode == PIERR_NO_ERROR)
        $note = gresult('evtlogseverityfiltersettingsok');
      else
        $note = gresult('evtlogseverityfiltersettingsfail');
    } else {
      $note = gresult('evtlogseverityfiltersettingsok');
    }
  }
  else
    $note = gresult('evtlogseverityfiltersettingsfail');
}
$cfgReturned = $subsystem->getEvtlogConfig();
?>


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
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center>
<?php
$title = gprop('evtlogseverityfilter');
table_title($title, 'evtlogseverityfilter', $note);
table_tab_head();
$tabTitle = tabTitleService();
table_tab($tabTitle);

/*
 * Beginning of Windows Event Forwarding
 */
table_head_fold(gprop('winlogsfwd'),'EvtLogSF', null, null, array('pad'=>2,'space'=>1,'border'=>0,'class'=>'tableContentInner'));
$radiovalue = array(
  array(EVT_SEVERITY_INFORMATION, $severity->info),
  array(EVT_SEVERITY_WARNING, $severity->warning),
  array(EVT_SEVERITY_MINOR, $severity->minor),
  array(EVT_SEVERITY_MAJOR, $severity->major),
  array(EVT_SEVERITY_CRITICAL, $severity->critical),
  array(EVT_SEVERITY_FATAL, $severity->fatal)
);
table_row_radio(gprop('evtlogseverityfilterlevel'), $radiovalue, 'evtfilter', $cfgReturned->severity);
table_foot_fold();

if ($http_fwd_enabled) {
  /*
  * Beginning of Folder HTTP Forwarding
  */
  table_head_fold(gprop('httpfwd'),'idTableHttpFwd', null, null, array('pad'=>2,'space'=>1,'border'=>0,'class'=>'tableContentInner'));
  table_row_checkbox(gprop('enable'), $cfgReturned->http_fwd_enabled, 'httpfwdenabled', 30, null, 'onHttpForwardEnabledChanged($(this))');

  table_row_combo_onchange(gprop('server'), array(array('nx','NX WITNESS VMS'), array('userdefined', gprop('userdefined')), array('wave','WISENET WAVE')), 'httpspec', $cfgReturned->http_fwd_spec, 'onHttpSpecChanged');

  table_row_edit(gprop('ipaddress'), htmlspecialchars($cfgReturned->http_fwd_ip), 'httpip', 63, 30, null, 'msg_httpip');
  table_row_radio(gprop('protocol'), array(array('http','http'),array('https','https')), 'httpProtocol', $cfgReturned->http_fwd_secured?'https':'http');
  table_row_edit(gprop('port'), $cfgReturned->http_fwd_port, 'httpport', 5, 30, null, 'msg_httpport');
  table_row_edit(gprop('url'), htmlspecialchars($cfgReturned->http_fwd_url), 'httpurl', 200, 30, null, 'msg_url');
  table_row_checkbox(gprop('needcertification'), $cfgReturned->http_fwd_authorized, 'httpauth', 30, null, 'onHttpForwardAuthenticatedEnabledChanged($(this))');
  table_row_edit(gprop('username'), htmlspecialchars($cfgReturned->http_fwd_username), 'httpname', 127, 30, null, 'msg_httpname');
  table_row_pwd_edit(gprop('password'), htmlspecialchars($cfgReturned->http_fwd_password), 'httppassword', 31, 30, 'msg_httppassword');
  table_row_combo(gprop('httpmethod'), array(array('GET', 'GET'), array('POST', 'POST')), 'httpmethod', htmlspecialchars($cfgReturned->http_fwd_method));
  table_row_radio(gprop('httpfwdseverityfilter'), $radiovalue, 'httpEvtFilter', $cfgReturned->http_fwd_filter, false, 30, 'msg_httpfilter');
  table_row_checkbox('Send test event while saving', false, 'httptest');
  // table_row_textarea(gprop('bodycontent'), '', 'bodycontent');
  table_foot_fold();
}

/* End of Folder */
table_tab_foot();
table_row_submit(array('submitClick'=>'validateSubmit()','cancel'=>0));
page_foot();
?>
  </table>
</form>
</body>
<script>
function onHttpForwardEnabledChanged(chkbox) {
  if (chkbox[0].checked) {
    $('#idTableHttpFwd select').prop('disabled', false);
    $('#idTableHttpFwd input:not(:first)').prop('disabled', false);
    onHttpSpecChanged();
  } else {
    $('#idTableHttpFwd select').prop('disabled', true);
    $('#idTableHttpFwd input:not(:first)').prop('disabled', true);
  }
}
function onHttpSpecChanged() {
  let elSpec = $('#idTableHttpFwd select[name=httpspec]')
  let elHttpIP = $('#idTableHttpFwd input[name=httpip]')
  let elHttpPort = $('#idTableHttpFwd input[name=httpport]')
  let elURL = $('#idTableHttpFwd input[name=httpurl]')
  let elAuth = $('#idTableHttpFwd input[name=httpauth]')
  let elMethod = $('#idTableHttpFwd select[name=httpmethod]')
  let elProtocol = $('#idTableHttpFwd input[name=httpProtocol]:radio')
  let opt = true

  if (elSpec.val() != 'userdefined') {
    opt = false
  }
    
  elHttpIP.prop('disabled', opt)
  elHttpIP.parent().parent().parent().prop('hidden', opt)

  elProtocol.parent().parent().parent().prop('hidden', opt)

  elHttpPort.prop('disabled', opt)
  elHttpPort.parent().parent().parent().prop('hidden', opt)

  elURL.prop('disabled', !opt)
  elURL.parent().parent().parent().prop('hidden', !opt)

  elAuth.prop('disabled', !opt)
  if (!opt) {
    elAuth.prop('checked', true)
  }
  onHttpForwardAuthenticatedEnabledChanged(elAuth);

  elMethod.prop('disabled', !opt)
  elMethod.parent().parent().parent().prop('hidden', !opt)
}
function onHttpForwardAuthenticatedEnabledChanged(chkbox) {
  if (chkbox[0].checked) {
    $('input[name=httpname').prop('disabled', false)
    $('input[name=httppassword').prop('disabled', false)
  } else {
    $('input[name=httpname').prop('disabled', true)
    $('input[name=httppassword').prop('disabled', true)
  }
}

function validateSubmit() {
  var fault = 0;
  if ($('input[name=httpfwdenabled]')[0].checked) {

    if ($('input[name=httpEvtFilter]:checked').length == 0) {
      msg_httpfilter.innerHTML = '<?=gjsalert("invalidinput")?>'
      ++fault
    } else {
      msg_httpfilter.innerHTML = ''
    }

    if ($('#idTableHttpFwd select[name=httpspec]').val() == 'userdefined') {
      // if ($('#idTableHttpFwd input[name=httpurl]').val().trim().length == 0) {
      // let re = /^http(s)+:\/\/[^\s]+$/
      let unsafeRe = /[\*\@\!\`\|\[\]\{\}\'\"\;\,]/
      let re = /http[s]?:\/\/[\S]+/
      let dbcsRe = /[^\x00-\xff]/ /* match double-byte, such as Chinese word */
      let url = $('#idTableHttpFwd input[name=httpurl]').val()

      if(dbcsRe.test(url) || unsafeRe.test(url) || !re.test(url)){
        msg_url.innerHTML = '<?=gjsalert("invalidinput")?>'
        ++fault
      } else {
        msg_url.innerHTML = '';
      }

    }
    else {
      if (!validateIP($('input[name=httpip]').val())) {
        msg_httpip.innerHTML = '<?=gjsalert("invalidip")?>'
        ++fault
      } else {
        msg_httpip.innerHTML = ''
      }

      let val = $('input[name=httpport]').val();
      if (val=='' || val[0] == '0' || !validateNumber(val) || parseInt(val) <= 0 || parseInt(val) > 65535) {
        msg_httpport.innerHTML = '<?=gjsalert("invalidport")?>'
        ++fault
      } else {
        msg_httpport.innerHTML = ''
      }
    }

    if ($('input[name=httpauth]')[0].checked) {
      let re = /^\S+$/
      if ( ! (re.test( $('input[name=httpname]').val() ) ) ) {
        msg_httpname.innerHTML = '<?=gjsalert("invalidinput")?>'
        ++fault
      } else {
        msg_httpname.innerHTML = ''
      }

      if ($('input[name=httppassword]').val().length == 0) {
        msg_httppassword.innerHTML = '<?=gjsalert("invalidinput")?>'
        ++fault
      } else {
        msg_httppassword.innerHTML = ''
      }
    }


  }
  return (fault==0);
}
$(window).on('load', function(){
  onHttpForwardEnabledChanged($('input[name=httpfwdenabled]'))
})
</script>
</html>

