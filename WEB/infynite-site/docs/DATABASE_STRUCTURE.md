# Database structure

Database engine InnoDB, collation utf8mb4_unicode_ci, 35 tables prefixed `is_`. Generated from `sql/schema.sql` (schema version 12). Upgrades run automatically (`app/lib/migrate.php`). Sensitive columns (`*_enc`, `smtp_pass`, `ftp_pass`, `totp_secret`) hold encrypted values.

| Table | Columns | Purpose |
|---|---:|---|
| `is_users` | 23 | Staff accounts (and customer records): role, status, frozen, picture, two-factor |
| `is_login_attempts` | 5 | Rate-limit counters for sign-in, resets and admin key |
| `is_requests` | 20 | Orders |
| `is_request_files` | 9 | Model files attached to orders |
| `is_request_history` | 6 | Order status history |
| `is_tasks` | 14 | Tasks |
| `is_task_comments` | 6 | Task comments and activity |
| `is_kb_articles` | 12 | Knowledge base and procedures |
| `is_kb_versions` | 8 | Article versions |
| `is_audit_log` | 6 | Who did what, when |
| `is_inventory_items` | 17 | Stock items |
| `is_stock_moves` | 9 | Stock movements |
| `is_settings` | 4 | Business settings and counters (key/value) |
| `is_invoices` | 30 | Invoices and pro-formas |
| `is_invoice_lines` | 7 | Invoice lines |
| `is_suppliers` | 10 | Suppliers |
| `is_purchase_orders` | 11 | Purchase orders |
| `is_po_lines` | 7 | Purchase order lines |
| `is_notify_rules` | 2 | Who receives which e-mail alert |
| `is_admin_requests` | 7 | Admin removal requests |
| `is_admin_approvals` | 4 | Admins agreement on a removal |
| `is_order_qc` | 5 | Quality check ticks per order |
| `is_order_issues` | 10 | Rework and returns |
| `is_printers` | 8 | Printers |
| `is_print_jobs` | 17 | Print jobs with cost |
| `is_supplier_bills` | 12 | Supplier bills |
| `is_bill_payments` | 8 | Payments against supplier bills |
| `is_invoice_payments` | 8 | Payments received against invoices |
| `is_credit_notes` | 17 | Credit notes |
| `is_expenses` | 9 | Running expenses |
| `is_recurring_tasks` | 17 | Recurring task schedules |
| `is_notes` | 12 | Notes and checklists |
| `is_attachments` | 9 | Files on tasks and notes |
| `is_staff_profiles` | 18 | Staff personal and employment details (sensitive fields encrypted) |
| `is_staff_docs` | 8 | ID proof copies (files encrypted on disk) |

## Columns

### `is_users`

`id`, `email`, `name`, `phone`, `pass_hash`, `role`, `active`, `frozen`, `avatar`, `email_verified_at`, `token_hash`, `token_kind`, `token_expires`, `dolibarr_id`, `address`, `gstin`, `state`, `totp_secret`, `totp_enabled`, `totp_last`, `recovery_codes`, `created_at`, `last_login_at`

### `is_login_attempts`

`id`, `ip`, `email`, `kind`, `at`

### `is_requests`

`id`, `ref`, `user_id`, `material`, `color`, `quantity`, `notes`, `status`, `quote_amount`, `quote_note`, `dolibarr_proposal_id`, `dolibarr_order_id`, `source`, `created_by`, `due_date`, `courier`, `tracking`, `dispatched_at`, `created_at`, `updated_at`

### `is_request_files`

`id`, `request_id`, `user_id`, `orig_name`, `stored_name`, `kind`, `size_bytes`, `sha256`, `created_at`

### `is_request_history`

`id`, `request_id`, `status`, `note`, `by_user`, `at`

### `is_tasks`

`id`, `title`, `description`, `status`, `priority`, `assignee_id`, `created_by`, `due_date`, `request_id`, `kb_id`, `phase`, `created_at`, `updated_at`, `completed_at`

### `is_task_comments`

`id`, `task_id`, `user_id`, `kind`, `body`, `created_at`

### `is_kb_articles`

`id`, `slug`, `title`, `category`, `kind`, `body`, `version`, `review_due`, `owner_id`, `updated_by`, `created_at`, `updated_at`

### `is_kb_versions`

`id`, `article_id`, `version`, `title`, `body`, `note`, `edited_by`, `edited_at`

### `is_audit_log`

`id`, `user_id`, `action`, `detail`, `ip`, `at`

### `is_inventory_items`

