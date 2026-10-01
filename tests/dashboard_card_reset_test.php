<?php

declare(strict_types=1);

echo "Iniciando verificação de Marco Zero / Reset Temporal dos Cards do Dashboard...\n";

$root = dirname(__DIR__);

// 1. Contratos no FinanceService.php
$serviceContent = file_get_contents($root . '/app/Services/FinanceService.php');
assert(str_contains($serviceContent, 'function getCardReset'), 'FinanceService deve ter o método getCardReset');
assert(str_contains($serviceContent, 'function setCardReset'), 'FinanceService deve ter o método setCardReset');
assert(str_contains($serviceContent, 'function clearCardReset'), 'FinanceService deve ter o método clearCardReset');
assert(str_contains($serviceContent, 'function allCardResets'), 'FinanceService deve ter o método allCardResets');
assert(str_contains($serviceContent, '$revReset = $resets[\'revenue\']'), 'dashboard() deve consultar o reset de faturamento bruto');
assert(str_contains($serviceContent, '$profitReset = $resets[\'profit\']'), 'dashboard() deve consultar o reset de lucro líquido');
assert(str_contains($serviceContent, '$mrrReset = $resets[\'mrr\']'), 'dashboard() deve consultar o reset de MRR');
assert(str_contains($serviceContent, '$cashReset = $this->getCardReset(\'cash\''), 'cashBalance() deve consultar o reset de saldo de caixa');
echo "✓ 1. Contratos de métodos de Marco Zero no FinanceService validados com sucesso.\n";

// 2. Contratos no ActionHandler.php
$actionContent = file_get_contents($root . '/app/Http/ActionHandler.php');
assert(str_contains($actionContent, "'reset_dashboard_card' => \$this->resetDashboardCard()"), 'ActionHandler deve mapear a ação reset_dashboard_card');
assert(str_contains($actionContent, 'function resetDashboardCard'), 'ActionHandler deve conter o método resetDashboardCard');
assert(str_contains($actionContent, 'apply_to_all'), 'resetDashboardCard deve suportar opção apply_to_all');
assert(str_contains($actionContent, 'initial_amount'), 'resetDashboardCard deve suportar initial_amount para o caixa');
assert(str_contains($actionContent, 'clear'), 'resetDashboardCard deve suportar limpeza/restauração do histórico');
echo "✓ 2. Ação reset_dashboard_card devidamente mapeada e implementada no ActionHandler.\n";

// 3. Contratos na View dashboard.php
$viewContent = file_get_contents($root . '/app/Views/pages/dashboard.php');
assert(str_contains($viewContent, 'allCardResets'), 'dashboard.php deve chamar allCardResets');
assert(str_contains($viewContent, 'card-reset-btn'), 'dashboard.php deve conter botões de reset nos cards');
assert(str_contains($viewContent, 'cardResetModal'), 'dashboard.php deve conter o modal cardResetModal');
assert(str_contains($viewContent, 'openCardResetModal'), 'dashboard.php deve conter a função JS openCardResetModal');
assert(str_contains($viewContent, 'cardResetCashInitialBlock'), 'dashboard.php deve conter campo para saldo inicial de caixa');
assert(str_contains($viewContent, 'cardResetApplyAllCheckbox'), 'dashboard.php deve conter checkbox para aplicar a todos os cards');
assert(str_contains($viewContent, 'cardResetClearForm'), 'dashboard.php deve conter formulário para restaurar histórico completo');
assert(str_contains($viewContent, 'card-reset-pill'), 'dashboard.php deve conter pill informativa de marco zero ativo');
echo "✓ 3. View dashboard.php completa com botões, modal interativo, scripts e feedback visual.\n";

// 4. Teste Lógico em SQLite Em Memória
require_once $root . '/app/Core/Database.php';
require_once $root . '/app/Services/FinanceService.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("
    CREATE TABLE settings (
        setting_key VARCHAR(120) PRIMARY KEY,
        setting_value TEXT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        business_unit_id INTEGER NULL,
        amount_brl DECIMAL(15,2) NOT NULL,
        fee_brl DECIMAL(15,2) NOT NULL DEFAULT 0,
        net_brl DECIMAL(15,2) NOT NULL,
        currency VARCHAR(3) NOT NULL DEFAULT 'BRL',
        amount DECIMAL(15,2) NOT NULL,
        status VARCHAR(20) NOT NULL,
        payment_date DATE NOT NULL,
        settlement_date DATE NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE expenses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        business_unit_id INTEGER NULL,
        amount_brl DECIMAL(15,2) NOT NULL,
        type VARCHAR(20) NOT NULL DEFAULT 'expense',
        status VARCHAR(20) NOT NULL,
        payment_date DATE NOT NULL
    );
    CREATE TABLE cash_entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        business_unit_id INTEGER NULL,
        amount_brl DECIMAL(15,2) NOT NULL,
        direction VARCHAR(3) NOT NULL,
        entry_date DATE NOT NULL
    );
    CREATE TABLE clients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        business_unit_id INTEGER NULL,
        deleted_at DATETIME NULL,
        status VARCHAR(20) DEFAULT 'active'
    );
    CREATE TABLE products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        business_unit_id INTEGER NULL,
        billing_cycle VARCHAR(20) DEFAULT 'monthly'
    );
    CREATE TABLE subscriptions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL DEFAULT 1,
        currency VARCHAR(3) DEFAULT 'BRL',
        unit_price DECIMAL(15,2) NOT NULL,
        discount DECIMAL(15,2) DEFAULT 0,
        status VARCHAR(20) DEFAULT 'active',
        start_date DATE NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
