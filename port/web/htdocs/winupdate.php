<?php
/* winupdate.php - update check for the WebPAM monitor.
 *
 * Compares the running version (/opt/promise/share/VERSION, baked into the image)
 * against the latest GitHub release, with a cached result so logins do not hammer
 * the API. Used by home.php (the login banner) and update.php (the update page).
 * Every failure path is silent - show no banner rather than a broken page.
 */
if (!defined('WINUPDATE_REPO')) {
    define('WINUPDATE_REPO', getenv('VESSAPP_UPDATE_REPO') ?: 'Weta-Technologies/vessapp-a3120-monitor');
}
define('WINUPDATE_VERSION_FILE', '/opt/promise/share/VERSION');
define('WINUPDATE_CACHE',   '/run/promise/update.check.json');
define('WINUPDATE_STATUS',  '/run/promise/update.status');
define('WINUPDATE_REQUEST', '/run/promise/update.request');
define('WINUPDATE_TTL', 6 * 3600);

function winupdate_current(): string {
    $v = @file_get_contents(WINUPDATE_VERSION_FILE);
    return $v !== false ? trim($v) : '0.0.0';
}

/* HTTPS GET via a stream context (no shell). Needs allow_url_fopen (PHP default
 * on); if your php.ini disables it the check is simply skipped. '' on any error. */
function winupdate_fetch(string $url): string {
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: webpam-monitor-updater\r\nAccept: application/vnd.github+json\r\n",
            'timeout' => 8,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $out = @file_get_contents($url, false, $ctx);
    return is_string($out) ? $out : '';
}

/* Latest release tag from GitHub, cached in /run. '' on any error. */
function winupdate_latest(): string {
    $cache = @json_decode(@file_get_contents(WINUPDATE_CACHE), true);
    if (is_array($cache) && isset($cache['t'], $cache['tag']) && (time() - $cache['t'] < WINUPDATE_TTL)) {
        return (string)$cache['tag'];
    }
    $tag = '';
    $json = winupdate_fetch('https://api.github.com/repos/' . WINUPDATE_REPO . '/releases/latest');
    if ($json !== '') {
        $d = json_decode($json, true);
        if (is_array($d) && !empty($d['tag_name'])) $tag = (string)$d['tag_name'];
    }
    if ($tag !== '') @file_put_contents(WINUPDATE_CACHE, json_encode(['t' => time(), 'tag' => $tag]));
    return $tag;
}

/* ['current'=>, 'latest'=>, 'tag'=>, 'available'=>bool, 'releases'=>url]. */
function winupdate_check(): array {
    $cur    = winupdate_current();
    $tag    = winupdate_latest();
    $latest = ltrim($tag, 'vV');
    return [
        'current'   => $cur,
        'latest'    => $latest,
        'tag'       => $tag,
        'available' => $tag !== '' && version_compare($latest, $cur, '>'),
        'releases'  => 'https://github.com/' . WINUPDATE_REPO . '/releases',
    ];
}

function winupdate_status(): string {
    $s = @file_get_contents(WINUPDATE_STATUS);
    return $s !== false ? trim($s) : '';
}

/* One-line banner for the landing page when an update is available / in progress. */
function winupdate_banner(): void {
    $st = winupdate_status();
    $c  = winupdate_check();
    if (!$c['available'] && $st === '') return;
    $msg = $c['available']
        ? 'WebPAM update available: ' . htmlspecialchars($c['current']) . ' &rarr; ' . htmlspecialchars($c['latest'])
        : 'WebPAM update: ' . htmlspecialchars($st);
    echo '<div style="background:#1f6feb;color:#fff;text-align:center;padding:8px;'
       . 'font:13px/1.4 sans-serif;">' . $msg
       . ' &nbsp; <a href="update.php" style="color:#fff;font-weight:bold;">Update now &raquo;</a></div>';
}