`id`, `sku`, `name`, `category`, `material`, `color`, `unit`, `qty`, `reorder_level`, `location`, `supplier`, `unit_cost`, `grams_per_unit`, `notes`, `active`, `created_at`, `updated_at`

### `is_stock_moves`

`id`, `item_id`, `user_id`, `delta`, `qty_after`, `reason`, `request_id`, `note`, `created_at`

### `is_settings`

`skey`, `svalue`, `updated_at`, `updated_by`

### `is_invoices`

`id`, `number`, `request_id`, `customer_id`, `bill_name`, `bill_address`, `bill_email`, `bill_phone`, `bill_gstin`, `bill_state`, `seller`, `issue_date`, `due_date`, `tax_inclusive`, `gst_rate`, `tax_kind`, `subtotal`, `cgst`, `sgst`, `igst`, `total`, `status`, `notes`, `paid_at`, `paid_method`, `paid_ref`, `kind`, `last_reminder`, `created_by`, `created_at`

### `is_invoice_lines`

`id`, `invoice_id`, `description`, `hsn`, `qty`, `rate`, `amount`

### `is_suppliers`

`id`, `name`, `contact`, `email`, `phone`, `address`, `gstin`, `notes`, `active`, `created_at`

### `is_purchase_orders`

`id`, `number`, `supplier_id`, `status`, `order_date`, `expected_date`, `received_at`, `total`, `notes`, `created_by`, `created_at`

### `is_po_lines`

`id`, `po_id`, `item_id`, `description`, `qty`, `unit_cost`, `amount`

### `is_notify_rules`

`event`, `user_id`

### `is_admin_requests`

`id`, `target_id`, `action`, `requested_by`, `status`, `created_at`, `resolved_at`

### `is_admin_approvals`

`request_id`, `user_id`, `decision`, `at`

### `is_order_qc`

`request_id`, `item`, `done`, `by_user`, `at`

### `is_order_issues`

`id`, `request_id`, `kind`, `reason`, `cost`, `status`, `resolution`, `created_by`, `created_at`, `resolved_at`

### `is_printers`

`id`, `name`, `model`, `status`, `next_maintenance`, `notes`, `active`, `created_at`

### `is_print_jobs`

`id`, `request_id`, `printer_id`, `item_id`, `status`, `scheduled_for`, `est_hours`, `est_grams`, `actual_hours`, `actual_grams`, `material_cost`, `machine_cost`, `notes`, `started_at`, `finished_at`, `created_by`, `created_at`

### `is_supplier_bills`

`id`, `supplier_id`, `po_id`, `bill_no`, `bill_date`, `due_date`, `amount`, `gst_amount`, `status`, `notes`, `created_by`, `created_at`

### `is_bill_payments`

`id`, `bill_id`, `amount`, `paid_on`, `method`, `ref`, `by_user`, `at`

### `is_invoice_payments`

`id`, `invoice_id`, `amount`, `paid_on`, `method`, `ref`, `by_user`, `at`

### `is_credit_notes`

`id`, `number`, `invoice_id`, `issue_date`, `reason`, `gst_rate`, `tax_kind`, `subtotal`, `cgst`, `sgst`, `igst`, `total`, `refunded_on`, `refund_method`, `refund_ref`, `created_by`, `created_at`

### `is_expenses`

`id`, `expense_date`, `category`, `description`, `amount`, `gst_amount`, `paid_via`, `created_by`, `created_at`

### `is_recurring_tasks`

`id`, `title`, `description`, `priority`, `assignee_id`, `phase`, `kb_id`, `freq`, `every`, `weekday`, `dom`, `due_days`, `next_run`, `last_run`, `active`, `created_by`, `created_at`

### `is_notes`

`id`, `owner_id`, `title`, `body`, `kind`, `items`, `color`, `pinned`, `archived`, `shared`, `created_at`, `updated_at`

### `is_attachments`

`id`, `kind`, `ref_id`, `orig_name`, `stored_name`, `mime`, `size_bytes`, `uploaded_by`, `created_at`

### `is_staff_profiles`

`user_id`, `blood_group`, `dob`, `joined_on`, `designation`, `address`, `emergency_name`, `emergency_phone`, `prev_experience`, `qualifications`, `aadhaar_enc`, `pan_enc`, `bank_holder`, `bank_name`, `bank_acc_enc`, `bank_ifsc`, `upi_id`, `updated_at`

### `is_staff_docs`

`id`, `user_id`, `kind`, `orig_name`, `stored_name`, `mime`, `size_bytes`, `created_at`
