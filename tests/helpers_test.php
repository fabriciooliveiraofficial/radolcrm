<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Support/helpers.php';

$tests = [
    'decimal brasileiro' => normalize_decimal('1.234,56') === 1234.56,
    'decimal internacional' => normalize_decimal('1234.56') === 1234.56,
    'decimal vazio' => normalize_decimal('') === 0.0,
    'escape HTML' => h('<script>') === '&lt;script&gt;',
    'data brasileira' => date_br('2026-07-21') === '21/07/2026',
    'ciclo mensal' => cycle_label('monthly') === 'Mensal',
    'status pago' => status_label('paid') === 'Pago',
    'produto cotado em USD converte para BRL' => product_with_current_prices(['pricing_mode'=>'usd','price_brl'=>0,'price_usd'=>15], 5.5)['price_brl'] === 82.5,
    'produto cotado em BRL converte para USD' => product_with_current_prices(['pricing_mode'=>'brl','price_brl'=>55,'price_usd'=>0], 5.5)['price_usd'] === 10.0,
    'produto manual preserva preços locais' => product_with_current_prices(['pricing_mode'=>'manual','price_brl'=>50,'price_usd'=>15], 5.5)['price_usd'] === 15.0,
];

$failed = [];
foreach ($tests as $name => $result) {
    if (!$result) {
        $failed[] = $name;
    }
}

if ($failed) {
    fwrite(STDERR, 'Falharam: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo count($tests) . " testes passaram.\n";
