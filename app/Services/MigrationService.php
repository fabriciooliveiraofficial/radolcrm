<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class MigrationService
{
    private const VERSION = 8;

    public function __construct(private readonly Database $db)
    {
    }

    public function run(): void
    {
        $version = (int) ($this->db->value("SELECT setting_value FROM settings WHERE setting_key='schema_version'") ?: 1);
        if ($version >= self::VERSION) {
            return;
        }

        if ($version < 2) {
            if (!$this->columnExists('payments', 'exchange_rate_source')) {
                $this->db->query("ALTER TABLE payments ADD COLUMN exchange_rate_source VARCHAR(80) NULL AFTER exchange_rate");
            }
            if (!$this->columnExists('payments', 'settlement_date')) {
                $this->db->query("ALTER TABLE payments ADD COLUMN settlement_date DATE NULL AFTER payment_date, ADD INDEX idx_payments_settlement_date (settlement_date)");
            }

            $this->db->query("UPDATE settings SET setting_value='720' WHERE setting_key='exchange_cache_minutes' AND setting_value='10'");
            $this->db->query("DELETE FROM settings WHERE setting_key='awesome_api_key'");
        }

        if ($version < 3) {
            $this->db->query(
                "CREATE TABLE IF NOT EXISTS subscription_events (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        if ($version < 4) {
            if (!$this->columnExists('products', 'pricing_mode')) {
                $this->db->query("ALTER TABLE products ADD COLUMN pricing_mode ENUM('manual','brl','usd') NOT NULL DEFAULT 'manual' AFTER price_usd");
            }
            if (!$this->columnExists('products', 'price_exchange_rate')) {
                $this->db->query("ALTER TABLE products ADD COLUMN price_exchange_rate DECIMAL(15,6) NULL AFTER pricing_mode");
            }
            if (!$this->columnExists('products', 'price_rate_source')) {
                $this->db->query("ALTER TABLE products ADD COLUMN price_rate_source VARCHAR(80) NULL AFTER price_exchange_rate");
            }
            if (!$this->columnExists('products', 'price_rate_date')) {
                $this->db->query("ALTER TABLE products ADD COLUMN price_rate_date DATE NULL AFTER price_rate_source");
            }
        }
        if ($version < 5) {
            $this->db->query(
                "CREATE TABLE IF NOT EXISTS whatsapp_reminder_logs (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $defaults = [
                'whatsapp_enabled' => '0',
                'whatsapp_instance_id' => '',
                'whatsapp_instance_token' => '',
                'whatsapp_client_token' => '',
                'whatsapp_send_time' => '09:00',
                'whatsapp_upcoming_enabled' => '1',
                'whatsapp_upcoming_start_days' => '1',
                'whatsapp_upcoming_interval_days' => '1',
                'whatsapp_upcoming_max_sends' => '1',
                'whatsapp_overdue_enabled' => '1',
                'whatsapp_overdue_start_days' => '1',
                'whatsapp_overdue_interval_days' => '3',
                'whatsapp_overdue_max_sends' => '3',
                'whatsapp_upcoming_message' => 'Olá, {{primeiro_nome}}! Lembramos que sua assinatura {{produto}} vence em {{data_vencimento}}, no valor de {{valor}}. Se já realizou o pagamento, desconsidere esta mensagem. Atenciosamente, {{empresa}}.',
                'whatsapp_overdue_message' => 'Olá, {{primeiro_nome}}! Identificamos que sua assinatura {{produto}}, no valor de {{valor}}, venceu em {{data_vencimento}}. Entre em contato conosco para regularizar. Se já realizou o pagamento, desconsidere esta mensagem. Atenciosamente, {{empresa}}.',
                'whatsapp_last_run_at' => '',
                'whatsapp_last_run_summary' => '',
            ];
            foreach ($defaults as $key => $value) {
                $this->db->query(
                    'INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)',
                    [$key,$value]
                );
            }
        }
        if ($version < 6) {
            $this->db->query(
                "CREATE TABLE IF NOT EXISTS service_badges (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(80) NOT NULL,
                    icon VARCHAR(30) NOT NULL DEFAULT 'sparkles',
                    tone VARCHAR(30) NOT NULL DEFAULT 'emerald',
                    active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE INDEX uq_service_badges_name (name),
                    INDEX idx_service_badges_active (active, name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $this->db->query(
                "CREATE TABLE IF NOT EXISTS subscription_service_badges (
                    subscription_id BIGINT UNSIGNED NOT NULL,
                    badge_id BIGINT UNSIGNED NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (subscription_id, badge_id),
                    CONSTRAINT fk_subscription_badges_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE,
                    CONSTRAINT fk_subscription_badges_badge FOREIGN KEY (badge_id) REFERENCES service_badges(id) ON DELETE CASCADE,
                    INDEX idx_subscription_badges_badge (badge_id, subscription_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        if ($version < 7) {
            if (!$this->columnExists('payments', 'base_amount')) {
                $this->db->query("ALTER TABLE payments ADD COLUMN base_amount DECIMAL(15,2) NULL AFTER amount");
            }
            if (!$this->columnExists('payments', 'discount_amount')) {
                $this->db->query("ALTER TABLE payments ADD COLUMN discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER base_amount");
            }
            if (!$this->columnExists('payments', 'surcharge_amount')) {
                $this->db->query("ALTER TABLE payments ADD COLUMN surcharge_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER discount_amount");
            }
            if (!$this->columnExists('payments', 'manual_adjustment_amount')) {
                $this->db->query("ALTER TABLE payments ADD COLUMN manual_adjustment_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER surcharge_amount");
            }
            if (!$this->columnExists('payments', 'renewal_mode')) {
                $this->db->query("ALTER TABLE payments ADD COLUMN renewal_mode ENUM('months','date') NULL AFTER manual_adjustment_amount");
            }
            if (!$this->columnExists('payments', 'renewal_months')) {
                $this->db->query("ALTER TABLE payments ADD COLUMN renewal_months TINYINT UNSIGNED NULL AFTER renewal_mode");
            }
            if (!$this->columnExists('payments', 'renewal_days')) {
                $this->db->query("ALTER TABLE payments ADD COLUMN renewal_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER renewal_months");
            }
            if (!$this->columnExists('payments', 'renewal_start_date')) {
                $this->db->query("ALTER TABLE payments ADD COLUMN renewal_start_date DATE NULL AFTER renewal_days");
            }
            if (!$this->columnExists('payments', 'renewal_end_date')) {
                $this->db->query("ALTER TABLE payments ADD COLUMN renewal_end_date DATE NULL AFTER renewal_start_date, ADD INDEX idx_payments_renewal_end (renewal_end_date)");
            }
        }
        if ($version < 8) {
            if (!$this->columnExists('clients', 'whatsapp_reminders_enabled')) {
                $this->db->query("ALTER TABLE clients ADD COLUMN whatsapp_reminders_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER phone");
            }
            if (!$this->columnExists('subscriptions', 'payment_link')) {
                $this->db->query("ALTER TABLE subscriptions ADD COLUMN payment_link VARCHAR(1000) NULL AFTER payment_method");
            }

            $this->db->query(
                "CREATE TABLE IF NOT EXISTS whatsapp_automation_steps (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            if (!$this->columnExists('whatsapp_reminder_logs', 'automation_step_id')) {
                $this->db->query("ALTER TABLE whatsapp_reminder_logs ADD COLUMN automation_step_id BIGINT UNSIGNED NULL AFTER client_id");
            }
            if (!$this->columnExists('whatsapp_reminder_logs', 'payload_type')) {
                $this->db->query("ALTER TABLE whatsapp_reminder_logs ADD COLUMN payload_type ENUM('text','image') NOT NULL DEFAULT 'text' AFTER rendered_message");
            }
            if (!$this->columnExists('whatsapp_reminder_logs', 'media_url')) {
                $this->db->query("ALTER TABLE whatsapp_reminder_logs ADD COLUMN media_url VARCHAR(1000) NULL AFTER payload_type");
            }
            if (!$this->columnExists('whatsapp_reminder_logs', 'payment_link')) {
                $this->db->query("ALTER TABLE whatsapp_reminder_logs ADD COLUMN payment_link VARCHAR(1000) NULL AFTER media_url");
            }
            if (!$this->columnExists('whatsapp_reminder_logs', 'scheduled_for')) {
                $this->db->query("ALTER TABLE whatsapp_reminder_logs ADD COLUMN scheduled_for DATETIME NULL AFTER payment_link");
            }
            if ($this->indexExists('whatsapp_reminder_logs', 'uq_whatsapp_reminder_cycle')) {
                $this->optionalDdl('ALTER TABLE whatsapp_reminder_logs DROP INDEX uq_whatsapp_reminder_cycle');
            }
            if (!$this->indexExists('whatsapp_reminder_logs', 'uq_whatsapp_reminder_step')) {
                $this->optionalDdl(
                    'ALTER TABLE whatsapp_reminder_logs ADD UNIQUE INDEX uq_whatsapp_reminder_step (subscription_id,due_date,automation_step_id)'
                );
            }
            if (!$this->constraintExists('whatsapp_reminder_logs', 'fk_whatsapp_reminder_step')) {
                $this->optionalDdl(
                    'ALTER TABLE whatsapp_reminder_logs ADD CONSTRAINT fk_whatsapp_reminder_step
                     FOREIGN KEY (automation_step_id) REFERENCES whatsapp_automation_steps(id) ON DELETE SET NULL'
                );
            }

            $defaults = [
                'whatsapp_timezone' => 'America/Sao_Paulo',
                'whatsapp_window_start' => '08:00',
                'whatsapp_window_end' => '19:00',
                'whatsapp_allowed_weekdays' => '1,2,3,4,5,6,7',
                'whatsapp_daily_limit' => '200',
                'whatsapp_max_per_client_daily' => '2',
                'whatsapp_max_attempts' => '3',
                'whatsapp_retry_delay_minutes' => '15',
                'whatsapp_support_phone' => '',
                'whatsapp_test_phone' => '',
                'whatsapp_test_country' => 'BR',
            ];
            foreach ($defaults as $key => $value) {
                $this->db->query(
                    'INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)',
                    [$key,$value]
                );
            }

            if ((int) $this->db->value('SELECT COUNT(*) FROM whatsapp_automation_steps') === 0) {
                $sendTime = (string) ($this->db->value("SELECT setting_value FROM settings WHERE setting_key='whatsapp_send_time'") ?: '09:00');
                $upcomingMessage = (string) ($this->db->value("SELECT setting_value FROM settings WHERE setting_key='whatsapp_upcoming_message'") ?: 'Olá, {{primeiro_nome}}! Sua assinatura {{produto}} vence em {{data_vencimento}}.');
                $overdueMessage = (string) ($this->db->value("SELECT setting_value FROM settings WHERE setting_key='whatsapp_overdue_message'") ?: 'Olá, {{primeiro_nome}}! Sua assinatura {{produto}} venceu em {{data_vencimento}}.');
                $upcomingStart = max(1, (int) ($this->db->value("SELECT setting_value FROM settings WHERE setting_key='whatsapp_upcoming_start_days'") ?: 1));
                $upcomingInterval = max(1, (int) ($this->db->value("SELECT setting_value FROM settings WHERE setting_key='whatsapp_upcoming_interval_days'") ?: 1));
                $upcomingMax = max(1, min(10, (int) ($this->db->value("SELECT setting_value FROM settings WHERE setting_key='whatsapp_upcoming_max_sends'") ?: 1)));
                $overdueStart = max(1, (int) ($this->db->value("SELECT setting_value FROM settings WHERE setting_key='whatsapp_overdue_start_days'") ?: 1));
                $overdueInterval = max(1, (int) ($this->db->value("SELECT setting_value FROM settings WHERE setting_key='whatsapp_overdue_interval_days'") ?: 3));
                $overdueMax = max(1, min(20, (int) ($this->db->value("SELECT setting_value FROM settings WHERE setting_key='whatsapp_overdue_max_sends'") ?: 3)));

                for ($position = 1; $position <= $upcomingMax; $position++) {
                    $offset = $upcomingStart - (($position - 1) * $upcomingInterval);
                    if ($offset < 1) {
                        break;
                    }
                    $this->db->query(
                        "INSERT INTO whatsapp_automation_steps
                         (reminder_type,name,day_offset,send_time,message_template,active,position)
                         VALUES ('upcoming',?,?,?,?,?,?)",
                        ['Lembrete ' . $offset . ' dia(s) antes',$offset,$sendTime,$upcomingMessage,1,$position]
                    );
                }
                for ($position = 1; $position <= $overdueMax; $position++) {
                    $offset = $overdueStart + (($position - 1) * $overdueInterval);
                    $this->db->query(
                        "INSERT INTO whatsapp_automation_steps
                         (reminder_type,name,day_offset,send_time,message_template,active,position)
                         VALUES ('overdue',?,?,?,?,?,?)",
                        ['Recuperação ' . $offset . ' dia(s) depois',$offset,$sendTime,$overdueMessage,1,$position]
                    );
                }
            }
        }
        $this->db->query(
            "INSERT INTO settings (setting_key,setting_value) VALUES ('schema_version',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",
            [(string) self::VERSION]
        );
    }

    private function columnExists(string $table, string $column): bool
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
            [$table, $column]
        ) > 0;
    }

    private function indexExists(string $table, string $index): bool
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',
            [$table, $index]
        ) > 0;
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?',
            [$table, $constraint]
        ) > 0;
    }

    private function optionalDdl(string $sql): void
    {
        try {
            $this->db->query($sql);
        } catch (\Throwable $exception) {
            error_log('[Nexo migration optional DDL] ' . $exception->getMessage());
        }
    }
}
