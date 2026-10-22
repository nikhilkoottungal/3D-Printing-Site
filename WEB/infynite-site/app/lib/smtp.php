<?php
/** Minimal SMTP client (no dependencies). enc: 'ssl' (implicit TLS, usually 465), 'tls' (STARTTLS, usually 587) or 'none'. Returns [ok, error]. */
function smtp_send(array $c, string $to, string $subject, string $body, ?array $attach = null): array
{
    $host = (string) $c['host']; $port = (int) $c['port']; $enc = (string) $c['enc'];
    $errno = 0; $errstr = '';
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client(($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { return [false, "cannot connect to $host:$port ($errstr)"]; }
    stream_set_timeout($fp, 20);
    $read = function () use ($fp): array {
        $code = 0; $text = '';
        while (($l = fgets($fp, 1024)) !== false) {
            $text .= $l; $code = (int) substr($l, 0, 3);
            if (strlen($l) < 4 || $l[3] !== '-') { break; }
        }
        return [$code, trim($text)];
    };
    $cmd = function (string $line, array $ok) use ($fp, $read): ?string {
        fwrite($fp, $line . "\r\n"); [$code, $text] = $read();
        return in_array($code, $ok, true) ? null : "server said: $text";
    };
    try {
        [$code, $text] = $read();
        if ($code !== 220) { return [false, "unexpected greeting: $text"]; }
        $ehlo = 'infynite.local';
        if ($e = $cmd("EHLO $ehlo", [250])) { return [false, $e]; }
        if ($enc === 'tls') {
            if ($e = $cmd('STARTTLS', [220])) { return [false, $e]; }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { return [false, 'TLS handshake failed']; }
            if ($e = $cmd("EHLO $ehlo", [250])) { return [false, $e]; }
        }
        if ((string) $c['user'] !== '') {
            if ($e = $cmd('AUTH LOGIN', [334])) { return [false, $e]; }
            if ($e = $cmd(base64_encode((string) $c['user']), [334])) { return [false, $e]; }
            if ($e = $cmd(base64_encode((string) $c['pass']), [235])) { return [false, 'login refused: ' . $e]; }
        }
        $from = (string) $c['from'];
        if ($e = $cmd("MAIL FROM:<$from>", [250])) { return [false, $e]; }
        if ($e = $cmd("RCPT TO:<$to>", [250, 251])) { return [false, $e]; }
        if ($e = $cmd('DATA', [354])) { return [false, $e]; }
        $name = str_replace(['"', "\r", "\n"], '', (string) $c['from_name']);
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
        $common = "From: \"$name\" <$from>\r\nTo: <$to>\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\nDate: " . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(10)) . "@$domain>\r\nMIME-Version: 1.0\r\n";
        $textPart = "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
        if ($attach) {
            $b = 'b' . bin2hex(random_bytes(12)); $fn = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $attach['name']);
            $payload = $common . "Content-Type: multipart/mixed; boundary=\"$b\"\r\n\r\n--$b\r\n" . $textPart . "--$b\r\nContent-Type: " . $attach['type'] . "; name=\"$fn\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$fn\"\r\n\r\n" . chunk_split(base64_encode((string) $attach['data']), 76, "\r\n") . "--$b--\r\n";
        } else { $payload = $common . $textPart; }
        fwrite($fp, $payload . ".\r\n"); [$code, $text] = $read();
        if ($code !== 250) { return [false, "message refused: $text"]; }
        $cmd('QUIT', [221]);
        return [true, null];
    } finally {
        @fclose($fp);
    }
}
