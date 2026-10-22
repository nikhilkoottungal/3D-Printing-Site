<?php
/** Mail settings come from Admin settings (mail_mode, smtp_*); app/config.php 'mail' is the fallback / default sender. */
function mail_config(): array
{
    return ['mode' => (string) setting('mail_mode', cfg('mail.log_only') ? 'log' : 'smtp'), 'host' => (string) setting('smtp_host', ''), 'port' => (int) setting('smtp_port', 587),
        'enc' => (string) setting('smtp_enc', 'tls'), 'user' => (string) setting('smtp_user', ''), 'pass' => (string) (secret_decrypt(setting('smtp_pass')) ?? ''),
        'from' => (string) setting('mail_from', cfg('mail.from')), 'from_name' => (string) setting('mail_from_name', setting('app_name', cfg('app_name')))];
}
function mail_log(string $to, string $subject, string $body, string $why = ''): void
{
    file_put_contents(cfg('storage') . '/logs/mail.log', "[" . now() . "] To: $to\nSubject: $subject\n" . ($why !== '' ? "(not sent: $why)\n" : '') . "$body\n\n", FILE_APPEND | LOCK_EX);
}
/** Sends one mail. In 'log' mode (or when SMTP is incomplete/failing) the mail is written to storage/logs/mail.log instead. */
function send_mail(string $to, string $subject, string $body, ?string &$error = null, ?array $attach = null): bool
{
    if (preg_match('/[\r\n]/', $to . $subject) || !filter_var($to, FILTER_VALIDATE_EMAIL)) { $error = 'invalid recipient'; return false; }   // header-injection guard
    if (str_ends_with($to, '@walkin.invalid')) { return false; }
    $c = mail_config();
    if ($c['mode'] !== 'smtp') { mail_log($to, $subject, $body . ($attach ? "\n[attachment: {$attach['name']}]" : '')); return true; }
    if ($c['host'] === '' || !filter_var($c['from'], FILTER_VALIDATE_EMAIL)) { $error = 'SMTP is not fully configured'; mail_log($to, $subject, $body, $error); return false; }
    [$ok, $err] = smtp_send($c, $to, $subject, $body, $attach);
    if (!$ok) { $error = $err; error_log("smtp: $err"); mail_log($to, $subject, $body, (string) $err); }
    return $ok;
}
