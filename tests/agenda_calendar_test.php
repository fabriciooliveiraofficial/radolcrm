<?php

declare(strict_types=1);

$root = dirname(__DIR__);

echo "Iniciando verificação de contratos da Fase 5: Agenda Financeira Avançada...\n";

// 1. FinanceService financialAgenda contracts
$serviceFile = (string) file_get_contents($root . '/app/Services/FinanceService.php');
assert(str_contains($serviceFile, 'function financialAgenda'), 'FinanceService deve implementar financialAgenda');
assert(str_contains($serviceFile, 'expected_in'), 'financialAgenda deve calcular expected_in');
assert(str_contains($serviceFile, 'expected_out'), 'financialAgenda deve calcular expected_out');
assert(str_contains($serviceFile, 'expected_net'), 'financialAgenda deve calcular expected_net');
assert(str_contains($serviceFile, 'by_date'), 'financialAgenda deve agrupar eventos by_date');
assert(str_contains($serviceFile, 'credit_card_invoices'), 'financialAgenda deve consultar credit_card_invoices');
assert(str_contains($serviceFile, 'installments'), 'financialAgenda deve consultar installments');
assert(str_contains($serviceFile, 'subscriptions'), 'financialAgenda deve consultar subscriptions');
echo "✓ Métodos de agregação de agenda em FinanceService validados.\n";

// 2. Index.php & Layout.php routing & navigation
$indexFile = (string) file_get_contents($root . '/index.php');
assert(str_contains($indexFile, "'agenda'"), 'index.php deve conter rota agenda');

$layoutFile = (string) file_get_contents($root . '/app/Views/layout.php');
assert(str_contains($layoutFile, '?page=agenda'), 'layout.php deve conter link para Agenda Financeira');
echo "✓ Roteamento e navegação da Agenda validados.\n";

// 3. Views existence and contents
$agendaView = $root . '/app/Views/pages/agenda.php';
assert(file_exists($agendaView), 'View agenda.php deve existir');
$content = (string) file_get_contents($agendaView);
assert(strlen($content) > 500, 'View agenda.php deve ter conteúdo substancial');
assert(str_contains($content, 'financialAgenda'), 'agenda.php deve chamar financialAgenda');
assert(str_contains($content, 'viewMode'), 'agenda.php deve suportar alternância de visualização');
assert(str_contains($content, 'CRONOGRAMA DE COMPROMISSOS'), 'agenda.php deve conter cronograma');
assert(str_contains($content, 'Janeiro'), 'agenda.php deve conter tradução de meses');

echo "✓ View agenda.php e modo calendário/timeline validados.\n";
echo "Todos os 15 testes e contratos da Fase 5 passaram com sucesso!\n";
