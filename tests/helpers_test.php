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

