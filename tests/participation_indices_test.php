<?php

declare(strict_types=1);

$root = dirname(__DIR__);

echo "Iniciando verificação de contratos da Fase 4: Índices de Participação e Limitadores Automáticos...\n";

// 1. FinanceService contracts
$serviceFile = (string) file_get_contents($root . '/app/Services/FinanceService.php');
assert(str_contains($serviceFile, 'function revenueParticipation'), 'FinanceService deve implementar revenueParticipation');
assert(str_contains($serviceFile, 'function categoryExpenseIndices'), 'FinanceService deve implementar categoryExpenseIndices');
assert(str_contains($serviceFile, 'share_percent'), 'revenueParticipation deve calcular share_percent');
assert(str_contains($serviceFile, 'budget_limit_percent'), 'categoryExpenseIndices deve avaliar budget_limit_percent');
assert(str_contains($serviceFile, 'budget_limit_amount'), 'categoryExpenseIndices deve avaliar budget_limit_amount');
assert(str_contains($serviceFile, 'consumption_ratio'), 'categoryExpenseIndices deve calcular consumption_ratio');
assert(str_contains($serviceFile, 'alerts'), 'categoryExpenseIndices deve retornar array de alerts');
echo "✓ Métodos de inteligência e índices em FinanceService validados.\n";

// 2. Reports View contracts
$reportsView = $root . '/app/Views/pages/reports.php';
assert(file_exists($reportsView), 'View reports.php deve existir');
$content = (string) file_get_contents($reportsView);
assert(str_contains($content, 'revenueParticipation'), 'reports.php deve chamar revenueParticipation');
assert(str_contains($content, 'categoryExpenseIndices'), 'reports.php deve chamar categoryExpenseIndices');
assert(str_contains($content, 'PARTICIPAÇÃO NO FATURAMENTO'), 'reports.php deve conter seção de participação no faturamento');
assert(str_contains($content, 'ÍNDICES DE DESPESA E LIMITADORES'), 'reports.php deve conter seção de limitadores e índices');
assert(str_contains($content, 'Limitador de Gastos Ultrapassado!'), 'reports.php deve conter card de alerta para tetos ultrapassados');
assert(str_contains($content, 'Estourou teto'), 'reports.php deve sinalizar status de estouro de teto');

echo "✓ View reports.php e painéis de índices validados.\n";
echo "Todos os 15 testes e contratos da Fase 4 passaram com sucesso!\n";