");

$ref = new ReflectionClass(\App\Core\Database::class);
$db = $ref->newInstanceWithoutConstructor();
$pdoProp = $ref->getProperty('pdo');
$pdoProp->setAccessible(true);
$pdoProp->setValue($db, $pdo);

$finance = new \App\Services\FinanceService($db);

// Inserir dados históricos (setembro/2026)
$pdo->exec("
    INSERT INTO payments (amount_brl, net_brl, amount, status, payment_date, created_at) VALUES (1000.00, 950.00, 1000.00, 'paid', '2026-09-15', '2026-09-15 10:00:00');
    INSERT INTO expenses (amount_brl, type, status, payment_date) VALUES (300.00, 'expense', 'paid', '2026-09-20');
    INSERT INTO cash_entries (amount_brl, direction, entry_date) VALUES (200.00, 'in', '2026-09-10');
");

// Inserir dados novos (outubro/2026)
$pdo->exec("
    INSERT INTO payments (amount_brl, net_brl, amount, status, payment_date, created_at) VALUES (500.00, 480.00, 500.00, 'paid', '2026-10-05', '2026-10-05 10:00:00');
    INSERT INTO expenses (amount_brl, type, status, payment_date) VALUES (150.00, 'expense', 'paid', '2026-10-06');
    INSERT INTO cash_entries (amount_brl, direction, entry_date) VALUES (100.00, 'in', '2026-10-02');
");

// 4.1 Testar saldo de caixa sem reset (acumulado de tudo: 950 + 200 - 300 + 480 + 100 - 150 = 1280)
$initialCash = $finance->cashBalance();
assert($initialCash === 1280.00, "Saldo de caixa sem reset deve ser 1280.00, obteve: {$initialCash}");

// 4.2 Aplicar marco zero no Saldo de Caixa a partir de 2026-10-01 com saldo base R$ 50,00
// No SQLite usamos REPLACE INTO para ON DUPLICATE KEY UPDATE
$pdo->exec("
    INSERT OR REPLACE INTO settings (setting_key, setting_value)
    VALUES ('dashboard_reset_cash', '{\"date\":\"2026-10-01\",\"initial_amount\":50.00}')
");

$resetCash = $finance->cashBalance();
// Esperado pós-reset: Base (50) + Outubro: payments net (480) + cashIn (100) - expenses (150) = 480.00
assert($resetCash === 480.00, "Saldo de caixa com marco zero em 2026-10-01 deve ser 480.00, obteve: {$resetCash}");
echo "✓ 4. Cálculo do Saldo de Caixa Atual com marco zero e saldo base validado com sucesso.\n";

// 4.3 Testar Faturamento Bruto com e sem marco zero
// Consulta de período completo 2026-09-01 até 2026-10-31 sem reset
$metricsFull = $finance->dashboard('2026-09-01', '2026-10-31', 5.50);
assert($metricsFull['gross'] === 1500.00, "Faturamento bruto sem reset deve ser 1500.00");

// Aplicar marco zero no Faturamento Bruto a partir de 2026-10-01
$pdo->exec("
    INSERT OR REPLACE INTO settings (setting_key, setting_value)
    VALUES ('dashboard_reset_revenue', '{\"date\":\"2026-10-01\",\"initial_amount\":0}')
");
$metricsAfterReset = $finance->dashboard('2026-09-01', '2026-10-31', 5.50);
assert($metricsAfterReset['gross'] === 500.00, "Faturamento bruto após marco zero em 2026-10-01 deve ser apenas 500.00");
assert($metricsAfterReset['paymentCount'] === 1, "Quantidade de pagamentos deve ser 1 após marco zero");
echo "✓ 5. Cálculo do Faturamento Bruto com marco zero validado com sucesso.\n";

// 4.4 Testar Restauração / Limpeza do Marco Zero
$finance->clearCardReset('cash');
$restoredCash = $finance->cashBalance();
assert($restoredCash === 1280.00, "Após remover marco zero, saldo de caixa deve retornar ao histórico completo (1280.00)");

$finance->clearCardReset('revenue');
$restoredMetrics = $finance->dashboard('2026-09-01', '2026-10-31', 5.50);
assert($restoredMetrics['gross'] === 1500.00, "Após remover marco zero, faturamento bruto deve retornar ao histórico completo (1500.00)");
echo "✓ 6. Restauração do Histórico Completo validada com êxito (preservação 100% dos registros).\n";

echo "\n🎉 TODOS OS TESTES DE MARCO ZERO DOS CARDS DO DASHBOARD PASSARAM COM 100% DE SUCESSO!\n";
