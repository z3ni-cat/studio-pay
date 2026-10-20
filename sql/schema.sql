-- PayCore — skema minimal
CREATE TABLE IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL DEFAULT 0,
    ext_id VARCHAR(120) DEFAULT '',
    amount INT NOT NULL,
    metode VARCHAR(30) DEFAULT 'qris_own',
    qris_string TEXT,
    status VARCHAR(20) DEFAULT 'pending',
    ket VARCHAR(120) DEFAULT '',
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX(user_id), INDEX(ext_id), INDEX(status, amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mutasi_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ref VARCHAR(120) NOT NULL UNIQUE,
    amount INT NOT NULL DEFAULT 0,
    invoice_id INT DEFAULT 0,
    matched TINYINT DEFAULT 0,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
