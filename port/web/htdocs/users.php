<?php

/*******************************************************************************
 * Filename    : users.php
 * Description : islaVista user list.
 * Created on  : 08/25/03
 *
 * (C) Copyright Promise Technology Inc., 2020
 * All Rights Reserved
 ******************************************************************************/
include 'tree.php';
include 'common.php';

if (isset($_GET['ssid']))
  $_SESSION['ssid'] = $_GET['ssid'];

unset($GLOBALS['_SESSION']['userid']);

$server = getServer();
$subsystem = $server->getSubsystem();

/* Admin-only, on-demand list of grantable Linux users for the autocomplete.
 * Returns JSON over the authenticated TLS session (no-store), so the system user
 * list is never embedded in a page or cached. Real login accounts (uid>=1000)
 * not already granted. */
if (isset($_GET['pamlist'])) {
  header('Content-Type: application/json');
  header('Cache-Control: no-store');
  $out = array();
  if (getUserPrivilege($subsystem) >= USER_GROUP_SUPER) {
    $granted = array();
    $ul = $subsystem->getUsers();
    if (isset($ul->userlist))
      foreach ($ul->userlist as $gu) if (!empty($gu->isPamUser)) $granted[$gu->username] = 1;
    foreach (@file('/etc/passwd') ?: array() as $pl) {
      $f = explode(':', $pl);
      if (count($f) < 3) continue;
      $uid = (int)$f[2];
      if (($uid == 0 || ($uid >= 1000 && $uid < 65534)) && !isset($granted[$f[0]])) $out[] = $f[0];
    }
    sort($out);
  }
  echo json_encode($out);
  exit;
}

/* Grant/revoke Linux (PAM) users via the setuid promise-pam-user helper. Admin
 * only. proc_open with an argv ARRAY runs the helper directly - NO shell is
 * involved, so command injection is impossible - and the helper itself
 * re-validates (real regular account, gid 1..4). Runs before getUsers so the
 * list below reflects the change. */
