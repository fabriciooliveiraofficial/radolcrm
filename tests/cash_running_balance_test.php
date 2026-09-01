<?php

declare(strict_types=1);

function h(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function money(float|int|string|null $value, string $currency = 'BRL'): string { return ($currency === 'USD' ? 'US$' : 'R$') . ' ' . number_format((float) $value, 2, ',', '.'); }
function date_br(?string $date): string { return $date ? (new DateTimeImmutable($date))->format('d/m/Y') : '—'; }

// Simular cálculo contábil exato do Livro Caixa / Extrato
$openingBalance = 0.0; // Saldo inicial antes de 21/07/2026

$rawLedger = [
    // Operações em ordem cronológica ASC
    [
        'row_id' => 'expense-1',
        'source' => 'expense',
        'id' => 1,
        'entry_date' => '2026-07-21',
        'direction' => 'out',
        'description' => 'Campanha de marketing',
        'amount_brl' => '1300.00',
        'currency' => 'BRL',
        'original_amount' => '1300.00',
        'status' => 'paid',
    ],
    [
        'row_id' => 'expense-2',
        'source' => 'expense',
        'id' => 2,
        'entry_date' => '2026-07-21',
        'direction' => 'out',
        'description' => 'Servidor AWS',
        'amount_brl' => '400.00',
        'currency' => 'BRL',
        'original_amount' => '400.00',
        'status' => 'paid',
    ],
    [
        'row_id' => 'payment-1',
        'source' => 'payment',
        'id' => 1,
        'entry_date' => '2026-07-22',
        'direction' => 'in',
        'description' => 'Renovação · 1 mês',
        'amount_brl' => '90.54',
        'currency' => 'BRL',
        'original_amount' => '90.54',
        'status' => 'paid',
    ],
];

$running = $openingBalance;
$periodIn = 0.0;
$periodOut = 0.0;
$computedLedger = [];

foreach ($rawLedger as $item) {
    $amount = (float) $item['amount_brl'];
    if ($item['direction'] === 'in') {
        $running += $amount;
        $periodIn += $amount;
    } else {
        $running -= $amount;
        $periodOut += $amount;
    }
    $item['balance_after'] = $running;
    $computedLedger[] = $item;
}

// Verificar os saldos após cada movimentação
assert(abs($computedLedger[0]['balance_after'] - (-1300.00)) < 0.001, "Primeiro gasto deve resultar em saldo -1300.00");
assert(abs($computedLedger[1]['balance_after'] - (-1700.00)) < 0.001, "Segundo gasto deve resultar em saldo -1700.00");
assert(abs($computedLedger[2]['balance_after'] - (-1609.46)) < 0.001, "Entrada deve resultar em saldo -1609.46");

// Ordem decrescente para exibição
$displayLedger = array_reverse($computedLedger);
assert($displayLedger[0]['id'] === 1 && $displayLedger[0]['source'] === 'payment', "Mais recente no topo");
assert(abs($displayLedger[0]['balance_after'] - (-1609.46)) < 0.001, "Saldo da linha mais recente");
assert($displayLedger[1]['id'] === 2 && $displayLedger[1]['source'] === 'expense', "Gasto 2 no meio");
assert(abs($displayLedger[1]['balance_after'] - (-1700.00)) < 0.001, "Saldo da linha do segundo gasto");
assert($displayLedger[2]['id'] === 1 && $displayLedger[2]['source'] === 'expense', "Gasto 1 no final");
assert(abs($displayLedger[2]['balance_after'] - (-1300.00)) < 0.001, "Saldo da linha do primeiro gasto");

echo "Todos os testes de Saldo Acumulado (Running Balance) passaram com sucesso!\n";
