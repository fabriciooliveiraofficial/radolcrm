<?php

declare(strict_types=1);

$root = dirname(__DIR__);

echo "Iniciando verificação de contratos da Fase 3: Cartões de Crédito, Faturas e Parcelamentos...\n";

// 1. MigrationService contracts
$migrationFile = (string) file_get_contents($root . '/app/Services/MigrationService.php');
assert(str_contains($migrationFile, 'private const VERSION = 11;'), 'MigrationService deve estar na versão 11');
assert(str_contains($migrationFile, 'CREATE TABLE IF NOT EXISTS credit_cards'), 'Migration deve criar tabela credit_cards');
assert(str_contains($migrationFile, 'CREATE TABLE IF NOT EXISTS credit_card_invoices'), 'Migration deve criar tabela credit_card_invoices');
assert(str_contains($migrationFile, 'CREATE TABLE IF NOT EXISTS credit_card_transactions'), 'Migration deve criar tabela credit_card_transactions');
assert(str_contains($migrationFile, 'closing_day TINYINT UNSIGNED'), 'credit_cards deve conter closing_day');
assert(str_contains($migrationFile, 'due_day TINYINT UNSIGNED'), 'credit_cards deve conter due_day');
assert(str_contains($migrationFile, 'reference_month VARCHAR(7)'), 'credit_card_invoices deve conter reference_month');
echo "✓ Contratos do MigrationService v11 validados.\n";

// 2. Schema SQL contracts
$schemaFile = (string) file_get_contents($root . '/database/schema.sql');
assert(str_contains($schemaFile, 'CREATE TABLE IF NOT EXISTS credit_cards'), 'Schema SQL deve definir credit_cards');
assert(str_contains($schemaFile, 'CREATE TABLE IF NOT EXISTS credit_card_invoices'), 'Schema SQL deve definir credit_card_invoices');
assert(str_contains($schemaFile, 'CREATE TABLE IF NOT EXISTS credit_card_transactions'), 'Schema SQL deve definir credit_card_transactions');
assert(str_contains($schemaFile, 'schema_version\', \'11\''), 'Schema version padrão deve ser 11');
echo "✓ Contratos do schema.sql v11 validados.\n";

// 3. ActionHandler contracts
$actionFile = (string) file_get_contents($root . '/app/Http/ActionHandler.php');
assert(str_contains($actionFile, "'save_credit_card' => \$this->saveCreditCard()"), 'ActionHandler deve mapear save_credit_card');
assert(str_contains($actionFile, "'delete_credit_card' => \$this->deleteCreditCard()"), 'ActionHandler deve mapear delete_credit_card');
assert(str_contains($actionFile, "'save_card_transaction' => \$this->saveCardTransaction()"), 'ActionHandler deve mapear save_card_transaction');
assert(str_contains($actionFile, "'delete_card_transaction' => \$this->deleteCardTransaction()"), 'ActionHandler deve mapear delete_card_transaction');
assert(str_contains($actionFile, "'pay_card_invoice' => \$this->payCardInvoice()"), 'ActionHandler deve mapear pay_card_invoice');
assert(str_contains($actionFile, 'function saveCreditCard'), 'ActionHandler deve implementar saveCreditCard');
assert(str_contains($actionFile, 'function deleteCreditCard'), 'ActionHandler deve implementar deleteCreditCard');
assert(str_contains($actionFile, 'function saveCardTransaction'), 'ActionHandler deve implementar saveCardTransaction');
assert(str_contains($actionFile, 'function deleteCardTransaction'), 'ActionHandler deve implementar deleteCardTransaction');
assert(str_contains($actionFile, 'function payCardInvoice'), 'ActionHandler deve implementar payCardInvoice');
assert(str_contains($actionFile, 'function getOrCreateCardInvoice'), 'ActionHandler deve implementar getOrCreateCardInvoice');
echo "✓ Contratos do ActionHandler validados.\n";

// 4. Index.php & Layout.php routing & navigation
$indexFile = (string) file_get_contents($root . '/index.php');
assert(str_contains($indexFile, "'cards'"), 'index.php deve conter rota cards');

$layoutFile = (string) file_get_contents($root . '/app/Views/layout.php');
assert(str_contains($layoutFile, '?page=cards'), 'layout.php deve conter link para Cartões de Crédito');
echo "✓ Roteamento e navegação de Cartões validados.\n";

// 5. Views existence and contents
$cardsView = $root . '/app/Views/pages/cards.php';
assert(file_exists($cardsView), 'View cards.php deve existir');
$content = (string) file_get_contents($cardsView);
assert(strlen($content) > 500, 'View cards.php deve ter conteúdo substancial');
assert(str_contains($content, 'save_credit_card'), 'View cards deve conter form save_credit_card');
assert(str_contains($content, 'save_card_transaction'), 'View cards deve conter form save_card_transaction');
assert(str_contains($content, 'pay_card_invoice'), 'View cards deve conter form pay_card_invoice');

echo "✓ View cards.php e componentes interativos validados.\n";
echo "Todos os 20 testes e contratos da Fase 3 passaram com sucesso!\n";
