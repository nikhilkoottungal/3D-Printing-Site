<?php
/** Global search. Every section is limited to what the signed-in person's role may see. */
function search_page(): void
{
    $u = require_staff();
    $qs = trim((string) ($_GET['q'] ?? '')); $res = [];
    if (mb_strlen($qs) >= 2) {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $qs) . '%';
        if (can('requests.view')) {
            $res['Orders'] = array_map(fn($r) => ['/staff/requests/' . $r['id'], $r['ref'] . ' - ' . $r['cname'], $r['material'] . ', ' . $r['color'] . ' x' . $r['quantity'] . ' - ' . (STATUSES[$r['status']] ?? $r['status'])],
                all('SELECT r.*, u.name cname FROM is_requests r JOIN is_users u ON u.id=r.user_id WHERE r.ref LIKE ? OR r.notes LIKE ? OR r.material LIKE ? OR r.tracking LIKE ? OR u.name LIKE ? OR u.phone LIKE ? OR u.email LIKE ? ORDER BY r.id DESC LIMIT 10', array_fill(0, 7, $like)));
            $res['Customers'] = array_map(fn($c) => ['/staff/customers/' . $c['id'], $c['name'], trim(($c['phone'] ?? '') . ' ' . (str_ends_with($c['email'], '@walkin.invalid') ? '' : $c['email']) . ' ' . ($c['gstin'] ?? ''))],
                all("SELECT id,name,phone,email,gstin FROM is_users WHERE role='customer' AND (name LIKE ? OR phone LIKE ? OR email LIKE ? OR gstin LIKE ?) ORDER BY name LIMIT 10", array_fill(0, 4, $like)));
        }
        if (can('invoices.manage')) {
            $res['Invoices'] = array_map(fn($i) => ['/staff/invoices/' . $i['id'], $i['number'] . ' - ' . $i['bill_name'], money($i['total']) . ' - ' . $i['status']], all('SELECT id,number,bill_name,total,status FROM is_invoices WHERE number LIKE ? OR bill_name LIKE ? OR bill_gstin LIKE ? ORDER BY id DESC LIMIT 10', array_fill(0, 3, $like)));
        }
        if (can('purchasing.manage')) {
            $res['Suppliers'] = array_map(fn($s) => ['/staff/suppliers/' . $s['id'], $s['name'], trim(($s['contact'] ?? '') . ' ' . ($s['phone'] ?? ''))], all('SELECT id,name,contact,phone FROM is_suppliers WHERE name LIKE ? OR contact LIKE ? OR gstin LIKE ? ORDER BY name LIMIT 10', array_fill(0, 3, $like)));
            $res['Purchase orders'] = array_map(fn($p) => ['/staff/purchase-orders/' . $p['id'], $p['number'] . ' - ' . $p['sname'], money($p['total']) . ' - ' . $p['status']], all('SELECT p.id,p.number,p.total,p.status,s.name sname FROM is_purchase_orders p JOIN is_suppliers s ON s.id=p.supplier_id WHERE p.number LIKE ? OR s.name LIKE ? OR p.notes LIKE ? ORDER BY p.id DESC LIMIT 10', array_fill(0, 3, $like)));
        }
        $res['Tasks'] = array_map(fn($t) => ['/staff/tasks/' . $t['id'], '#' . $t['id'] . ' ' . $t['title'], TASK_STATUSES[$t['status']] ?? $t['status']], all('SELECT id,title,status FROM is_tasks WHERE title LIKE ? OR description LIKE ? ORDER BY id DESC LIMIT 10', array_fill(0, 2, $like)));
        $res['Knowledge and procedures'] = array_map(fn($a) => ['/staff/kb/' . $a['slug'], $a['title'], $a['category']], all('SELECT slug,title,category FROM is_kb_articles WHERE title LIKE ? OR body LIKE ? ORDER BY title LIMIT 10', array_fill(0, 2, $like)));
        $res['Inventory'] = array_map(fn($i) => ['/staff/inventory/' . $i['id'], $i['name'], $i['qty'] . ' ' . $i['unit']], all('SELECT id,name,qty,unit FROM is_inventory_items WHERE active=1 AND (name LIKE ? OR sku LIKE ? OR material LIKE ? OR color LIKE ? OR supplier LIKE ?) ORDER BY name LIMIT 10', array_fill(0, 5, $like)));
        $res = array_filter($res);
    }
    render('staff/search', ['title' => 'Search', 'nav' => 'search', 'q' => $qs, 'res' => $res, 'u' => $u], 'staff');
}
function stl_view(string $id): void
{
    $u = require_staff('requests.view');
    $f = one('SELECT f.*, r.ref FROM is_request_files f JOIN is_requests r ON r.id=f.request_id WHERE f.id=?', [(int) $id]);
    if (!$f || !preg_match('/\.stl$/i', $f['orig_name'])) { abort(404, 'No preview for this file.'); }
    render('staff/stl_view', ['title' => 'Preview ' . $f['orig_name'], 'nav' => 'requests', 'f' => $f, 'u' => $u], 'staff');
}
