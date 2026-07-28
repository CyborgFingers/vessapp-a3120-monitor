<?php
/*******************************************************************************
 * opas_client.php - Linux transport shim to the opasd UNIX-socket daemon.
 *
 * Replaces the Windows php_i2 in-process engine calls for the adapted API
 * endpoints (api/login.php, api/opas.php). Frame (see docs/RE-opas.md §2):
 *   [ magic:u32 LE = 0xBABA0001 ][ len:u32 LE ][ body ]
 * Request body = "key=value\n" lines, first line "method=NAME".
 * Response body = a JSON object (decoded and returned as an assoc array).
 *
 * Socket path: $OPASD_SOCK, else /tmp/opasd.sock.
 ******************************************************************************/

define('OPAS_MAGIC', 0xBABA0001);

/* Read exactly $n bytes from the stream (fread can short-read). */
function opas_read($fp, $n)
{
    $buf = '';
    while (strlen($buf) < $n) {
        $chunk = fread($fp, $n - strlen($buf));
        if ($chunk === false || $chunk === '') break;
        $buf .= $chunk;
    }
    return $buf;
}

/*
 * Call opasd. $method is the verb; $params a flat key=>value map (e.g.
 * ['sid'=>123]). Returns the decoded response array, or null on transport error.
 */
function opas_call($method, $params = array())
{
    $sock = getenv('OPASD_SOCK');
    if (!$sock) $sock = '/tmp/opasd.sock';

    $fp = @stream_socket_client('unix://' . $sock, $errno, $errstr, 5);
    if (!$fp) {
        error_log("opas_call($method): cannot reach opasd at $sock: $errstr");
        return null;
    }

    $body = "method=$method\n";
    foreach ($params as $k => $v) $body .= $k . '=' . $v . "\n";

    fwrite($fp, pack('VV', OPAS_MAGIC, strlen($body)) . $body);

    $hdr = opas_read($fp, 8);
    if (strlen($hdr) < 8) { fclose($fp); return null; }
    $h = unpack('Vmagic/Vlen', $hdr);
    if ($h['magic'] != OPAS_MAGIC || $h['len'] > 1048576) { fclose($fp); return null; }

    $resp = $h['len'] > 0 ? opas_read($fp, $h['len']) : '';
    fclose($fp);
    return json_decode($resp, true);
}
