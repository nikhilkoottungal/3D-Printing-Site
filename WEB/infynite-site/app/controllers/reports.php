<?php
/** Reports for Owner and Sales ('reports.view'). Financial year defaults to 1 Apr - today (India). */
function report_range(): array
{
    $y = (int) date('n') < 4 ? 1 : 0;   // Jan-Mar: the FY started last year
    $from = (string) ($_GET['from'] ?? ''); $to = (string) ($_GET['to'] ?? '');
    if (!valid_date($from)) { $from = ((int) date('Y') - $y) . '-04-01'; }
    if (!valid_date($to)) { $to = date('Y-m-d'); }
    return [$from, $to];
}
function report_page(): void
{
    $u = require_staff('reports.view'); invoice_ensure_tables();
    [$from, $to] = report_range();
    $p = [$from, $to];
    $tot = one("SELECT COUNT(*) n, COALESCE(SUM(subtotal),0) taxable, COALESCE(SUM(cgst),0) cgst, COALESCE(SUM(sgst),0) sgst, COALESCE(SUM(igst),0) igst, COALESCE(SUM(total),0) total FROM is_invoices WHERE kind='tax' AND status<>'void' AND issue_date BETWEEN ? AND ?", $p);
    $tot['credits'] = (float) one('SELECT COALESCE(SUM(total),0) s FROM is_credit_notes WHERE issue_date BETWEEN ? AND ?', $p)['s'];
    $tot['paid'] = (float) one('SELECT COALESCE(SUM(amount),0) s FROM is_invoice_payments WHERE paid_on BETWEEN ? AND ?', $p)['s'];
    $months = all("SELECT DATE_FORMAT(issue_date,'%Y-%m') m, COUNT(*) n, SUM(subtotal) taxable, SUM(cgst+sgst+igst) gst, SUM(total) total FROM is_invoices WHERE kind='tax' AND status<>'void' AND issue_date BETWEEN ? AND ? GROUP BY m ORDER BY m", $p);
    $top = all("SELECT bill_name, COUNT(*) n, SUM(total) total FROM is_invoices WHERE kind='tax' AND status<>'void' AND issue_date BETWEEN ? AND ? GROUP BY bill_name ORDER BY total DESC LIMIT 10", $p);
    $age = ['current_' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd60p' => 0.0]; $unpaid = 0.0; $today = date('Y-m-d');
    foreach (all("SELECT * FROM is_invoices WHERE kind='tax' AND status='issued'") as $i) {
        $bal = inv_balance($i); if ($bal <= 0.005) { continue; } $unpaid += $bal; $late = (int) ((strtotime($today) - strtotime($i['due_date'])) / 86400);
        $age[$late <= 0 ? 'current_' : ($late <= 30 ? 'd30' : ($late <= 60 ? 'd60' : 'd60p'))] += $bal;
    }
    $tot['unpaid'] = $unpaid;
    $orders = all("SELECT status, COUNT(*) n FROM is_requests WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY status", $p);
    $stock = one("SELECT COUNT(*) n, COALESCE(SUM(qty*COALESCE(unit_cost,0)),0) value, SUM(qty<=reorder_level AND reorder_level>0) low FROM is_inventory_items WHERE active=1");
    $po = one("SELECT COUNT(*) n, COALESCE(SUM(total),0) value FROM is_purchase_orders WHERE status='ordered'");
    $pay = ['owed' => 0.0, 'overdue' => 0.0, 'n' => 0];
    foreach (all("SELECT * FROM is_supplier_bills WHERE status='open'") as $b) { $bal = round((float) $b['amount'] - bill_paid((int) $b['id']), 2); if ($bal <= 0.005) { continue; } $pay['owed'] += $bal; $pay['n']++; if ($b['due_date'] < $today) { $pay['overdue'] += $bal; } }
    $jobs = one("SELECT COUNT(*) n, COALESCE(SUM(actual_hours),0) h, COALESCE(SUM(material_cost+machine_cost),0) c FROM is_print_jobs WHERE status='done' AND DATE(finished_at) BETWEEN ? AND ?", $p);
    $issues = all("SELECT kind, COUNT(*) n FROM is_order_issues WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY kind", $p);
    render('staff/reports', ['title' => 'Reports', 'nav' => 'reports', 'from' => $from, 'to' => $to, 'tot' => $tot, 'months' => $months, 'top' => $top, 'age' => $age, 'orders' => $orders, 'stock' => $stock, 'po' => $po, 'pay' => $pay, 'jobs' => $jobs, 'issues' => $issues, 'u' => $u], 'staff');
}
function csv_cell($v): string
{
    $v = (string) $v;
    if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) { $v = "'" . $v; }
    return '"' . str_replace('"', '""', $v) . '"';
}
function report_csv(): void
{
    $u = require_staff('reports.view'); invoice_ensure_tables();
    [$from, $to] = report_range();
    $rows = all('SELECT i.*, COALESCE((SELECT SUM(amount) FROM is_invoice_payments p WHERE p.invoice_id=i.id),0) paid FROM is_invoices i WHERE i.issue_date BETWEEN ? AND ? ORDER BY i.id', [$from, $to]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="invoices-' . $from . '-to-' . $to . '.csv"');
    echo "\xEF\xBB\xBF" . implode(',', array_map('csv_cell', ['Number', 'Date', 'Customer', 'Customer GSTIN', 'Customer state', 'Taxable', 'CGST', 'SGST', 'IGST', 'Total', 'Status', 'Paid on', 'Payment method', 'Type', 'Received so far'])) . "\r\n";
    foreach ($rows as $r) {
        echo implode(',', array_map('csv_cell', [$r['number'], $r['issue_date'], $r['bill_name'], $r['bill_gstin'], $r['bill_state'], $r['subtotal'], $r['cgst'], $r['sgst'], $r['igst'], $r['total'], $r['status'], $r['paid_at'], $r['paid_method'], $r['kind'], $r['paid']])) . "\r\n";
    }
    audit('report.csv', "$from..$to"); exit;
}
