<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Http/ActionHandler.php';

$reflection = new ReflectionClass(\App\Http\ActionHandler::class);
$handler = $reflection->newInstanceWithoutConstructor();
$addBillingMonths = $reflection->getMethod('addBillingMonths');
$addCalendarMonths = $reflection->getMethod('addCalendarMonths');
$renewalPeriodBetween = $reflection->getMethod('renewalPeriodBetween');

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

$advanceCases = [
    ['2026-07-26', 2, '2026-09-26'],
    ['2026-07-26', 4, '2026-11-26'],
    ['2026-07-26', 24, '2028-07-26'],
    ['2026-01-31', 1, '2026-02-28'],
    ['2024-01-31', 1, '2024-02-29'],
];
foreach ($advanceCases as [$paymentDate, $months, $expected]) {
    $actual = $addCalendarMonths->invoke($handler, $paymentDate, $months);
    if ($actual !== $expected) {
        fwrite(STDERR, "Falha: {$paymentDate} + {$months} mês(es) deveria gerar {$expected}, mas gerou {$actual}.\n");
        exit(1);
    }
}

$periodCases = [
    ['2026-07-26', '2026-11-26', [4, 0]],
    ['2026-07-26', '2026-12-10', [4, 14]],
    ['2026-01-31', '2026-03-15', [1, 15]],
    ['2026-07-26', '2026-08-10', [0, 15]],
];
foreach ($periodCases as [$start, $end, $expected]) {
    $actual = $renewalPeriodBetween->invoke($handler, $start, $end);
    if ($actual !== $expected) {
        fwrite(STDERR, "Falha: {$start} até {$end} deveria gerar {$expected[0]} mês(es) e {$expected[1]} dia(s).\n");
        exit(1);
    }
}

$actionHandler = (string) file_get_contents(dirname(__DIR__) . '/app/Http/ActionHandler.php');
$javascript = (string) file_get_contents(dirname(__DIR__) . '/assets/js/app.js');
if (!str_contains($actionHandler, "\$renewalEndDate = \$this->addCalendarMonths(\$receiptDate, \$renewalMonths)")
    || !str_contains($actionHandler, "\$nextBillingDate = (string) \$row['renewal_end_date']")) {
    fwrite(STDERR, "O backend não usa o período escolhido a partir da data do pagamento.\n");
    exit(1);
}
if (!str_contains($javascript, 'period.days / 30')
    || !str_contains($javascript, 'addMonths(receipt.value, months)')) {
    fwrite(STDERR, "A previsão no popup não calcula meses e dias proporcionais.\n");
    exit(1);
}

echo (count($cases) + count($advanceCases) + count($periodCases)) . " cenários de renovação por data de pagamento passaram.\n";
