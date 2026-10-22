<?php
/*******************************************************************************
 * certhelp.php - standalone (pre-login) TLS certificate helper.
 *
 * WebPAM serves HTTPS with a self-signed certificate (no public CA can issue a
 * trusted cert for a private IP). This page explains the one-time browser
 * warning and lets you download + trust the certificate so it stops. It serves
 * ONLY the public certificate and includes no app code, so it needs no login.
 ******************************************************************************/
$CRT = '/etc/promise/webpam.crt';

if (isset($_GET['get']) && $_GET['get'] === 'crt') {
  $pem = @file_get_contents($CRT);
  if ($pem === false) { http_response_code(404); exit('certificate not found'); }
  header('Content-Type: application/x-x509-ca-cert');
  header('Content-Disposition: attachment; filename="webpam-monitor.crt"');
  echo $pem;
  exit;
}

$pem = @file_get_contents($CRT);
$fp = '';
if ($pem !== false && function_exists('openssl_x509_fingerprint')) {
  $raw = openssl_x509_fingerprint($pem, 'sha256');
  if ($raw) $fp = rtrim(strtoupper(chunk_split($raw, 2, ':')), ':');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>WebPAM — Certificate</title>
  <style>
    :root { color-scheme: light dark; }
    body { font: 15px/1.55 -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
           margin: 0; background: #f2f3f5; color: #1c1e21; }
    .card { max-width: 640px; margin: 6vh auto; background: #fff; border: 1px solid #dadde1;
            border-radius: 10px; padding: 28px 32px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
    h1 { font-size: 20px; margin: 0 0 4px; }
    h2 { font-size: 15px; margin: 22px 0 6px; }
    p, li { color: #3a3b3c; }
    code, .fp { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    .fp { display: block; background: #f0f2f5; border: 1px solid #dadde1; border-radius: 6px;
          padding: 8px 10px; font-size: 12px; word-break: break-all; margin: 6px 0 0; }
    .btn { display: inline-block; background: #1877f2; color: #fff; text-decoration: none;
           padding: 9px 16px; border-radius: 6px; font-weight: 600; }
    .btn:hover { background: #166fe0; }
    .muted { color: #65676b; font-size: 13px; }
    a { color: #1877f2; }
    @media (prefers-color-scheme: dark) {
      body { background: #18191a; color: #e4e6eb; }
      .card { background: #242526; border-color: #3a3b3c; }
      p, li { color: #b0b3b8; } .fp { background: #3a3b3c; border-color: #4e4f50; color: #e4e6eb; }
    }
  </style>
</head>
<body>
  <div class="card">
    <h1>Certificate &amp; the “not secure” warning</h1>
    <p>WebPAM protects this page with its <b>own self-signed certificate</b>. A public
    authority like Let’s Encrypt can’t issue a trusted certificate for a device
    reached by its IP address, so your browser shows a one-time warning. The
    connection is still <b>fully encrypted</b> — the warning only means the browser
    hasn’t been told to trust this specific server yet.</p>

    <h2>Option 1 — just proceed (quickest)</h2>
    <p class="muted">On the warning screen choose <b>Advanced → Proceed / Continue</b>.
    This is safe because it is your own server’s certificate — confirm the fingerprint
    below matches if you want to be certain.</p>

    <h2>Option 2 — trust it once (no more warnings)</h2>
    <p><a class="btn" href="certhelp.php?get=crt">Download certificate (.crt)</a></p>
    <ul>
      <li><b>Windows:</b> double-click the file → <i>Install Certificate</i> → <i>Local
        Machine</i> → place in <i>Trusted Root Certification Authorities</i>.</li>
      <li><b>macOS:</b> open in <i>Keychain Access</i> (System keychain) → set it to
        <i>Always Trust</i>.</li>
      <li><b>iOS:</b> install the profile → Settings → <i>General → About → Certificate
        Trust Settings</i> → enable it.</li>
      <li><b>Linux / Firefox:</b> import under the browser’s Authorities, or add to
        <code>/usr/local/share/ca-certificates</code> and run <code>update-ca-certificates</code>.</li>
    </ul>
    <?php if ($fp): ?>
      <p class="muted"><b>SHA-256 fingerprint</b> (verify this matches the browser’s):</p>
      <span class="fp"><?php echo htmlspecialchars($fp); ?></span>
    <?php endif; ?>

    <p style="margin-top:24px"><a href="index.php">← Back to sign in</a></p>
  </div>
</body>
</html>
