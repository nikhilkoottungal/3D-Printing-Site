<?php
function routes(): array
{
    return [
        ['GET', '/login', 'auth_login_form'], ['POST', '/login', 'auth_login'],
        ['POST', '/logout', 'auth_logout'],
        ['GET', '/forgot', 'auth_forgot_form'], ['POST', '/forgot', 'auth_forgot'],
        ['GET', '/reset', 'auth_reset_form'], ['POST', '/reset', 'auth_reset'],
        ['GET', '/setup', 'setup_form'], ['POST', '/setup', 'setup_run'],
        ['GET', '/files/(\d+)', 'file_download'],

        ['GET', '/staff', 'staff_home'],
        ['GET', '/staff/ping', 'staff_ping'], ['GET', '/staff/avatar/(\d+)', 'avatar_get'], ['GET', '/staff/hr-docs/(\d+)', 'hr_doc_get'], ['POST', '/staff/hr-docs/(\d+)/delete', 'hr_doc_delete'], ['GET', '/staff/team/(\d+)/profile', 'team_profile'],
        ['GET', '/staff/me', 'me_page'], ['POST', '/staff/me/delete', 'me_delete'], ['POST', '/staff/me', 'me_update'],
        ['GET', '/login/2fa', 'login2fa_form'], ['POST', '/login/2fa', 'login2fa_check'],
        ['GET', '/staff/requests', 'sreq_list'],
        ['GET', '/staff/requests/new', 'sreq_new_form'], ['POST', '/staff/requests/new', 'sreq_create'],
        ['GET', '/staff/requests/(\d+)', 'sreq_show'], ['POST', '/staff/requests/(\d+)', 'sreq_update'],
        ['GET', '/staff/tasks', 'task_list'],
        ['GET', '/staff/tasks/new', 'task_new_form'], ['POST', '/staff/tasks/new', 'task_create_post'],
        ['GET', '/staff/tasks/(\d+)', 'task_show'], ['POST', '/staff/tasks/(\d+)', 'task_update'],
        ['POST', '/staff/tasks/(\d+)/comment', 'task_comment'],
        ['GET', '/staff/kb', 'kb_list'],
        ['GET', '/staff/kb/new', 'kb_new_form'], ['POST', '/staff/kb/new', 'kb_create_post'],
        ['GET', '/staff/kb/([a-z0-9-]+)', 'kb_show'],
        ['GET', '/staff/kb/([a-z0-9-]+)/edit', 'kb_edit_form'], ['POST', '/staff/kb/([a-z0-9-]+)/edit', 'kb_edit_post'],
        ['GET', '/staff/kb/([a-z0-9-]+)/history', 'kb_history'],
        ['POST', '/staff/kb/([a-z0-9-]+)/restore', 'kb_restore'],
        ['POST', '/staff/kb/([a-z0-9-]+)/delete', 'kb_delete'],
        ['POST', '/staff/kb/([a-z0-9-]+)/task', 'kb_make_task'],
        ['GET', '/staff/inventory', 'inv_list'],
        ['GET', '/staff/inventory/new', 'inv_new_form'], ['POST', '/staff/inventory/new', 'inv_create'],
        ['GET', '/staff/inventory/(\d+)', 'inv_show'], ['POST', '/staff/inventory/(\d+)', 'inv_update'],
        ['POST', '/staff/inventory/(\d+)/move', 'inv_move'],
        ['GET', '/staff/backups', 'backups_page'],
        ['GET', '/staff/suppliers', 'sup_list'], ['GET', '/staff/suppliers/new', 'sup_new_form'], ['POST', '/staff/suppliers/new', 'sup_create'],
        ['GET', '/staff/suppliers/(\d+)', 'sup_show'], ['POST', '/staff/suppliers/(\d+)', 'sup_update'],
        ['GET', '/staff/purchase-orders', 'po_list'], ['GET', '/staff/purchase-orders/new', 'po_new_form'], ['POST', '/staff/purchase-orders/new', 'po_create'],
        ['GET', '/staff/purchase-orders/(\d+)', 'po_show'], ['POST', '/staff/purchase-orders/(\d+)', 'po_update'],
        ['GET', '/staff/purchase-orders/(\d+)\.pdf', 'po_pdf'], ['GET', '/staff/reorder', 'reorder_page'], ['POST', '/staff/reorder', 'reorder_create'],
        ['GET', '/staff/bills', 'bills_page'], ['GET', '/staff/bills/new', 'bill_new_form'], ['POST', '/staff/bills/new', 'bill_create'], ['GET', '/staff/bills/(\d+)', 'bill_show'], ['POST', '/staff/bills/(\d+)', 'bill_update'],
        ['GET', '/staff/credit-notes', 'cn_list'], ['GET', '/staff/credit-notes/new', 'cn_new_form'], ['POST', '/staff/credit-notes/new', 'cn_create'], ['GET', '/staff/credit-notes/(\d+)\.pdf', 'cn_pdf'], ['GET', '/staff/credit-notes/(\d+)', 'cn_show'], ['POST', '/staff/credit-notes/(\d+)', 'cn_update'],
        ['GET', '/staff/gst', 'gst_page'], ['GET', '/staff/gst/(b2b|b2c|hsn|cdn)\.csv', 'gst_csv'],
        ['GET', '/staff/expenses', 'exp_list'], ['POST', '/staff/expenses', 'exp_create'], ['POST', '/staff/expenses/(\d+)/delete', 'exp_delete'], ['GET', '/staff/profit', 'profit_page'],
        ['GET', '/staff/bank', 'bank_page'], ['POST', '/staff/bank/upload', 'bank_upload'], ['POST', '/staff/bank/clear', 'bank_clear'],
        ['GET', '/staff/reports', 'report_page'], ['GET', '/staff/reports/invoices.csv', 'report_csv'],
        ['GET', '/staff/recurring', 'rec_list'], ['POST', '/staff/recurring', 'rec_create'], ['POST', '/staff/recurring/(\d+)', 'rec_update'],
        ['GET', '/staff/notes', 'notes_page'], ['POST', '/staff/notes', 'note_create'], ['GET', '/staff/notes/(\d+)', 'note_edit_page'], ['POST', '/staff/notes/(\d+)', 'note_update'],
        ['POST', '/staff/attachments', 'att_upload'], ['GET', '/staff/attachments/(\d+)', 'att_get'], ['POST', '/staff/attachments/(\d+)/delete', 'att_delete'],
        ['GET', '/staff/calendar', 'calendar_page'], ['GET', '/staff/exports', 'exports_page'], ['GET', '/staff/export/([a-z_]+)\.csv', 'export_csv'],
        ['GET', '/staff/search', 'search_page'], ['GET', '/staff/files/(\d+)/view', 'stl_view'],
        ['GET', '/staff/calculator', 'calc_page'], ['GET', '/staff/requests/(\d+)/quote\.pdf', 'quote_pdf'], ['GET', '/staff/requests/(\d+)/challan\.pdf', 'challan_pdf'], ['GET', '/staff/requests/(\d+)/label', 'label_page'],
        ['GET', '/staff/printers', 'printers_page'], ['POST', '/staff/printers', 'printer_save'], ['GET', '/staff/queue', 'queue_page'], ['POST', '/staff/jobs/(\d+)', 'job_update'],
        ['GET', '/staff/customers', 'crm_list'], ['GET', '/staff/customers/new', 'crm_new_form'], ['POST', '/staff/customers/new', 'crm_create'],
        ['GET', '/staff/customers/(\d+)', 'crm_show'], ['POST', '/staff/customers/(\d+)', 'crm_update'],
        ['GET', '/staff/invoices', 'invoice_list'], ['GET', '/staff/invoices/new', 'invoice_new_form'], ['POST', '/staff/invoices/new', 'invoice_create'],
        ['GET', '/staff/invoices/(\d+)\.pdf', 'invoice_pdf'], ['GET', '/staff/invoices/(\d+)', 'invoice_show'], ['POST', '/staff/invoices/(\d+)', 'invoice_update'],
        ['GET', '/staff/admin', 'admin_page'], ['POST', '/staff/admin/unlock', 'admin_unlock'], ['POST', '/staff/admin/lock', 'admin_lock'],
        ['POST', '/staff/admin/save', 'admin_save'], ['POST', '/staff/admin/key', 'admin_change_key'], ['POST', '/staff/admin/test-mail', 'admin_test_mail'],
        ['GET', '/staff/admin/integrations', 'integ_page'], ['POST', '/staff/admin/integrations', 'integ_save'], ['POST', '/staff/admin/integrations/test', 'integ_test'], ['POST', '/staff/admin/integrations/run', 'integ_run'],
        ['GET', '/staff/kb.pdf', 'kb_pdf_all'], ['GET', '/staff/kb/([a-z0-9-]+)\.pdf', 'kb_pdf_one'],
        ['GET', '/staff/admin/notifications', 'admin_notify_page'], ['POST', '/staff/admin/notifications', 'admin_notify_save'],
        ['GET', '/staff/team', 'team_list'], ['POST', '/staff/team/new', 'team_create'],
        ['POST', '/staff/team/(\d+)', 'team_update'],
        ['POST', '/staff/team/(\d+)/reset2fa', 'team_reset_2fa'], ['POST', '/staff/team/(\d+)/remove', 'team_remove'], ['POST', '/staff/team/(\d+)/delete', 'team_delete'], ['POST', '/staff/team/(\d+)/freeze', 'team_freeze'], ['POST', '/staff/team/(\d+)/unfreeze', 'team_unfreeze'],  ['POST', '/staff/team/(\d+)/restore', 'team_restore'], ['POST', '/staff/team/(\d+)/removal', 'team_removal_request'], ['POST', '/staff/team/requests/(\d+)', 'team_removal_decide'],
    ];
}

