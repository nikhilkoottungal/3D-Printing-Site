<?php
function send_mail(string $to, string $subject, string $body): bool
{
    $from = cfg('mail.from');
    $name = str_replace(["\r", "\n", '"'], '', cfg('mail.from_name', cfg('app_name')));
    if (preg_match('/[\r\n]/', $to . $subject)) { return false; }   // header-injection guard

    if (cfg('mail.log_only')) {   // development only: links in this log are live secrets
        file_put_contents(cfg('storage') . '/logs/mail.log', "[" . now() . "] To: $to\nSubject: $subject\n$body\n\n", FILE_APPEND | LOCK_EX);
        return true;
    }
    $headers = "From: \"$name\" <$from>\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0";
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}
