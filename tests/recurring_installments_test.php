<?php

declare(strict_types=1);

$root = dirname(__DIR__);

echo "Iniciando verificação de contratos da Fase 2: Lançamentos Recorrentes e Parcelamento...\n";

// 1. MigrationService contracts
$migrationFile = (string) file_get_contents($root . '/app/Services/MigrationService.php');
assert(str_contains($migrationFile, 'private const VERSION = 10;'), 'MigrationService deve estar na versão 10');
assert(str_contains($migrationFile, 'CREATE TABLE IF NOT EXISTS recurring_templates'), 'Migration deve criar tabela recurring_templates');
assert(str_contains($migrationFile, 'CREATE TABLE IF NOT EXISTS installments'), 'Migration deve criar tabela installments');
assert(str_contains($migrationFile, 'template_id BIGINT UNSIGNED NOT NULL'), 'Tabela installments deve conter template_id');
assert(str_contains($migrationFile, 'installment_number SMALLINT UNSIGNED NOT NULL'), 'Tabela installments deve conter installment_number');
assert(str_contains($migrationFile, 'expense_id BIGINT UNSIGNED NULL'), 'Tabela installments deve conter expense_id para vínculo financeiro');
echo "✓ Contratos do MigrationService v10 validados.\n";

// 2. Schema SQL contracts
$schemaFile = (string) file_get_contents($root . '/database/schema.sql');
assert(str_contains($schemaFile, 'CREATE TABLE IF NOT EXISTS recurring_templates'), 'Schema SQL deve definir recurring_templates');
assert(str_contains($schemaFile, 'CREATE TABLE IF NOT EXISTS installments'), 'Schema SQL deve definir installments');
assert(str_contains($schemaFile, 'schema_version\', \'10\''), 'Schema version padrão deve ser 10');
echo "✓ Contratos do schema.sql v10 validados.\n";

// 3. ActionHandler contracts
$actionFile = (string) file_get_contents($root . '/app/Http/ActionHandler.php');
assert(str_contains($actionFile, "'save_recurring_template' => \$this->saveRecurringTemplate()"), 'ActionHandler deve mapear save_recurring_template');
assert(str_contains($actionFile, "'delete_recurring_template' => \$this->deleteRecurringTemplate()"), 'ActionHandler deve mapear delete_recurring_template');
assert(str_contains($actionFile, "'pay_installment' => \$this->payInstallment()"), 'ActionHandler deve mapear pay_installment');
assert(str_contains($actionFile, "'edit_installment' => \$this->editInstallment()"), 'ActionHandler deve mapear edit_installment');
assert(str_contains($actionFile, "'delete_installment' => \$this->deleteInstallment()"), 'ActionHandler deve mapear delete_installment');
assert(str_contains($actionFile, 'function saveRecurringTemplate'), 'ActionHandler deve implementar saveRecurringTemplate');
assert(str_contains($actionFile, 'function payInstallment'), 'ActionHandler deve implementar payInstallment');
assert(str_contains($actionFile, 'function editInstallment'), 'ActionHandler deve implementar editInstallment');
assert(str_contains($actionFile, 'function deleteInstallment'), 'ActionHandler deve implementar deleteInstallment');
assert(str_contains($actionFile, 'function deleteRecurringTemplate'), 'ActionHandler deve implementar deleteRecurringTemplate');
echo "✓ Contratos do ActionHandler validados.\n";

// 4. Index.php & Layout.php routing & navigation
$indexFile = (string) file_get_contents($root . '/index.php');
assert(str_contains($indexFile, "'recurring'"), 'index.php deve conter rota recurring');

$layoutFile = (string) file_get_contents($root . '/app/Views/layout.php');
assert(str_contains($layoutFile, '?page=recurring'), 'layout.php deve conter link para Recorrências e Parcelas');
echo "✓ Roteamento e navegação de Recorrências validados.\n";

// 5. Views existence and contents
$recurringView = $root . '/app/Views/pages/recurring.php';
assert(file_exists($recurringView), 'View recurring.php deve existir');
$content = (string) file_get_contents($recurringView);
assert(strlen($content) > 500, 'View recurring.php deve ter conteúdo substancial');
assert(str_contains($content, 'save_recurring_template'), 'View recurring deve conter form save_recurring_template');
assert(str_contains($content, 'pay_installment'), 'View recurring deve conter form pay_installment');
assert(str_contains($content, 'generatePreview'), 'View recurring deve conter gerador interativo de prévia de parcelas');
assert(str_contains($content, 'preview-amount-input'), 'View recurring deve permitir edição de valores individuais das parcelas');

echo "✓ View recurring.php e componentes interativos validados.\n";
echo "Todos os 20 testes e contratos da Fase 2 passaram com sucesso!\n";