function dispatch(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $bp = base_path();
    if ($bp !== '' && ($path === $bp || str_starts_with($path, $bp . '/'))) { $path = substr($path, strlen($bp)); }
    $path = rtrim($path, '/') ?: '/';
    // Sign-in rules (Admin settings > Security): optional sign-out on page reload and after idle time.
    // Browsers add Cache-Control: max-age=0 to a form submission and to the redirect that follows it, so that look-alike must not count as a reload.
    if ($method === 'POST') { $_SESSION['post_at'] = time(); }
    if (user()) {
        $idle = (int) setting('idle_minutes', 0);
        if (idle_expired($_SESSION['last_seen'] ?? null, $idle)) { auth_force_logout('You were signed out after ' . $idle . ' minutes of inactivity.'); }
        if ($method === 'GET' && setting('session_rule', 'reload') === 'reload' && is_reload_request($_SERVER) && time() - (int) ($_SESSION['post_at'] ?? 0) > 5) { auth_force_logout('You were signed out because the page was reloaded. Sign in again to continue.'); }
        $_SESSION['last_seen'] = time();
    }
    // Internal staff system: no public site and no customer accounts. Only these paths exist.
    if ($path === '/') { redirect(user() ? '/staff' : '/login'); }
    if ($method === 'POST') { csrf_check(); }
    foreach (routes() as [$m, $re, $fn]) {
        if ($m === $method && preg_match('#^' . $re . '$#', $path, $mm)) {
            array_shift($mm);
            $fn(...$mm);
            return;
        }
    }
    abort(404, 'Page not found.');
}
