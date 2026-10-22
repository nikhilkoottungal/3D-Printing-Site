<?php
/** Brings an existing database up to the current schema (idempotent). Runs once per schema version, tracked in is_settings. */
const SCHEMA_VERSION = '12';
function migrate(): void
{
    try {
        if ((settings_all()['schema_v'] ?? '') === SCHEMA_VERSION) { return; }
        $sql = preg_replace('/^--.*$/m', '', (string) file_get_contents(APP_ROOT . '/sql/schema.sql'));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if (stripos($stmt, 'CREATE TABLE') === 0) { q($stmt); }
        }
        foreach (['address' => 'TEXT NULL', 'gstin' => 'VARCHAR(15) NULL', 'state' => 'VARCHAR(60) NULL', 'totp_secret' => 'TEXT NULL', 'totp_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0', 'totp_last' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'recovery_codes' => 'TEXT NULL'] as $col => $def) {
            if (!one('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=\'is_users\' AND column_name=?', [$col])) { q("ALTER TABLE is_users ADD COLUMN $col $def"); }
        }
        foreach ([['is_requests', 'due_date', 'DATE NULL'], ['is_requests', 'courier', 'VARCHAR(80) NULL'], ['is_requests', 'tracking', 'VARCHAR(80) NULL'], ['is_requests', 'dispatched_at', 'DATETIME NULL'], ['is_inventory_items', 'grams_per_unit', 'DECIMAL(10,2) NOT NULL DEFAULT 1000'], ['is_invoices', 'kind', "VARCHAR(8) NOT NULL DEFAULT 'tax'"], ['is_invoices', 'last_reminder', 'DATE NULL'], ['is_users', 'frozen', 'TINYINT(1) NOT NULL DEFAULT 0'], ['is_users', 'avatar', 'CHAR(40) NULL']] as [$tb, $col, $def]) {
            if (!one('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?', [$tb, $col])) { q("ALTER TABLE $tb ADD COLUMN $col $def"); }
        }
        // v7: invoices paid before partial payments existed get one full payment row, so balances add up
        q("INSERT INTO is_invoice_payments (invoice_id,amount,paid_on,method,ref,by_user,at) SELECT i.id, i.total, COALESCE(i.paid_at, i.issue_date), COALESCE(i.paid_method,'Recorded'), i.paid_ref, NULL, NOW() FROM is_invoices i WHERE i.status='paid' AND NOT EXISTS (SELECT 1 FROM is_invoice_payments p WHERE p.invoice_id=i.id)");
        // a task keeps working after its creator is deleted
        if (one("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='is_tasks' AND column_name='created_by' AND is_nullable='NO'")) { q('ALTER TABLE is_tasks MODIFY created_by INT UNSIGNED NULL'); }
        // v4: the admin key becomes per-admin. The old single key goes to the first owner.
        $old = one("SELECT svalue FROM is_settings WHERE skey='admin_key_hash'");
        if ($old && $old['svalue'] !== null) {
            $first = one("SELECT id FROM is_users WHERE role='owner' ORDER BY id LIMIT 1");
            if ($first) {
                q('INSERT IGNORE INTO is_settings (skey, svalue, updated_at) VALUES (?,?,?)', ['admin_key:' . $first['id'], $old['svalue'], now()]);
                q("DELETE FROM is_settings WHERE skey='admin_key_hash'");
            }
        }
        // An alert type that nobody has rules for yet (new in this version) goes to the owners.
        foreach (array_keys(NOTIFY_EVENTS) as $ev) { if (!one('SELECT 1 FROM is_notify_rules WHERE event=? LIMIT 1', [$ev]) && one('SELECT 1 FROM is_notify_rules LIMIT 1')) { foreach (all("SELECT id FROM is_users WHERE role='owner' AND active=1") as $o) { q('INSERT IGNORE INTO is_notify_rules (event,user_id) VALUES (?,?)', [$ev, $o['id']]); } } }
        // First time: owners receive every notification until the Owner changes the rules.
        if (!one('SELECT 1 FROM is_notify_rules LIMIT 1')) { foreach (all("SELECT id FROM is_users WHERE role='owner'") as $o) { notify_defaults((int) $o['id']); } }
        setting_set('schema_v', SCHEMA_VERSION);
    } catch (Throwable $e) {
        error_log('migrate failed: ' . $e->getMessage());
    }
}