$pamNote = '';
if ((isset($_POST['pamgrant']) || isset($_POST['pamrevoke']))
    && getUserPrivilege($subsystem) >= USER_GROUP_SUPER) {
  $pu = isset($_POST['pamuser']) ? trim($_POST['pamuser']) : '';
  if ($pu === '' || !preg_match('/^[a-z_][a-z0-9_.-]{0,31}$/i', $pu)) {
    $pamNote = 'Invalid Linux username.';
  } else {
    $argv = isset($_POST['pamgrant'])
      ? array('add', $pu, (string)max(1, min(4, (int)($_POST['pamgroup'] ?? 1))))
      : array('del', $pu);
    $rc = 1;
    $proc = @proc_open(array_merge(array('/opt/promise/bin/promise-pam-user'), $argv),
                       array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (is_resource($proc)) { fclose($pipes[1]); fclose($pipes[2]); $rc = proc_close($proc); }
    if (isset($_POST['pamgrant']))
      $pamNote = ($rc === 0)
        ? "Granted WebPAM access to Linux user '" . htmlspecialchars($pu) . "' (" . getStringUserPrivilege((int)$argv[2]) . "). They log in with their Linux password."
        : "Could not grant '" . htmlspecialchars($pu) . "' - it must be root or a regular Linux account.";
    else
      $pamNote = "Revoked WebPAM access for '" . htmlspecialchars($pu) . "'.";
  }
}

$note = null;
$result = null;

$users = $subsystem->getUsers();
$result = $users->errorcode;
if ($result == PIERR_INACTIVE_SESSION) {
  errorOnInvalidSession();
}

if ($result == PIERR_NO_ERROR) {
  $users = $users->userlist;
  $sts = PIERR_NO_ERROR;
  $stspwdc = PIERR_NO_ERROR;
  if (isset($_GET['errorcode'])) {
    $sts = (int)$_GET['errorcode'];
  }
  if (isset($_GET['errcodepwd'])) {
    $stspwdc = (int)$_GET['errcodepwd'];
  }

  if (isset($_GET['act']) && $_GET['act'] == 'created') {
    if (($sts == PIERR_NO_ERROR) && ($stspwdc == PIERR_NO_ERROR))
      $note = gresult('usercreateok');
    else
      $note = gresult('usercreatefail');
  } else if (isset($_GET['act']) && $_GET['act'] == 'changed') {
    if ($sts == PIERR_NO_ERROR)
      $note = gresult('usersettingchangeok');
    else
      $note = gresult('usersettingchangefail');
  } else if (isset($_GET['act']) && $_GET['act'] == 'pwd') {
    if ($sts == PIERR_NO_ERROR)
      $note = gresult('passwordchangeok');
    else
      $note = gresult('passwordchangefail');
  } else if (isset($_GET['act']) && $_GET['act'] == 'test email') {
    if ($sts == PIERR_NO_ERROR)
      $note = gresult('testemail');
    else
      $note = gerror('testemail');
  } else if (isset($_GET['act']) && $_GET['act'] == 'set preference') {
    if ($sts == PIERR_NO_ERROR)
      $note = gresult('eventsubscriptionsetok');
    else
      $note = gresult('eventsubscriptionsetfail');
  }

}
if ($pamNote !== '') $note = $pamNote;
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
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center
    bordercolor=#666666 bordercolordark=#FFFFFF>
<?php
if ($result == PIERR_NO_ERROR)
  table_title(gprop('usermanagement'), 'diskArray', $note, $sts);
else
  table_title(gprop('usermanagement'), 'diskArray', $note, $result);

table_tab_head();
if (count($users) == 1 && $users[0]->userGroupId != USER_GROUP_SUPER)
  $tabTitle = tabTitleUser();
else
  $tabTitle = tabTitleUsers();
table_tab($tabTitle);

if ($result == PIERR_NO_ERROR) {
  $columns = array(gprop('username'), gprop('status'), gprop('privilege'), gprop('displayname'), gprop('email'));
  $widths = array(20, 20, 20, 20, 20);
  table_head_fold(gprop('userlist'), 'userlist', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
  table_header_cols($columns, $widths);

  for ($i = 0; $i < count($users); $i++) {
    $user = $users[$i];
    if ($user->enabled)
      $enable = gprop('enabled');
    else
      $enable = gprop('disabled');

    $columns = array(
      array($user->username, 'usert.php?tab=1&userid=' . $i), $enable,
      getStringUserPrivilege($user->userGroupId) . ((isset($user->isPamUser) && $user->isPamUser) ? ' <span style="color:#888">(Linux)</span>' : ''),
      preg_replace("/\s/", "&nbsp;", $user->displayName), $user->email
    );
    table_row_cols($columns);
  }
  table_foot();
  table_foot();

  /* Linux/PAM user management (admin only): grant a Linux account WebPAM access +
   * a privilege, or revoke it. Shares the page's outer form (two named submit
   * buttons); the handler at the top runs the setuid promise-pam-user helper. */
  if (getUserPrivilege($subsystem) >= USER_GROUP_SUPER) {
    table_head_fold('Linux User Access (PAM)', 'pamgrant', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
    echo '<tr><td class=tableRowColOther colspan=5 style="padding:10px">';
    echo '<small style="color:#888">Grant a Linux account access to WebPAM &mdash; it logs in with its existing Linux password (verified via PAM over HTTPS) at the privilege you choose. Only regular accounts (uid &ge; 1000) can be granted; Revoke removes access.</small><br><br>';
    /* The username list is NOT embedded in the page. It is fetched on demand from
     * the admin-only endpoint users.php?pamlist over TLS when the operator focuses
     * the box, so the system user list never sits in the page source or cache. */
    echo 'Linux user <span style="position:relative;display:inline-block;vertical-align:middle">';
    echo '<input name="pamuser" id="pamuser" autocomplete="off" onfocus="pamOpen()" oninput="pamOpen()" onblur="setTimeout(pamClose,200)" size=18 maxlength=31 placeholder="type or pick" style="border:1px solid #8d8d8d;padding:1px 4px;font-size:11px;height:18px;background:#fff;color:#000">';
    echo '<div id="pamdrop" style="display:none;position:absolute;left:0;top:100%;z-index:1000;min-width:100%;max-height:170px;overflow-y:auto;background:#fff;border:1px solid #3c5f79;border-top:none;box-shadow:0 2px 5px rgba(0,0,0,.3);font-size:11px;color:#000"></div>';
    echo '</span> &nbsp; ';
    echo 'privilege <select name="pamgroup" class="input-p"><option value="1">View</option><option value="2">Maintenance</option><option value="3" selected>Power User</option><option value="4">Administrator</option></select> &nbsp; ';
    echo '<input type="submit" name="pamgrant" value="Grant Access" class="input-p"> &nbsp; ';
    echo '<input type="submit" name="pamrevoke" value="Revoke Access" class="input-p" onclick="return confirm(\'Revoke this Linux user\\\'s WebPAM access?\')">';
    echo '</td></tr>';
    table_foot_fold();
    /* Custom themed autocomplete: fetch the grantable-user list once on first focus
     * from the admin-only TLS endpoint (never embedded in the page), then filter as
     * the operator types into a dropdown styled to the WebPAM theme. Safe DOM. */
    echo '<script>var pamL=null;'
       . 'function pamFetch(cb){if(pamL!==null){cb();return;}'
       . 'fetch("users.php?pamlist=1",{credentials:"same-origin",cache:"no-store"})'
       . '.then(function(r){return r.json();}).then(function(l){pamL=l;cb();}).catch(function(){pamL=[];cb();});}'
       . 'function pamOpen(){pamFetch(function(){var i=document.getElementById("pamuser"),d=document.getElementById("pamdrop"),q=i.value.toLowerCase();'
       . 'while(d.firstChild)d.removeChild(d.firstChild);'
       . 'var m=(pamL||[]).filter(function(u){return u.toLowerCase().indexOf(q)>=0;}).slice(0,50);'
       . 'if(!m.length){d.style.display="none";return;}'
       . 'm.forEach(function(u){var o=document.createElement("div");o.textContent=u;o.style.cssText="padding:3px 8px;cursor:pointer;white-space:nowrap";'
       . 'o.onmouseover=function(){o.style.background="#7C8CA5";o.style.color="#fff";};'
       . 'o.onmouseout=function(){o.style.background="";o.style.color="";};'
       . 'o.onmousedown=function(){i.value=u;pamClose();};d.appendChild(o);});d.style.display="block";});}'
       . 'function pamClose(){document.getElementById("pamdrop").style.display="none";}</script>';
  }
} //NO_ERROR
else {
  table_head_fold(gprop('userlist'), 'content', null, null, array('class' => 'tableBorder'));
  table_row_empty(gerror('displayuserlist'));
  table_foot_fold();
}
table_tab_foot();

page_foot();
?>

  </table>
</form>

</body>
</html>
