<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$schema = (string) file_get_contents($root . '/database/schema.sql');
$migration = (string) file_get_contents($root . '/app/Services/MigrationService.php');
$migrationSql = (string) file_get_contents($root . '/database/migrations/007_advance_renewals.sql');
$actions = (string) file_get_contents($root . '/app/Http/ActionHandler.php');
$subscriptions = (string) file_get_contents($root . '/app/Views/pages/subscriptions.php');
$payments = (string) file_get_contents($root . '/app/Views/pages/payments.php');
$dashboard = (string) file_get_contents($root . '/app/Views/pages/dashboard.php');
$finance = (string) file_get_contents($root . '/app/Services/FinanceService.php');
$javascript = (string) file_get_contents($root . '/assets/js/app.js');

$contracts = [
    'schema v7 guarda período e composição financeira' => str_contains($schema, 'renewal_mode')
        && str_contains($schema, 'renewal_months')
        && str_contains($schema, 'renewal_days')
        && str_contains($schema, 'renewal_end_date')
        && str_contains($schema, 'base_amount')
        && str_contains($schema, 'discount_amount')
        && str_contains($schema, 'surcharge_amount')
        && str_contains($schema, 'manual_adjustment_amount')
        && str_contains($schema, "('schema_version', '8')"),
    'migração automática v7' => str_contains($migration, 'private const VERSION = 8')
        && str_contains($migration, 'if ($version < 7)')
        && str_contains($migrationSql, "VALUES ('schema_version','7')"),
    'dois modos de renovação' => str_contains($subscriptions, 'value="months" data-renewal-mode')
        && str_contains($subscriptions, 'value="date" data-renewal-mode')
        && str_contains($subscriptions, 'data-renewal-months')
        && str_contains($subscriptions, 'data-renewal-custom-date'),
    'limite de 1 a 24 meses' => str_contains($subscriptions, 'min="1" max="24"')
        && str_contains($actions, '$renewalMonths < 1 || $renewalMonths > 24'),
    'pró-rata de 30 dias' => str_contains($javascript, 'period.days / 30')
        && str_contains($subscriptions, 'Valor mensal ÷ 30 dias'),
    'valores editáveis' => str_contains($subscriptions, 'name="renewals[<?= $subscriptionId ?>][base_amount]"')
        && str_contains($subscriptions, '[payment_discount]')
        && str_contains($subscriptions, '[surcharge_amount]')
        && str_contains($subscriptions, '[amount]'),
    'valor final pode ter ajuste manual' => str_contains($actions, '$manualAdjustment = round($received - $calculatedFinal, 2)')
        && str_contains($javascript, 'Valor final ajustado'),
    'próxima cobrança usa período escolhido' => str_contains($actions, "\$nextBillingDate = (string) \$row['renewal_end_date']")
        && str_contains($actions, 'renewalPeriodBetween'),
    'pagamento persiste detalhamento' => str_contains($actions, 'manual_adjustment_amount')
        && str_contains($actions, 'renewal_start_date')
        && str_contains($actions, 'renewal_end_date'),
    'dashboard e caixa usam valor recebido real' => str_contains($finance, 'SUM(amount_brl)')
        && str_contains($finance, 'SUM(net_brl)')
        && str_contains($dashboard, '$item[\'amount_brl\']'),
    'histórico e pagamentos exibem período' => str_contains($subscriptions, 'Período renovado')
        && str_contains($payments, 'payment-coverage')
        && str_contains($payments, 'Ajuste manual:'),
];

$failed = array_keys(array_filter($contracts, static fn(bool $passed): bool => !$passed));
if ($failed) {
    fwrite(STDERR, 'Falharam: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo count($contracts) . " contratos de renovações antecipadas passaram.\n";
