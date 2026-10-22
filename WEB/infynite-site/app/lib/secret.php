<?php
/** Encrypts secrets kept in the database (SMTP password, 2FA seeds). The key lives in storage/secret.key (not in the DB). */
function secret_key(): string
{
    static $k = null;
    if ($k !== null) { return $k; }
    $f = cfg('storage') . '/secret.key';
    if (!is_file($f)) {
        $raw = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        file_put_contents($f, bin2hex($raw), LOCK_EX);
        @chmod($f, 0600);
    }
    $k = (string) hex2bin(trim((string) file_get_contents($f)));
    if (strlen($k) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) { throw new RuntimeException('storage/secret.key is invalid'); }
    return $k;
}
function secret_encrypt(string $plain): string
{
    $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($n . sodium_crypto_secretbox($plain, $n, secret_key()));
}
function secret_decrypt(?string $enc): ?string
{
    if ($enc === null || $enc === '') { return null; }
    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) { return null; }
    $p = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), secret_key());
    return $p === false ? null : $p;
}
