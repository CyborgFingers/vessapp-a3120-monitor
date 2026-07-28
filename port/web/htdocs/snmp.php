<?php
/*******************************************************************************
 * Filename    : snmp.php
 * Description : islaVista snmp setting.
 * Created on  : 09/23/03
 * CVS Version : $Id: snmp.php,v 1.50 2007/09/27 04:30:34 jackyl Exp $
 *
 * (C) Copyright Promise Technology Inc., 2003
 * All Rights Reserved
 ******************************************************************************/

include 'tree.php';
include 'common.php';

$server = getServer();
$subsystem = $server->getSubsystem();

$note = null;
$trapSinks = null;

$daemons = $subsystem->getAllDaemons();
$result = $daemons->errorcode;
if ($result == PIERR_INACTIVE_SESSION) {
    errorOnInvalidSession();
}
if ($result == PIERR_NO_ERROR) {
    $daemons = $daemons->daemonlist;
    for ($i = 0; $i < count($daemons ?? array()); $i++) {
        $daemon = $daemons[$i];
        if ($daemon->daemonname == DAEMON_SNMP) {
            break;
        }

    }
    //$severity = $subsystem->evtCusGetStrSeverity($language);

    $snmpsetting = $subsystem->getSNMPv3Setting();
    //error_log(print_r($snmpsetting, 1));
    // $snmpsetting->enablev2 = true;
    // $snmpsetting->enablev3 = false;
    $result = $snmpsetting->errorcode;
}
if ($result == PIERR_INACTIVE_SESSION) {
    errorOnInvalidSession();
}
/*
if (isset($_GET['act']) && $_GET['act'] == 'create')
unset($GLOBALS['_SESSION']['snmp']);
 */

if (!isset($_POST['update']) && !isset($_POST['delete']) && !isset($_POST['submit'])) {
    unset($GLOBALS['_SESSION']['snmp']);
}

if (isset($_SESSION['snmp'])) {
    $trapSinks = $_SESSION['snmp'];
} else {
    $trapSinks = $snmpsetting->trapsinks;
}

?>

<html>

<head>
<?php
    function getSeverityStr($id)
    {
        switch ($id) {
            case EVT_SEVERITY_INFORMATION:
                return "Info";
            case EVT_SEVERITY_WARNING:
                return "Warning";
            case EVT_SEVERITY_MINOR:
                return "Minor";
            case EVT_SEVERITY_MAJOR:
                return "Major";
            case EVT_SEVERITY_CRITICAL:
                return "Critical";
            case EVT_SEVERITY_FATAL:
                return "Fatal";
            default:
                return "";
        }
    }
page_head();
$ldindex = -1;

if (isset($_POST['ldindex'])) {
    $ldindex = $_POST['ldindex'];
}

