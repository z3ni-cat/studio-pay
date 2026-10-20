-- Skema inti PayCore (MySQL 5.7+/8). QRIS statis disimpan TERPISAH, jangan commit.
CREATE TABLE IF NOT EXISTS invoices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED DEFAULT 0,
  ext_id VARCHAR(24) NOT NULL UNIQUE,
  amount BIGINT UNSIGNED NOT NULL,
  metode VARCHAR(16) DEFAULT 'qris_own',
  qris_string TEXT,
  status ENUM('pending','paid','cancel','expire') DEFAULT 'pending',
  ket VARCHAR(100) DEFAULT '',
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY idx_status_amount (status, amount),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ledger idempoten: SATU row per event pembayaran (unik via ref)
CREATE TABLE IF NOT EXISTS mutasi_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ref VARCHAR(64) NOT NULL UNIQUE,
  amount BIGINT UNSIGNED NOT NULL,
  invoice_id INT UNSIGNED DEFAULT 0,
  matched TINYINT(1) DEFAULT 0,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
