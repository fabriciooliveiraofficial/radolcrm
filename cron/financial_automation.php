<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\FinancialAutomationService;

$service = new FinancialAutomationService($db);
$result = $service->runDailyFinancialAutomation();

if (php_sapi_name() === 'cli') {
    echo "Automação Financeira Concluída com Sucesso:\n";
    echo "- Faturas fechadas: {$result['closed_invoices']}\n";
    echo "- Parcelas vencidas identificadas: {$result['overdue_installments']}\n";
    echo "- Próximas parcelas contínuas geradas: {$result['generated_installments']}\n";
    echo "- Alertas de teto de gastos ativos: {$result['budget_alerts_count']}\n";
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'result' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
