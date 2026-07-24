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
    'amanhã calculado no timezone configurado' => str_contains($view, "new DateTimeZone((string) (\$config['app']['timezone'] ?? 'America/Sao_Paulo'))")
        && str_contains($view, "\$tomorrowDate = (new DateTimeImmutable('today', \$dashboardTimezone))->modify('+1 day')->format('Y-m-d')"),
    'consulta parametrizada de assinaturas que vencem amanhã' => str_contains($view, 's.next_billing_date=?')
        && str_contains($view, '[$tomorrowDate]'),
    'tabela de vencimentos do dia seguinte' => str_contains($view, 'Assinaturas a vencer no dia seguinte'),
    'nome, país e vencimento na tabela' => str_contains($view, '<th>Nome</th><th>País</th><th>Data de vencimento</th>'),
    'bandeira do país nos vencimentos' => str_contains($view, "country_flag_icon(\$item['country'])"),
    'cards inferiores contidos no mobile' => str_contains($css, '.lower-grid .card { min-width:0;max-width:100%')
        && str_contains($css, 'grid-template-columns:minmax(0,1fr)'),
    'layout executivo responsivo' => str_contains($css, '.executive-hero') && str_contains($css, '.customer-status-grid'),
];

$failed = array_keys(array_filter($contracts, static fn(bool $passed): bool => !$passed));
if ($failed) {
    fwrite(STDERR, 'Falharam: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo count($contracts) . " contratos do dashboard passaram.\n";
