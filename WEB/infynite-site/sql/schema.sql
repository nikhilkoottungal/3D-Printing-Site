-- Infynite Solutions portal schema (MySQL 5.7+/MariaDB 10.3+). All tables prefixed is_ so the
-- portal can share one database with Dolibarr (llx_) if the hosting plan allows only one.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS is_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(30) NULL,
  pass_hash VARCHAR(255) NOT NULL,
  role ENUM('customer','owner','sales','production','purchasing') NOT NULL DEFAULT 'customer',
  active TINYINT(1) NOT NULL DEFAULT 1,
  frozen TINYINT(1) NOT NULL DEFAULT 0,
  avatar CHAR(40) NULL,
  email_verified_at DATETIME NULL,
  token_hash CHAR(64) NULL,
  token_kind VARCHAR(10) NULL,
  token_expires DATETIME NULL,
  dolibarr_id INT NULL,
  address TEXT NULL,
  gstin VARCHAR(15) NULL,
  state VARCHAR(60) NULL,
  totp_secret TEXT NULL,
  totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  totp_last INT UNSIGNED NOT NULL DEFAULT 0,
  recovery_codes TEXT NULL,
  created_at DATETIME NOT NULL,
  last_login_at DATETIME NULL,
  INDEX (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS is_login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  email VARCHAR(190) NOT NULL,
  kind VARCHAR(10) NOT NULL DEFAULT 'login',
  at DATETIME NOT NULL,
  INDEX (ip, at), INDEX (email, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS is_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ref VARCHAR(20) NOT NULL UNIQUE,
  user_id INT UNSIGNED NOT NULL,
  material VARCHAR(40) NOT NULL,
  color VARCHAR(40) NOT NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  notes TEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'requested',
  quote_amount DECIMAL(12,2) NULL,
  quote_note TEXT NULL,
  dolibarr_proposal_id INT NULL,
  dolibarr_order_id INT NULL,
  source VARCHAR(10) NOT NULL DEFAULT 'portal',
  created_by INT UNSIGNED NULL,
  due_date DATE NULL,
  courier VARCHAR(80) NULL,
  tracking VARCHAR(80) NULL,
  dispatched_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX (user_id), INDEX (status),
  FOREIGN KEY (user_id) REFERENCES is_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS is_request_files (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  orig_name VARCHAR(200) NOT NULL,
  stored_name CHAR(40) NOT NULL UNIQUE,
  kind VARCHAR(5) NOT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX (request_id), INDEX (user_id),
  FOREIGN KEY (request_id) REFERENCES is_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS is_request_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL,
  note VARCHAR(500) NULL,
  by_user INT UNSIGNED NULL,
  at DATETIME NOT NULL,
  INDEX (request_id),
  FOREIGN KEY (request_id) REFERENCES is_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Staff workspace: tasks -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS is_tasks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  description MEDIUMTEXT NULL,
  status ENUM('todo','in_progress','blocked','review','done') NOT NULL DEFAULT 'todo',
  priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  assignee_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  due_date DATE NULL,
  request_id INT UNSIGNED NULL,
  kb_id INT UNSIGNED NULL,
  phase VARCHAR(40) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  INDEX (status), INDEX (assignee_id), INDEX (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS is_task_comments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  kind ENUM('comment','event') NOT NULL DEFAULT 'comment',
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX (task_id),
  FOREIGN KEY (task_id) REFERENCES is_tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Staff workspace: knowledge base & procedures (versioned) ----------------------------------
CREATE TABLE IF NOT EXISTS is_kb_articles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(120) NOT NULL UNIQUE,
  title VARCHAR(200) NOT NULL,
  category VARCHAR(60) NOT NULL DEFAULT 'General',
  kind ENUM('procedure','knowledge') NOT NULL DEFAULT 'procedure',
  body MEDIUMTEXT NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  review_due DATE NULL,
  owner_id INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS is_kb_versions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  article_id INT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  note VARCHAR(200) NULL,
  edited_by INT UNSIGNED NULL,
  edited_at DATETIME NOT NULL,
  UNIQUE (article_id, version),
  FOREIGN KEY (article_id) REFERENCES is_kb_articles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS is_audit_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  detail VARCHAR(500) NULL,
  ip VARCHAR(45) NULL,
  at DATETIME NOT NULL,
  INDEX (user_id), INDEX (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inventory (filament, consumables, spares) ---------------------------------------------------
CREATE TABLE IF NOT EXISTS is_inventory_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(40) NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  category ENUM('filament','consumable','spare','other') NOT NULL DEFAULT 'filament',
  material VARCHAR(40) NULL,
  color VARCHAR(40) NULL,
  unit VARCHAR(20) NOT NULL DEFAULT 'spool',
  qty DECIMAL(12,2) NOT NULL DEFAULT 0,
  reorder_level DECIMAL(12,2) NOT NULL DEFAULT 0,
  location VARCHAR(80) NULL,
  supplier VARCHAR(120) NULL,
  unit_cost DECIMAL(12,2) NULL,
  grams_per_unit DECIMAL(10,2) NOT NULL DEFAULT 1000,
  notes TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS is_stock_moves (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  delta DECIMAL(12,2) NOT NULL,
  qty_after DECIMAL(12,2) NOT NULL,
  reason ENUM('purchase','use','adjust','waste','return') NOT NULL,
  request_id INT UNSIGNED NULL,
  note VARCHAR(300) NULL,
  created_at DATETIME NOT NULL,
  INDEX (item_id),
  FOREIGN KEY (item_id) REFERENCES is_inventory_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upgrade for existing databases (direct orders): run once if is_requests already exists without these columns
-- ALTER TABLE is_requests ADD COLUMN source VARCHAR(10) NOT NULL DEFAULT 'portal', ADD COLUMN created_by INT UNSIGNED NULL;

-- Business settings editable from the owner-only, key-protected admin page (/staff/admin).
CREATE TABLE IF NOT EXISTS is_settings (
  skey VARCHAR(60) NOT NULL PRIMARY KEY,
  svalue TEXT NULL,
  updated_at DATETIME NOT NULL,
  updated_by INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invoices (issued from orders; immutable once issued, can be marked paid or voided).
CREATE TABLE IF NOT EXISTS is_invoices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL UNIQUE,
  request_id INT UNSIGNED NULL,
  customer_id INT UNSIGNED NULL,
  bill_name VARCHAR(160) NOT NULL,
  bill_address TEXT NULL,
  bill_email VARCHAR(190) NULL,
  bill_phone VARCHAR(30) NULL,
  bill_gstin VARCHAR(15) NULL,
  bill_state VARCHAR(60) NULL,
  seller TEXT NOT NULL,
  issue_date DATE NOT NULL,
  due_date DATE NOT NULL,
  tax_inclusive TINYINT(1) NOT NULL DEFAULT 0,
  gst_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  tax_kind VARCHAR(10) NOT NULL DEFAULT 'cgst_sgst',
  subtotal DECIMAL(12,2) NOT NULL,
  cgst DECIMAL(12,2) NOT NULL DEFAULT 0,
  sgst DECIMAL(12,2) NOT NULL DEFAULT 0,
  igst DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'issued',
  notes TEXT NULL,
  paid_at DATE NULL,
  paid_method VARCHAR(30) NULL,
  paid_ref VARCHAR(60) NULL,
  kind VARCHAR(8) NOT NULL DEFAULT 'tax',
  last_reminder DATE NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  INDEX (request_id), INDEX (status), INDEX (issue_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS is_invoice_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  description VARCHAR(255) NOT NULL,
  hsn VARCHAR(10) NULL,
  qty DECIMAL(10,2) NOT NULL,
  rate DECIMAL(12,2) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  INDEX (invoice_id),
  FOREIGN KEY (invoice_id) REFERENCES is_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Suppliers and purchase orders (receiving a purchase order adds stock to linked inventory items).
CREATE TABLE IF NOT EXISTS is_suppliers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  contact VARCHAR(120) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(30) NULL,
  address TEXT NULL,
  gstin VARCHAR(15) NULL,
  notes TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  INDEX (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS is_purchase_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL UNIQUE,
  supplier_id INT UNSIGNED NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'ordered',
  order_date DATE NOT NULL,
  expected_date DATE NULL,
  received_at DATETIME NULL,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  INDEX (supplier_id), INDEX (status),
  FOREIGN KEY (supplier_id) REFERENCES is_suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS is_po_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NULL,
  description VARCHAR(255) NOT NULL,
  qty DECIMAL(12,2) NOT NULL,
  unit_cost DECIMAL(12,2) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  INDEX (po_id),
  FOREIGN KEY (po_id) REFERENCES is_purchase_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who receives which e-mail notification (set in Admin settings > Notifications).
CREATE TABLE IF NOT EXISTS is_notify_rules (
  event VARCHAR(40) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (event, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Removing or demoting an admin needs every admin's agreement.
CREATE TABLE IF NOT EXISTS is_admin_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  target_id INT UNSIGNED NOT NULL,
  action VARCHAR(30) NOT NULL,
  requested_by INT UNSIGNED NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL,
  resolved_at DATETIME NULL,
  INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS is_admin_approvals (
  request_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  decision VARCHAR(8) NOT NULL,
  at DATETIME NOT NULL,
  PRIMARY KEY (request_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quality checklist ticks saved against an order.
CREATE TABLE IF NOT EXISTS is_order_qc (
  request_id INT UNSIGNED NOT NULL,
  item VARCHAR(120) NOT NULL,
  done TINYINT(1) NOT NULL DEFAULT 0,
  by_user INT UNSIGNED NULL,
  at DATETIME NOT NULL,
  PRIMARY KEY (request_id, item)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rework and returns on an order.
CREATE TABLE IF NOT EXISTS is_order_issues (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  kind VARCHAR(10) NOT NULL,
  reason VARCHAR(500) NOT NULL,
  cost DECIMAL(12,2) NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'open',
  resolution VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  resolved_at DATETIME NULL,
  INDEX (request_id), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS is_printers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  model VARCHAR(120) NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'active',
  next_maintenance DATE NULL,
  notes VARCHAR(500) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A print job is one run of an order on one printer; finishing it books filament and cost.
CREATE TABLE IF NOT EXISTS is_print_jobs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  printer_id INT UNSIGNED NULL,
  item_id INT UNSIGNED NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'queued',
  scheduled_for DATE NULL,
  est_hours DECIMAL(8,2) NULL,
  est_grams DECIMAL(10,2) NULL,
  actual_hours DECIMAL(8,2) NULL,
  actual_grams DECIMAL(10,2) NULL,
  material_cost DECIMAL(12,2) NULL,
  machine_cost DECIMAL(12,2) NULL,
  notes VARCHAR(500) NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  INDEX (request_id), INDEX (printer_id), INDEX (status), INDEX (scheduled_for)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Supplier bills (what we owe) and the payments made against them.
CREATE TABLE IF NOT EXISTS is_supplier_bills (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  po_id INT UNSIGNED NULL,
  bill_no VARCHAR(60) NOT NULL,
  bill_date DATE NOT NULL,
  due_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  gst_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  status VARCHAR(10) NOT NULL DEFAULT 'open',
  notes VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  INDEX (supplier_id), INDEX (status), INDEX (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS is_bill_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bill_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  paid_on DATE NOT NULL,
  method VARCHAR(30) NOT NULL,
  ref VARCHAR(60) NULL,
  by_user INT UNSIGNED NULL,
  at DATETIME NOT NULL,
  INDEX (bill_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payments received against an invoice (partial payments allowed).
CREATE TABLE IF NOT EXISTS is_invoice_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  paid_on DATE NOT NULL,
  method VARCHAR(30) NOT NULL,
  ref VARCHAR(60) NULL,
  by_user INT UNSIGNED NULL,
  at DATETIME NOT NULL,
  INDEX (invoice_id), INDEX (paid_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Credit notes reduce what a customer owes on an invoice (returns, rework, discounts).
CREATE TABLE IF NOT EXISTS is_credit_notes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL UNIQUE,
  invoice_id INT UNSIGNED NOT NULL,
  issue_date DATE NOT NULL,
  reason VARCHAR(300) NOT NULL,
  gst_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  tax_kind VARCHAR(10) NOT NULL DEFAULT 'cgst_sgst',
  subtotal DECIMAL(12,2) NOT NULL,
  cgst DECIMAL(12,2) NOT NULL DEFAULT 0,
  sgst DECIMAL(12,2) NOT NULL DEFAULT 0,
  igst DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL,
  refunded_on DATE NULL,
  refund_method VARCHAR(30) NULL,
  refund_ref VARCHAR(60) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  INDEX (invoice_id), INDEX (issue_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS is_expenses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expense_date DATE NOT NULL,
  category VARCHAR(40) NOT NULL,
  description VARCHAR(200) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  gst_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  paid_via VARCHAR(30) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  INDEX (expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tasks that repeat on a schedule (a new task is created when each date arrives).
CREATE TABLE IF NOT EXISTS is_recurring_tasks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  description TEXT NULL,
  priority VARCHAR(10) NOT NULL DEFAULT 'normal',
  assignee_id INT UNSIGNED NULL,
  phase VARCHAR(40) NULL,
  kb_id INT UNSIGNED NULL,
  freq VARCHAR(8) NOT NULL,
  every INT UNSIGNED NOT NULL DEFAULT 1,
  weekday TINYINT NULL,
  dom TINYINT NULL,
  due_days INT UNSIGNED NOT NULL DEFAULT 0,
  next_run DATE NOT NULL,
  last_run DATE NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  INDEX (active, next_run)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Free notes and checklists (private to the author, or shared with all staff).
CREATE TABLE IF NOT EXISTS is_notes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id INT UNSIGNED NOT NULL,
  title VARCHAR(160) NULL,
  body TEXT NULL,
  kind VARCHAR(6) NOT NULL DEFAULT 'text',
  items TEXT NULL,
  color VARCHAR(8) NOT NULL DEFAULT 'none',
  pinned TINYINT(1) NOT NULL DEFAULT 0,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  shared TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX (owner_id), INDEX (shared)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Files attached to tasks and notes.
CREATE TABLE IF NOT EXISTS is_attachments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(6) NOT NULL,
  ref_id INT UNSIGNED NOT NULL,
  orig_name VARCHAR(190) NOT NULL,
  stored_name CHAR(40) NOT NULL,
  mime VARCHAR(100) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  uploaded_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  INDEX (kind, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Staff personal and employment details. Aadhaar, PAN and bank account numbers are stored encrypted.
CREATE TABLE IF NOT EXISTS is_staff_profiles (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  blood_group VARCHAR(4) NULL,
  dob DATE NULL,
  joined_on DATE NULL,
  designation VARCHAR(80) NULL,
  address TEXT NULL,
  emergency_name VARCHAR(120) NULL,
  emergency_phone VARCHAR(30) NULL,
  prev_experience TEXT NULL,
  qualifications TEXT NULL,
  aadhaar_enc TEXT NULL,
  pan_enc TEXT NULL,
  bank_holder VARCHAR(120) NULL,
  bank_name VARCHAR(120) NULL,
  bank_acc_enc TEXT NULL,
  bank_ifsc VARCHAR(11) NULL,
  upi_id VARCHAR(80) NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ID proof copies. The files are encrypted on disk.
CREATE TABLE IF NOT EXISTS is_staff_docs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  kind VARCHAR(12) NOT NULL,
  orig_name VARCHAR(190) NOT NULL,
  stored_name CHAR(40) NOT NULL,
  mime VARCHAR(60) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