if (isset($_POST['update'])) {
    if ($ldindex >= 0) {
        $trapSink = $trapSinks[$ldindex];
    } else {
        $trapSink = new stdClass();
    }

    $trapSink->trapsink = $_POST['trapsink'];
    $trapSink->trapfilter = EVT_SEVERITY_INFORMATION;
    $trapSink->version = $_POST['TrapVersion'];
    if (isset($_POST['TrapCommunity'])) {
        $trapSink->community = $_POST['TrapCommunity'];
    }
    if (isset($_POST['TrapSecurityLevel'])) {
        $trapSink->securitylevel = $_POST['TrapSecurityLevel'];
    }
    if (isset($_POST['TrapAuthProto'])) {
        $trapSink->authproto = $_POST['TrapAuthProto'];
    }
    if (isset($_POST['TrapAuthPwd'])) {
        $trapSink->authpwd = $_POST['TrapAuthPwd'];
    }
    if (isset($_POST['TrapPrivProto'])) {
        $trapSink->privproto = $_POST['TrapPrivProto'];
    }
    if (isset($_POST['TrapPrivPwd'])) {
        $trapSink->privpwd = $_POST['TrapPrivPwd'];
    }
    if (isset($_POST['trapuser'])) {
        $trapSink->authname = $_POST['trapuser'];
    }
    if ($ldindex >= 0) {
        $trapSinks[$ldindex] = $trapSink;
    } else {
        $trapSinks[] = $trapSink;
        // point to the last one
        $ldindex = count($trapSinks) - 1;
    }

    // unselect any row
    $ldindex = -1;

} else if (isset($_POST['delete'])) {
    if ($ldindex >= 0) {
        array_splice($trapSinks, $ldindex, 1);
    }

    if ($ldindex >= 0 && !isset($trapSinks[$ldindex])) {
        $ldindex--;
    }

}
if (isset($_POST['update']) || isset($_POST['delete'])) {
    //avoid to erase value when refreshing page
    $snmpsetting->port = trim($_POST['port']);
    $snmpsetting->systemname = trim($_POST['systemname']);
    $snmpsetting->systemlocation = trim($_POST['systemlocation']);
    $snmpsetting->systemcontact = trim($_POST['systemcontact']);
    $snmpsetting->severitylevel = trim($_POST['severitylevel']);

    $snmpsetting->enablev2 = isset($_POST['enablev2']);
    if ($snmpsetting->enablev2) {
        $snmpsetting->readcommunity = trim($_POST['readcommunity']);
        //$snmpsetting->writecommunity = trim($_POST['writecommunity']);
    }

    $snmpsetting->enablev3 = isset($_POST['enablev3']) ? 1 : 0;
    if ($snmpsetting->enablev3) {
        $snmpsetting->authname = trim($_POST['user']);
        $snmpsetting->securitylevel = $_POST['SecurityLevel'];
        if (isset($_POST['AuthProto'])) {
            $snmpsetting->authproto = $_POST['AuthProto'];
            $snmpsetting->authpwd = $_POST['AuthPwd'];
        }
        if (isset($_POST['PrivProto'])) {
            $snmpsetting->privproto = $_POST['PrivProto'];
            $snmpsetting->privpwd = $_POST['PrivPwd'];
        }
    }
}
if (isset($_POST['start']) || isset($_POST['stop']) || isset($_POST['restart'])) {
    if (isset($_POST['start'])) {
        $daemon->daemonstatus = DAEMON_STARTED;
        $note = gresult('snmpstart');
    } else if (isset($_POST['stop'])) {
        $daemon->daemonstatus = DAEMON_STOPPED;
        $note = gresult('snmpstop');
    } else if (isset($_POST['restart'])) {
        $daemon->daemonstatus = DAEMON_RESTART;
        $note = gresult('snmprestart');
    }
    $setresult = $subsystem->startStopDaemon($daemon);
    if ($setresult != PIERR_NO_ERROR) {
        $note = gresult('snmpnotstartorstop');
    } else {
        if ($daemon->daemonstatus == DAEMON_RESTART || $daemon->daemonstatus == DAEMON_STARTED) {
            $daemon->daemonstatus = 'Started';
        } else {
            $daemon->daemonstatus = 'Stopped';
        }

    }
}
if (isset($_POST['submit'])) {
    $setresult = PIERR_NO_ERROR;
    // if (isset($_POST['startuptype'])) {
    //     if ($_POST['startuptype'] == "Auto") {
    //         $daemon->startuptype = DAEMON_STARTTYPE_AUTO;
    //     } else if ($_POST['startuptype'] == "Manual") {
    //         $daemon->startuptype = DAEMON_STARTTYPE_MANUAL;
    //     }

    //     $setresult = $subsystem->setDaemonStartupType($daemon);
    // } else {
    //     $setresult = 1;
    //     $startuptypeerr = gresult('servicestartuptypeerr'); //gresult('snmpsettingapplyok')
    // }

    if ($setresult == PIERR_NO_ERROR && !isset($startuptypeerr)) {
        if ($daemon->startuptype == DAEMON_STARTTYPE_AUTO) {
            $daemon->startuptype = 'Automatic';
        } else {
            $daemon->startuptype = 'Manual';
        }

        $snmpsetting->port = trim($_POST['port']);
        $snmpsetting->systemname = trim($_POST['systemname']);
        $snmpsetting->systemlocation = trim($_POST['systemlocation']);
        $snmpsetting->systemcontact = trim($_POST['systemcontact']);
        $snmpsetting->severitylevel = trim($_POST['severitylevel']);

        $snmpsetting->enablev2 = isset($_POST['enablev2']) ? 1 : 0;
        if ($snmpsetting->enablev2) {
            $snmpsetting->readcommunity = trim($_POST['readcommunity']);
            //$snmpsetting->writecommunity = trim($_POST['writecommunity']);
        }

        $snmpsetting->enablev3 = isset($_POST['enablev3']) ? 1 : 0;
        if ($snmpsetting->enablev3) {
            $snmpsetting->authname = trim($_POST['user']);
            $snmpsetting->securitylevel = $_POST['SecurityLevel'];
            if (isset($_POST['AuthProto'])) {
                $snmpsetting->authproto = $_POST['AuthProto'];
                $snmpsetting->authpwd = $_POST['AuthPwd'];
            }
            if (isset($_POST['PrivProto'])) {
                $snmpsetting->privproto = $_POST['PrivProto'];
                $snmpsetting->privpwd = $_POST['PrivPwd'];
            }
        }

        $snmpsetting->numtrapsink = count($trapSinks);
        $snmpsetting->trapsinks = array();
        foreach ($trapSinks as $sink) {
            $snmpsetting->trapsinks[] = $sink;
        }
        //error_log("setSNMPSetting :: \n" . print_r($snmpsetting, 1));
        $setresult = $subsystem->setSNMPv3Setting($snmpsetting, $snmpsetting->trapsinks);
    }

    if ($setresult == PIERR_NO_ERROR && !isset($startuptypeerr)) {
        if ($_POST['warnrestart'] == 'restart') {
            //NOTE: restart has to be after set SNMP setting
            $daemon->daemonstatus = DAEMON_RESTART;
            $setresult = $subsystem->startStopDaemon($daemon, true);
            if ($setresult == PIERR_NO_ERROR) {
                $daemon->daemonstatus = "Started";
                $note = gresult('snmpsettingapplyok');
            }
        } else if ($_POST['warnrestart'] == 'norestart') {
            $note = gresult('snmpsettingtakeeffect');
        } else {
            $note = gresult('snmpsettingapplyok');
        }
    } else {
        $note = gresult('snmpsettingapplyfail');
    }

}

