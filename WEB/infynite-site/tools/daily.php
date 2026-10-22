<?php
/** Daily summaries by e-mail (run by deploy/infynite-daily.timer): overdue invoices and overdue tasks, to whoever subscribed in Admin settings > Notifications. */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
$_SERVER['REQUEST_URI'] = '/'; $_SERVER['REQUEST_METHOD'] = 'GET';
require dirname(__DIR__) . '/app/bootstrap.php';
$today = date('Y-m-d'); $sent = 0;
$recMade = rec_generate();

$inv = all("SELECT number, bill_name, total, due_date FROM is_invoices WHERE status='issued' AND due_date < ? ORDER BY due_date", [$today]);
if ($inv) {
    $lines = array_map(fn($i) => "{$i['number']}  {$i['bill_name']}  " . money($i['total']) . "  due " . fmt_date($i['due_date']), $inv);
    $sent += notify('invoice.overdue', count($inv) . ' overdue invoice(s)', "These invoices are past their due date:\n\n" . implode("\n", $lines) . "\n\n" . app_url('/staff/invoices?status=issued'));
}
$tasks = all("SELECT t.id, t.title, t.due_date, a.name aname FROM is_tasks t LEFT JOIN is_users a ON a.id=t.assignee_id WHERE t.status <> 'done' AND t.due_date IS NOT NULL AND t.due_date < ? ORDER BY t.due_date", [$today]);
if ($tasks) {
    $lines = array_map(fn($t) => "#{$t['id']}  {$t['title']}  (" . ($t['aname'] ?? 'unassigned') . ", due " . fmt_date($t['due_date']) . ")", $tasks);
    $sent += notify('task.overdue', count($tasks) . ' overdue task(s)', "These tasks are past their due date:\n\n" . implode("\n", $lines) . "\n\n" . app_url('/staff/tasks'));
    // each assignee also gets their own list
    $by = [];
    foreach (all("SELECT t.title, t.due_date, t.assignee_id FROM is_tasks t WHERE t.status <> 'done' AND t.due_date < ? AND t.assignee_id IS NOT NULL", [$today]) as $t) { $by[$t['assignee_id']][] = "{$t['title']} (due " . fmt_date($t['due_date']) . ')'; }
    foreach ($by as $uid => $list) {
        $u = one("SELECT email FROM is_users WHERE id=? AND active=1", [$uid]);
        if ($u && send_mail($u['email'], 'You have ' . count($list) . ' overdue task(s)', "These tasks assigned to you are overdue:\n\n" . implode("\n", $list) . "\n\n" . app_url('/staff/tasks?who=me'))) { $sent++; }
    }
}
$bills = all("SELECT b.*, s.name sname FROM is_supplier_bills b JOIN is_suppliers s ON s.id=b.supplier_id WHERE b.status='open' AND b.due_date <= ? ORDER BY b.due_date", [date('Y-m-d', strtotime('+3 days'))]);
if ($bills) {
    $lines = array_map(fn($b) => "{$b['sname']}  {$b['bill_no']}  " . money(round((float) $b['amount'] - bill_paid((int) $b['id']), 2)) . "  due " . fmt_date($b['due_date']) . ($b['due_date'] < $today ? '  (overdue)' : ''), $bills);
    $sent += notify('bill.due', count($bills) . ' supplier bill(s) due or overdue', "These supplier bills are due within 3 days or overdue:\n\n" . implode("\n", $lines) . "\n\n" . app_url('/staff/bills'));
}
$reminded = 0;
if (setting('remind_customers') === '1') {
    foreach (all("SELECT * FROM is_invoices WHERE kind='tax' AND status='issued' AND due_date < ? AND bill_email IS NOT NULL AND (last_reminder IS NULL OR last_reminder <= ?)", [$today, date('Y-m-d', strtotime('-7 days'))]) as $i) {
        if (inv_balance($i) > 0.005 && invoice_send($i, true)[0]) { $reminded++; }
    }
}
echo "daily: $recMade recurring task(s) created, $sent mail(s) sent, $reminded customer reminder(s), " . count($inv) . " overdue invoice(s), " . count($tasks) . " overdue task(s)\n";
