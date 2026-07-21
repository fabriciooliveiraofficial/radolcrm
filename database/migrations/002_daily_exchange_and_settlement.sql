-- Referência manual para instalações existentes. A aplicação executa esta
-- migração automaticamente no primeiro acesso após o deploy.
ALTER TABLE payments
    ADD COLUMN exchange_rate_source VARCHAR(80) NULL AFTER exchange_rate,
    ADD COLUMN settlement_date DATE NULL AFTER payment_date,
    ADD INDEX idx_payments_settlement_date (settlement_date);

UPDATE settings SET setting_value='720'
WHERE setting_key='exchange_cache_minutes' AND setting_value='10';

DELETE FROM settings WHERE setting_key='awesome_api_key';

INSERT INTO settings (setting_key,setting_value) VALUES ('schema_version','2')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
