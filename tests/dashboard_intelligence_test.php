<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/app/Services/FinanceService.php');
$view = (string) file_get_contents($root . '/app/Views/pages/dashboard.php');
$css = (string) file_get_contents($root . '/assets/css/app.css');

$contracts = [
    'serviço de inteligência' => str_contains($service, 'function businessIntelligence'),
    'segmentação de clientes' => str_contains($service, 'overdue_clients'),
    'taxa de recebimento' => str_contains($service, 'collectionRate'),
    'ranking por MRR' => str_contains($service, 'ORDER BY mrr DESC LIMIT 5'),
    'central de comando' => str_contains($view, 'CENTRAL DE COMANDO'),
    'clientes ativos' => str_contains($view, 'Clientes ativos'),
    'clientes inativos' => str_contains($view, 'Clientes inativos'),
    'clientes vencidos' => str_contains($view, 'Clientes vencidos'),
    'copiloto de gestão' => str_contains($view, 'COPILOTO DE GESTÃO'),
    'pipeline de renovação' => str_contains($view, 'PIPELINE DE RENOVAÇÕES'),
    'layout executivo responsivo' => str_contains($css, '.executive-hero') && str_contains($css, '.customer-status-grid'),
];

$failed = array_keys(array_filter($contracts, static fn(bool $passed): bool => !$passed));
if ($failed) {
    fwrite(STDERR, 'Falharam: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo count($contracts) . " contratos do dashboard passaram.\n";