?>
</head>

<body onclick=unselectTS(event)>

<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']);?>
<form method=post action="<?php echo $action_url; ?>">
<?=form_csrf()?>
    <input type=hidden value='-1' name=ldindex>
    <input type=hidden value=none name=warnrestart>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center>
<?php
$title = gprop('snmpsettings');
if (isset($startuptypeerr)) {
    table_title($title, gprop('snmpoverallsettings'), $note . ' ' . $startuptypeerr);
} else {
    if (isset($setresult) && $setresult != PIERR_NO_ERROR) {
        table_title($title, gprop('snmpoverallsettings'), $note, $setresult);
    } else {
        table_title($title, gprop('snmpoverallsettings'), $note, $result);
    }

}

table_tab_head();
$s = array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner');
$tabTitle = tabTitleService();
table_tab($tabTitle);

if ($result == PIERR_NO_ERROR) {
    $privilege = getUserPrivilege($subsystem);
    table_swmgt($daemon, $privilege, $s);

    table_head_fold(gprop('snmpoverallsettings'), 'overall', null, null, $s);
    table_row_edit(gprop('port'), htmlspecialchars($snmpsetting->port), 'port', 5, 30, null, 'msg_port');
    table_row_edit(gprop('systemname'), htmlspecialchars($snmpsetting->systemname), 'systemname', 255, 30, null, 'msg_sysname');
    table_row_edit(gprop('systemlocation'), htmlspecialchars($snmpsetting->systemlocation), 'systemlocation', 255, 30, null, 'msg_syslocation');
    table_row_edit(gprop('systemcontact'), htmlspecialchars($snmpsetting->systemcontact), 'systemcontact', 255, 30, null, 'msg_syscontact');
    $radiovalue = array(
        array(EVT_SEVERITY_INFORMATION, "Info"),
        array(EVT_SEVERITY_WARNING, "Warning"),
        array(EVT_SEVERITY_MINOR, "Minor"),
        array(EVT_SEVERITY_MAJOR, "Major"),
        array(EVT_SEVERITY_CRITICAL, "Critical"),
        array(EVT_SEVERITY_FATAL, "Fatal")
      );
    table_row_radio(gprop('trapfilter'), $radiovalue, 'severitylevel', $snmpsetting->severitylevel);
    table_row_checkbox(gprop('enablesnmpv2agent'), $snmpsetting->enablev2, 'enablev2');
    table_row_edit(gprop('readcommunity'), htmlspecialchars($snmpsetting->readcommunity), 'readcommunity', 63, 30, null, 'msg_community');
    table_row_checkbox(gprop('enablesnmpv3agent'), $snmpsetting->enablev3, 'enablev3');
    table_row_edit(gprop('username'), isset($snmpsetting->authname)?htmlspecialchars($snmpsetting->authname):'', 'user', 31, 30, null, 'msg_user');
    table_row_radio(gprop('securitylevel'), array(array(0, 'NoAuth,NoPriv'), array(1, 'Auth,NoPriv'), array(2, 'Auth,Priv')), 'SecurityLevel',
        isset($snmpsetting->securitylevel) ? $snmpsetting->securitylevel : 1);
    table_row_combo(gprop('authenticationtype'), array(array(0, 'MD5'), array(1, 'SHA'), array(2, 'SHA-224'), array(3, 'SHA-256'), array(4, 'SHA-384'), array(5, 'SHA-512')), 'AuthProto',
        isset($snmpsetting->authproto) ? $snmpsetting->authproto : 0);
    table_row_pwd_edit(gprop('authenticationpass'), isset($snmpsetting->authpwd) ? htmlspecialchars($snmpsetting->authpwd) : '', 'AuthPwd', 31, 30, 'msg_authpwd');
    table_row_combo(gprop('privacytype'), array(array(0, 'DES'), array(1, 'AES'), array(2, 'AES-192'), array(3, 'AES-256')), 'PrivProto',
        isset($snmpsetting->privproto) ? $snmpsetting->privproto : 0);
    table_row_pwd_edit(gprop('privacypass'), isset($snmpsetting->privpwd) ? htmlspecialchars($snmpsetting->privpwd) : '', 'PrivPwd', 31, 30, 'msg_privpwd');
    ?>
    <script>

        function switchV2Layer() {
            $('input[name=readcommunity]').prop('disabled', !$('input[name=enablev2]').is(':checked'))
        }
        switchV2Layer()
        $('input[name=enablev2]').change(function(){
           switchV2Layer()
        })

        function switchV3Layer() {
            v3enabled = $('input[name=enablev3]').is(':checked')
            $('input[name=user]').prop('disabled', !v3enabled)
            $('input[name=SecurityLevel]').prop('disabled', !v3enabled)
            switchV3SecLevel(v3enabled)
        }
        switchV3Layer()
        $('input[name=enablev3]').change(function(){
            switchV3Layer()
        })

        function switchV3SecLevel(parentEnabled) {
            v = $('input:radio[name=SecurityLevel]:checked').val()
            e = parentEnabled && (v > 0)
            if (!e){
                $('select[name=AuthProto]').prop('disabled', 'disabled')
            } else {
                $('select[name=AuthProto]').removeAttr("disabled")
            }
            $('input[name=AuthPwd]').prop('disabled', !e)
            e = parentEnabled && (v > 1)
            if (!e){
                $('select[name=PrivProto]').prop('disabled', 'disabled')
            } else {
                $('select[name=PrivProto]').removeAttr("disabled")
            }
            $('input[name=PrivPwd]').prop('disabled', !e)
        }
        $('input[name=SecurityLevel]').change(function(evt){
            switchV3SecLevel(true)
        })

    </script>
    <?php
table_foot_fold();

    table_head_fold(gprop('trapsink'), 'trapsinkno', null, null, $s);
    table_row_edit(gprop('trapsinkipaddress'), '0.0.0.0', 'trapsink', 15, 30, null, 'msg_trapsinkserver');
    table_row_radio(gprop('trapversion'), array(array(0, 'v1/2c'), array(1, 'v3')), 'TrapVersion', 0);
    table_row_edit(gprop('trapcommunity'), 'public', 'TrapCommunity', 63, 30, null, 'msg_trapcommunity');
    table_row_edit(gprop('username'), '', 'trapuser', 31, 30, null, 'msg_trapuser');
    table_row_radio(gprop('securitylevel'), array(array(0, 'NoAuth,NoPriv'), array(1, 'Auth,NoPriv'), array(2, 'Auth,Priv')), 'TrapSecurityLevel', 1);
    table_row_combo(gprop('authenticationtype'), array(array(0, 'MD5'), array(1, 'SHA'), array(2, 'SHA-224'), array(3, 'SHA-256'), array(4, 'SHA-384'), array(5, 'SHA-512')), 'TrapAuthProto', 0);
    table_row_pwd_edit(gprop('authenticationpass'), '', 'TrapAuthPwd', 31, 30, 'msg_trapAuthPass');
    table_row_combo(gprop('privacytype'), array(array(0, 'DES'), array(1, 'AES'), array(2, 'AES-192'), array(3, 'AES-256')), 'TrapPrivProto', 0);
    table_row_pwd_edit(gprop('privacypass'), '', 'TrapPrivPwd', 31, 30, 'msg_trapPrivPass');

    // if (!isset($_SESSION['snmp'])) {
    //     for ($i = 0; $i < $snmpsetting->numtrapsink; $i++) {
    //         $trapSinks[$i]->trapsink = $snmpsetting->trapsinks[$i]->trapsink;
    //         //convert to HTML element value
    //         $trapSinks[$i]->trapfilter = getSeverityStr($snmpsetting->trapsinks[$i]->trapfilter);
    //     }
    // }
    ?>
    <script>
        function switchTrapV3Layer() {
            v = $('input:radio[name=TrapVersion]:checked').val()
            $('input[name=TrapCommunity]').prop('disabled', (v!=0))
            $('input[name=trapuser]').prop('disabled', (v!=1))
            $('input:radio[name=TrapSecurityLevel]').prop('disabled', (v!=1))
            switchTrapV3SecLevel(v==1)
        }
        switchTrapV3Layer()
        $('input[name=TrapVersion]').change(function(){
            switchTrapV3Layer()
        })

        function switchTrapV3SecLevel(parentEnabled) {
            v = $('input:radio[name=TrapSecurityLevel]:checked').val()
            e = parentEnabled && (v > 0)
            if(!e){
                $('select[name=TrapAuthProto]').prop('disabled', 'disabled')
            }else{
                $('select[name=TrapAuthProto]').removeAttr("disabled")
            }
            $('input[name=TrapAuthPwd]').prop('disabled', !e)
            e = parentEnabled && (v > 1)
            if(!e){
                $('select[name=TrapPrivProto]').prop('disabled', !e)
            }else{
                $('select[name=TrapPrivProto]').removeAttr("disabled")
            }
            $('input[name=TrapPrivPwd]').prop('disabled', !e)
        }
        $('input[name=TrapSecurityLevel]').change(function(evt){
            switchTrapV3SecLevel(true)
        })
    </script>
    <tr class=tableHeader>
      <td colspan=2>
        <input type=submit class=input-p value='  <?=gprop('update')?>  ' name=update onClick="return validateUpdate()">
        &nbsp;
        <input type=submit class=input-p value='  <?=gprop('delete')?>  ' name=delete>
      </td>
    </tr>

<?php
table_foot_fold();
    //table_tab_foot();

    table_head(array('class' => 'tableBorder'));
    table_head();

    table_header(gprop('trapsinks'));
    table_foot();
    ?>
    <tr class=tableHeader>
      <td >
        <div id=listDiv style="overflow:auto">
          <table width=100% cellpadding=2 cellspacing=1 border=0 class="tableContentInner">
          	<tr class="tableRowHeader" id='listh'>
          		<td width="10%">#</td>
          		<td width="30%"><?=gprop('trapsinks')?></td>
                <td><?=gprop('version')?></td>
          	</tr>
<?php
for ($i = 0;isset($trapSinks) && $i < count($trapSinks); $i++) {
        $class = ($i == $ldindex) ? $SELECTED_CLASS : "tableRow1Cols";
        $trapSink = $trapSinks[$i];
        echo '
            <tr id=ld' . $i . ' class=' . $class . '
              onclick=selectTS(this) style="cursor: hand"
              onMouseOver=overColor(this) onMouseOut=outColor(this)>
              <td>' . ($i + 1) . '</td>
              <td>' . htmlspecialchars($trapSink->trapsink) . '</td>
              <td>' . ($trapSink->version == 0 ? 'v1/2c' : 'v3') . '</td>
            </tr>';
    }

    if (!isset($trapSinks) || count($trapSinks) == 0) {
        table_row_empty(gresult('notrapsink'), 4);
    }

    ?>
          </table>
        </div>
      </td>
    </tr>

<?php
table_foot();
    if ($privilege >= USER_GROUP_SUPER) {
        table_row_submit(array('submitClick' => 'validateSubmit()'));
    }

} //NO_ERROR
else {
    table_head_fold(gprop('snmpsettings'), 'content', null, null, $s);
    table_row_empty(gerror('snmpdisplay'));
    table_foot_fold();
    table_tab_foot();
}
page_foot();
?>
  </table>
