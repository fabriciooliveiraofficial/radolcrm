<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$clients = (string) file_get_contents($root . '/app/Views/pages/clients.php');
$subscriptions = (string) file_get_contents($root . '/app/Views/pages/subscriptions.php');
$css = (string) file_get_contents($root . '/assets/css/app.css');
$layout = (string) file_get_contents($root . '/app/Views/layout.php');

$clientColumns = [
    "'client' => 'c.name'",
    "'country' =>",
    "'currency' => 'c.preferred_currency'",
    "'subscriptions' => 'active_subscriptions'",
    "'status' =>",
];
$subscriptionColumns = [
    "'client_product' => 'c.name'",
    "'recurring_value' => '((s.unit_price*s.quantity)-s.discount)'",
    "'cycle' =>",
    "'next_billing' => 's.next_billing_date'",
    "'status' =>",
];

$contracts = [
    'todas as colunas de clientes possuem ordenação' => array_reduce(
        $clientColumns,
        static fn(bool $found, string $column): bool => $found && str_contains($clients, $column),
        true
    ) && substr_count($clients, "\$tableSortHeader('") >= 5,
    'todas as colunas de assinaturas possuem ordenação' => array_reduce(
        $subscriptionColumns,
        static fn(bool $found, string $column): bool => $found && str_contains($subscriptions, $column),
        true
    ) && substr_count($subscriptions, "\$tableSortHeader('") >= 5,
    'ordenação ocorre no servidor antes da paginação' => str_contains($clients, "' ORDER BY '.\$orderBy")
        && str_contains($subscriptions, 'ORDER BY {$orderBy}')
        && str_contains($clients, '$params, $perPage)')
        && str_contains($subscriptions, '$params, $perPage)'),
    'parâmetros de ordenação usam listas permitidas' => str_contains($clients, 'isset($sortOptions[(string) ($_GET[\'sort\'] ?? \'\')])')
        && str_contains($subscriptions, 'isset($sortOptions[(string) ($_GET[\'sort\'] ?? \'\')])'),
    'direção aceita somente ascendente ou descendente' => substr_count($clients . $subscriptions, "=== 'desc' ? 'desc' : 'asc'") === 2,
    'clique alterna direção e reinicia a paginação' => substr_count($clients . $subscriptions, "unset(\$query['p']") === 2
        && substr_count($clients . $subscriptions, "\$nextDirection = \$active && \$sortDirection === 'asc' ? 'desc' : 'asc'") === 2,
    'busca e quantidade por página preservam a ordenação' => substr_count($clients, 'name="sort"') >= 2
        && substr_count($clients, 'name="dir"') >= 2
        && substr_count($subscriptions, 'name="sort"') >= 2
        && substr_count($subscriptions, 'name="dir"') >= 2,
    'cabeçalhos informam estado para tecnologias assistivas' => substr_count($clients . $subscriptions, 'aria-sort=') >= 2
        && substr_count($clients . $subscriptions, 'aria-label="Ordenar por') >= 2,
    'indicador visual possui estados padrão, hover e ativo' => str_contains($css, '.table-sort-link')
        && str_contains($css, '.table-sort-link:hover')
        && str_contains($css, '.sortable-column.is-sorted .table-sort-indicator'),
    'cache do estilo foi atualizado' => str_contains($layout, 'assets/css/app.css?v=21'),
];

$failed = array_keys(array_filter($contracts, static fn(bool $passed): bool => !$passed));
if ($failed) {
    fwrite(STDERR, 'Falharam: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo count($contracts) . " contratos de ordenação passaram.\n";
