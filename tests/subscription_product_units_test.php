<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$view = (string) file_get_contents($root . '/app/Views/pages/subscriptions.php');
$css = (string) file_get_contents($root . '/assets/css/app.css');

$contracts = [
    'resumo considera somente assinaturas ativas' => str_contains($view, "s.product_id=p.id AND s.status='active'"),
    'produtos numéricos multiplicam pontos pela quantidade' => str_contains($view, "LOWER(TRIM(p.name)) REGEXP '^[0-9]+[[:space:]]*pontos?'")
        && str_contains($view, 'CAST(TRIM(p.name) AS UNSIGNED) * s.quantity'),
    'multipontos e aplicativos somam quantidade direta' => str_contains($view, 'ELSE s.quantity'),
    'resultado agrupa por produto' => str_contains($view, 'GROUP BY p.id,p.name,p.active')
        && str_contains($view, 'COUNT(s.id) active_subscriptions'),
    'total geral soma unidades de todos os produtos' => str_contains($view, '$totalProductUnits = array_sum')
        && str_contains($view, 'data-total-units="<?= $totalProductUnits ?>"'),
    'um card é criado para cada produto' => str_contains($view, 'foreach ($productUnitSummary as $productSummary)')
        && str_contains($view, 'data-product-id="<?= (int) $productSummary[\'id\'] ?>"')
        && str_contains($view, 'data-product-units="<?= (int) $productSummary[\'active_units\'] ?>"'),
    'cards distinguem unidades e assinaturas' => str_contains($view, 'unidades ativas')
        && str_contains($view, 'assinatura(s) ativa(s)'),
    'layout responsivo dos cards' => str_contains($css, '.subscription-unit-grid')
        && str_contains($css, '.subscription-unit-card')
        && str_contains($css, 'grid-template-columns:1fr'),
];

$failed = array_keys(array_filter($contracts, static fn(bool $passed): bool => !$passed));
if ($failed) {
    fwrite(STDERR, 'Falharam: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo count($contracts) . " contratos de unidades por produto passaram.\n";
