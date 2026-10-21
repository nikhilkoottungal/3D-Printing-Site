<?php
/**
 * Generates a backup key pair. RUN THIS ON THE OWNER'S OWN DEVICE, never on the web host.
 *   php tools/keygen.php --label="Owner 1" --out=owner1.key
 * Output: <out> (private key, passphrase-protected, safe to print/store) and the PUBLIC key to paste into app/config.php
 * under backup.recipients. Passphrase: prompted, or from env BACKUP_PASSPHRASE (min 12 chars).
 */
require __DIR__ . '/lib/isbk.php';
try {
    $a = isbk_args($argv);
    $label = is_string($a['label'] ?? null) ? $a['label'] : 'owner';
    $out = is_string($a['out'] ?? null) ? $a['out'] : (preg_replace('/[^a-z0-9]+/i', '-', strtolower($label)) . '.key');
    if (file_exists($out)) { isbk_fail("$out already exists - refusing to overwrite"); }
    $p1 = isbk_prompt_secret('Choose a passphrase (12+ chars): ');
    if (strlen($p1) < 12) { isbk_fail('passphrase must be at least 12 characters'); }
    if (!getenv('BACKUP_PASSPHRASE') && isbk_prompt_secret('Repeat passphrase: ') !== $p1) { isbk_fail('passphrases do not match'); }
    $k = isbk_make_keyfile($label, $p1);
    file_put_contents($out, json_encode($k, JSON_PRETTY_PRINT));
    @chmod($out, 0600);
    echo "\nKey file written: $out   (key id {$k['kid']})\n";
    echo "PUBLIC key (put in app/config.php -> backup.recipients, it is not secret):\n  {$k['public']}\n\n";
    echo "NEXT: 1) store $out + the passphrase in your password manager (separate entries)\n";
    echo "      2) print $out and keep the paper sealed in a safe; keep the passphrase on separate paper\n";
    echo "      3) copy to an offline USB stick;  4) NEVER put it on the server, in git or in Google Drive.\n";
    echo "      5) run a test restore/verify with this key before trusting any backup.\n";
} catch (Throwable $e) { fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n"); exit(1); }
