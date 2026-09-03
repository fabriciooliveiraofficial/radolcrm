<?php

declare(strict_types=1);

$root = dirname(__DIR__);

echo "Iniciando verificação de contratos e inteligência de Categorias de Receita...\n";

// 1. MigrationService Version 14 contracts
$migrationFile = (string) file_get_contents($root . '/app/Services/MigrationService.php');
assert(preg_match('/private const VERSION = (\d+);/', $migrationFile, $m) && (int)$m[1] >= 14, 'MigrationService deve estar na versão 14 ou superior');
assert(str_contains($migrationFile, '$version < 14'), 'MigrationService deve ter bloco para version < 14');
assert(str_contains($migrationFile, 'ALTER TABLE products ADD COLUMN category_id'), 'Migration deve adicionar category_id em products');
assert(str_contains($migrationFile, 'Receitas com Assinaturas'), 'Migration deve criar categoria padrão de assinaturas');
assert(str_contains($migrationFile, 'UPDATE payments p'), 'Migration deve executar backfill retroativo de pagamentos');
echo "✓ 1. Contratos da Migração v14 validados.\n";

// 2. Schema SQL contracts
$schemaFile = (string) file_get_contents($root . '/database/schema.sql');
assert(str_contains($schemaFile, "category_id BIGINT UNSIGNED NULL"), 'Schema SQL deve definir category_id em products');
assert(str_contains($schemaFile, "fk_products_category"), 'Schema SQL deve ter FK fk_products_category');
assert(preg_match('/\'schema_version\', \'(\d+)\'/', $schemaFile, $sm) && (int)$sm[1] >= 14, 'Schema version deve ser 14 ou superior');
echo "✓ 2. Contratos do schema.sql validados.\n";

