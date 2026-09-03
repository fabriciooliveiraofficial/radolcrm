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

CREATE TABLE IF NOT EXISTS business_units (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    icon VARCHAR(30) NOT NULL DEFAULT '💼',
    color VARCHAR(20) NOT NULL DEFAULT '#2b826b',
    is_personal TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_business_units_active (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_unit_id BIGINT UNSIGNED NULL,
    parent_id BIGINT UNSIGNED NULL,
    name VARCHAR(80) NOT NULL,
    type ENUM('expense','income','both') NOT NULL DEFAULT 'expense',
    icon VARCHAR(30) NOT NULL DEFAULT '📁',
    color VARCHAR(20) NULL,
    budget_limit_percent DECIMAL(5,2) NULL,
    budget_limit_amount DECIMAL(15,2) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_categories_business FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE SET NULL,
    CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_categories_bu (business_unit_id, active),
    INDEX idx_categories_parent (parent_id),
    INDEX idx_categories_type (type, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_unit_id BIGINT UNSIGNED NULL,
    name VARCHAR(160) NOT NULL,
    company VARCHAR(160) NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(50) NULL,
    whatsapp_reminders_enabled TINYINT(1) NOT NULL DEFAULT 1,
    document VARCHAR(60) NULL,
    country ENUM('BR','US') NOT NULL DEFAULT 'BR',
    preferred_currency ENUM('BRL','USD') NOT NULL DEFAULT 'BRL',
    status ENUM('lead','active','inactive') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_clients_business_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE SET NULL,
    INDEX idx_clients_status (status),
    INDEX idx_clients_name (name),
    INDEX idx_clients_email (email),
    INDEX idx_clients_bu (business_unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_unit_id BIGINT UNSIGNED NULL,
    category_id BIGINT UNSIGNED NULL,
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
    CONSTRAINT fk_products_business_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE SET NULL,
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_products_active (active),
    INDEX idx_products_bu (business_unit_id),
    INDEX idx_products_category (category_id)
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
    payment_link VARCHAR(1000) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_subscriptions_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    CONSTRAINT fk_subscriptions_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    INDEX idx_subscriptions_status (status),
    INDEX idx_subscriptions_next_billing (next_billing_date),
    INDEX idx_subscriptions_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_badges (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    icon VARCHAR(30) NOT NULL DEFAULT 'sparkles',
    tone VARCHAR(30) NOT NULL DEFAULT 'emerald',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uq_service_badges_name (name),
    INDEX idx_service_badges_active (active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscription_service_badges (
    subscription_id BIGINT UNSIGNED NOT NULL,
    badge_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (subscription_id, badge_id),
    CONSTRAINT fk_subscription_badges_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE,
    CONSTRAINT fk_subscription_badges_badge FOREIGN KEY (badge_id) REFERENCES service_badges(id) ON DELETE CASCADE,
    INDEX idx_subscription_badges_badge (badge_id, subscription_id)
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
    business_unit_id BIGINT UNSIGNED NULL,
    subscription_id BIGINT UNSIGNED NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NULL,
    description VARCHAR(190) NULL,
    amount DECIMAL(15,2) NOT NULL,
    base_amount DECIMAL(15,2) NULL,
    discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    surcharge_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    manual_adjustment_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    renewal_mode ENUM('months','date') NULL,
    renewal_months TINYINT UNSIGNED NULL,
    renewal_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    renewal_start_date DATE NULL,
    renewal_end_date DATE NULL,
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
    CONSTRAINT fk_payments_business_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE SET NULL,
    CONSTRAINT fk_payments_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_payments_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL,
    CONSTRAINT fk_payments_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    INDEX idx_payments_status_date (status, payment_date),
    INDEX idx_payments_settlement_date (settlement_date),
    INDEX idx_payments_renewal_end (renewal_end_date),
    INDEX idx_payments_client (client_id),
    INDEX idx_payments_subscription (subscription_id),
    INDEX idx_payments_bu (business_unit_id),
    INDEX idx_payments_category (category_id)
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

CREATE TABLE IF NOT EXISTS whatsapp_automation_steps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reminder_type ENUM('upcoming','overdue') NOT NULL,
    name VARCHAR(100) NOT NULL,
    day_offset SMALLINT UNSIGNED NOT NULL,
    send_time TIME NOT NULL DEFAULT '09:00:00',
    message_template TEXT NOT NULL,
    image_url VARCHAR(1000) NULL,
    payment_link VARCHAR(1000) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uq_whatsapp_step_offset (reminder_type, day_offset),
    INDEX idx_whatsapp_steps_active (reminder_type, active, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_reminder_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscription_id BIGINT UNSIGNED NULL,
    client_id BIGINT UNSIGNED NULL,
    automation_step_id BIGINT UNSIGNED NULL,
    reminder_type ENUM('upcoming','overdue') NOT NULL,
    reminder_number SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    due_date DATE NOT NULL,
    recipient_phone VARCHAR(30) NOT NULL,
    rendered_message TEXT NOT NULL,
    payload_type ENUM('text','image') NOT NULL DEFAULT 'text',
    media_url VARCHAR(1000) NULL,
    payment_link VARCHAR(1000) NULL,
    scheduled_for DATETIME NULL,
    status ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    provider_message_id VARCHAR(190) NULL,
    provider_response TEXT NULL,
    error_message VARCHAR(500) NULL,
    last_attempt_at DATETIME NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_whatsapp_reminder_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL,
    CONSTRAINT fk_whatsapp_reminder_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_whatsapp_reminder_step FOREIGN KEY (automation_step_id) REFERENCES whatsapp_automation_steps(id) ON DELETE SET NULL,
    UNIQUE INDEX uq_whatsapp_reminder_step (subscription_id, due_date, automation_step_id),
    INDEX idx_whatsapp_reminder_status (status, created_at),
    INDEX idx_whatsapp_reminder_client (client_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_unit_id BIGINT UNSIGNED NULL,
    category_id BIGINT UNSIGNED NULL,
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
    CONSTRAINT fk_expenses_business_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE SET NULL,
    CONSTRAINT fk_expenses_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_expenses_status_date (status, payment_date),
    INDEX idx_expenses_type (type),
    INDEX idx_expenses_category (category),
    INDEX idx_expenses_category_id (category_id),
    INDEX idx_expenses_bu (business_unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cash_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_unit_id BIGINT UNSIGNED NULL,
    category_id BIGINT UNSIGNED NULL,
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
    CONSTRAINT fk_cash_business_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE SET NULL,
    CONSTRAINT fk_cash_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_cash_date (entry_date),
    INDEX idx_cash_direction (direction),
    INDEX idx_cash_bu (business_unit_id),
    INDEX idx_cash_category_id (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recurring_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_unit_id BIGINT UNSIGNED NULL,
    category_id BIGINT UNSIGNED NULL,
    type ENUM('expense','income','credit_card') NOT NULL DEFAULT 'expense',
    description VARCHAR(190) NOT NULL,
    supplier VARCHAR(160) NULL,
    amount DECIMAL(15,2) NOT NULL,
    currency ENUM('BRL','USD') NOT NULL DEFAULT 'BRL',
    exchange_rate DECIMAL(15,6) NOT NULL DEFAULT 1,
    recurrence ENUM('monthly','weekly','biweekly','quarterly','annual') NOT NULL DEFAULT 'monthly',
    total_installments SMALLINT UNSIGNED NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    day_of_month TINYINT UNSIGNED NULL,
    auto_generate TINYINT(1) NOT NULL DEFAULT 1,
    auto_pay TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rec_templates_bu FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE SET NULL,
    CONSTRAINT fk_rec_templates_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_rec_templates_active (active),
    INDEX idx_rec_templates_bu (business_unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS installments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id BIGINT UNSIGNED NOT NULL,
    business_unit_id BIGINT UNSIGNED NULL,
    category_id BIGINT UNSIGNED NULL,
    installment_number SMALLINT UNSIGNED NOT NULL,
    total_installments SMALLINT UNSIGNED NULL,
    description VARCHAR(190) NOT NULL,
    supplier VARCHAR(160) NULL,
    amount DECIMAL(15,2) NOT NULL,
    currency ENUM('BRL','USD') NOT NULL DEFAULT 'BRL',
    exchange_rate DECIMAL(15,6) NOT NULL DEFAULT 1,
    amount_brl DECIMAL(15,2) NOT NULL,
    due_date DATE NOT NULL,
    payment_date DATE NULL,
    status ENUM('pending','paid','overdue','canceled') NOT NULL DEFAULT 'pending',
    expense_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_installments_template FOREIGN KEY (template_id) REFERENCES recurring_templates(id) ON DELETE CASCADE,
    CONSTRAINT fk_installments_bu FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE SET NULL,
    CONSTRAINT fk_installments_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_installments_expense FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE SET NULL,
    INDEX idx_installments_due_status (due_date, status),
    INDEX idx_installments_template (template_id, installment_number),
    INDEX idx_installments_bu (business_unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credit_cards (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_unit_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    brand VARCHAR(60) NOT NULL DEFAULT 'Mastercard',
    last_four_digits VARCHAR(4) NULL,
    credit_limit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    closing_day TINYINT UNSIGNED NOT NULL DEFAULT 1,
    due_day TINYINT UNSIGNED NOT NULL DEFAULT 10,
    color VARCHAR(30) NOT NULL DEFAULT '#6366f1',
    active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_credit_cards_bu FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE SET NULL,
    INDEX idx_credit_cards_active (active),
    INDEX idx_credit_cards_bu (business_unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credit_card_invoices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    card_id BIGINT UNSIGNED NOT NULL,
    reference_month VARCHAR(7) NOT NULL,
    closing_date DATE NOT NULL,
    due_date DATE NOT NULL,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    status ENUM('open','closed','paid') NOT NULL DEFAULT 'open',
    payment_date DATE NULL,
    expense_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_card_invoices_card FOREIGN KEY (card_id) REFERENCES credit_cards(id) ON DELETE CASCADE,
    CONSTRAINT fk_card_invoices_expense FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE SET NULL,
    UNIQUE KEY uk_card_month (card_id, reference_month),
    INDEX idx_card_invoices_due (due_date, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credit_card_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    card_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NULL,
    business_unit_id BIGINT UNSIGNED NULL,
    category_id BIGINT UNSIGNED NULL,
    transaction_date DATE NOT NULL,
    description VARCHAR(190) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    currency ENUM('BRL','USD') NOT NULL DEFAULT 'BRL',
    exchange_rate DECIMAL(15,6) NOT NULL DEFAULT 1,
    amount_brl DECIMAL(15,2) NOT NULL,
    installment_number SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    total_installments SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_card_tx_card FOREIGN KEY (card_id) REFERENCES credit_cards(id) ON DELETE CASCADE,
    CONSTRAINT fk_card_tx_invoice FOREIGN KEY (invoice_id) REFERENCES credit_card_invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_card_tx_bu FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE SET NULL,
    CONSTRAINT fk_card_tx_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_card_tx_date (transaction_date),
    INDEX idx_card_tx_invoice (invoice_id),
    INDEX idx_card_tx_bu (business_unit_id)
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
('whatsapp_enabled', '0'),
('whatsapp_instance_id', ''),
('whatsapp_instance_token', ''),
('whatsapp_client_token', ''),
('whatsapp_send_time', '09:00'),
('whatsapp_upcoming_enabled', '1'),
('whatsapp_upcoming_start_days', '1'),
('whatsapp_upcoming_interval_days', '1'),
('whatsapp_upcoming_max_sends', '1'),
('whatsapp_overdue_enabled', '1'),
('whatsapp_overdue_start_days', '1'),
('whatsapp_overdue_interval_days', '3'),
('whatsapp_overdue_max_sends', '3'),
('whatsapp_upcoming_message', 'Olá, {{primeiro_nome}}! Lembramos que sua assinatura {{produto}} vence em {{data_vencimento}}, no valor de {{valor}}. Se já realizou o pagamento, desconsidere esta mensagem. Atenciosamente, {{empresa}}.'),
('whatsapp_overdue_message', 'Olá, {{primeiro_nome}}! Identificamos que sua assinatura {{produto}}, no valor de {{valor}}, venceu em {{data_vencimento}}. Entre em contato conosco para regularizar. Se já realizou o pagamento, desconsidere esta mensagem. Atenciosamente, {{empresa}}.'),
('whatsapp_last_run_at', ''),
('whatsapp_last_run_summary', ''),
('whatsapp_timezone', 'America/Sao_Paulo'),
('whatsapp_window_start', '08:00'),
('whatsapp_window_end', '19:00'),
('whatsapp_allowed_weekdays', '1,2,3,4,5,6,7'),
('whatsapp_daily_limit', '200'),
('whatsapp_max_per_client_daily', '2'),
('whatsapp_max_attempts', '3'),
('whatsapp_retry_delay_minutes', '15'),
('whatsapp_support_phone', ''),
('whatsapp_test_phone', ''),
('whatsapp_test_country', 'BR'),
('schema_version', '15')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

INSERT INTO whatsapp_automation_steps
    (reminder_type,name,day_offset,send_time,message_template,active,position)
SELECT 'upcoming','Lembrete de amanhã',1,'09:00',
       'Olá, {{primeiro_nome}}! Sua assinatura {{produto}} vence amanhã, {{data_vencimento}}, no valor de {{valor}}. {{link_pagamento}}',
       1,1
WHERE NOT EXISTS (SELECT 1 FROM whatsapp_automation_steps WHERE reminder_type='upcoming');

INSERT INTO whatsapp_automation_steps
    (reminder_type,name,day_offset,send_time,message_template,active,position)
SELECT 'overdue','Primeira recuperação',1,'09:00',
       'Olá, {{primeiro_nome}}! Sua assinatura {{produto}} venceu em {{data_vencimento}}. Regularize pelo link: {{link_pagamento}}',
       1,1
WHERE NOT EXISTS (SELECT 1 FROM whatsapp_automation_steps WHERE reminder_type='overdue');

SET FOREIGN_KEY_CHECKS = 1;
