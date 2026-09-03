<?php

declare(strict_types=1);

$root = dirname(__DIR__);

echo "Iniciando verificação de contratos e inteligência de Plano de Contas Enxuto & Auto-Pay de Despesas...\n";

// 1. Validar Contratos do MigrationService (v15)
$migrationFile = (string) file_get_contents($root . '/app/Services/MigrationService.php');
assert(str_contains($migrationFile, 'VERSION = 15;'), 'MigrationService deve estar na versão 15');
assert(str_contains($migrationFile, 'recurring_templates ADD COLUMN auto_pay'), 'Migração v15 deve adicionar coluna auto_pay');
assert(str_contains($migrationFile, 'Softwares, Cloud & Ferramentas (SaaS)'), 'Migração v15 deve garantir categoria de Softwares/SaaS');
assert(str_contains($migrationFile, 'Operação, Sede & Infraestrutura'), 'Migração v15 deve garantir categoria de Operação');
assert(str_contains($migrationFile, 'Equipe, Parceiros & Terceiros'), 'Migração v15 deve garantir categoria de Equipe');
assert(str_contains($migrationFile, 'UPDATE expenses e'), 'Migração v15 deve remapear expenses vinculadas a subcategorias');
assert(str_contains($migrationFile, 'UPDATE installments i'), 'Migração v15 deve remapear installments vinculadas a subcategorias');
assert(str_contains($migrationFile, 'UPDATE categories SET active = 0 WHERE parent_id IS NOT NULL'), 'Migração v15 deve desativar subcategorias excedentes');
echo "✓ 1. Contratos da Migração v15 validados.\n";

// 2. Validar Contratos do schema.sql
$schemaFile = (string) file_get_contents($root . '/database/schema.sql');
assert(str_contains($schemaFile, 'auto_pay TINYINT(1) NOT NULL DEFAULT 0'), 'schema.sql deve incluir coluna auto_pay em recurring_templates');
assert(str_contains($schemaFile, "('schema_version', '15')"), 'schema.sql deve ter schema_version = 15');
echo "✓ 2. Contratos do schema.sql validados.\n";

// 3. Validar Contratos do ActionHandler
$actionHandlerFile = (string) file_get_contents($root . '/app/Http/ActionHandler.php');
assert(str_contains($actionHandlerFile, "autoPay = isset(\$_POST['auto_pay']) ? 1 : 0;"), 'saveRecurringTemplate deve capturar auto_pay');
assert(str_contains($actionHandlerFile, "auto_pay=?"), 'saveRecurringTemplate UPDATE deve persistir auto_pay');
assert(str_contains($actionHandlerFile, "parcelas quitadas automaticamente via Auto-Pay"), 'runFinancialAutomation deve informar parcelas auto-pagas');
echo "✓ 3. Contratos do ActionHandler validados.\n";

// 4. Validar Contratos da View recurring.php
$recurringView = (string) file_get_contents($root . '/app/Views/pages/recurring.php');
assert(str_contains($recurringView, 'rt.auto_pay rt_auto_pay'), 'recurring.php deve consultar rt.auto_pay');
assert(str_contains($recurringView, '⚡ Auto-Pay'), 'recurring.php deve renderizar badge de Auto-Pay');
assert(str_contains($recurringView, 'name="auto_pay"'), 'recurring.php modal deve conter checkbox de auto_pay');
assert(str_contains($recurringView, 'c.parent_id IS NULL OR c.parent_id = 0'), 'recurring.php deve filtrar apenas categorias macro/raiz');
echo "✓ 4. Contratos da view recurring.php validados.\n";

// 5. Validar Contratos da View expenses.php
$expensesView = (string) file_get_contents($root . '/app/Views/pages/expenses.php');
assert(str_contains($expensesView, 'c.parent_id IS NULL OR c.parent_id = 0'), 'expenses.php deve carregar apenas categorias macro');
assert(str_contains($expensesView, 'list="frequent-suppliers"'), 'expenses.php deve ter datalist para fornecedores');
assert(str_contains($expensesView, 'id="frequent-suppliers"'), 'expenses.php deve definir datalist de fornecedores');
echo "✓ 5. Contratos da view expenses.php validados.\n";

// 6. Validar Contratos da View categories.php
$categoriesView = (string) file_get_contents($root . '/app/Views/pages/categories.php');
assert(str_contains($categoriesView, 'Plano de Contas Estratégico & Enxuto'), 'categories.php deve exibir banner educativo');
echo "✓ 6. Contratos da view categories.php validados.\n";

// 7. Testar Lógica de Execução do Auto-Pay via SQLite em memória
require_once $root . '/app/Core/Database.php';
require_once $root . '/app/Services/FinanceService.php';
require_once $root . '/app/Services/FinancialAutomationService.php';

