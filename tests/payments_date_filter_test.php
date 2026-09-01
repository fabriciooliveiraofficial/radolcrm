<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$view = (string) file_get_contents($root . '/app/Views/pages/payments.php');

$contracts = [
    'variáveis from e to no início do arquivo' => str_contains($view, "\$from=trim((string)(\$_GET['from']??''));")
        && str_contains($view, "\$to=trim((string)(\$_GET['to']??''));"),
    'expressão de data para pagamentos em BRL e USD' => str_contains($view, "\$dateExpr=\"COALESCE(CASE WHEN p.currency='USD' THEN COALESCE(p.settlement_date,p.payment_date) ELSE p.payment_date END,p.due_date,DATE(p.created_at))\";"),
    'filtro de data inicial (from)' => str_contains($view, "if(\$from!==''){ \$where.=\" AND {\$dateExpr} >= ?\"; \$params[]=\$from; }"),
    'filtro de data final (to)' => str_contains($view, "if(\$to!==''){ \$where.=\" AND {\$dateExpr} <= ?\"; \$params[]=\$to; }"),
    'inputs De e Até no formulário de busca com live-filter' => str_contains($view, '<label>De<input type="date" name="from" value="<?= h($from) ?>"></label>')
        && str_contains($view, '<label>Até<input type="date" name="to" value="<?= h($to) ?>"></label>'),
    'mini-stats atualizam com data-live-results' => str_contains($view, '<section class="mini-stats money-stats" data-live-results>'),
];

$failed = array_keys(array_filter($contracts, static fn(bool $passed): bool => !$passed));
if ($failed) {
    fwrite(STDERR, 'Falharam: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo count($contracts) . " contratos do filtro de data de pagamentos passaram com sucesso.\n";
