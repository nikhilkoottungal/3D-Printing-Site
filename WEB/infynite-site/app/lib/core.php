<?php
const ROLES = ['owner' => 'Owner', 'sales' => 'Sales', 'production' => 'Production', 'purchasing' => 'Purchasing'];
const ROLE_DESC = [
    'owner'      => 'Full access: team, roles, every order, task and procedure.',
    'sales'      => 'Reviews enquiries, prices and sends quotations, handles customers.',
    'production' => 'Schedules and runs print jobs, quality check, dispatch.',
    'purchasing' => 'Manages inventory and reordering; supplier purchase orders in Dolibarr; reads orders.',
];
const ROLE_PERMS = [
    'owner'      => ['*'],
    'sales'      => ['requests.view', 'requests.quote', 'requests.create'],
    'production' => ['requests.view', 'requests.production', 'requests.create', 'stock.use'],
    'purchasing' => ['requests.view', 'stock.manage', 'stock.use'],
];

const STATUSES = [
    'requested' => 'Requested', 'under_review' => 'Under review', 'quoted' => 'Quoted', 'approved' => 'Approved',
    'scheduled' => 'Scheduled', 'printing' => 'Printing', 'quality_check' => 'Quality check',
    'dispatched' => 'Ready / Dispatched', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'failed' => 'Failed',
];
const STATUS_FLOW = ['requested', 'under_review', 'quoted', 'approved', 'scheduled', 'printing', 'quality_check', 'dispatched', 'completed'];
const STATUS_PERM = [
    'requested' => 'requests.quote', 'under_review' => 'requests.quote', 'quoted' => 'requests.quote',
    'approved' => 'requests.quote', 'cancelled' => 'requests.quote',
    'scheduled' => 'requests.production', 'printing' => 'requests.production', 'quality_check' => 'requests.production',
    'dispatched' => 'requests.production', 'completed' => 'requests.production', 'failed' => 'requests.production',
];

const TASK_STATUSES = ['todo' => 'To do', 'in_progress' => 'In progress', 'blocked' => 'Blocked', 'review' => 'In review', 'done' => 'Done'];
const TASK_PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];
const STOCK_CATEGORIES = ['filament' => 'Filament', 'consumable' => 'Consumable', 'spare' => 'Spare part', 'other' => 'Other'];
const STOCK_REASONS = ['purchase' => 'Received (purchase)', 'use' => 'Used in production', 'waste' => 'Waste / failed print', 'return' => 'Returned to stock', 'adjust' => 'Stock-take correction'];
const MATERIALS = ['PLA', 'PETG', 'ABS / ASA', 'TPU', 'Nylon / CF'];
const COLORS = ['Black', 'White', 'Grey', 'Red', 'Blue', 'Green', 'Other (see notes)'];

function cfg(string $key, $default = null)
{
    $v = $GLOBALS['CFG'];
    foreach (explode('.', $key) as $p) {
        if (!is_array($v) || !array_key_exists($p, $v)) {
            return $default;
        }
        $v = $v[$p];
    }
    return $v;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = cfg('db');
        $pdo = new PDO(
            'mysql:host=' . $c['host'] . ';port=' . ($c['port'] ?? 3306) . ';dbname=' . $c['name'] . ';charset=utf8mb4',
            $c['user'],
            $c['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
    }
    return $pdo;
}
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function one(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r ?: null; }
function all(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function insert(string $sql, array $p = []): int { q($sql, $p); return (int) db()->lastInsertId(); }

function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function now(): string { return date('Y-m-d H:i:s'); }
function client_ip(): string { return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45); }
function fmt_date(?string $d): string { return $d ? date('d M Y', strtotime($d)) : '—'; }
function fmt_dt(?string $d): string { return $d ? date('d M Y, H:i', strtotime($d)) : '—'; }
function fmt_bytes(int $b): string
{
    if ($b >= 1048576) { return round($b / 1048576, 1) . ' MB'; }
    if ($b >= 1024) { return round($b / 1024) . ' KB'; }
    return $b . ' B';
}
function money($v): string { return $v === null || $v === '' ? '—' : '₹' . number_format((float) $v, 2); }

function redirect(string $to): void
{
    // Keep application redirects inside the configured mount point when hosted
    // from a subfolder (for example, /infynite-test).
    if (str_starts_with($to, '/') && !str_starts_with($to, '//')) {
        $mountPath = rtrim((string) parse_url((string) cfg('base_url'), PHP_URL_PATH), '/');
        if ($mountPath !== '' && $to !== $mountPath && !str_starts_with($to, $mountPath . '/')) {
            $to = $mountPath . $to;
        }
    }
    header('Location: ' . $to);
    exit;
}
function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'][] = [$type, $msg]; }
function take_flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || $t === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
        http_response_code(419);
        exit('Session expired or invalid form token. Go back, reload the page and try again.');
    }
}

