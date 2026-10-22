<?php
function staff_users(): array
{
    return all("SELECT id,name,role FROM is_users WHERE role <> 'customer' AND active=1 ORDER BY name");
}

function staff_home(): void
{
    $u = require_staff();
    if (setting('rec_checked') !== date('Y-m-d H')) { setting_set('rec_checked', date('Y-m-d H')); rec_generate(); }
    $mine = all("SELECT * FROM is_tasks WHERE assignee_id=? AND status <> 'done' ORDER BY (due_date IS NULL), due_date, FIELD(priority,'urgent','high','normal','low') LIMIT 8", [$u['id']]);
    $kpi = [
        'mine'    => (int) one("SELECT COUNT(*) c FROM is_tasks WHERE assignee_id=? AND status <> 'done'", [$u['id']])['c'],
        'overdue' => (int) one("SELECT COUNT(*) c FROM is_tasks WHERE status <> 'done' AND due_date < CURDATE()")['c'],
        'blocked' => (int) one("SELECT COUNT(*) c FROM is_tasks WHERE status='blocked'")['c'],
        'new'     => can('requests.view') ? (int) one("SELECT COUNT(*) c FROM is_requests WHERE status='requested'")['c'] : 0,
        'lowstock' => (int) one('SELECT COUNT(*) c FROM is_inventory_items WHERE active=1 AND qty <= reorder_level AND reorder_level > 0')['c'],
        'review'  => (int) one('SELECT COUNT(*) c FROM is_kb_articles WHERE review_due IS NOT NULL AND review_due < CURDATE()')['c'],
    ];
    $recent = can('requests.view') ? all('SELECT r.*, u.name cname FROM is_requests r JOIN is_users u ON u.id=r.user_id ORDER BY r.updated_at DESC LIMIT 6') : [];
    render('staff/home', ['title' => 'Staff overview', 'nav' => 'home', 'u' => $u, 'mine' => $mine, 'kpi' => $kpi, 'recent' => $recent, 'dash' => dash_data($u)], 'staff');
}
