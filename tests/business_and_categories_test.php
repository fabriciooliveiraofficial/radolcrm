<?php

declare(strict_types=1);

$root = dirname(__DIR__);

echo "Iniciando verificação de contratos da Fase 1: Unidades de Negócio e Categorias Dinâmicas...\n";

// 1. MigrationService contracts
$migrationFile = (string) file_get_contents($root . '/app/Services/MigrationService.php');
assert(str_contains($migrationFile, 'private const VERSION = 9;'), 'MigrationService deve estar na versão 9');
assert(str_contains($migrationFile, 'CREATE TABLE IF NOT EXISTS business_units'), 'Migration deve criar tabela business_units');
assert(str_contains($migrationFile, 'CREATE TABLE IF NOT EXISTS categories'), 'Migration deve criar tabela categories');
assert(str_contains($migrationFile, 'ALTER TABLE clients ADD COLUMN business_unit_id'), 'Migration deve adicionar business_unit_id em clients');
assert(str_contains($migrationFile, 'ALTER TABLE products ADD COLUMN business_unit_id'), 'Migration deve adicionar business_unit_id em products');
assert(str_contains($migrationFile, 'ALTER TABLE payments ADD COLUMN business_unit_id'), 'Migration deve adicionar business_unit_id em payments');
assert(str_contains($migrationFile, 'ALTER TABLE expenses ADD COLUMN business_unit_id'), 'Migration deve adicionar business_unit_id em expenses');
assert(str_contains($migrationFile, 'ALTER TABLE cash_entries ADD COLUMN business_unit_id'), 'Migration deve adicionar business_unit_id em cash_entries');
assert(str_contains($migrationFile, 'ALTER TABLE expenses ADD COLUMN category_id'), 'Migration deve adicionar category_id em expenses');
assert(str_contains($migrationFile, 'ALTER TABLE cash_entries ADD COLUMN category_id'), 'Migration deve adicionar category_id em cash_entries');
assert(str_contains($migrationFile, 'Gasolina'), 'Migration deve incluir categorias iniciais essenciais');
assert(str_contains($migrationFile, 'Alimentação e Mercado'), 'Migration deve incluir categorias de alimentação');
echo "✓ Contratos do MigrationService v9 validados.\n";

// 2. Schema SQL contracts
$schemaFile = (string) file_get_contents($root . '/database/schema.sql');
assert(str_contains($schemaFile, 'CREATE TABLE IF NOT EXISTS business_units'), 'Schema SQL deve definir business_units');
assert(str_contains($schemaFile, 'CREATE TABLE IF NOT EXISTS categories'), 'Schema SQL deve definir categories');
assert(str_contains($schemaFile, 'schema_version\', \'9\''), 'Schema version padrão deve ser 9');
echo "✓ Contratos do schema.sql validados.\n";

// 3. ActionHandler contracts
$actionFile = (string) file_get_contents($root . '/app/Http/ActionHandler.php');
assert(str_contains($actionFile, "'save_business_unit' => \$this->saveBusinessUnit()"), 'ActionHandler deve mapear save_business_unit');
assert(str_contains($actionFile, "'delete_business_unit' => \$this->deleteBusinessUnit()"), 'ActionHandler deve mapear delete_business_unit');
assert(str_contains($actionFile, "'save_category' => \$this->saveCategory()"), 'ActionHandler deve mapear save_category');
assert(str_contains($actionFile, "'delete_category' => \$this->deleteCategory()"), 'ActionHandler deve mapear delete_category');
assert(str_contains($actionFile, 'function saveBusinessUnit'), 'ActionHandler deve implementar saveBusinessUnit');
assert(str_contains($actionFile, 'function deleteBusinessUnit'), 'ActionHandler deve implementar deleteBusinessUnit');
assert(str_contains($actionFile, 'function saveCategory'), 'ActionHandler deve implementar saveCategory');
assert(str_contains($actionFile, 'function deleteCategory'), 'ActionHandler deve implementar deleteCategory');
assert(str_contains($actionFile, 'function businessUnitId'), 'ActionHandler deve implementar helper businessUnitId');
assert(str_contains($actionFile, 'function categoryId'), 'ActionHandler deve implementar helper categoryId');
echo "✓ Contratos do ActionHandler validados.\n";

// 4. Index.php & Layout.php routing & navigation
$indexFile = (string) file_get_contents($root . '/index.php');
assert(str_contains($indexFile, "'businesses'"), 'index.php deve conter rota businesses');
assert(str_contains($indexFile, "'categories'"), 'index.php deve conter rota categories');

$layoutFile = (string) file_get_contents($root . '/app/Views/layout.php');
assert(str_contains($layoutFile, '?page=businesses'), 'layout.php deve conter link para Negócios');
assert(str_contains($layoutFile, '?page=categories'), 'layout.php deve conter link para Categorias');
echo "✓ Roteamento e navegação validados.\n";

// 5. Views existence and contents
$views = [
    'businesses' => $root . '/app/Views/pages/businesses.php',
    'categories' => $root . '/app/Views/pages/categories.php',
    'expenses' => $root . '/app/Views/pages/expenses.php',
    'cash' => $root . '/app/Views/pages/cash.php',
];

foreach ($views as $name => $path) {
    assert(file_exists($path), "View {$name} deve existir");
    $content = (string) file_get_contents($path);
    assert(strlen($content) > 300, "View {$name} deve ter conteúdo substancial");
}

$businessesView = (string) file_get_contents($views['businesses']);
assert(str_contains($businessesView, 'UNIDADE DE NEGÓCIO'), 'View businesses deve ter cabeçalho de modal');
assert(str_contains($businessesView, 'save_business_unit'), 'View businesses deve submeter save_business_unit');

$categoriesView = (string) file_get_contents($views['categories']);
assert(str_contains($categoriesView, 'CATEGORIA'), 'View categories deve ter cabeçalho de modal');
assert(str_contains($categoriesView, 'budget_limit_percent'), 'View categories deve ter limitador de % de orçamento');
assert(str_contains($categoriesView, 'save_category'), 'View categories deve submeter save_category');

$expensesView = (string) file_get_contents($views['expenses']);
assert(str_contains($expensesView, 'name="bu"'), 'View expenses deve permitir filtrar por negócio');
assert(str_contains($expensesView, 'name="category_id"'), 'View expenses deve permitir selecionar category_id');

$cashView = (string) file_get_contents($views['cash']);
assert(str_contains($cashView, 'name="bu"'), 'View cash deve permitir filtrar por negócio');
assert(str_contains($cashView, 'bu_name'), 'View cash deve exibir identificação do negócio');

echo "✓ Contratos e formulários de todas as Views validados.\n";
echo "Todos os 24 testes e contratos da Fase 1 passaram com sucesso!\n";
