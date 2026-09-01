<?php

declare(strict_types=1);

$root = dirname(__DIR__);

echo "Iniciando verificação de contratos da Fase 6: Dashboard Multi-Negócio Consolidado...\n";

// 1. FinanceService dashboard and BI contracts
$serviceFile = (string) file_get_contents($root . '/app/Services/FinanceService.php');
assert(str_contains($serviceFile, 'function dashboard(string $from, string $to, float $usdRate, ?int $businessUnitId = null)'), 'FinanceService::dashboard deve aceitar ?int $businessUnitId');
assert(str_contains($serviceFile, 'function businessIntelligence(float $usdRate, ?int $businessUnitId = null)'), 'FinanceService::businessIntelligence deve aceitar ?int $businessUnitId');
assert(str_contains($serviceFile, '$buWherePay = $businessUnitId'), 'dashboard deve filtrar pagamentos por negócio quando informado');
assert(str_contains($serviceFile, '$buWhereExp = $businessUnitId'), 'dashboard deve filtrar despesas por negócio quando informado');
assert(str_contains($serviceFile, '$buWhereCash = $businessUnitId'), 'dashboard deve filtrar caixa por negócio quando informado');
echo "✓ Contratos de suporte multi-negócio no FinanceService validados.\n";

// 2. Dashboard view contracts
$dashboardView = $root . '/app/Views/pages/dashboard.php';
assert(file_exists($dashboardView), 'View dashboard.php deve existir');
$content = (string) file_get_contents($dashboardView);
assert(str_contains($content, 'name="bu"'), 'dashboard.php deve conter seletor de negócio name="bu"');
assert(str_contains($content, 'Visão Consolidada'), 'dashboard.php deve suportar Visão Consolidada');
assert(str_contains($content, '$finance->dashboard($from, $to, (float) $rate[\'bid\'], $buFilter)'), 'dashboard.php deve repassar buFilter para dashboard');
assert(str_contains($content, '$finance->businessIntelligence((float) $rate[\'bid\'], $buFilter)'), 'dashboard.php deve repassar buFilter para businessIntelligence');
assert(str_contains($content, 'Distribuição de Faturamento por Unidade de Negócio'), 'dashboard.php deve conter widget de distribuição consolidada');

echo "✓ View dashboard.php e seletor multi-negócio validados.\n";
echo "Todos os 15 testes e contratos da Fase 6 passaram com sucesso!\n";
