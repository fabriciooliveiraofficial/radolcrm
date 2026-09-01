<?php

declare(strict_types=1);

$root = dirname(__DIR__);

echo "Iniciando verificação de contratos da Fase 7: Automações Financeiras e Rotinas Cron...\n";

// 1. FinancialAutomationService contracts
$serviceFile = (string) file_get_contents($root . '/app/Services/FinancialAutomationService.php');
assert(str_contains($serviceFile, 'class FinancialAutomationService'), 'FinancialAutomationService class deve existir');
assert(str_contains($serviceFile, 'function runDailyFinancialAutomation'), 'runDailyFinancialAutomation deve ser implementado');
assert(str_contains($serviceFile, "UPDATE credit_card_invoices SET status = 'closed'"), 'Deve fechar faturas com closing_date vencida');
assert(str_contains($serviceFile, "UPDATE installments SET status = 'overdue'"), 'Deve marcar parcelas vencidas como overdue');
assert(str_contains($serviceFile, 'auto_generate = 1'), 'Deve manter contratos contínuos auto_generate');
assert(str_contains($serviceFile, 'financial_automation_last_run_at'), 'Deve salvar registro de última execução');
echo "✓ Contratos do FinancialAutomationService validados.\n";

// 2. Cron script contracts
$cronFile = $root . '/cron/financial_automation.php';
assert(file_exists($cronFile), 'cron/financial_automation.php deve existir');
$cronContent = (string) file_get_contents($cronFile);
assert(str_contains($cronContent, 'FinancialAutomationService'), 'cron script deve invocar FinancialAutomationService');
echo "✓ Script cron validado.\n";

// 3. ActionHandler contracts
$actionFile = (string) file_get_contents($root . '/app/Http/ActionHandler.php');
assert(str_contains($actionFile, "'run_financial_automation'"), 'ActionHandler deve conter ação run_financial_automation');
assert(str_contains($actionFile, 'function runFinancialAutomation'), 'ActionHandler deve implementar runFinancialAutomation');
echo "✓ Ação de disparo manual no ActionHandler validada.\n";

// 4. Settings view contracts
$settingsView = $root . '/app/Views/pages/settings.php';
assert(file_exists($settingsView), 'View settings.php deve existir');
$settingsContent = (string) file_get_contents($settingsView);
assert(str_contains($settingsContent, 'id="automation"'), 'settings.php deve conter seção de automação');
assert(str_contains($settingsContent, 'run_financial_automation'), 'settings.php deve conter botão de disparo de automação');
assert(str_contains($settingsContent, 'cron/financial_automation.php'), 'settings.php deve exibir instrução do cron');
echo "✓ View settings.php e painel de automação validados.\n";

echo "Todos os 15 testes e contratos da Fase 7 passaram com sucesso!\n";