// 3. ActionHandler Product and Renewal contracts
$actionFile = (string) file_get_contents($root . '/app/Http/ActionHandler.php');
assert(str_contains($actionFile, '$categoryId = $this->categoryId();'), 'ActionHandler deve ler categoryId em saveProduct');
assert(str_contains($actionFile, 'UPDATE products SET business_unit_id=?, category_id=?, name=?'), 'saveProduct deve atualizar category_id em products');
assert(str_contains($actionFile, 'INSERT INTO products (business_unit_id, category_id, name'), 'saveProduct deve inserir category_id em products');
assert(str_contains($actionFile, '$categoryId = (int) ($product[\'category_id\'] ?? 0) ?: null;'), 'processSubscriptionRenewals deve capturar category_id do produto');
assert(str_contains($actionFile, 'category_id=COALESCE(category_id,?)'), 'processSubscriptionRenewals deve gravar category_id no UPDATE de pagamentos');
assert(str_contains($actionFile, 'INSERT INTO payments (
                            business_unit_id,subscription_id,client_id,category_id,'), 'processSubscriptionRenewals deve gravar category_id no INSERT de pagamentos');
assert(str_contains($actionFile, "category_id=COALESCE(category_id, (SELECT pr.category_id FROM subscriptions s JOIN products pr ON pr.id=s.product_id WHERE s.id=payments.subscription_id)"), 'markPaymentsPaid deve fazer fallback automático de category_id');
echo "✓ 3. Contratos do ActionHandler para vinculação automática validados.\n";

// 4. Products view contracts
$productsView = (string) file_get_contents($root . '/app/Views/pages/products.php');
assert(str_contains($productsView, '$incomeCategories'), 'products.php deve carregar categorias de receita');
assert(str_contains($productsView, 'name="category_id"'), 'products.php deve ter campo select para category_id');
assert(str_contains($productsView, 'cat.name as category_name'), 'products.php deve consultar nome da categoria');
assert(str_contains($productsView, '$item[\'category_name\']'), 'products.php deve exibir badge de categoria no card de produto');
echo "✓ 4. Contratos da view products.php validados.\n";

// 5. Payments view and JS contracts
$paymentsView = (string) file_get_contents($root . '/app/Views/pages/payments.php');
assert(str_contains($paymentsView, 'cat.name category_name'), 'payments.php deve selecionar category_name');
assert(str_contains($paymentsView, 'data-payment-category'), 'payments.php deve ter select de categoria com data-payment-category');
assert(str_contains($paymentsView, 'data-category='), 'payments.php deve passar data-category nas opções de assinatura');
assert(str_contains($paymentsView, '$item[\'category_name\']'), 'payments.php deve exibir badge da categoria na tabela');

$appJs = (string) file_get_contents($root . '/assets/js/app.js');
assert(str_contains($appJs, 'data-payment-category'), 'app.js deve referenciar data-payment-category');
assert(str_contains($appJs, 'paymentCategory.value = selected.dataset.category'), 'app.js deve pré-selecionar categoria ao escolher assinatura');
echo "✓ 5. Contratos da view payments.php e automação em app.js validados.\n";

// 6. FinanceService & Reports view contracts
$financeService = (string) file_get_contents($root . '/app/Services/FinanceService.php');
assert(str_contains($financeService, 'function categoryRevenueIndices'), 'FinanceService deve conter categoryRevenueIndices');
assert(str_contains($financeService, 'p.category_id = c.id'), 'FinanceService deve agrupar pagamentos por categoria');

$reportsView = (string) file_get_contents($root . '/app/Views/pages/reports.php');
assert(str_contains($reportsView, 'categoryRevenueIndices'), 'reports.php deve chamar categoryRevenueIndices');
assert(str_contains($reportsView, 'RECEITAS POR CATEGORIA'), 'reports.php deve renderizar o bloco de Receitas por Categoria');
assert(str_contains($reportsView, 'pct_of_revenue'), 'reports.php deve renderizar porcentagem de participação');
echo "✓ 6. Contratos de inteligência financeira em FinanceService e reports.php validados.\n";

// 7. Test FinanceService categoryRevenueIndices logic with real SQLite PDO
require_once $root . '/app/Core/Database.php';
require_once $root . '/app/Services/FinanceService.php';

$dbRef = (new ReflectionClass(\App\Core\Database::class))->newInstanceWithoutConstructor();
$pdoProp = (new ReflectionClass(\App\Core\Database::class))->getProperty('pdo');
$pdoProp->setAccessible(true);
$sqlite = new PDO('sqlite::memory:');
$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$sqlite->exec("CREATE TABLE categories (id INTEGER PRIMARY KEY, business_unit_id INTEGER, name TEXT, icon TEXT, color TEXT, active INTEGER, type TEXT)");
$sqlite->exec("CREATE TABLE business_units (id INTEGER PRIMARY KEY, name TEXT, icon TEXT)");
$sqlite->exec("CREATE TABLE payments (id INTEGER PRIMARY KEY, category_id INTEGER, business_unit_id INTEGER, status TEXT, amount_brl REAL, net_brl REAL, currency TEXT, payment_date TEXT, settlement_date TEXT)");

$sqlite->exec("INSERT INTO categories VALUES (1, 1, 'Receitas com Assinaturas', '💎', '#10b981', 1, 'income')");
$sqlite->exec("INSERT INTO categories VALUES (2, 1, 'Vendas de Produtos', '📦', '#3b82f6', 1, 'income')");

$sqlite->exec("INSERT INTO payments VALUES (1, 1, 1, 'paid', 7000.00, 6700.00, 'BRL', '2026-09-02', NULL)");
$sqlite->exec("INSERT INTO payments VALUES (2, 2, 1, 'paid', 2000.00, 1900.00, 'BRL', '2026-09-02', NULL)");
$sqlite->exec("INSERT INTO payments VALUES (3, NULL, 1, 'paid', 1000.00, 950.00, 'BRL', '2026-09-02', NULL)");

$pdoProp->setValue($dbRef, $sqlite);
$finance = new \App\Services\FinanceService($dbRef);
$indices = $finance->categoryRevenueIndices('2026-09-01', '2026-09-30');

assert($indices['total_revenue'] === 10000.00, 'Total de receitas deve ser 10000.00');
assert(count($indices['categories']) === 3, 'Deve conter 3 categorias analisadas (incluindo uncategorized)');
assert($indices['categories'][0]['pct_of_revenue'] === 70.0, 'Assinaturas deve ter 70% de participação');
assert($indices['categories'][1]['pct_of_revenue'] === 20.0, 'Vendas de Produtos deve ter 20% de participação');
assert($indices['categories'][2]['pct_of_revenue'] === 10.0, 'Outras / Sem categoria deve ter 10% de participação');
echo "✓ 7. Lógica matemática e agrupamentos de categoryRevenueIndices validados.\n";

echo "\nTODOS OS TESTES DE CATEGORIZAÇÃO INTELIGENTE DE RECEITA PASSARAM COM SUCESSO!\n";