function render(string $__view, array $__vars = [], string $__layout = 'site'): void
{
    extract($__vars, EXTR_SKIP);
    ob_start();
    require APP_ROOT . '/app/views/' . $__view . '.php';
    $content = ob_get_clean();
    require APP_ROOT . '/app/views/layout_' . $__layout . '.php';
}

function abort(int $code, string $msg = ''): void
{
    http_response_code($code);
    $title = $code === 404 ? 'Not found' : ($code === 403 ? 'Not allowed' : 'Error');
    render('error', ['code' => $code, 'msg' => $msg ?: $title, 'title' => $title], is_staff() ? 'staff' : (user() ? 'app' : 'site'));
    exit;
}

function audit(string $action, string $detail = ''): void
{
    $u = user();
    q('INSERT INTO is_audit_log (user_id, action, detail, ip, at) VALUES (?,?,?,?,?)', [$u['id'] ?? null, $action, mb_substr($detail, 0, 500), client_ip(), now()]);
}

function rate_limited(string $kind, string $email, int $maxIp = 20, int $maxEmail = 6, int $minutes = 15): bool
{
    $since = date('Y-m-d H:i:s', time() - $minutes * 60);
    $byIp = (int) one('SELECT COUNT(*) c FROM is_login_attempts WHERE kind=? AND ip=? AND at>?', [$kind, client_ip(), $since])['c'];
    $byEmail = (int) one('SELECT COUNT(*) c FROM is_login_attempts WHERE kind=? AND email=? AND at>?', [$kind, $email, $since])['c'];
    return $byIp >= $maxIp || $byEmail >= $maxEmail;
}
function note_attempt(string $kind, string $email): void
{
    q('INSERT INTO is_login_attempts (ip, email, kind, at) VALUES (?,?,?,?)', [client_ip(), substr($email, 0, 190), $kind, now()]);
    q('DELETE FROM is_login_attempts WHERE at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
}

function valid_date(string $d): bool
{
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}
function slugify(string $s): string
{
    $s = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $s), '-'));
    $s = $s !== '' ? substr($s, 0, 100) : 'article';
    return $s === 'new' ? 'new-article' : $s;
}
function badge(string $text, string $cls = ''): string { return '<span class="pill ' . e($cls) . '">' . e($text) . '</span>'; }
function status_badge(string $s): string
{
    $cls = in_array($s, ['completed'], true) ? 'p-ok' : (in_array($s, ['cancelled', 'failed'], true) ? 'p-bad' : (in_array($s, ['quoted'], true) ? 'p-warn' : 'p-info'));
    return badge(STATUSES[$s] ?? $s, $cls);
}
function task_badge(string $s): string
{
    $cls = ['done' => 'p-ok', 'blocked' => 'p-bad', 'review' => 'p-warn', 'in_progress' => 'p-info', 'todo' => ''][$s] ?? '';
    return badge(TASK_STATUSES[$s] ?? $s, $cls);
}
function prio_badge(string $p): string
{
    $cls = ['urgent' => 'p-bad', 'high' => 'p-warn', 'normal' => '', 'low' => ''][$p] ?? '';
    return badge(TASK_PRIORITIES[$p] ?? $p, $cls);
}
function options(array $map, $selected = null): string
{
    $o = '';
    foreach ($map as $k => $label) {
        if (is_int($k)) { $k = $label; }
        $o .= '<option value="' . e($k) . '"' . ((string) $k === (string) $selected ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $o;
}
