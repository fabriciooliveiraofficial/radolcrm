<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class MigrationService
{
    private const VERSION = 4;

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
}
