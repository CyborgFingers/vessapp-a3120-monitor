<?php
/*******************************************************************************
 * sslsettings.php - manage the HTTPS server certificate.
 *
 * Lets a super user install their own server (device) certificate + private key
 * + optional CA/intermediate chain, or regenerate the built-in self-signed cert.
 * The web tier (www-data) only QUEUES the change to /run/promise/ssl.request;
 * the root applier (promise-webpam-ssl, run from the entrypoint loop) validates
 * it, installs /etc/promise/webpam.{crt,key} and reloads nginx.
 ******************************************************************************/
include 'tree.php';
include 'common.php';

$server = getServer();
$subsystem = $server->getSubsystem();
$priv = getUserPrivilege($subsystem);
$isSuper = ($priv >= USER_GROUP_SUPER);

$CRT = '/etc/promise/webpam.crt';
$REQ = '/run/promise/ssl.request';
$STATUS = '/run/promise/ssl.status';

$note = null;

/* ---- handle POST (super user only; the web tier just queues) --------------- */
if ($isSuper && ($_SERVER['REQUEST_METHOD'] === 'POST')) {
  if (isset($_POST['regen'])) {
    @file_put_contents($REQ, json_encode(array('action' => 'selfsigned')));
    @chmod($REQ, 0600);
    $note = 'Regenerating a self-signed certificate and reloading - reload this page in a few seconds.';
  } else if (isset($_POST['apply'])) {
    $cert = trim($_POST['cert'] ?? '');
    $key  = trim($_POST['key'] ?? '');
    $chain = trim($_POST['chain'] ?? '');
    $err = '';
    if (strpos($cert, '-----BEGIN CERTIFICATE-----') === false)
      $err = 'The certificate field is not a PEM certificate (expected -----BEGIN CERTIFICATE-----).';
    else if (strpos($key, '-----BEGIN') === false || strpos($key, 'PRIVATE KEY-----') === false)
      $err = 'The private key field is not a PEM private key.';
    else if (function_exists('openssl_x509_check_private_key') && !@openssl_x509_check_private_key($cert, $key))
      $err = 'The certificate and private key do not match.';
    else if ($chain !== '' && strpos($chain, '-----BEGIN CERTIFICATE-----') === false)
      $err = 'The CA chain field is not PEM certificate(s).';
    if ($err) {
      $note = 'Not applied: ' . $err;
    } else {
      @file_put_contents($REQ, json_encode(array('action' => 'install', 'cert' => $cert, 'key' => $key, 'chain' => $chain)));
      @chmod($REQ, 0600);
      $note = 'Certificate queued - installing and reloading nginx. Reload this page in a few seconds to confirm.';
    }
  }
}

/* ---- current certificate summary ------------------------------------------ */
$pem = @file_get_contents($CRT);
$info = ($pem !== false && function_exists('openssl_x509_parse')) ? @openssl_x509_parse($pem) : false;
function ssl_dn($a) { if (!is_array($a)) return '-'; $o = array(); foreach ($a as $k => $v) $o[] = "$k=$v"; return implode(', ', $o); }
$cn = is_array($info) && isset($info['subject']['CN']) ? $info['subject']['CN'] : '-';
$issuer = is_array($info) ? ssl_dn($info['issuer'] ?? null) : '-';
$san = is_array($info) && isset($info['extensions']['subjectAltName']) ? $info['extensions']['subjectAltName'] : '-';
$validTo = is_array($info) && isset($info['validTo_time_t']) ? date('Y-m-d H:i', $info['validTo_time_t']) : '-';
$selfSigned = is_array($info) && (ssl_dn($info['subject'] ?? null) === ssl_dn($info['issuer'] ?? null));
$fp = ($pem !== false && function_exists('openssl_x509_fingerprint')) ? rtrim(strtoupper(chunk_split(openssl_x509_fingerprint($pem, 'sha256'), 2, ':')), ':') : '-';
$lastStatus = @file_get_contents($STATUS);
?>
<html>
<head><?php page_head(); ?></head>
<body>
<?php $action_url = validateActionUrl($_SERVER['PHP_SELF']); ?>
<form method=post action="<?php echo $action_url; ?>">
<?php form_csrf(); ?>
  <table width=100% border=0 cellspacing=0 cellpadding=0 align=center>
<?php
table_title('SSL / TLS Certificate', 'webserver', $note);
table_tab_head();
table_tab(array(array('SSL / TLS Certificate', 'sslsettings.php')));

table_head_fold('Current certificate', 'CUR', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
table_row('Common Name (CN)', htmlspecialchars($cn));
table_row('Issuer', htmlspecialchars($issuer) . ($selfSigned ? ' <b>(self-signed)</b>' : ''));
table_row('Subject Alternative Names', htmlspecialchars($san));
table_row('Valid until', htmlspecialchars($validTo));
table_row('SHA-256 fingerprint', '<span style="font-family:monospace;font-size:11px">' . htmlspecialchars($fp) . '</span>');
if ($lastStatus) table_row('Last apply result', htmlspecialchars(trim($lastStatus)));
table_foot_fold();

if ($isSuper) {
  table_head_fold('Install your own certificate', 'INS', null, null, array('pad' => 2, 'space' => 1, 'border' => 0, 'class' => 'tableContentInner'));
  echo '<tr><td colspan=2 class=tableRowColOther style="padding:6px 10px;color:#555">'
     . 'Paste PEM-encoded data below. The server certificate and private key are required; '
     . 'the CA chain (intermediate / root certificates) is optional but recommended so browsers can build the trust path. '
     . 'The key is written to the server only and never shown back.</td></tr>';
  ssl_textarea_row('Server certificate (PEM)', 'cert', "-----BEGIN CERTIFICATE-----\n...\n-----END CERTIFICATE-----");
  ssl_textarea_row('Private key (PEM)', 'key', "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----");
  ssl_textarea_row('CA chain / intermediates (PEM, optional)', 'chain', "-----BEGIN CERTIFICATE-----\n...(intermediate)...\n-----END CERTIFICATE-----");
  table_foot_fold();
}
table_tab_foot();

if ($isSuper) {
  echo '<table border=0 cellspacing=2 cellpadding=0 align=center><tr><td>'
     . '<input class=input-p type=submit name=apply value="Install Certificate" onclick="return confirm(\'Install this certificate and reload the web server?\')">'
     . '&nbsp;&nbsp;<input class=input-p type=submit name=regen value="Regenerate Self-Signed" onclick="return confirm(\'Replace the current certificate with a fresh self-signed one?\')">'
     . '</td></tr></table>';
} else {
  echo '<p align=center style="color:#888">You need super-user privileges to change the certificate.</p>';
}
page_foot();
?>
  </table>
</form>
</body>
</html>
<?php
/* local helper: a labelled multi-line PEM input row */
function ssl_textarea_row($label, $name, $placeholder)
{
  echo '<tr><td width=22% class=tableRowCol1><span>' . htmlspecialchars($label) . '</span></td>'
     . '<td width=78% class=tableRowColOther><textarea name="' . $name . '" rows="7" '
     . 'style="width:98%;font-family:monospace;font-size:11px" spellcheck="false" '
     . 'placeholder="' . htmlspecialchars($placeholder) . '"></textarea></td></tr>';
}