$dbRef = (new ReflectionClass(\App\Core\Database::class))->newInstanceWithoutConstructor();
$pdoProp = (new ReflectionClass(\App\Core\Database::class))->getProperty('pdo');
$pdoProp->setAccessible(true);
$sqlite = new PDO('sqlite::memory:');
$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Criação do schema em SQLite
$sqlite->exec("CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)");
$sqlite->exec("CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, action TEXT, entity_type TEXT, entity_id INTEGER, details TEXT, created_at TEXT)");
$sqlite->exec("CREATE TABLE credit_card_invoices (id INTEGER PRIMARY KEY, card_id INTEGER, closing_date TEXT, status TEXT, total_amount REAL)");
$sqlite->exec("CREATE TABLE credit_cards (id INTEGER PRIMARY KEY, name TEXT)");
$sqlite->exec("CREATE TABLE business_units (id INTEGER PRIMARY KEY, name TEXT, icon TEXT)");
$sqlite->exec("CREATE TABLE categories (id INTEGER PRIMARY KEY, business_unit_id INTEGER, parent_id INTEGER, name TEXT, icon TEXT, color TEXT, active INTEGER, type TEXT, budget_limit_percent REAL, budget_limit_amount REAL)");
$sqlite->exec("CREATE TABLE payments (id INTEGER PRIMARY KEY, category_id INTEGER, business_unit_id INTEGER, status TEXT, amount_brl REAL, net_brl REAL, currency TEXT, payment_date TEXT, settlement_date TEXT)");
$sqlite->exec("CREATE TABLE recurring_templates (id INTEGER PRIMARY KEY, business_unit_id INTEGER, category_id INTEGER, type TEXT, description TEXT, supplier TEXT, amount REAL, currency TEXT, exchange_rate REAL, recurrence TEXT, total_installments INTEGER, start_date TEXT, auto_generate INTEGER, auto_pay INTEGER, active INTEGER)");
$sqlite->exec("CREATE TABLE installments (id INTEGER PRIMARY KEY, template_id INTEGER, business_unit_id INTEGER, category_id INTEGER, installment_number INTEGER, total_installments INTEGER, description TEXT, supplier TEXT, amount REAL, currency TEXT, exchange_rate REAL, amount_brl REAL, due_date TEXT, payment_date TEXT, status TEXT, expense_id INTEGER, notes TEXT)");
$sqlite->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY, business_unit_id INTEGER, category_id INTEGER, type TEXT, category TEXT, description TEXT, supplier TEXT, amount REAL, currency TEXT, exchange_rate REAL, amount_brl REAL, status TEXT, payment_date TEXT, is_recurring INTEGER, notes TEXT)");

// Seed para teste
$sqlite->exec("INSERT INTO categories VALUES (1, 1, NULL, 'Softwares, Cloud & Ferramentas (SaaS)', '🛠️', '#3b82f6', 1, 'expense', NULL, NULL)");
$sqlite->exec("INSERT INTO recurring_templates VALUES (10, 1, 1, 'expense', 'Assinatura OpenAI ChatGPT Plus', 'OpenAI Inc', 20.00, 'USD', 5.50, 'monthly', NULL, '2026-09-01', 1, 1, 1)");

$today = date('Y-m-d');
$sqlite->exec("INSERT INTO installments VALUES (100, 10, 1, 1, 1, NULL, 'Assinatura OpenAI ChatGPT Plus (1)', 'OpenAI Inc', 20.00, 'USD', 5.50, 110.00, '{$today}', NULL, 'pending', NULL, 'Mensalidade')");

// Parcela que NÃO deve ser paga (auto_pay = 0)
$sqlite->exec("INSERT INTO recurring_templates VALUES (20, 1, 1, 'expense', 'Conta Manual', 'Fornecedor X', 50.00, 'BRL', 1.0, 'monthly', NULL, '2026-09-01', 1, 0, 1)");
$sqlite->exec("INSERT INTO installments VALUES (200, 20, 1, 1, 1, NULL, 'Conta Manual (1)', 'Fornecedor X', 50.00, 'BRL', 1.0, 50.00, '{$today}', NULL, 'pending', NULL, 'Manual')");

// Mock de função helper audit
if (!function_exists('audit')) {
    function audit($db, $action, $entityType, $entityId = null, $details = null) {
        // Mock audit call
    }
}

$pdoProp->setValue($dbRef, $sqlite);
$autoService = new \App\Services\FinancialAutomationService($dbRef);
$summary = $autoService->runDailyFinancialAutomation();

assert($summary['auto_paid_installments'] === 1, 'Deve ter quitado exatamente 1 parcela via Auto-Pay');

// Validar que a parcela 100 foi para 'paid' e tem expense_id
$instRow = $sqlite->query("SELECT status, expense_id, payment_date FROM installments WHERE id = 100")->fetch();
assert($instRow['status'] === 'paid', 'Parcela 100 deve estar com status paid');
assert((int) $instRow['expense_id'] > 0, 'Parcela 100 deve ter expense_id preenchido');
assert($instRow['payment_date'] === $today, 'Data de pagamento deve ser a data de vencimento');

// Validar que a parcela 200 continua 'pending'
$instRow2 = $sqlite->query("SELECT status FROM installments WHERE id = 200")->fetch();
assert($instRow2['status'] === 'pending', 'Parcela 200 não deve ser auto-paga');

// Validar que a despesa correspondente foi gerada em expenses
$expRow = $sqlite->query("SELECT * FROM expenses WHERE id = " . (int)$instRow['expense_id'])->fetch();
assert($expRow !== false, 'Registro em expenses deve ter sido criado');
assert($expRow['status'] === 'paid', 'Status em expenses deve ser paid');
assert($expRow['amount_brl'] == 110.00, 'Valor amount_brl deve ser 110.00');
assert($expRow['supplier'] === 'OpenAI Inc', 'Fornecedor deve ser OpenAI Inc');
assert($expRow['is_recurring'] == 1, 'is_recurring deve ser 1');
echo "✓ 7. Lógica de execução do Auto-Pay no vencimento validada com sucesso.\n";

echo "\nTODOS OS 7 CONJUNTOS DE TESTES DE PLANO DE CONTAS ENXUTO & AUTO-PAY PASSARAM COM SUCESSO!\n";
