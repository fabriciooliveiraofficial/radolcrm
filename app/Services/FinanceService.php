<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;

final class FinanceService
{
    public function __construct(private readonly Database $db)
    {
    }

    public function dashboard(string $from, string $to, float $usdRate): array
    {
        $payments = $this->db->fetch(
            "SELECT COALESCE(SUM(amount_brl),0) gross, COALESCE(SUM(fee_brl),0) fees, COALESCE(SUM(net_brl),0) net,
                    COALESCE(SUM(CASE WHEN currency='USD' THEN amount ELSE 0 END),0) usd,
                    COALESCE(SUM(CASE WHEN currency='BRL' THEN amount ELSE 0 END),0) brl,
                    COUNT(*) payment_count
             FROM payments WHERE status = 'paid' AND payment_date BETWEEN ? AND ?",
            [$from, $to]
        );
        $costs = $this->db->fetch(
            "SELECT COALESCE(SUM(CASE WHEN type='expense' THEN amount_brl ELSE 0 END),0) expenses,
                    COALESCE(SUM(CASE WHEN type='investment' THEN amount_brl ELSE 0 END),0) investments
             FROM expenses WHERE status = 'paid' AND payment_date BETWEEN ? AND ?",
            [$from, $to]
        );
        $cash = $this->db->fetch(
            "SELECT COALESCE(SUM(CASE WHEN direction='in' THEN amount_brl ELSE 0 END),0) cash_in,
                    COALESCE(SUM(CASE WHEN direction='out' THEN amount_brl ELSE 0 END),0) cash_out
             FROM cash_entries WHERE entry_date BETWEEN ? AND ?",
            [$from, $to]
        );

        $activeClients = (int) $this->db->value("SELECT COUNT(DISTINCT client_id) FROM subscriptions WHERE status IN ('active','trial','past_due')");
        $activeSubscriptions = (int) $this->db->value("SELECT COUNT(*) FROM subscriptions WHERE status = 'active'");
        $mrrRows = $this->db->fetchAll(
            "SELECT s.currency, s.quantity, s.unit_price, s.discount, p.billing_cycle
             FROM subscriptions s JOIN products p ON p.id = s.product_id WHERE s.status = 'active'"
        );
        $mrr = 0.0;
        foreach ($mrrRows as $row) {
            $factor = ['monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'annual' => 12][$row['billing_cycle']] ?? 1;
            $value = max(0, ((float) $row['unit_price'] * (int) $row['quantity']) - (float) $row['discount']);
            $mrr += ($value / $factor) * ($row['currency'] === 'USD' ? $usdRate : 1);
        }

        $gross = (float) $payments['gross'];
        $fees = (float) $payments['fees'];
        $net = (float) $payments['net'];
        $expenses = (float) $costs['expenses'];
        $investments = (float) $costs['investments'];
        $cashIn = (float) $cash['cash_in'];
        $cashOut = (float) $cash['cash_out'];
        // Movimentos avulsos (aportes, retiradas e ajustes) alteram o caixa,
        // mas não são receita nem despesa e portanto não alteram o lucro.
        $profit = $net - $expenses - $investments;
        $margin = $gross > 0 ? ($profit / $gross) * 100 : 0;

        return compact('gross', 'fees', 'net', 'expenses', 'investments', 'cashIn', 'cashOut', 'profit', 'margin', 'mrr', 'activeClients', 'activeSubscriptions') + [
            'revenueUsd' => (float) $payments['usd'],
            'revenueBrl' => (float) $payments['brl'],
            'paymentCount' => (int) $payments['payment_count'],
        ];
    }

    public function monthlySeries(int $months = 6): array
    {
        $start = (new DateTimeImmutable('first day of this month'))->modify('-' . ($months - 1) . ' months');
        $rows = $this->db->fetchAll(
            "SELECT month_key, SUM(revenue) revenue, SUM(cost) cost FROM (
                SELECT DATE_FORMAT(payment_date, '%Y-%m') month_key, SUM(net_brl) revenue, 0 cost
                FROM payments WHERE status='paid' AND payment_date >= ? GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
                UNION ALL
                SELECT DATE_FORMAT(payment_date, '%Y-%m') month_key, 0 revenue, SUM(amount_brl) cost
                FROM expenses WHERE status='paid' AND payment_date >= ? GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
                UNION ALL
                SELECT DATE_FORMAT(entry_date, '%Y-%m') month_key,
                       SUM(CASE WHEN direction='in' THEN amount_brl ELSE 0 END) revenue,
                       SUM(CASE WHEN direction='out' THEN amount_brl ELSE 0 END) cost
                FROM cash_entries WHERE entry_date >= ? GROUP BY DATE_FORMAT(entry_date, '%Y-%m')
            ) flow GROUP BY month_key ORDER BY month_key",
            [$start->format('Y-m-d'), $start->format('Y-m-d'), $start->format('Y-m-d')]
        );
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['month_key']] = $row;
        }
        $series = [];
        for ($i = 0; $i < $months; $i++) {
            $date = $start->modify('+' . $i . ' months');
            $key = $date->format('Y-m');
            $series[] = [
                'label' => ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'][(int) $date->format('n') - 1],
                'revenue' => (float) ($indexed[$key]['revenue'] ?? 0),
                'cost' => (float) ($indexed[$key]['cost'] ?? 0),
            ];
        }

        return $series;
    }

    public function cashBalance(): float
    {
        $initial = (float) ($this->db->value("SELECT setting_value FROM settings WHERE setting_key='initial_balance_brl'") ?: 0);
        $payments = (float) $this->db->value("SELECT COALESCE(SUM(net_brl),0) FROM payments WHERE status='paid'");
        $expenses = (float) $this->db->value("SELECT COALESCE(SUM(amount_brl),0) FROM expenses WHERE status='paid'");
        $cashIn = (float) $this->db->value("SELECT COALESCE(SUM(amount_brl),0) FROM cash_entries WHERE direction='in'");
        $cashOut = (float) $this->db->value("SELECT COALESCE(SUM(amount_brl),0) FROM cash_entries WHERE direction='out'");

        return $initial + $payments + $cashIn - $expenses - $cashOut;
    }
}
