CREATE TABLE IF NOT EXISTS whatsapp_reminder_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscription_id BIGINT UNSIGNED NULL,
    client_id BIGINT UNSIGNED NULL,
    reminder_type ENUM('upcoming','overdue') NOT NULL,
    reminder_number SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    due_date DATE NOT NULL,
    recipient_phone VARCHAR(30) NOT NULL,
    rendered_message TEXT NOT NULL,
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
    UNIQUE INDEX uq_whatsapp_reminder_cycle (subscription_id, due_date, reminder_type, reminder_number),
    INDEX idx_whatsapp_reminder_status (status, created_at),
    INDEX idx_whatsapp_reminder_client (client_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key,setting_value) VALUES
('whatsapp_enabled','0'),
('whatsapp_instance_id',''),
('whatsapp_instance_token',''),
('whatsapp_client_token',''),
('whatsapp_send_time','09:00'),
('whatsapp_upcoming_enabled','1'),
('whatsapp_upcoming_start_days','1'),
('whatsapp_upcoming_interval_days','1'),
('whatsapp_upcoming_max_sends','1'),
('whatsapp_overdue_enabled','1'),
('whatsapp_overdue_start_days','1'),
('whatsapp_overdue_interval_days','3'),
('whatsapp_overdue_max_sends','3'),
('whatsapp_upcoming_message','Olá, {{primeiro_nome}}! Lembramos que sua assinatura {{produto}} vence em {{data_vencimento}}, no valor de {{valor}}. Se já realizou o pagamento, desconsidere esta mensagem. Atenciosamente, {{empresa}}.'),
('whatsapp_overdue_message','Olá, {{primeiro_nome}}! Identificamos que sua assinatura {{produto}}, no valor de {{valor}}, venceu em {{data_vencimento}}. Entre em contato conosco para regularizar. Se já realizou o pagamento, desconsidere esta mensagem. Atenciosamente, {{empresa}}.'),
('whatsapp_last_run_at',''),
('whatsapp_last_run_summary','')
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);

INSERT INTO settings (setting_key,setting_value) VALUES ('schema_version','5')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
