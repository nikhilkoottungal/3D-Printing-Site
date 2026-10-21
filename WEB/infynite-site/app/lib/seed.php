<?php
/** Creates the starter procedures and the implementation task list (from the AI Agent Implementation Brief) on first setup. */
function kb_create(string $title, string $category, string $kind, string $body, ?int $ownerId, string $note = 'Initial version', ?string $reviewDue = null): int
{
    $slug = slugify($title);
    $base = $slug; $i = 2;
    while (one('SELECT id FROM is_kb_articles WHERE slug=?', [$slug])) { $slug = $base . '-' . $i++; }
    $id = insert('INSERT INTO is_kb_articles (slug,title,category,kind,body,version,review_due,owner_id,updated_by,created_at,updated_at) VALUES (?,?,?,?,?,1,?,?,?,?,?)',
        [$slug, $title, $category, $kind, $body, $reviewDue, $ownerId, $ownerId, now(), now()]);
    q('INSERT INTO is_kb_versions (article_id,version,title,body,note,edited_by,edited_at) VALUES (?,?,?,?,?,?,?)', [$id, 1, $title, $body, $note, $ownerId, now()]);
    return $id;
}

function task_create(string $title, string $desc, ?int $assignee, int $createdBy, string $priority = 'normal', ?string $due = null, ?string $phase = null, ?int $kbId = null, ?int $requestId = null): int
{
    $id = insert('INSERT INTO is_tasks (title,description,status,priority,assignee_id,created_by,due_date,request_id,kb_id,phase,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        [$title, $desc, 'todo', $priority, $assignee, $createdBy, $due, $requestId, $kbId, $phase, now(), now()]);
    task_event($id, $createdBy, 'Task created' . ($assignee ? ' and assigned' : ''));
    return $id;
}
function task_event(int $taskId, ?int $userId, string $text): void
{
    q('INSERT INTO is_task_comments (task_id,user_id,kind,body,created_at) VALUES (?,?,?,?,?)', [$taskId, $userId, 'event', $text, now()]);
}

function seed_inventory(int $ownerId): void
{
    $items = [['PLA 1.75mm 1kg - Black', 'PLA', 'Black'], ['PLA 1.75mm 1kg - White', 'PLA', 'White'], ['PETG 1.75mm 1kg - Black', 'PETG', 'Black'], ['ASA 1.75mm 1kg - Black', 'ASA', 'Black'], ['TPU 1.75mm 0.5kg - Black', 'TPU', 'Black']];
    foreach ($items as [$name, $mat, $col]) {
        insert('INSERT INTO is_inventory_items (name,category,material,color,unit,qty,reorder_level,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)', [$name, 'filament', $mat, $col, 'spool', 0, 2, now(), now()]);
    }
}

function seed_starter(int $ownerId): void
{
    seed_inventory($ownerId);
    $review = date('Y-m-d', strtotime('+180 days'));
    $kb = [];

    $kb['phase0'] = kb_create('Hosting compatibility check (Phase 0)', 'Setup & IT', 'procedure', <<<'MD'
Goal: record evidence of what the Hostinger Single plan really allows **before** installing anything. Never assume limits.

## Steps
- [ ] Record plan name, renewal date and nominal disk quota from hPanel
- [ ] Record PHP version(s) selectable and enabled extensions (zip, fileinfo, mbstring, curl, pdo_mysql, intl, gd)
- [ ] Record MySQL/MariaDB version and the number of databases allowed
- [ ] Check whether the 10 GB quota includes databases, mailboxes, backups and temp files
- [ ] Check if a folder above the public document root is writable (needed for private uploads)
- [ ] Check cron job availability and minimum interval
- [ ] Test actual PHP limits: upload_max_filesize, post_max_size, memory_limit, max_execution_time
- [ ] Confirm TLS certificate, HTTP to HTTPS redirect and outgoing e-mail delivery (SPF/DKIM)
- [ ] Check backup and export options and plan off-site backups
- [ ] Write findings in a comment on the Phase 0 task with screenshots (no passwords or API keys)

## Go / no-go
If private file storage above the web root is **not** possible, stop. Do not accept uploads until a secure alternative is agreed with the owner.
MD, $ownerId, 'Initial version', $review);

    $kb['quote'] = kb_create('Review an enquiry and send a quotation', 'Sales', 'procedure', <<<'MD'
Applies to: Sales (and Owner). Target: first response within 1 working day.

## Steps
1. Open the order under **Orders**; set status to **Under review**.
2. Download and open each model in your slicer. Check size, orientation, wall thickness, supports.
3. If the model is not printable or details are missing, comment to the customer by changing status with a note and e-mail them.
4. Estimate material grams, print time and post-processing. Price manually using the current price sheet.
5. Enter the amount and a short note (what is included, lead time) and press **Send quote**. Status becomes **Quoted**.
6. When the customer approves, press **Sync to Dolibarr** (if enabled) and create the sales order in Dolibarr.
7. Create a production task from the **Print job handling** procedure.

## Notes
- Quotes are valid for 14 days unless stated otherwise.
- Never promise delivery dates that production has not confirmed.
MD, $ownerId, 'Initial version', $review);

    $kb['printjob'] = kb_create('Print job handling', 'Production', 'procedure', <<<'MD'
Applies to: Production.

## Before printing
- [ ] Order status is **Approved** and the Dolibarr sales order exists
- [ ] Material and colour in stock (check filament stock in Dolibarr)
- [ ] Slice with the agreed profile; save the sliced file name in the task comments
- [ ] Set status **Scheduled**, assign a printer in the task

## During printing
- [ ] Set status **Printing**; check the first layer and note any issue as a comment
- [ ] Record actual filament used and print time in a comment

## After printing
- [ ] Remove supports, clean up, then follow **Quality check**
- [ ] Update stock in Dolibarr
MD, $ownerId, 'Initial version', $review);

    $kb['qc'] = kb_create('Quality check', 'Production', 'procedure', <<<'MD'
Every job is checked by someone other than the person who printed it where possible.

- [ ] Dimensions match the model within the agreed tolerance (measure at least 3 critical dimensions)
- [ ] No warping, stringing, layer shifts or under-extrusion
- [ ] Surface and colour acceptable; supports fully removed
- [ ] Correct quantity counted
- [ ] Photo of the batch attached or linked in the task

If it fails: set the order to **Failed** with a note, tell Sales, and re-queue if the reprint is on us.
MD, $ownerId, 'Initial version', $review);

    $kb['dispatch'] = kb_create('Pack and dispatch', 'Production', 'procedure', <<<'MD'
- [ ] QC passed and recorded
- [ ] Pack to avoid damage (bubble wrap, rigid box for thin parts)
- [ ] Include packing slip with the order reference
- [ ] Book courier, record tracking number in the order note
- [ ] Set status **Ready / Dispatched** (customer is e-mailed)
- [ ] Create the invoice in Dolibarr; set **Completed** after delivery is confirmed
MD, $ownerId, 'Initial version', $review);

    $kb['filament'] = kb_create('Filament stock and reordering', 'Purchasing', 'procedure', <<<'MD'
Stock and purchase orders live in **Dolibarr**; this page defines the rules.

- Check stock weekly and after every large job.
- Reorder a material when fewer than **2 spools** remain.
- Create a supplier purchase order in Dolibarr; add a task here to track delivery.
- When goods arrive, receive them against the PO in Dolibarr and check spool weight and condition.
MD, $ownerId, 'Initial version', $review);

    $kb['backup'] = kb_create('Backup and restore test', 'Setup & IT', 'procedure', <<<'MD'
Backups must not rely only on the hosting account.

## Daily (automated)
- Database dump, uploaded files and configuration, encrypted, copied off-site.

## Monthly restore test
- [ ] Download the latest backup
- [ ] Restore into a **staging** location, never over live data
- [ ] Open a customer, an order and a model file and confirm they match
- [ ] Record date, result and time taken in a comment on the task

Never store encryption keys in the same account as the backups.
MD, $ownerId, 'Initial version', $review);

    $kb['onboard'] = kb_create('Onboard a new staff member', 'Team', 'procedure', <<<'MD'
Owner only.

1. Go to **Team**, add the person with the least-privilege role that fits their work.
2. Send them the one-time password link shown on screen, and ask them to set a strong password.
3. Walk them through this knowledge base: start with the procedures for their role.
4. Assign a first small task so they learn the workflow.
5. When someone leaves: deactivate the account the same day.
MD, $ownerId, 'Initial version', $review);

    $kb['privacy'] = kb_create('Customer data requests (export and deletion)', 'Sales', 'procedure', <<<'MD'
Customers can export their data from their account page and request deletion.

- [ ] Verify the request came from the account holder
- [ ] Check no open orders or invoices that must be retained for legal or tax reasons
- [ ] Delete or anonymise the account and stored model files
- [ ] Record the date and what was removed in the task
- [ ] Reply to the customer

Retention periods and tax obligations: confirm with a qualified Indian tax/legal professional.
MD, $ownerId, 'Initial version', $review);

    $kb['drill'] = kb_create('Restore drill (monthly)', 'Setup & IT', 'procedure', <<<'MD'
Goal: prove that a backup can really be restored. Done by an owner, on a PC (never on the live site). Details: docs/BACKUP_RESTORE_PLAN.md and docs/BACKUP_OPERATIONS.md.

- [ ] Confirm the **Backups** page shows a successful run in the last 24 h and no pending uploads
- [ ] Take the private key from its **stored location** (password manager / sealed paper), not the working copy
- [ ] `php tools/restore.php verify --src=<PC backup folder> --key=<key file>` must say VERIFY PASSED
- [ ] Restore into a scratch database: `php tools/restore.php restore --src=... --key=... --db=portal=infynite_drill --files-to=<scratch folder>`
- [ ] Log in to a staging copy, open a recent order and download its model file
- [ ] Write date, backup run ID, time taken and problems in a comment on the task
- [ ] Drop the scratch database and delete the scratch folder
MD, $ownerId, 'Initial version', $review);

    $kb['incident'] = kb_create('Restore after data loss or corruption', 'Setup & IT', 'procedure', <<<'MD'
Owner only. Read **docs/BACKUP_RESTORE_PLAN.md section 5** first. Stay calm: nothing is deleted from backups.

1. Write down what happened and when it was noticed.
2. Pick the newest backup from **before** the damage: `php tools/restore.php list --src=<folder>`
3. **Staging first:** restore into a scratch DB and check it (orders, tasks, a file download).
4. Only then restore live: `php tools/restore.php restore --key=<key> --run=<ID> --live --yes --confirm=<ID>` (the tool takes a pre-restore snapshot, shows the maintenance page, restores and checks).
5. If a hack is possible: rebuild the code from git, restore **data only**, change every password/key, review the audit log.
6. Run the post-restore checklist (plan section 5.7) and record the incident.
MD, $ownerId, 'Initial version', $review);

    $kb['about'] = kb_create('About our printing services (internal reference)', 'Knowledge', 'knowledge', <<<'MD'
Use this page for facts the team needs: materials we offer, typical lead times, price sheet location, supplier contacts and printer settings.

- **Materials offered:** PLA, PETG, ABS/ASA, TPU, Nylon/CF (see public Materials page)
- **Typical lead time:** _edit me_
- **Price sheet:** _edit me_
- **Printers and profiles:** _edit me_

Edit this page and add your own articles with **New article**.
MD, $ownerId, 'Initial version', $review);

    // Implementation task list from the brief
    $p = fn(int $d) => date('Y-m-d', strtotime("+$d days"));
    task_create('Phase 0: Hostinger Single compatibility checklist', "Inspect the real hPanel account and record evidence. Follow the procedure linked on this task.\n\nDeliverable: a checklist with screenshots/values (no secrets) and an explicit **go / no-go** recommendation.", $ownerId, $ownerId, 'urgent', $p(7), 'Phase 0', $kb['phase0']);
    task_create('Phase 1: Repository, environments, staging and rollback', "Git repository, environment templates without secrets, staging approach that fits Single hosting, rollback and backup plan.", null, $ownerId, 'high', $p(21), 'Phase 1');
    task_create('Phase 2: Install and secure Dolibarr', "Install a supported Dolibarr release; disable unused modules; lock the installer; configure products/materials, suppliers, stock, permissions and staff roles.", null, $ownerId, 'high', $p(35), 'Phase 2');
    task_create('Phase 3: Customer portal (register, verify, login, dashboard)', "This codebase implements the first version. Deploy to staging and review accessibility and mobile layout.", null, $ownerId, 'normal', $p(49), 'Phase 3');
    task_create('Phase 4: Safe uploads and quotation workflow', "Live-test upload limits on Hostinger, test with malformed STL/3MF/zip-bomb samples, map portal records to Dolibarr IDs.", null, $ownerId, 'high', $p(63), 'Phase 4');
    task_create('Phase 5: Production statuses, purchase orders, stock, invoices', "Wire Dolibarr sales orders, supplier POs, filament stock and invoices into the daily workflow.", null, $ownerId, 'normal', $p(77), 'Phase 5');
    task_create('Phase 6: Integration, security, load and restore tests', "Run the acceptance criteria from the brief: customer isolation, upload rejection, backup restore, no secrets in public paths. Owner approves the final test report before launch.", null, $ownerId, 'high', $p(91), 'Phase 6');
    task_create('Write privacy policy, terms and retention policy', "Draft the pages, then have them reviewed by a qualified professional (Indian legal/tax/privacy).", null, $ownerId, 'normal', $p(40), 'Legal', $kb['privacy']);
    task_create('Generate both owners\' backup keys and test a restore', "Each owner runs tools/keygen.php on their own PC, stores the key and passphrase separately (password manager + sealed paper), and adds the PUBLIC keys to app/config.php. Then run a full restore drill.", $ownerId, $ownerId, 'high', $p(21), 'Phase 1', $kb['drill']);
    task_create('Set up off-site encrypted daily backups', "Database + uploads + configuration. Follow the backup procedure and test a restore.", null, $ownerId, 'high', $p(30), 'Phase 1', $kb['backup']);
    task_create('Set up the price sheet for manual quoting', "Agree per-material rates, setup fee, minimum order, and post-processing charges. Record in the knowledge base.", null, $ownerId, 'normal', $p(14), 'Sales', $kb['quote']);
    task_create('Onboard the four staff users', "Create accounts with the right roles and make sure everyone has read the procedures for their role.", $ownerId, $ownerId, 'normal', $p(14), 'Team', $kb['onboard']);
}
