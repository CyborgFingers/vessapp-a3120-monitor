<?php
/* update.php - WebPAM update page.
 *
 * Shows the installed version vs the latest GitHub release and, when newer,
 * offers "Update now". Applying only QUEUES the update (writes a request file in
 * /run); the root-side applier (promise-webpam-update, driven by the entrypoint)
 * performs the pull + restart, so the unprivileged web tier never updates itself.
 * Session-guarded: only a logged-in WebPAM user can queue an update.
 */
include 'tree.php';
include 'common.php';
require_once 'winupdate.php';

if (!isset($_SESSION)) session_start();
if (!isset($_SESSION['sessionService'])) { header('Location: /index.php'); exit; }

$c = winupdate_check();

if (isset($_POST['apply']) && $c['available']) {
    // Queue for the root applier. The tag is validated against the live check
    // above, so only the real latest release can be requested.
    @file_put_contents(WINUPDATE_REQUEST, $c['tag'] . "\n");
    @file_put_contents(WINUPDATE_STATUS, 'queued (' . $c['current'] . ' -> ' . $c['latest'] . ')');
    header('Location: update.php?queued=1');
    exit;
}
?>
<html>
<head><?php page_head(); ?></head>
<body>
  <table width="100%" border="0" cellspacing="0" cellpadding="0" align="center">
    <tr><td class="tableTitle">&nbsp;WebPAM Update</td></tr>
    <tr><td style="padding:16px;">
<?php
$status = winupdate_status();
if (isset($_GET['queued']) || $status !== '') {
    echo '<p><b>Update ' . htmlspecialchars($status ?: 'queued') . '.</b> The app pulls the new image '
       . 'and restarts; your settings - login, fan calibration, front-panel config - are kept on the '
       . 'persistent volume. This page reconnects on the new version.</p>';
} elseif ($c['available']) {
    echo '<p>Installed version: <b>' . htmlspecialchars($c['current']) . '</b></p>';
    echo '<p>Latest version: <b>' . htmlspecialchars($c['latest']) . '</b> &nbsp;'
       . '<a href="' . htmlspecialchars($c['releases']) . '" target="_blank">release notes</a></p>';
    echo '<form method="post" action="update.php">';
    form_csrf();
    echo '<input type="submit" name="apply" value="Update now" class="input-p-login"></form>';
    echo '<p style="color:#666;font-size:12px;margin-top:12px;">The update does not overwrite your '
       . 'settings. On TrueNAS it uses the app updater; if it does not complete, use '
       . 'Apps &rarr; Update for this app in the TrueNAS UI.</p>';
} else {
    echo '<p>WebPAM is up to date (version <b>' . htmlspecialchars($c['current']) . '</b>).</p>';
}
?>
    </td></tr>
  </table>
<?php page_foot(); ?>
</body>
</html>
