ALTER TABLE clients
    ADD COLUMN whatsapp_reminders_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER phone;

ALTER TABLE subscriptions
    ADD COLUMN payment_link VARCHAR(1000) NULL AFTER payment_method;

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

ALTER TABLE whatsapp_reminder_logs
    ADD COLUMN automation_step_id BIGINT UNSIGNED NULL AFTER client_id,
    ADD COLUMN payload_type ENUM('text','image') NOT NULL DEFAULT 'text' AFTER rendered_message,
    ADD COLUMN media_url VARCHAR(1000) NULL AFTER payload_type,
    ADD COLUMN payment_link VARCHAR(1000) NULL AFTER media_url,
    ADD COLUMN scheduled_for DATETIME NULL AFTER payment_link,
    DROP INDEX uq_whatsapp_reminder_cycle,
    ADD UNIQUE INDEX uq_whatsapp_reminder_step (subscription_id,due_date,automation_step_id),
    ADD CONSTRAINT fk_whatsapp_reminder_step FOREIGN KEY (automation_step_id) REFERENCES whatsapp_automation_steps(id) ON DELETE SET NULL;

INSERT INTO settings (setting_key,setting_value) VALUES
('whatsapp_timezone','America/Sao_Paulo'),
('whatsapp_window_start','08:00'),
('whatsapp_window_end','19:00'),
('whatsapp_allowed_weekdays','1,2,3,4,5,6,7'),
('whatsapp_daily_limit','200'),
('whatsapp_max_per_client_daily','2'),
('whatsapp_max_attempts','3'),
('whatsapp_retry_delay_minutes','15'),
('whatsapp_support_phone',''),
('whatsapp_test_phone',''),
('whatsapp_test_country','BR')
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);

INSERT INTO whatsapp_automation_steps
    (reminder_type,name,day_offset,send_time,message_template,active,position)
SELECT
    'upcoming','Lembrete de amanhã',1,
    COALESCE((SELECT setting_value FROM settings WHERE setting_key='whatsapp_send_time'),'09:00'),
    COALESCE((SELECT setting_value FROM settings WHERE setting_key='whatsapp_upcoming_message'),
             'Olá, {{primeiro_nome}}! Sua assinatura {{produto}} vence em {{data_vencimento}}.'),
    1,1
WHERE NOT EXISTS (SELECT 1 FROM whatsapp_automation_steps WHERE reminder_type='upcoming');

INSERT INTO whatsapp_automation_steps
    (reminder_type,name,day_offset,send_time,message_template,active,position)
SELECT
    'overdue','Primeira recuperação',1,
    COALESCE((SELECT setting_value FROM settings WHERE setting_key='whatsapp_send_time'),'09:00'),
    COALESCE((SELECT setting_value FROM settings WHERE setting_key='whatsapp_overdue_message'),
             'Olá, {{primeiro_nome}}! Sua assinatura {{produto}} venceu em {{data_vencimento}}.'),
    1,1
WHERE NOT EXISTS (SELECT 1 FROM whatsapp_automation_steps WHERE reminder_type='overdue');

INSERT INTO settings (setting_key,setting_value)
VALUES ('schema_version','8')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
