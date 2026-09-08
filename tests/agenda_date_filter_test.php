<?php

declare(strict_types=1);

$root = dirname(__DIR__);

echo "Iniciando verificação do filtro de data (de/até) na Agenda Preditiva...\n";

$viewCode = (string) file_get_contents($root . '/app/Views/pages/financeiro.php');
assert(
    str_contains($viewCode, '$agendaData = $dailyService->agenda($from, $to, $search, $typeFilter);'),
    'financeiro.php deve passar exatamente os parâmetros $from e $to sem acrescentar dias extras (+30 days)'
);

echo "✓ Filtro por período de data (de/até) na Agenda Preditiva validado com sucesso!\n";
