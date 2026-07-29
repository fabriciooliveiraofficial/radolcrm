<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$clients = (string) file_get_contents($root . '/app/Views/pages/clients.php');
$subscriptions = (string) file_get_contents($root . '/app/Views/pages/subscriptions.php');
$javascript = (string) file_get_contents($root . '/assets/js/app.js');
$css = (string) file_get_contents($root . '/assets/css/app.css');

$contracts = [
    'clientes aceita 20, 50, 100 ou 200 linhas' => str_contains($clients, '$pageSizeOptions = [20,50,100,200]')
        && str_contains($clients, 'pagination($db,')
        && str_contains($clients, '$params, $perPage)'),
    'assinaturas aceita 20, 50, 100 ou 200 linhas' => str_contains($subscriptions, '$pageSizeOptions = [20,50,100,200]')
        && str_contains($subscriptions, 'pagination($db, $countSql, $dataSql, $params, $perPage)'),
    'padrão de 20 linhas' => str_contains($clients, "\$_GET['per_page'] ?? 20")
        && str_contains($subscriptions, "\$_GET['per_page'] ?? 20"),
    'valores não permitidos retornam ao padrão' => str_contains($clients, '? (int) ($_GET[\'per_page\'] ?? 20) : 20')
        && str_contains($subscriptions, '? (int) ($_GET[\'per_page\'] ?? 20) : 20'),
    'intervalo exibido no cabeçalho' => str_contains($clients, 'Exibindo <?= $displayedFrom ?>–<?= $displayedTo ?> de')
        && str_contains($subscriptions, 'Exibindo <?= $displayedFrom ?>–<?= $displayedTo ?> de'),
    'seletor no cabeçalho das duas tabelas' => str_contains($clients, 'class="table-meta with-page-size"')
        && str_contains($subscriptions, 'class="table-meta with-page-size"')
        && substr_count($clients . $subscriptions, 'data-page-size-select') === 2,
    'filtros preservam quantidade escolhida' => str_contains($clients, '<input type="hidden" name="per_page" value="<?= $perPage ?>">')
        && str_contains($subscriptions, '<input type="hidden" name="per_page" value="<?= $perPage ?>">'),
    'alteração submete o seletor mesmo após busca dinâmica' => str_contains($javascript, "event.target.closest('[data-page-size-select]')")
        && str_contains($javascript, 'select.form?.submit()'),
    'controle responsivo no mobile' => str_contains($css, '.table-meta.with-page-size')
        && str_contains($css, '.page-size-form label')
        && str_contains($css, '.page-size-form select'),
];

$failed = array_keys(array_filter($contracts, static fn(bool $passed): bool => !$passed));
if ($failed) {
    fwrite(STDERR, 'Falharam: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo count($contracts) . " contratos de quantidade de linhas passaram.\n";
