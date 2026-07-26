<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Http/ActionHandler.php';

$reflection = new ReflectionClass(\App\Http\ActionHandler::class);
$handler = $reflection->newInstanceWithoutConstructor();
$addBillingMonths = $reflection->getMethod('addBillingMonths');

$cases = [
    ['2026-07-26', 'monthly', '2026-08-26'],
    ['2026-07-26', 'quarterly', '2026-10-26'],
    ['2026-07-26', 'semiannual', '2027-01-26'],
    ['2026-07-26', 'annual', '2027-07-26'],
    ['2026-01-31', 'monthly', '2026-02-28'],
    ['2024-01-31', 'monthly', '2024-02-29'],
];

foreach ($cases as [$paymentDate, $cycle, $expected]) {
    $actual = $addBillingMonths->invoke($handler, $paymentDate, $cycle);
    if ($actual !== $expected) {
        fwrite(STDERR, "Falha: {$paymentDate} ({$cycle}) deveria gerar {$expected}, mas gerou {$actual}.\n");
        exit(1);
    }
}

$actionHandler = (string) file_get_contents(dirname(__DIR__) . '/app/Http/ActionHandler.php');
$javascript = (string) file_get_contents(dirname(__DIR__) . '/assets/js/app.js');
if (!str_contains($actionHandler, "addBillingMonths(\$row['receipt_date'], (string) \$product['billing_cycle'])")) {
    fwrite(STDERR, "O backend não usa a data de pagamento como base da próxima cobrança.\n");
    exit(1);
}
if (!str_contains($javascript, 'addMonths(receipt.value, months)')) {
    fwrite(STDERR, "A previsão no popup não usa a data de pagamento.\n");
    exit(1);
}

echo count($cases) . " cenários de renovação por data de pagamento passaram.\n";
