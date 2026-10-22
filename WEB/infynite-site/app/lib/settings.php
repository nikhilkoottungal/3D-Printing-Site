<?php
/** Business settings stored in is_settings, edited at /staff/admin. Falls back to config values / defaults. */
const SETTING_GROUPS = [
    'Company' => [
        'app_name'      => ['Display name (shown in the app and e-mails)', 'text', 120],
        'legal_name'    => ['Legal / invoice name', 'text', 160],
        'address'       => ['Address', 'textarea', 400],
        'phone'         => ['Phone', 'text', 30],
        'email'         => ['Business e-mail', 'email', 190],
        'website'       => ['Website', 'text', 120],
    ],
    'Tax' => [
        'gstin'         => ['GSTIN', 'gstin', 15],
        'pan'           => ['PAN', 'pan', 10],
        'gst_rate'      => ['Default GST rate (%)', 'percent', 6],
        'state'         => ['State / place of supply', 'text', 60],
    ],
    'Invoicing' => [
        'invoice_prefix'  => ['Invoice number prefix', 'prefix', 12],
        'invoice_next'    => ['Next invoice number', 'int', 9],
        'payment_terms'   => ['Payment terms (days)', 'int', 3],
        'bank_details'    => ['Bank details (printed on invoices)', 'textarea', 400],
        'invoice_footer'  => ['Invoice footer note', 'textarea', 400],
        'proforma_prefix' => ['Pro-forma invoice prefix', 'prefix', 12],
        'cn_prefix'       => ['Credit note prefix', 'prefix', 12],
        'remind_customers'=> ['Automatic payment reminders to customers (daily, every 7 days per invoice)', 'select', 3, ['0' => 'Off (staff send reminders by hand)', '1' => 'On (needs the customer e-mail on the invoice)']],
    ],
    'Email' => [
        'mail_mode'      => ['Sending mode', 'select', 10, ['log' => 'Log only (nothing is sent; links appear in storage/logs/mail.log)', 'smtp' => 'Send through SMTP']],
        'smtp_host'      => ['SMTP server', 'host', 120],
        'smtp_port'      => ['Port (587 for STARTTLS, 465 for SSL)', 'port', 5],
        'smtp_enc'       => ['Encryption', 'select', 5, ['tls' => 'STARTTLS (port 587)', 'ssl' => 'SSL/TLS (port 465)', 'none' => 'None (only for a trusted internal server)']],
        'smtp_user'      => ['SMTP username', 'text', 190],
        'smtp_pass'      => ['SMTP password (leave blank to keep the current one)', 'secret', 200],
        'mail_from'      => ['Sender address', 'email', 190],
        'mail_from_name' => ['Sender name', 'text', 120],
    ],
    'Security' => [
        'session_rule'  => ['When someone reloads a page', 'select', 6, ['reload' => 'Sign them out and show the login page', 'keep' => 'Keep them signed in']],
        'idle_minutes'  => ['Sign out after this many idle minutes (0 = never)', 'int', 4],
    ],
    'Pricing (calculator and quotes)' => [
        'rate_pla'        => ['Material price: PLA (per gram, Rs.)', 'money', 10],
        'rate_petg'       => ['Material price: PETG (per gram, Rs.)', 'money', 10],
        'rate_abs'        => ['Material price: ABS / ASA (per gram, Rs.)', 'money', 10],
        'rate_tpu'        => ['Material price: TPU (per gram, Rs.)', 'money', 10],
        'rate_nylon'      => ['Material price: Nylon / CF (per gram, Rs.)', 'money', 10],
        'calc_machine'    => ['Machine cost per print hour (Rs.)', 'money', 10],
        'calc_setup'      => ['Setup fee per order (Rs.)', 'money', 10],
        'calc_min'        => ['Minimum order value (Rs.)', 'money', 10],
        'calc_waste'      => ['Material wastage / supports (%)', 'percent', 6],
        'calc_margin'     => ['Profit margin added (%)', 'percent', 6],
        'calc_rush'       => ['Rush surcharge (%)', 'percent', 6],
        'quote_valid'     => ['Quotation valid for (days)', 'int', 3],
        'quote_terms'     => ['Terms printed on quotations', 'textarea', 600],
    ],
    'Production' => [
        'qc_items'        => ['Quality check list (one item per line)', 'textarea', 1200],
    ],
    'Purchasing' => [
        'po_prefix'     => ['Purchase order number prefix', 'prefix', 12],
        'po_next'       => ['Next purchase order number', 'int', 9],
    ],
];

