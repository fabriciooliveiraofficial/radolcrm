<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class MigrationService
{
    private const VERSION = 15;

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
        if ($version < 9) {
            $this->db->query(
                "CREATE TABLE IF NOT EXISTS business_units (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $this->db->query(
                "CREATE TABLE IF NOT EXISTS categories (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            if (!$this->columnExists('clients', 'business_unit_id')) {
                $this->db->query("ALTER TABLE clients ADD COLUMN business_unit_id BIGINT UNSIGNED NULL AFTER id, ADD INDEX idx_clients_bu (business_unit_id)");
                $this->optionalDdl("ALTER TABLE clients ADD CONSTRAINT fk_clients_business_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE SET NULL");
            }
            if (!$this->columnExists('products', 'business_unit_id')) {
                $this->db->query("ALTER TABLE products ADD COLUMN business_unit_id BIGINT UNSIGNED NULL AFTER id, ADD INDEX idx_products_bu (business_unit_id)");
                $this->optionalDdl("ALTER TABLE products ADD CONSTRAINT fk_products_business_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE SET NULL");
            }
            if (!$this->columnExists('payments', 'business_unit_id')) {
                $this->db->query("ALTER TABLE payments ADD COLUMN business_unit_id BIGINT UNSIGNED NULL AFTER id, ADD INDEX idx_payments_bu (business_unit_id)");
                $this->optionalDdl("ALTER TABLE payments ADD CONSTRAINT fk_payments_business_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE SET NULL");
            }
            if (!$this->columnExists('payments', 'category_id')) {
                $this->db->query("ALTER TABLE payments ADD COLUMN category_id BIGINT UNSIGNED NULL AFTER client_id, ADD INDEX idx_payments_category (category_id)");
                $this->optionalDdl("ALTER TABLE payments ADD CONSTRAINT fk_payments_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL");
            }
            if (!$this->columnExists('expenses', 'business_unit_id')) {
                $this->db->query("ALTER TABLE expenses ADD COLUMN business_unit_id BIGINT UNSIGNED NULL AFTER id, ADD INDEX idx_expenses_bu (business_unit_id)");
                $this->optionalDdl("ALTER TABLE expenses ADD CONSTRAINT fk_expenses_business_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE SET NULL");
            }
            if (!$this->columnExists('expenses', 'category_id')) {
                $this->db->query("ALTER TABLE expenses ADD COLUMN category_id BIGINT UNSIGNED NULL AFTER business_unit_id, ADD INDEX idx_expenses_category_id (category_id)");
                $this->optionalDdl("ALTER TABLE expenses ADD CONSTRAINT fk_expenses_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL");
            }
            if (!$this->columnExists('cash_entries', 'business_unit_id')) {
                $this->db->query("ALTER TABLE cash_entries ADD COLUMN business_unit_id BIGINT UNSIGNED NULL AFTER id, ADD INDEX idx_cash_bu (business_unit_id)");
                $this->optionalDdl("ALTER TABLE cash_entries ADD CONSTRAINT fk_cash_business_unit FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE SET NULL");
            }
            if (!$this->columnExists('cash_entries', 'category_id')) {
                $this->db->query("ALTER TABLE cash_entries ADD COLUMN category_id BIGINT UNSIGNED NULL AFTER business_unit_id, ADD INDEX idx_cash_category_id (category_id)");
                $this->optionalDdl("ALTER TABLE cash_entries ADD CONSTRAINT fk_cash_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL");
            }

            // Seed default business units
            $companyName = (string) ($this->db->value("SELECT setting_value FROM settings WHERE setting_key='company_name'") ?: 'Gearzone Apps');
            $buCount = (int) $this->db->value("SELECT COUNT(*) FROM business_units");
            if ($buCount === 0) {
                $mainBuId = $this->db->insert(
                    "INSERT INTO business_units (name, icon, color, is_personal, active, sort_order) VALUES (?, '💼', '#2b826b', 0, 1, 1)",
                    [$companyName]
                );
                $personalBuId = $this->db->insert(
                    "INSERT INTO business_units (name, icon, color, is_personal, active, sort_order) VALUES ('Pessoal / Família', '🏠', '#6366f1', 1, 1, 2)"
                );
            } else {
                $mainBuId = (int) $this->db->value("SELECT id FROM business_units WHERE is_personal = 0 ORDER BY sort_order ASC, id ASC LIMIT 1");
                $personalBuId = (int) $this->db->value("SELECT id FROM business_units WHERE is_personal = 1 ORDER BY sort_order ASC, id ASC LIMIT 1");
                if (!$mainBuId) {
                    $mainBuId = (int) $this->db->value("SELECT id FROM business_units ORDER BY id ASC LIMIT 1");
                }
            }

            // Seed default categories
            $catCount = (int) $this->db->value("SELECT COUNT(*) FROM categories");
            if ($catCount === 0) {
                // Business categories
                $bizCategories = [
                    ['Marketing', 'expense', '📣', '#f59e0b', null, [
                        'Anúncios online', 'Influenciadores e parcerias', 'Design e identidade'
                    ]],
                    ['Software e ferramentas', 'expense', '💻', '#3b82f6', null, [
                        'SaaS e assinaturas', 'Servidores e hospedagem', 'Domínios e SSL'
                    ]],
                    ['Impostos e taxas', 'expense', '🏛️', '#ef4444', null, [
                        'MEI / Simples Nacional', 'Taxas bancárias', 'Contabilidade e notas'
                    ]],
                    ['Equipe e parceiros', 'expense', '👥', '#8b5cf6', null, [
                        'Prestadores e freelancers', 'Comissões de vendas'
                    ]],
                    ['Infraestrutura e escritório', 'expense', '🏢', '#64748b', null, []],
                    ['Receitas de Assinaturas', 'income', '💎', '#10b981', null, [
                        'Planos mensais', 'Planos anuais', 'Setup e ativação'
                    ]],
                ];

                foreach ($bizCategories as $cat) {
                    $parentId = $this->db->insert(
                        "INSERT INTO categories (business_unit_id, parent_id, name, type, icon, color, active, sort_order) VALUES (?, NULL, ?, ?, ?, ?, 1, 0)",
                        [$mainBuId ?: null, $cat[0], $cat[1], $cat[2], $cat[3]]
                    );
                    foreach ($cat[5] as $subName) {
                        $this->db->insert(
                            "INSERT INTO categories (business_unit_id, parent_id, name, type, icon, color, active, sort_order) VALUES (?, ?, ?, ?, ?, ?, 1, 0)",
                            [$mainBuId ?: null, $parentId, $subName, $cat[1], '🔹', $cat[3]]
                        );
                    }
                }

                // Personal categories
                $personalCategories = [
                    ['Moradia', 'expense', '🏠', '#0284c7', 25.0, [
                        'Aluguel / Condomínio', 'Luz / Energia elétrica', 'Água e esgoto', 'Internet / Wi-Fi', 'Gás residencial'
                    ]],
                    ['Transporte e Veículo', 'expense', '🚗', '#d97706', 15.0, [
                        'Gasolina / Combustível', 'Mecânica e manutenção', 'Seguro e IPVA', 'Uber e táxi'
                    ]],
                    ['Alimentação e Mercado', 'expense', '🛒', '#10b981', 20.0, [
                        'Mercado e feira', 'Restaurantes e padaria', 'Delivery / Pizza / Lanches'
                    ]],
                    ['Saúde e Bem-estar', 'expense', '🩺', '#ec4899', 10.0, [
                        'Plano de saúde', 'Farmácia e medicamentos', 'Consultas e exames'
                    ]],
                    ['Educação e Filhos', 'expense', '🎓', '#8b5cf6', 15.0, [
                        'Escola dos filhos', 'Vale transporte / Condução', 'Cursos e treinamentos', 'Material e livros'
                    ]],
                    ['Telecomunicações', 'expense', '📱', '#06b6d4', 5.0, [
                        'Pacote de dados celular', 'Telefonia móvel'
                    ]],
                    ['Financiamentos e Empréstimos', 'expense', '🏦', '#b91c1c', 15.0, [
                        'Financiamento do carro', 'Financiamento imobiliário', 'Parcelas e empréstimos'
                    ]],
                    ['Lazer e Família', 'expense', '🍿', '#f97316', 5.0, [
                        'Streaming e entretenimento', 'Passeios e viagens', 'Presentes e compras'
                    ]],
                ];

                foreach ($personalCategories as $cat) {
                    $parentId = $this->db->insert(
                        "INSERT INTO categories (business_unit_id, parent_id, name, type, icon, color, budget_limit_percent, active, sort_order) VALUES (?, NULL, ?, ?, ?, ?, ?, 1, 0)",
                        [$personalBuId ?: null, $cat[0], $cat[1], $cat[2], $cat[3], $cat[4]]
                    );
                    foreach ($cat[5] as $subName) {
                        $this->db->insert(
                            "INSERT INTO categories (business_unit_id, parent_id, name, type, icon, color, active, sort_order) VALUES (?, ?, ?, ?, ?, ?, 1, 0)",
                            [$personalBuId ?: null, $parentId, $subName, $cat[1], '🔹', $cat[3]]
                        );
                    }
                }
            }

            // Associate existing entities to the default business unit
            if ($mainBuId) {
                $this->db->query("UPDATE clients SET business_unit_id = ? WHERE business_unit_id IS NULL", [$mainBuId]);
                $this->db->query("UPDATE products SET business_unit_id = ? WHERE business_unit_id IS NULL", [$mainBuId]);
                $this->db->query("UPDATE payments SET business_unit_id = ? WHERE business_unit_id IS NULL", [$mainBuId]);
                $this->db->query("UPDATE expenses SET business_unit_id = ? WHERE business_unit_id IS NULL", [$mainBuId]);
                $this->db->query("UPDATE cash_entries SET business_unit_id = ? WHERE business_unit_id IS NULL", [$mainBuId]);
            }

            // Map string category names to category_id
            $existingExpenses = $this->db->fetchAll("SELECT id, category FROM expenses WHERE category_id IS NULL AND category IS NOT NULL AND category != ''");
            foreach ($existingExpenses as $exp) {
                $catId = (int) $this->db->value("SELECT id FROM categories WHERE name = ? LIMIT 1", [$exp['category']]);
                if (!$catId) {
                    $catId = (int) $this->db->value("SELECT id FROM categories WHERE name LIKE ? LIMIT 1", ['%' . $exp['category'] . '%']);
                }
                if ($catId) {
                    $this->db->query("UPDATE expenses SET category_id = ? WHERE id = ?", [$catId, $exp['id']]);
                }
            }
        }
        if ($version < 10) {
            $this->db->query(
                "CREATE TABLE IF NOT EXISTS recurring_templates (
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
                    notes TEXT NULL,
                    active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_rec_templates_bu FOREIGN KEY (business_unit_id) REFERENCES business_units(id) ON DELETE SET NULL,
                    CONSTRAINT fk_rec_templates_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
                    INDEX idx_rec_templates_active (active),
                    INDEX idx_rec_templates_bu (business_unit_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $this->db->query(
                "CREATE TABLE IF NOT EXISTS installments (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        if ($version < 11) {
            $this->db->query(
                "CREATE TABLE IF NOT EXISTS credit_cards (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $this->db->query(
                "CREATE TABLE IF NOT EXISTS credit_card_invoices (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $this->db->query(
                "CREATE TABLE IF NOT EXISTS credit_card_transactions (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        if ($version < 12) {
            $this->db->query("ALTER TABLE categories MODIFY COLUMN type ENUM('expense','income','investment','both') NOT NULL DEFAULT 'expense'");
            
            $mainBuId = (int) $this->db->value("SELECT id FROM business_units WHERE is_personal = 0 ORDER BY sort_order ASC, id ASC LIMIT 1") ?: 1;
            
            // Fix any orphaned records without business_unit_id
            $this->db->query("UPDATE clients SET business_unit_id = ? WHERE business_unit_id IS NULL OR business_unit_id = 0", [$mainBuId]);
            $this->db->query("UPDATE products SET business_unit_id = ? WHERE business_unit_id IS NULL OR business_unit_id = 0", [$mainBuId]);
            $this->db->query("UPDATE payments SET business_unit_id = ? WHERE business_unit_id IS NULL OR business_unit_id = 0", [$mainBuId]);
            $this->db->query("UPDATE expenses SET business_unit_id = ? WHERE business_unit_id IS NULL OR business_unit_id = 0", [$mainBuId]);
            $this->db->query("UPDATE cash_entries SET business_unit_id = ? WHERE business_unit_id IS NULL OR business_unit_id = 0", [$mainBuId]);

            // Map any unmapped expenses to categories
            $expenses = $this->db->fetchAll("SELECT id, category FROM expenses WHERE category_id IS NULL AND category IS NOT NULL AND category != ''");
            foreach ($expenses as $exp) {
                $catId = (int) $this->db->value("SELECT id FROM categories WHERE name = ? LIMIT 1", [$exp['category']]);
                if (!$catId) {
                    $catId = (int) $this->db->value("SELECT id FROM categories WHERE name LIKE ? LIMIT 1", ['%' . $exp['category'] . '%']);
                }
                if ($catId) {
                    $this->db->query("UPDATE expenses SET category_id = ? WHERE id = ?", [$catId, $exp['id']]);
                }
            }
        }
        if ($version < 13) {
            $mainBuId = (int) ($this->db->value("SELECT id FROM business_units WHERE active = 1 ORDER BY sort_order ASC, id ASC LIMIT 1") ?: 1);

            // 1. Vincular pagamentos a partir do client_id do cliente
            $this->db->query("
                UPDATE payments p
                JOIN clients c ON c.id = p.client_id
                SET p.business_unit_id = COALESCE(c.business_unit_id, ?)
                WHERE p.business_unit_id IS NULL OR p.business_unit_id = 0
            ", [$mainBuId]);

            // 2. Vincular pagamentos a partir da assinatura e cliente
            $this->db->query("
                UPDATE payments p
                JOIN subscriptions s ON s.id = p.subscription_id
                JOIN clients c ON c.id = s.client_id
                SET p.business_unit_id = COALESCE(c.business_unit_id, ?)
                WHERE p.business_unit_id IS NULL OR p.business_unit_id = 0
            ", [$mainBuId]);

            // 3. Fallback para qualquer pagamento remanescente sem unidade
            $this->db->query("
                UPDATE payments SET business_unit_id = ?
                WHERE business_unit_id IS NULL OR business_unit_id = 0
            ", [$mainBuId]);

            // 4. Garantir consistência em despesas e movimentações avulsas
            $this->db->query("
                UPDATE expenses SET business_unit_id = ?
                WHERE business_unit_id IS NULL OR business_unit_id = 0
            ", [$mainBuId]);

            $this->db->query("
                UPDATE cash_entries SET business_unit_id = ?
                WHERE business_unit_id IS NULL OR business_unit_id = 0
            ", [$mainBuId]);
        }
        if ($version < 14) {
            // 1. Adicionar category_id em products se não existir
            if (!$this->columnExists('products', 'category_id')) {
                $this->db->query("ALTER TABLE products ADD COLUMN category_id BIGINT UNSIGNED NULL AFTER business_unit_id, ADD INDEX idx_products_category (category_id)");
                $this->optionalDdl("ALTER TABLE products ADD CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL");
            }

            // 2. Garantir que existam categorias de receita padrão
            $mainBuId = (int) ($this->db->value("SELECT id FROM business_units WHERE active = 1 ORDER BY sort_order ASC, id ASC LIMIT 1") ?: 1);

            $defaultIncomeCategories = [
                ['name' => 'Receitas com Assinaturas', 'icon' => '💎', 'color' => '#10b981', 'sort_order' => 1],
                ['name' => 'Vendas de Produtos', 'icon' => '📦', 'color' => '#3b82f6', 'sort_order' => 2],
                ['name' => 'Serviços e Consultorias', 'icon' => '🛠️', 'color' => '#8b5cf6', 'sort_order' => 3],
                ['name' => 'Outras Receitas', 'icon' => '💰', 'color' => '#f59e0b', 'sort_order' => 4],
            ];

            foreach ($defaultIncomeCategories as $cat) {
                $existing = $this->db->value("SELECT id FROM categories WHERE name = ? AND type IN ('income', 'both') LIMIT 1", [$cat['name']]);
                if (!$existing) {
                    $this->db->query(
                        "INSERT INTO categories (business_unit_id, name, type, icon, color, active, sort_order) VALUES (?, ?, 'income', ?, ?, 1, ?)",
                        [$mainBuId, $cat['name'], $cat['icon'], $cat['color'], $cat['sort_order']]
                    );
                }
            }

            $subCatId = (int) $this->db->value("SELECT id FROM categories WHERE name = 'Receitas com Assinaturas' LIMIT 1")
                ?: (int) $this->db->value("SELECT id FROM categories WHERE type IN ('income', 'both') LIMIT 1");

            // 3. Vincular produtos existentes à categoria de assinaturas se estiverem nulos
            if ($subCatId > 0) {
                $this->db->query("UPDATE products SET category_id = ? WHERE category_id IS NULL OR category_id = 0", [$subCatId]);
            }

            // 4. Backfill retroativo em pagamentos
            // 4.1 Pagamentos gerados a partir de assinatura pegam a categoria do produto
            $this->db->query("
                UPDATE payments p
                JOIN subscriptions s ON s.id = p.subscription_id
                JOIN products pr ON pr.id = s.product_id
                SET p.category_id = pr.category_id
                WHERE (p.category_id IS NULL OR p.category_id = 0) AND pr.category_id IS NOT NULL
            ");

            // 4.2 Pagamentos avulsos que ainda estiverem sem categoria recebem a categoria padrão
            if ($subCatId > 0) {
                $this->db->query("
                    UPDATE payments
                    SET category_id = ?
                    WHERE category_id IS NULL OR category_id = 0
                ", [$subCatId]);
            }
        }
        if ($version < 15) {
            // 1. Adicionar auto_pay em recurring_templates
            if (!$this->columnExists('recurring_templates', 'auto_pay')) {
                $this->optionalDdl("ALTER TABLE recurring_templates ADD COLUMN auto_pay TINYINT(1) NOT NULL DEFAULT 0 AFTER auto_generate");
            }

            // 2. Garantir as 8 Macro-Categorias Universais Enxutas para Empresas (Plano de Contas Enxuto)
            $mainBuId = (int) $this->db->value("SELECT id FROM business_units WHERE active = 1 ORDER BY sort_order ASC, id ASC LIMIT 1");
            $leanCategories = [
                ['Softwares, Cloud & Ferramentas (SaaS)', 'expense', '🛠️', '#3b82f6', 1],
                ['Operação, Sede & Infraestrutura', 'expense', '🏢', '#64748b', 2],
                ['Equipe, Parceiros & Terceiros', 'expense', '👥', '#8b5cf6', 3],
                ['Marketing, Vendas & Publicidade', 'expense', '📣', '#f59e0b', 4],
                ['Mobilidade, Logística & Viagens', 'expense', '🚗', '#d97706', 5],
                ['Impostos, Tributos & Taxas', 'expense', '🏛️', '#ef4444', 6],
                ['Alimentação & Despesas Diárias', 'expense', '☕', '#10b981', 7],
                ['Investimentos & Equipamentos', 'expense', '💰', '#06b6d4', 8],
            ];

            foreach ($leanCategories as $cat) {
                $existing = $this->db->fetch("SELECT id FROM categories WHERE name = ? AND parent_id IS NULL LIMIT 1", [$cat[0]]);
                if (!$existing) {
                    $this->db->insert(
                        "INSERT INTO categories (business_unit_id, parent_id, name, type, icon, color, active, sort_order) VALUES (?, NULL, ?, ?, ?, ?, 1, ?)",
                        [$mainBuId ?: null, $cat[0], $cat[1], $cat[2], $cat[3], $cat[4]]
                    );
                } else {
                    $this->db->query("UPDATE categories SET active = 1, icon = ?, color = ?, sort_order = ? WHERE id = ?", [
                        $cat[2], $cat[3], $cat[4], $existing['id']
                    ]);
                }
            }

            // 3. Remapear lançamentos históricos ligados a subcategorias para a categoria-pai correspondente
            $this->optionalDdl("
                UPDATE expenses e
                JOIN categories c ON c.id = e.category_id
                SET e.category_id = c.parent_id
                WHERE c.parent_id IS NOT NULL AND c.parent_id > 0
            ");

            $this->optionalDdl("
                UPDATE installments i
                JOIN categories c ON c.id = i.category_id
                SET i.category_id = c.parent_id
                WHERE c.parent_id IS NOT NULL AND c.parent_id > 0
            ");

            $this->optionalDdl("
                UPDATE recurring_templates rt
                JOIN categories c ON c.id = rt.category_id
                SET rt.category_id = c.parent_id
                WHERE c.parent_id IS NOT NULL AND c.parent_id > 0
            ");

            $this->optionalDdl("
                UPDATE credit_card_transactions ct
                JOIN categories c ON c.id = ct.category_id
                SET ct.category_id = c.parent_id
                WHERE c.parent_id IS NOT NULL AND c.parent_id > 0
            ");

            $this->optionalDdl("
                UPDATE cash_entries ce
                JOIN categories c ON c.id = ce.category_id
                SET ce.category_id = c.parent_id
                WHERE c.parent_id IS NOT NULL AND c.parent_id > 0
            ");

            // 4. Desativar subcategorias para limpar os dropdowns (evitar o monstro/Frankenstein)
            $this->optionalDdl("UPDATE categories SET active = 0 WHERE parent_id IS NOT NULL");
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