</form>

<script>
    var trapSinks = [];
<?php
if(isset($trapSinks)) {
    foreach ($trapSinks as $trapSink) {
        echo '    trapSinks.push('.json_encode($trapSink).");\n";
    }
}
echo '
    var daemonstatus = "' . $daemon->daemonstatus . '";';
?>
    form = document.forms[0];
    form.elements['ldindex'].value = <?echo $ldindex; ?>;
    elements=document.forms[0].elements;
    systemcontact=elements['systemcontact'];
    function overColor(obj)
    {
        obj.className = 'tableRowOverCols';
    }

    function outColor(line)
    {
        if (document.forms[0].elements['ldindex'].value == getTSIndex(line, 'ld'))
            line.className = '<?echo $SELECTED_CLASS; ?>';
        else
           line.className = 'tableRow1Cols';
    }
    function getTSIndex(obj, name)
    {
        for (i = 0; i < <?echo MAX_TRAP_SINKS ?>; i++)
        {
            id = name + i;
            if (document.getElementById(id) == obj)
                return i;
        }
        return -1;
    }
    function selectTS(line)
    {
        // unselect all lines
        for (i = 0; i < <?echo MAX_TRAP_SINKS ?>; i++)
        {
            id = 'ld' + i;
            if (document.getElementById(id))
                document.getElementById(id).className = 'tableRow1Cols'
            if (document.getElementById(id) == line)
                index = i;
        }
        // select current line
        line.className = '<?echo $SELECTED_CLASS; ?>';

        $('input[name=trapsink]').val(trapSinks[index].trapsink);
        // $(":radio[name=trapfilter]").each(function() {
        //     if ($(this).val() == trapSinks[index].trapfilter) {
        //         $(this).prop('checked', true);
        //     }
        // })
        $.each($(':radio[name=TrapVersion]'),function(i, btn) {
            if ($(btn).val() == trapSinks[index].version){
                $(btn).prop('checked', true);
            }
        })
        if (trapSinks[index].version == 0) {
            $('input[name=TrapCommunity]').prop('disabled', false).val(trapSinks[index].community);
            $('input[name=trapuser]').prop('disabled', true).val("");
            $(':radio[name=TrapSecurityLevel]').prop('disabled', true);
            $('select[name=TrapAuthProto]').prop('disabled', 'disabled')
            $('input[name=TrapAuthPwd]').prop('disabled', true).val("");
            $('select[name=TrapPrivProto]').prop('disabled', 'disabled');
            $('input[name=TrapPrivPwd]').prop('disabled', true).val("");
        } else if (trapSinks[index].version == 1) {
            $('input[name=TrapCommunity]').prop('disabled', true).val("");
            $('input[name=trapuser]').prop('disabled', false).val(trapSinks[index].authname);
            $(':radio[name=TrapSecurityLevel]').prop('disabled', false).each(function(i, btn){
                if ($(btn).val() == trapSinks[index].securitylevel){
                    $(btn).prop('checked', true);
                }
            })
            if (trapSinks[index].securitylevel == 0) {
                $('select[name=TrapAuthProto]').prop('disabled', 'disabled')
                $('input[name=TrapAuthPwd]').prop('disabled', true).val("");
                $('select[name=TrapPrivProto]').prop('disabled', 'disabled');
                $('input[name=TrapPrivPwd]').prop('disabled', true).val("");
            }
            if (trapSinks[index].securitylevel > 0) {
                $('select[name=TrapAuthProto]').removeAttr('disabled').val(trapSinks[index].authproto)
                $('input[name=TrapAuthPwd]').prop('disabled', false).val(trapSinks[index].authpwd);
                $('select[name=TrapPrivProto]').prop('disabled', 'disabled');
                $('input[name=TrapPrivPwd]').prop('disabled', true).val("");
            }
            if (trapSinks[index].securitylevel > 1) {
                $('select[name=TrapPrivProto]').removeAttr('disabled').val(trapSinks[index].privproto);
                $('input[name=TrapPrivPwd]').prop('disabled', false).val(trapSinks[index].privpwd);
            }
        }
        document.forms[0].elements['ldindex'].value = index;
    }

      function unselectTS(e)
  {
    if(!document.forms[0].ldindex||document.forms[0].ldindex.value==-1) return;

    // windows event is only for IE
    if(window.event&&
      ((window.event.srcElement.tagName=='INPUT')||
      (window.event.srcElement.tagName=='SELECT')||
      window.event.srcElement.parentElement.id||
      window.event.srcElement.id))
      return;

    if(e.target&&
      ((e.target.tagName=='INPUT')||
      (e.target.tagName=='SELECT')||
      (e.target.tagName=='OPTION')||
      e.target.parentNode.id||
      e.target.id))
      return;
      //save the selected line
      var index=document.forms[0].ldindex.value;
      document.forms[0].elements['ldindex'].value=-1;
      document.forms[0].elements['trapsink'].value = "0.0.0.0";
      // document.forms[0].elements.trapfilter[0].checked = true;
       id = 'ld' + index;
       if (document.getElementById(id))
            document.getElementById(id).className = 'tableRow1Cols'

  }

    function typeChanged(x)
    {
        document.forms[0].restarttype.value = x.value;
    }

    function validateStop()
    {
        var ret = confirm('<?=gjsalert('stopservice')?>');
        if (!ret)
            return false;
        return true;
    }

    function validatePort(input)
    {
        if (!/^[1-9][\d+]{1,4}$/.test(input)){
            return false
        }
        if (input > 65535){
            return false
        }
        return true
    }

    const communityPolicy=/^[\(\)\.\*\/\-:_\?=,&%\$a-zA-Z0-9]+$/
    const pwdPolicy=/^[\.\-\{\}\(\)\[\]\!@$\^\*\/\\?|&,_:=#<> a-zA-Z0-9]{8,31}$/
    const namePolicy=/^[a-zA-Z][a-zA-Z0-9_]{1,31}$/

    function validateSubmit()
    {
        var result = true;
        $(".text5 > .warning").html('')

        if (!validatePort($("input[name=port]").val())){
            msg_port.innerHTML = '* <?=gjsalert('invalidport')?>';
            result = false;
        }
        if (!/^[\x20-\x7e]*$/.test($('input[name=systemname]').val())){
            msg_sysname.innerHTML = '* <?=galert("invalidinput")?>';
            result = false;
        }
        if (!/^[\x20-\x7e]*$/.test($('input[name=systemlocation]').val())){
            msg_syslocation.innerHTML = '* <?=galert("invalidinput")?>';
            result = false;
        }
        if (!/^[\x20-\x7e]*$/.test($('input[name=systemcontact]').val())){
            msg_syscontact.innerHTML = '* <?=galert("invalidinput")?>';
            result = false;
        }
        if ($(':checkbox[name=enablev2]').prop('checked')){
            if (!communityPolicy.test($('input[name=readcommunity]').val())){
                msg_community.innerHTML = '* <?=galert("invalidinput")?>';
                result = false;
            }
        }
        if ($(':checkbox[name=enablev3]').prop('checked')){
            if (!namePolicy.test($('input[name=user]').val())){
                msg_user.innerHTML = '* <?=galert("invalidinput")?>';
                result = false;
            }
            if ($(":radio[name=SecurityLevel]:checked").val() > 0 && !pwdPolicy.test($("input[name=AuthPwd]").val())) {
                msg_authpwd.innerHTML = '* <?=gerror(521)?>';
                result = false;
            }
            if ($(":radio[name=SecurityLevel]:checked").val() > 1 && !pwdPolicy.test($("input[name=PrivPwd]").val())) {
                msg_privpwd.innerHTML = '* <?=gerror(521)?>';
                result = false;
            }
        }
        if (result && daemonstatus == 'Started')
        {
            var ret = confirm('<?=gjsalert('snmprestart')?>');
            if (ret){
                form.warnrestart.value = 'restart';
            }
            else{
                form.warnrestart.value = 'norestart';
            }
        }
        if(result){
            $('.tableTitle .text6').empty()
        }
        return result;
    }
    function validateRestart()
    {
        return true;
    }
    function validateUpdate()
    {
        var result = true
        var ip=document.forms[0].trapsink;
        var ipzeroPattern=/^(0*)\.(0*)\.(0*)\.(0*)$/;

        $(".text5 > .warning").html('')
        
        if(!validateIP(ip.value)){
            msg_trapsinkserver.innerHTML='<?=gjsalert('invalidip')?>';
            ip.focus();
            result = false
        }
        else if (ipzeroPattern.test(ip.value)){
            msg_trapsinkserver.innerHTML='<?=gjsalert('invalidip')?>';
            ip.focus();
            result = false
        }

        v = $('input[name=TrapVersion]:checked').val()
        if (v == 0) {
            if (!communityPolicy.test($('input[name=TrapCommunity]').val())){
                msg_trapcommunity.innerHTML = '* <?=galert("inputusername")?>'
                result = false
            }
        } else {
            if (!namePolicy.test($('input[name=trapuser]').val())){
                msg_trapuser.innerHTML = '* <?=galert("inputusername")?>'
                result = false
            }
            a = $('input[name=TrapSecurityLevel]:checked').val()
            if (a >= 1) {
                if (!pwdPolicy.test($('input[name=TrapAuthPwd]').val())){
                    msg_trapAuthPass.innerHTML = '* <?=gerror(521)?>'
                    result = false
                }
            }
            if (a == 2) {
                if (!pwdPolicy.test($('input[name=TrapPrivPwd]').val())){
                    msg_trapPrivPass.innerHTML = '* <?=gerror(521)?>'
                    result = false
                }
            }
        }
        return result;
    }
</script>
</body>
</html>

<?php
$_SESSION['snmp'] = $trapSinks;
?>
