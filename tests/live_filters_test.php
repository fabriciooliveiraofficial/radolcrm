<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$pages = ['clients', 'products', 'subscriptions', 'payments', 'expenses', 'cash'];
$failures = [];
foreach ($pages as $page) {
    $html = (string) file_get_contents($root . '/app/Views/pages/' . $page . '.php');
    if (!str_contains($html, 'data-live-filter')) $failures[] = $page . ': formulário dinâmico ausente';
    if (!str_contains($html, 'data-live-results')) $failures[] = $page . ': resultados dinâmicos ausentes';
    if (preg_match('/>(Buscar|Filtrar|Aplicar)<\/button>/', $html)) $failures[] = $page . ': botão manual ainda presente';
}
$javascript = (string) file_get_contents($root . '/assets/js/app.js');
foreach (['AbortController', 'DOMParser', 'history.replaceState', '320'] as $feature) {
    if (!str_contains($javascript, $feature)) $failures[] = 'JavaScript: ' . $feature . ' ausente';
}
if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "Filtros dinâmicos presentes em todas as listagens.\n";
