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
             FROM payments WHERE status = 'paid'
             AND (CASE WHEN currency='USD' THEN COALESCE(settlement_date,payment_date) ELSE payment_date END) BETWEEN ? AND ?",
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

    public function businessIntelligence(float $usdRate): array
    {
        $rate = $usdRate > 0 ? $usdRate : 1.0;
        $clients = $this->db->fetch(
            "SELECT COUNT(*) total,
                    COALESCE(SUM(CASE WHEN status='active' THEN 1 ELSE 0 END),0) active,
                    COALESCE(SUM(CASE WHEN status='inactive' THEN 1 ELSE 0 END),0) inactive,
                    COALESCE(SUM(CASE WHEN status='lead' THEN 1 ELSE 0 END),0) leads,
                    COALESCE(SUM(CASE WHEN country='BR' THEN 1 ELSE 0 END),0) brazil,
                    COALESCE(SUM(CASE WHEN country='US' THEN 1 ELSE 0 END),0) usa
             FROM clients"
        ) ?? [];
        $subscriptions = $this->db->fetch(
            "SELECT COUNT(*) total,
                    COALESCE(SUM(CASE WHEN status='active' THEN 1 ELSE 0 END),0) active,
                    COALESCE(SUM(CASE WHEN status='trial' THEN 1 ELSE 0 END),0) trial,
                    COALESCE(SUM(CASE WHEN status='past_due' THEN 1 ELSE 0 END),0) past_due,
                    COALESCE(SUM(CASE WHEN status='paused' THEN 1 ELSE 0 END),0) paused,
                    COALESCE(SUM(CASE WHEN status='canceled' THEN 1 ELSE 0 END),0) canceled,
                    COUNT(DISTINCT CASE WHEN status IN ('active','trial','past_due') THEN client_id END) recurring_clients,
                    COUNT(DISTINCT CASE WHEN status='past_due' OR (status IN ('active','trial') AND next_billing_date < CURDATE()) THEN client_id END) overdue_clients
             FROM subscriptions"
        ) ?? [];
        $renewals = $this->db->fetch(
            "SELECT COALESCE(SUM(CASE WHEN next_billing_date < CURDATE() THEN 1 ELSE 0 END),0) overdue_count,
                    COALESCE(SUM(CASE WHEN next_billing_date = CURDATE() THEN 1 ELSE 0 END),0) due_today,
                    COALESCE(SUM(CASE WHEN next_billing_date BETWEEN DATE_ADD(CURDATE(),INTERVAL 1 DAY) AND DATE_ADD(CURDATE(),INTERVAL 7 DAY) THEN 1 ELSE 0 END),0) next_7,
                    COALESCE(SUM(CASE WHEN next_billing_date BETWEEN DATE_ADD(CURDATE(),INTERVAL 1 DAY) AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) THEN 1 ELSE 0 END),0) next_30,
                    COALESCE(SUM(CASE WHEN next_billing_date < CURDATE() THEN GREATEST(0,(unit_price * quantity)-discount) * CASE WHEN currency='USD' THEN ? ELSE 1 END ELSE 0 END),0) overdue_value,
                    COALESCE(SUM(CASE WHEN next_billing_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) THEN GREATEST(0,(unit_price * quantity)-discount) * CASE WHEN currency='USD' THEN ? ELSE 1 END ELSE 0 END),0) next_30_value
             FROM subscriptions WHERE status IN ('active','trial','past_due') AND next_billing_date IS NOT NULL",
            [$rate, $rate]
        ) ?? [];
        $collections = $this->db->fetch(
            "SELECT COALESCE(SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END),0) paid,
                    COALESCE(SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END),0) failed,
                    COALESCE(SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END),0) pending,
                    COALESCE(SUM(CASE WHEN status='pending' AND due_date < CURDATE() THEN 1 ELSE 0 END),0) pending_overdue,
                    COALESCE(AVG(CASE WHEN status='paid' THEN amount_brl END),0) average_ticket
             FROM payments
             WHERE status IN ('paid','failed','pending')
               AND COALESCE(due_date,payment_date,DATE(created_at))
                   BETWEEN DATE_SUB(CURDATE(),INTERVAL 90 DAY) AND CURDATE()"
        ) ?? [];
        $topProducts = $this->db->fetchAll(
            "SELECT p.id,p.name,COUNT(*) subscriptions,COUNT(DISTINCT s.client_id) clients,
                    SUM(GREATEST(0,(s.unit_price * s.quantity)-s.discount)
                        / CASE p.billing_cycle WHEN 'quarterly' THEN 3 WHEN 'semiannual' THEN 6 WHEN 'annual' THEN 12 ELSE 1 END
                        * CASE WHEN s.currency='USD' THEN ? ELSE 1 END) mrr
             FROM subscriptions s JOIN products p ON p.id=s.product_id
             WHERE s.status='active'
             GROUP BY p.id,p.name ORDER BY mrr DESC LIMIT 5",
            [$rate]
        );
        $countries = $this->db->fetchAll(
            "SELECT c.country,COUNT(DISTINCT s.client_id) clients,COUNT(*) subscriptions,
                    SUM(GREATEST(0,(s.unit_price * s.quantity)-s.discount)
                        / CASE p.billing_cycle WHEN 'quarterly' THEN 3 WHEN 'semiannual' THEN 6 WHEN 'annual' THEN 12 ELSE 1 END
                        * CASE WHEN s.currency='USD' THEN ? ELSE 1 END) mrr
             FROM subscriptions s
             JOIN clients c ON c.id=s.client_id
             JOIN products p ON p.id=s.product_id
             WHERE s.status='active'
             GROUP BY c.country ORDER BY mrr DESC",
            [$rate]
        );

        $collectionBase = (int) ($collections['paid'] ?? 0) + (int) ($collections['failed'] ?? 0) + (int) ($collections['pending'] ?? 0);
        $collectionRate = $collectionBase > 0 ? ((int) ($collections['paid'] ?? 0) / $collectionBase) * 100 : 0.0;
        $operatingSubscriptions = (int) ($subscriptions['active'] ?? 0) + (int) ($subscriptions['trial'] ?? 0)
            + (int) ($subscriptions['past_due'] ?? 0) + (int) ($subscriptions['paused'] ?? 0);
        $portfolioHealth = $operatingSubscriptions > 0
            ? (((int) ($subscriptions['active'] ?? 0) + (int) ($subscriptions['trial'] ?? 0)) / $operatingSubscriptions) * 100
            : 0.0;

        return [
            'clients' => array_map('intval', $clients),
            'subscriptions' => array_map('intval', $subscriptions),
            'renewals' => [
                'overdueCount' => (int) ($renewals['overdue_count'] ?? 0),
                'dueToday' => (int) ($renewals['due_today'] ?? 0),
                'next7' => (int) ($renewals['next_7'] ?? 0),
                'next30' => (int) ($renewals['next_30'] ?? 0),
                'overdueValue' => (float) ($renewals['overdue_value'] ?? 0),
                'next30Value' => (float) ($renewals['next_30_value'] ?? 0),
            ],
            'collections' => [
                'paid' => (int) ($collections['paid'] ?? 0),
                'failed' => (int) ($collections['failed'] ?? 0),
                'pending' => (int) ($collections['pending'] ?? 0),
                'pendingOverdue' => (int) ($collections['pending_overdue'] ?? 0),
                'averageTicket' => (float) ($collections['average_ticket'] ?? 0),
                'rate' => $collectionRate,
                'base' => $collectionBase,
            ],
            'portfolioHealth' => $portfolioHealth,
            'topProducts' => $topProducts,
            'countries' => $countries,
        ];
    }

    public function monthlySeries(int $months = 6): array
    {
        $start = (new DateTimeImmutable('first day of this month'))->modify('-' . ($months - 1) . ' months');
        $rows = $this->db->fetchAll(
            "SELECT month_key, SUM(revenue) revenue, SUM(cost) cost FROM (
                SELECT DATE_FORMAT(CASE WHEN currency='USD' THEN COALESCE(settlement_date,payment_date) ELSE payment_date END, '%Y-%m') month_key, SUM(net_brl) revenue, 0 cost
                FROM payments WHERE status='paid' AND (CASE WHEN currency='USD' THEN COALESCE(settlement_date,payment_date) ELSE payment_date END) >= ?
                GROUP BY DATE_FORMAT(CASE WHEN currency='USD' THEN COALESCE(settlement_date,payment_date) ELSE payment_date END, '%Y-%m')
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
