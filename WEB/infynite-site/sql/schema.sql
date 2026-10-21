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
  email_verified_at DATETIME NULL,
  token_hash CHAR(64) NULL,
  token_kind VARCHAR(10) NULL,
  token_expires DATETIME NULL,
  dolibarr_id INT NULL,
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
  created_by INT UNSIGNED NOT NULL,
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
