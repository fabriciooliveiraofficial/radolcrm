-- Migration 020: Soft delete para clientes e suporte a exclusao avancada
ALTER TABLE clients
  ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER updated_at,
  ADD INDEX idx_clients_deleted (deleted_at);

INSERT INTO settings (setting_key, setting_value)
VALUES ('schema_version', '20')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
