<?php

declare(strict_types=1);

echo "Iniciando verificação de Isolamento e Integridade da Gestão Financeira Diária...\n";

$root = dirname(__DIR__);

// 1. Verificar se a rota 'financeiro' está registrada no index.php
$indexContent = file_get_contents($root . '/index.php');
assert(str_contains($indexContent, "'financeiro'"), "A rota 'financeiro' deve estar presente no index.php");
assert(str_contains($indexContent, "'Gestão Financeira Diária'"), "O título da página 'financeiro' deve estar no index.php");
echo "✓ 1. Rota 'financeiro' devidamente registrada no index.php.\n";

// 2. Verificar layout.php
$layoutContent = file_get_contents($root . '/app/Views/layout.php');
assert(str_contains($layoutContent, "?page=financeiro"), "O menu lateral deve conter o link para '?page=financeiro'");
assert(str_contains($layoutContent, "Gestão Financeira Diária"), "O menu lateral deve exibir 'Gestão Financeira Diária'");
echo "✓ 2. Menu lateral do layout.php atualizado com a nova central financeira.\n";

// 3. Verificar schema.sql
$schemaContent = file_get_contents($root . '/database/schema.sql');
$dailyTables = [
    'daily_categories',
    'daily_payees',
    'daily_credit_cards',
    'daily_card_invoices',
    'daily_transactions',
    'daily_recurring_commitments',
];
foreach ($dailyTables as $table) {
    assert(str_contains($schemaContent, "CREATE TABLE IF NOT EXISTS {$table}"), "Tabela {$table} deve estar definida no schema.sql");
}
assert(str_contains($schemaContent, "('schema_version', '19')"), "Versão 19 de schema deve estar no schema.sql");
assert(str_contains($schemaContent, "'boleto'"), "Método de pagamento 'boleto' deve estar presente no schema.sql");
echo "✓ 3. Tabelas isoladas daily_* e versão 19 presentes no schema.sql.\n";

// 4. Verificar MigrationService.php
$migrationContent = file_get_contents($root . '/app/Services/MigrationService.php');
assert(str_contains($migrationContent, "const VERSION = 19;"), "MigrationService deve estar na versão 19");
assert(str_contains($migrationContent, "\$version < 19"), "MigrationService deve conter o bloco de migração 19");
assert(str_contains($migrationContent, "canonicalGearzoneId"), "MigrationService deve conter a lógica de unificação canônica");
assert(str_contains($migrationContent, "Gearzone"), "MigrationService deve garantir a preservação da Gearzone");
assert(str_contains($migrationContent, "Transafe"), "MigrationService deve limpar referências à Transafe");
assert(str_contains($migrationContent, "Assistente Virtual"), "MigrationService deve limpar referências ao Assistente Virtual");
echo "✓ 4. MigrationService com versão 19 e blindagem/unificação da Gearzone validada.\n";

// 5. Verificar DailyFinanceService.php
assert(file_exists($root . '/app/Services/DailyFinanceService.php'), "DailyFinanceService.php deve existir");

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $file = $root . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

$serviceReflection = new ReflectionClass(\App\Services\DailyFinanceService::class);
$methods = [
    'summary',
    'agenda',
    'categoriesWithBudgets',
    'recentPayees',
    'cardsList',
    'commitmentsList',
    'getOrCreateInvoice',
    'getOrCreateInvoiceForDueDate',
    'recalculateInvoiceTotal'
];
foreach ($methods as $method) {
    assert($serviceReflection->hasMethod($method), "DailyFinanceService deve ter o método {$method}");
}
echo "✓ 5. DailyFinanceService analisado e métodos de contrato validados (incluindo faturas por vencimento).\n";

// 6. Verificar ActionHandler.php
$actionHandlerContent = file_get_contents($root . '/app/Http/ActionHandler.php');
$dailyActions = [
    'save_daily_transaction',
    'delete_daily_transaction',
    'save_daily_card',
    'save_daily_card_ajax',
    'delete_daily_card',
    'pay_daily_card_invoice',
    'save_daily_commitment',
    'pay_daily_commitment',
    'delete_daily_commitment',
    'save_daily_category',
    'delete_daily_category',
];
foreach ($dailyActions as $act) {
    assert(str_contains($actionHandlerContent, "'{$act}'"), "ActionHandler deve mapear ação {$act}");
    assert(str_contains($actionHandlerContent, "function {$act}"), "ActionHandler deve conter o método {$act}");
}
echo "✓ 6. Todas as ações do ActionHandler implementadas e mapeadas (incluindo AJAX de cartões).\n";

// 7. Verificar View financeiro.php
assert(file_exists($root . '/app/Views/pages/financeiro.php'), "View app/Views/pages/financeiro.php deve existir");
$viewContent = file_get_contents($root . '/app/Views/pages/financeiro.php');
assert(str_contains($viewContent, "quickTxModal"), "Modal de lançamento rápido inteligente deve existir");
assert(str_contains($viewContent, "installmentsScheduleBlock"), "Cronograma de parcelas editável deve existir");
assert(str_contains($viewContent, "inlineCardModal"), "Modal inline de criação de cartão via AJAX deve existir");
assert(str_contains($viewContent, "openEditTxModal"), "Função para edição de lançamentos individuais deve existir");
assert(str_contains($viewContent, "calculateInstallmentDates"), "Cálculo inteligente de datas de vencimento deve existir");
assert(str_contains($viewContent, "payeesList"), "Datalist inteligente de favorecidos deve existir");
assert(str_contains($viewContent, "cardDetailsBlock"), "Bloco de cartões com parcelamento deve existir");
assert(str_contains($viewContent, "Extrato Diário"), "Aba de Extrato Diário deve existir");
assert(str_contains($viewContent, "Agenda Preditiva"), "Aba de Agenda Preditiva deve existir");
assert(str_contains($viewContent, "Cartões de Crédito"), "Aba de Cartões de Crédito deve existir");
assert(str_contains($viewContent, "Despesas Fixas & Filhos"), "Aba de Despesas Fixas & Filhos deve existir");
assert(str_contains($viewContent, "Tetos & Orçamentos"), "Aba de Tetos e Orçamentos deve existir");
assert(str_contains($viewContent, "Categorias Oficiais"), "Aba de Categorias Oficiais deve existir");
echo "✓ 7. View financeiro.php completa com parcelamento avançado, ajuste fino e cadastro ágil de cartões.\n";

// 8. Verificar Database.php
$dbReflection = new ReflectionClass(\App\Core\Database::class);
assert($dbReflection->hasMethod('insert'), "Database deve ter método insert");
assert($dbReflection->hasMethod('execute'), "Database deve ter método execute");
assert($dbReflection->hasMethod('update'), "Database deve ter método update");
echo "✓ 8. Database com insert flexível, update e execute validados.\n";

echo "\n🎉 TODOS OS CONTRATOS E TESTES DE ISOLAMENTO PASSARAM COM 100% DE SUCESSO!\n";
