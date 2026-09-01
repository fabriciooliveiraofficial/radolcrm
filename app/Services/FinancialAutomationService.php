<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;

final class FinancialAutomationService
{
    public function __construct(private readonly Database $db)
    {
    }

    public function runDailyFinancialAutomation(): array
    {
        $closedInvoices = 0;
        $overdueInstallments = 0;
        $generatedInstallments = 0;
        $budgetAlertsCount = 0;
        $today = date('Y-m-d');

        // 1. Fechar faturas de cartão de crédito cujo período de compras fechou
        $openInvoices = $this->db->fetchAll(
            "SELECT inv.id, inv.card_id, inv.closing_date, cc.name card_name
             FROM credit_card_invoices inv
             JOIN credit_cards cc ON cc.id = inv.card_id
             WHERE inv.status = 'open' AND inv.closing_date <= ?",
            [$today]
        );
        foreach ($openInvoices as $inv) {
            $total = (float) $this->db->value(
                "SELECT COALESCE(SUM(amount_brl), 0) FROM credit_card_transactions WHERE invoice_id = ?",
                [$inv['id']]
            );
            $this->db->query(
                "UPDATE credit_card_invoices SET status = 'closed', total_amount = ? WHERE id = ?",
                [$total, $inv['id']]
            );
            $closedInvoices++;
        }

        // 2. Marcar parcelas vencidas
        $overdueRows = $this->db->fetchAll(
            "SELECT id FROM installments WHERE status = 'pending' AND due_date < ?",
            [$today]
        );
        foreach ($overdueRows as $row) {
            $this->db->query("UPDATE installments SET status = 'overdue' WHERE id = ?", [$row['id']]);
            $overdueInstallments++;
        }

        // 3. Gerar próximas parcelas para contratos contínuos (água, luz, internet, etc.)
        $continuousTemplates = $this->db->fetchAll(
            "SELECT rt.* FROM recurring_templates rt WHERE rt.active = 1 AND rt.auto_generate = 1 AND rt.total_installments IS NULL"
        );
        foreach ($continuousTemplates as $tpl) {
            $latestDue = $this->db->value(
                "SELECT MAX(due_date) FROM installments WHERE template_id = ?",
                [$tpl['id']]
            );
            $latestNumber = (int) $this->db->value(
                "SELECT COALESCE(MAX(installment_number), 0) FROM installments WHERE template_id = ?",
                [$tpl['id']]
            );

            $thresholdDate = (new DateTimeImmutable('today'))->modify('+90 days')->format('Y-m-d');
            if (!$latestDue || $latestDue < $thresholdDate) {
                $baseDate = $latestDue ? (new DateTimeImmutable($latestDue))->modify('+1 month') : new DateTimeImmutable($tpl['start_date']);
                $numToGenerate = 6;
                $currentDate = $baseDate;

                for ($step = 1; $step <= $numToGenerate; $step++) {
                    $instNum = $latestNumber + $step;
                    $instDesc = $tpl['description'] . " ({$instNum})";
                    $instDue = $currentDate->format('Y-m-d');
                    $instBrl = round((float) $tpl['amount'] * (float) $tpl['exchange_rate'], 2);

                    $this->db->insert(
                        "INSERT INTO installments (template_id, business_unit_id, category_id, installment_number, total_installments, description, supplier, amount, currency, exchange_rate, amount_brl, due_date, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'Gerada automaticamente pela automação')",
                        [
                            $tpl['id'], $tpl['business_unit_id'], $tpl['category_id'],
                            $instNum, null, $instDesc, $tpl['supplier'],
                            $tpl['amount'], $tpl['currency'], $tpl['exchange_rate'],
                            $instBrl, $instDue, 'pending'
                        ]
                    );
                    $generatedInstallments++;
                    $currentDate = $currentDate->modify('+1 month');
                }
            }
        }

        // 4. Checar limitadores de orçamento do mês corrente
        $finance = new FinanceService($this->db);
        $indices = $finance->categoryExpenseIndices(date('Y-m-01'), date('Y-m-t'));
        $budgetAlertsCount = count($indices['alerts']);

        $summary = [
            'executed_at' => date('Y-m-d H:i:s'),
            'closed_invoices' => $closedInvoices,
            'overdue_installments' => $overdueInstallments,
            'generated_installments' => $generatedInstallments,
            'budget_alerts_count' => $budgetAlertsCount,
        ];

        $summaryJson = (string) json_encode($summary, JSON_UNESCAPED_UNICODE);
        $this->db->query(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('financial_automation_last_run_at', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
            [date('Y-m-d H:i:s')]
        );
        $this->db->query(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('financial_automation_last_summary', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
            [$summaryJson]
        );

        audit($this->db, 'run', 'financial_automation', null, $summary);

        return $summary;
    }
}
