<?php
function routes(): array
{
    return [
        ['GET', '/', 'page_home'],
        ['GET', '/services', fn() => render('services', ['title' => 'Services'])],
        ['GET', '/materials', fn() => render('materials', ['title' => 'Materials'])],
        ['GET', '/gallery', fn() => render('gallery', ['title' => 'Gallery'])],
        ['GET', '/contact', fn() => render('contact', ['title' => 'Contact'])],
        ['GET', '/privacy', fn() => render('privacy', ['title' => 'Privacy policy'])],
        ['GET', '/terms', fn() => render('terms', ['title' => 'Terms of service'])],

        ['GET', '/register', 'auth_register_form'], ['POST', '/register', 'auth_register'],
        ['GET', '/login', 'auth_login_form'], ['POST', '/login', 'auth_login'],
        ['POST', '/logout', 'auth_logout'],
        ['GET', '/verify', 'auth_verify'], ['POST', '/resend-verification', 'auth_resend'],
        ['GET', '/forgot', 'auth_forgot_form'], ['POST', '/forgot', 'auth_forgot'],
        ['GET', '/reset', 'auth_reset_form'], ['POST', '/reset', 'auth_reset'],
        ['GET', '/setup', 'setup_form'], ['POST', '/setup', 'setup_run'],

        ['GET', '/account', 'cust_dashboard'],
        ['GET', '/account/profile', 'cust_profile_form'], ['POST', '/account/profile', 'cust_profile'],
        ['GET', '/account/export', 'cust_export'],
        ['POST', '/account/delete-request', 'cust_delete_request'],
        ['GET', '/requests/new', 'cust_request_form'], ['POST', '/requests/new', 'cust_request_create'],
        ['GET', '/requests/(\d+)', 'cust_request_show'],
        ['POST', '/requests/(\d+)/approve', 'cust_request_approve'],
        ['POST', '/requests/(\d+)/cancel', 'cust_request_cancel'],
        ['GET', '/files/(\d+)', 'file_download'],

        ['GET', '/staff', 'staff_home'],
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
        ['GET', '/staff/team', 'team_list'], ['POST', '/staff/team/new', 'team_create'],
        ['POST', '/staff/team/(\d+)', 'team_update'],
    ];
}

function dispatch(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';
    // Allow the app to be mounted below the document root (for example /infynite-test).
    $basePath = rtrim((string) parse_url((string) cfg('base_url'), PHP_URL_PATH), '/');
    if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
        $path = substr($path, strlen($basePath)) ?: '/';
    }
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

function page_home(): void
{
    render('home', ['title' => '3D printing made to order', 'home' => true]);
}
