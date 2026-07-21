ALTER TABLE products
    ADD COLUMN pricing_mode ENUM('manual','brl','usd') NOT NULL DEFAULT 'manual' AFTER price_usd,
    ADD COLUMN price_exchange_rate DECIMAL(15,6) NULL AFTER pricing_mode,
    ADD COLUMN price_rate_source VARCHAR(80) NULL AFTER price_exchange_rate,
    ADD COLUMN price_rate_date DATE NULL AFTER price_rate_source;

INSERT INTO settings (setting_key, setting_value) VALUES ('schema_version', '4')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
