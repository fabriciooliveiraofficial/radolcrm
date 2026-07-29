ALTER TABLE payments
    ADD COLUMN base_amount DECIMAL(15,2) NULL AFTER amount,
    ADD COLUMN discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER base_amount,
    ADD COLUMN surcharge_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER discount_amount,
    ADD COLUMN manual_adjustment_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER surcharge_amount,
    ADD COLUMN renewal_mode ENUM('months','date') NULL AFTER manual_adjustment_amount,
    ADD COLUMN renewal_months TINYINT UNSIGNED NULL AFTER renewal_mode,
    ADD COLUMN renewal_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER renewal_months,
    ADD COLUMN renewal_start_date DATE NULL AFTER renewal_days,
    ADD COLUMN renewal_end_date DATE NULL AFTER renewal_start_date,
    ADD INDEX idx_payments_renewal_end (renewal_end_date);

INSERT INTO settings (setting_key,setting_value)
VALUES ('schema_version','7')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
