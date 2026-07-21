SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','manager','viewer') NOT NULL DEFAULT 'admin',
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    successful TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_throttle (email, ip_address, attempted_at),
    INDEX idx_login_cleanup (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(120) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    company VARCHAR(160) NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(50) NULL,
    document VARCHAR(60) NULL,
    country ENUM('BR','US') NOT NULL DEFAULT 'BR',
    preferred_currency ENUM('BRL','USD') NOT NULL DEFAULT 'BRL',
    status ENUM('lead','active','inactive') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_clients_status (status),
    INDEX idx_clients_name (name),
    INDEX idx_clients_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    sku VARCHAR(80) NULL UNIQUE,
    description TEXT NULL,
    price_brl DECIMAL(15,2) NOT NULL DEFAULT 0,
    price_usd DECIMAL(15,2) NOT NULL DEFAULT 0,
    pricing_mode ENUM('manual','brl','usd') NOT NULL DEFAULT 'manual',
    price_exchange_rate DECIMAL(15,6) NULL,
    price_rate_source VARCHAR(80) NULL,
    price_rate_date DATE NULL,
    billing_cycle ENUM('monthly','quarterly','semiannual','annual') NOT NULL DEFAULT 'monthly',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_products_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    currency ENUM('BRL','USD') NOT NULL DEFAULT 'BRL',
    unit_price DECIMAL(15,2) NOT NULL,
    discount DECIMAL(15,2) NOT NULL DEFAULT 0,
    status ENUM('trial','active','past_due','paused','canceled') NOT NULL DEFAULT 'active',
    start_date DATE NOT NULL,
    next_billing_date DATE NULL,
    canceled_at DATE NULL,
    payment_method VARCHAR(80) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_subscriptions_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    CONSTRAINT fk_subscriptions_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    INDEX idx_subscriptions_status (status),
    INDEX idx_subscriptions_next_billing (next_billing_date),
    INDEX idx_subscriptions_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exchange_rates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    base_currency CHAR(3) NOT NULL DEFAULT 'USD',
    quote_currency CHAR(3) NOT NULL DEFAULT 'BRL',
    bid DECIMAL(15,6) NOT NULL,
    ask DECIMAL(15,6) NULL,
    source VARCHAR(80) NOT NULL,
    quoted_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rates_pair_date (base_currency, quote_currency, quoted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscription_id BIGINT UNSIGNED NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    description VARCHAR(190) NULL,
    amount DECIMAL(15,2) NOT NULL,
    currency ENUM('BRL','USD') NOT NULL DEFAULT 'BRL',
    exchange_rate DECIMAL(15,6) NOT NULL DEFAULT 1,
    exchange_rate_source VARCHAR(80) NULL,
    amount_brl DECIMAL(15,2) NOT NULL,
    fee_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    fee_brl DECIMAL(15,2) NOT NULL DEFAULT 0,
    net_brl DECIMAL(15,2) NOT NULL,
    status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'paid',
    due_date DATE NULL,
    payment_date DATE NULL,
    settlement_date DATE NULL,
    payment_method VARCHAR(80) NULL,
    external_reference VARCHAR(120) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_payments_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL,
    CONSTRAINT fk_payments_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    INDEX idx_payments_status_date (status, payment_date),
    INDEX idx_payments_settlement_date (settlement_date),
    INDEX idx_payments_client (client_id),
    INDEX idx_payments_subscription (subscription_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscription_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscription_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(50) NOT NULL,
    event_date DATE NOT NULL,
    summary VARCHAR(255) NOT NULL,
    details TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_subscription_events_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE,
    CONSTRAINT fk_subscription_events_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    CONSTRAINT fk_subscription_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_subscription_events_subscription (subscription_id, created_at),
    INDEX idx_subscription_events_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type ENUM('expense','investment') NOT NULL DEFAULT 'expense',
    category VARCHAR(80) NOT NULL,
    description VARCHAR(190) NOT NULL,
    supplier VARCHAR(160) NULL,
    amount DECIMAL(15,2) NOT NULL,
    currency ENUM('BRL','USD') NOT NULL DEFAULT 'BRL',
    exchange_rate DECIMAL(15,6) NOT NULL DEFAULT 1,
    amount_brl DECIMAL(15,2) NOT NULL,
    status ENUM('pending','paid') NOT NULL DEFAULT 'paid',
    payment_date DATE NOT NULL,
    is_recurring TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_expenses_status_date (status, payment_date),
    INDEX idx_expenses_type (type),
    INDEX idx_expenses_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cash_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    direction ENUM('in','out') NOT NULL,
    category VARCHAR(80) NOT NULL,
    description VARCHAR(190) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    currency ENUM('BRL','USD') NOT NULL DEFAULT 'BRL',
    exchange_rate DECIMAL(15,6) NOT NULL DEFAULT 1,
    amount_brl DECIMAL(15,2) NOT NULL,
    entry_date DATE NOT NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cash_date (entry_date),
    INDEX idx_cash_direction (direction)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
('company_name', 'Minha Empresa'),
('manual_exchange_rate', '5.500000'),
('exchange_cache_minutes', '720'),
('initial_balance_brl', '0.00'),
('schema_version', '4')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

SET FOREIGN_KEY_CHECKS = 1;
