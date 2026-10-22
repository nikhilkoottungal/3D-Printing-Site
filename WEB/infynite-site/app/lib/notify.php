<?php
/** E-mail notifications. Who receives which event is set by the Owner in Admin settings > Notifications (table is_notify_rules). */
const NOTIFY_EVENTS = [
    'order.new'      => 'A new order is entered',
    'order.status'   => 'An order changes status',
    'invoice.paid'   => 'An invoice is marked paid',
    'invoice.overdue'=> 'Invoices are overdue (daily summary)',
    'po.received'    => 'A purchase order is received',
    'stock.low'      => 'An item falls to its reorder level',
    'task.overdue'   => 'Tasks are overdue (daily summary)',
    'bill.due'       => 'Supplier bills are due or overdue (daily summary)',
];

function notify_recipients(string $event, array $also = []): array
{
    $ids = array_map('intval', array_column(all('SELECT user_id FROM is_notify_rules WHERE event=?', [$event]), 'user_id'));
    $ids = array_unique(array_merge($ids, array_map('intval', $also)));
    if (!$ids) { return []; }
    $me = PHP_SAPI === 'cli' ? 0 : (int) (user()['id'] ?? 0);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    return array_values(array_filter(all("SELECT id,name,email FROM is_users WHERE id IN ($ph) AND active=1 AND role<>'customer'", $ids), fn($r) => (int) $r['id'] !== $me));
}
/** Sends $subject/$body to everyone subscribed to $event (plus $also user ids), never to the person who caused it. */
function notify(string $event, string $subject, string $body, array $also = []): int
{
    $n = 0;
    try { foreach (notify_recipients($event, $also) as $r) { if (send_mail($r['email'], $subject, $body)) { $n++; } } }
    catch (Throwable $e) { error_log('notify failed: ' . $e->getMessage()); }
    return $n;
}
/** Mail to every active Owner (admin) except $exceptId. Used for security-relevant changes; not configurable. */
function notify_admins(string $subject, string $body, ?int $exceptId = null): int
{
    $n = 0;
    try { foreach (all("SELECT id,email FROM is_users WHERE role='owner' AND active=1") as $r) { if ((int) $r['id'] !== (int) $exceptId && send_mail($r['email'], $subject, $body)) { $n++; } } }
    catch (Throwable $e) { error_log('notify_admins failed: ' . $e->getMessage()); }
    return $n;
}
function app_url(string $path): string { return rtrim((string) cfg('base_url'), '/') . $path; }
function notify_defaults(int $userId): void
{
    foreach (array_keys(NOTIFY_EVENTS) as $ev) { q('INSERT IGNORE INTO is_notify_rules (event,user_id) VALUES (?,?)', [$ev, $userId]); }
}
