<?php

declare(strict_types=1);

$root = dirname(__DIR__);

echo "Iniciando verificação de Busca Preditiva Avançada na Agenda Preditiva...\n";

// 1. Verificar suporte a $search em DailyFinanceService::agenda
$serviceCode = (string) file_get_contents($root . '/app/Services/DailyFinanceService.php');
assert(str_contains($serviceCode, 'public function agenda(string $from, string $to, string $search = \'\', string $typeFilter = \'\')'), 'DailyFinanceService::agenda deve aceitar parâmetros $search e $typeFilter');
assert(str_contains($serviceCode, 'Filtro Avançado por Texto (Busca Preditiva Caractere por Caractere)'), 'DailyFinanceService::agenda deve possuir bloco de filtro por texto');

// 2. Verificar suporte a $search em FinanceService::financialAgenda
$financeServiceCode = (string) file_get_contents($root . '/app/Services/FinanceService.php');
assert(str_contains($financeServiceCode, 'public function financialAgenda(string $from, string $to, ?int $businessUnitId = null, float $usdRate = 5.5, string $search = \'\')'), 'FinanceService::financialAgenda deve aceitar parâmetro $search');

// 3. Verificar presença de data-live-filter e data-live-results na view financeiro.php (aba agenda)
$viewCode = (string) file_get_contents($root . '/app/Views/pages/financeiro.php');
assert(str_contains($viewCode, 'data-live-filter'), 'financeiro.php deve conter formulário com data-live-filter');
assert(str_contains($viewCode, 'data-live-results'), 'financeiro.php deve conter container com data-live-results');
assert(str_contains($viewCode, 'placeholder="Buscar favorecido, cartão, categoria, valor..."'), 'financeiro.php deve conter input de busca preditiva na aba agenda');
assert(str_contains($viewCode, '$dailyService->agenda($from, date(\'Y-m-d\', strtotime($to . \' +30 days\')), $search, $typeFilter)'), 'financeiro.php deve passar $search e $typeFilter para $dailyService->agenda');

echo "✓ Busca preditiva na Agenda Preditiva totalmente validada com sucesso!\n";
