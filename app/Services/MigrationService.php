<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class MigrationService
{
    private const VERSION = 2;

    public function __construct(private readonly Database $db)
    {
    }

    public function run(): void
    {
        $version = (int) ($this->db->value("SELECT setting_value FROM settings WHERE setting_key='schema_version'") ?: 1);
        if ($version >= self::VERSION) {
            return;
        }

        if (!$this->columnExists('payments', 'exchange_rate_source')) {
            $this->db->query("ALTER TABLE payments ADD COLUMN exchange_rate_source VARCHAR(80) NULL AFTER exchange_rate");
        }
        if (!$this->columnExists('payments', 'settlement_date')) {
            $this->db->query("ALTER TABLE payments ADD COLUMN settlement_date DATE NULL AFTER payment_date, ADD INDEX idx_payments_settlement_date (settlement_date)");
        }

        $this->db->query("UPDATE settings SET setting_value='720' WHERE setting_key='exchange_cache_minutes' AND setting_value='10'");
        $this->db->query("DELETE FROM settings WHERE setting_key='awesome_api_key'");
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