/** Atomically takes the next number for a series (call inside a transaction). Returns prefix + 4-digit number. */
function next_number(string $nextKey, string $prefix, ?int $uid): string
{
    $row = one('SELECT svalue FROM is_settings WHERE skey=? FOR UPDATE', [$nextKey]);
    if (!$row) { q("INSERT IGNORE INTO is_settings (skey, svalue, updated_at, updated_by) VALUES (?, '1', ?, ?)", [$nextKey, now(), $uid]); $row = one('SELECT svalue FROM is_settings WHERE skey=? FOR UPDATE', [$nextKey]); }
    $n = max(1, (int) $row['svalue']);
    q('UPDATE is_settings SET svalue=?, updated_at=?, updated_by=? WHERE skey=?', [(string) ($n + 1), now(), $uid, $nextKey]);
    return $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}

function settings_all(bool $fresh = false): array
{
    static $c = null;
    if ($c === null || $fresh) {
        q("CREATE TABLE IF NOT EXISTS is_settings (skey VARCHAR(60) NOT NULL PRIMARY KEY, svalue TEXT NULL, updated_at DATETIME NOT NULL, updated_by INT UNSIGNED NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $c = [];
        foreach (all('SELECT skey, svalue FROM is_settings') as $r) { $c[$r['skey']] = $r['svalue']; }
    }
    return $c;
}
function setting(string $key, $default = null)
{
    try { $v = settings_all()[$key] ?? null; } catch (Throwable $e) { $v = null; }
    return $v === null || $v === '' ? $default : $v;
}
function setting_set(string $key, ?string $val): void
{
    $u = user();
    q('INSERT INTO is_settings (skey, svalue, updated_at, updated_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue), updated_at=VALUES(updated_at), updated_by=VALUES(updated_by)', [$key, $val, now(), $u['id'] ?? null]);
    settings_all(true);
}

/** Validate one submitted value; returns [value, error|null]. */
function setting_clean(string $type, int $max, string $v): array
{
    $v = trim(str_replace("\r\n", "\n", $v));
    if (mb_strlen($v) > $max) { return [$v, "too long (max $max)"]; }
    if ($v === '') { return ['', null]; }
    switch ($type) {
        case 'gstin': $v = strtoupper($v); return [$v, preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $v) ? null : 'not a valid 15-character GSTIN'];
        case 'pan':   $v = strtoupper($v); return [$v, preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $v) ? null : 'not a valid PAN'];
        case 'percent': return [$v, is_numeric($v) && $v >= 0 && $v <= 100 ? null : 'must be 0-100'];
        case 'int':   return [$v, ctype_digit($v) ? null : 'must be a whole number'];
        case 'prefix': return [$v, preg_match('/^[A-Za-z0-9\-\/]{1,12}$/', $v) ? null : 'letters, digits, - and / only'];
        case 'money': return [$v, is_numeric($v) && $v >= 0 && $v <= 99999999 ? null : 'must be a number, 0 or more'];
        case 'host':  return [$v, preg_match('/^[A-Za-z0-9.\-]{1,120}$/', $v) ? null : 'not a valid host name'];
        case 'port':  return [$v, ctype_digit($v) && $v >= 1 && $v <= 65535 ? null : 'must be 1-65535'];
        case 'select': return [$v, null];
        case 'secret': return [$v, null];
        case 'email': return [$v, filter_var($v, FILTER_VALIDATE_EMAIL) ? null : 'not a valid e-mail'];
    }
    return [$v, null];
}
