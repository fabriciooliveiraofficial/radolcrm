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

    public function dashboard(string $from, string $to, float $usdRate, ?int $businessUnitId = null): array
    {
        $buWherePay = $businessUnitId ? ' AND business_unit_id = ' . (int) $businessUnitId : '';
        $buWhereExp = $businessUnitId ? ' AND business_unit_id = ' . (int) $businessUnitId : '';
        $buWhereCash = $businessUnitId ? ' AND business_unit_id = ' . (int) $businessUnitId : '';
        $buWhereSub = $businessUnitId ? ' AND c.business_unit_id = ' . (int) $businessUnitId : '';

        $payments = $this->db->fetch(
            "SELECT COALESCE(SUM(amount_brl),0) gross, COALESCE(SUM(fee_brl),0) fees, COALESCE(SUM(net_brl),0) net,
                    COALESCE(SUM(CASE WHEN currency='USD' THEN amount ELSE 0 END),0) usd,
                    COALESCE(SUM(CASE WHEN currency='BRL' THEN amount ELSE 0 END),0) brl,
                    COUNT(*) payment_count
             FROM payments WHERE status = 'paid'{$buWherePay}
             AND (
                 (CASE WHEN currency='USD' THEN COALESCE(settlement_date,payment_date) ELSE payment_date END) BETWEEN ? AND ?
                 OR DATE(created_at) BETWEEN ? AND ?
             )",
            [$from, $to, $from, $to]
        );
        $costs = $this->db->fetch(
            "SELECT COALESCE(SUM(CASE WHEN type='expense' THEN amount_brl ELSE 0 END),0) expenses,
                    COALESCE(SUM(CASE WHEN type='investment' THEN amount_brl ELSE 0 END),0) investments
             FROM expenses WHERE status = 'paid'{$buWhereExp} AND payment_date BETWEEN ? AND ?",
            [$from, $to]
        );
        $cash = $this->db->fetch(
            "SELECT COALESCE(SUM(CASE WHEN direction='in' THEN amount_brl ELSE 0 END),0) cash_in,
                    COALESCE(SUM(CASE WHEN direction='out' THEN amount_brl ELSE 0 END),0) cash_out
             FROM cash_entries WHERE entry_date BETWEEN ? AND ?{$buWhereCash}",
            [$from, $to]
        );

        $activeClients = (int) $this->db->value(
            "SELECT COUNT(DISTINCT s.client_id) FROM subscriptions s JOIN clients c ON c.id = s.client_id WHERE s.status IN ('active','trial','past_due'){$buWhereSub}"
        );
        $activeSubscriptions = (int) $this->db->value(
            "SELECT COUNT(*) FROM subscriptions s JOIN clients c ON c.id = s.client_id WHERE s.status = 'active'{$buWhereSub}"
        );
        $mrrRows = $this->db->fetchAll(
            "SELECT s.currency, s.quantity, s.unit_price, s.discount, p.billing_cycle
             FROM subscriptions s JOIN clients c ON c.id = s.client_id JOIN products p ON p.id = s.product_id WHERE s.status = 'active'{$buWhereSub}"
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
        $profit = $net - $expenses - $investments;
        $margin = $gross > 0 ? ($profit / $gross) * 100 : 0;

        return compact('gross', 'fees', 'net', 'expenses', 'investments', 'cashIn', 'cashOut', 'profit', 'margin', 'mrr', 'activeClients', 'activeSubscriptions') + [
            'revenueUsd' => (float) $payments['usd'],
            'revenueBrl' => (float) $payments['brl'],
            'paymentCount' => (int) $payments['payment_count'],
        ];
    }

    public function businessIntelligence(float $usdRate, ?int $businessUnitId = null): array
    {
        $rate = $usdRate > 0 ? $usdRate : 1.0;
        $buWhereClient = $businessUnitId ? ' WHERE business_unit_id = ' . (int) $businessUnitId : '';
        $buWhereSub = $businessUnitId ? ' AND c.business_unit_id = ' . (int) $businessUnitId : '';
        $buWherePay = $businessUnitId ? ' AND p.business_unit_id = ' . (int) $businessUnitId : '';
        $buWhereProd = $businessUnitId ? ' AND p.business_unit_id = ' . (int) $businessUnitId : '';

        $clients = $this->db->fetch(
            "SELECT COUNT(*) total,
                    COALESCE(SUM(CASE WHEN status='active' THEN 1 ELSE 0 END),0) active,
                    COALESCE(SUM(CASE WHEN status='inactive' THEN 1 ELSE 0 END),0) inactive,
                    COALESCE(SUM(CASE WHEN status='lead' THEN 1 ELSE 0 END),0) leads,
                    COALESCE(SUM(CASE WHEN country='BR' THEN 1 ELSE 0 END),0) brazil,
                    COALESCE(SUM(CASE WHEN country='US' THEN 1 ELSE 0 END),0) usa
             FROM clients{$buWhereClient}"
        ) ?? [];
        $subscriptions = $this->db->fetch(
            "SELECT COUNT(*) total,
                    COALESCE(SUM(CASE WHEN s.status='active' THEN 1 ELSE 0 END),0) active,
                    COALESCE(SUM(CASE WHEN s.status='trial' THEN 1 ELSE 0 END),0) trial,
                    COALESCE(SUM(CASE WHEN s.status='past_due' THEN 1 ELSE 0 END),0) past_due,
                    COALESCE(SUM(CASE WHEN s.status='paused' THEN 1 ELSE 0 END),0) paused,
                    COALESCE(SUM(CASE WHEN s.status='canceled' THEN 1 ELSE 0 END),0) canceled,
                    COUNT(DISTINCT CASE WHEN s.status IN ('active','trial','past_due') THEN s.client_id END) recurring_clients,
                    COUNT(DISTINCT CASE WHEN s.status='past_due' OR (s.status IN ('active','trial') AND s.next_billing_date < CURDATE()) THEN s.client_id END) overdue_clients
             FROM subscriptions s JOIN clients c ON c.id = s.client_id WHERE 1=1{$buWhereSub}"
        ) ?? [];
        $renewals = $this->db->fetch(
            "SELECT COALESCE(SUM(CASE WHEN s.next_billing_date < CURDATE() THEN 1 ELSE 0 END),0) overdue_count,
                    COALESCE(SUM(CASE WHEN s.next_billing_date = CURDATE() THEN 1 ELSE 0 END),0) due_today,
                    COALESCE(SUM(CASE WHEN s.next_billing_date BETWEEN DATE_ADD(CURDATE(),INTERVAL 1 DAY) AND DATE_ADD(CURDATE(),INTERVAL 7 DAY) THEN 1 ELSE 0 END),0) next_7,
                    COALESCE(SUM(CASE WHEN s.next_billing_date BETWEEN DATE_ADD(CURDATE(),INTERVAL 1 DAY) AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) THEN 1 ELSE 0 END),0) next_30,
                    COALESCE(SUM(CASE WHEN s.next_billing_date < CURDATE() THEN GREATEST(0,(s.unit_price * s.quantity)-s.discount) * CASE WHEN s.currency='USD' THEN ? ELSE 1 END ELSE 0 END),0) overdue_value,
                    COALESCE(SUM(CASE WHEN s.next_billing_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) THEN GREATEST(0,(s.unit_price * s.quantity)-s.discount) * CASE WHEN s.currency='USD' THEN ? ELSE 1 END ELSE 0 END),0) next_30_value
             FROM subscriptions s JOIN clients c ON c.id = s.client_id WHERE s.status IN ('active','trial','past_due') AND s.next_billing_date IS NOT NULL{$buWhereSub}",
            [$rate, $rate]
        ) ?? [];
        $collections = $this->db->fetch(
            "SELECT COALESCE(SUM(CASE WHEN p.status='paid' THEN 1 ELSE 0 END),0) paid,
                    COALESCE(SUM(CASE WHEN p.status='failed' THEN 1 ELSE 0 END),0) failed,
                    COALESCE(SUM(CASE WHEN p.status='pending' THEN 1 ELSE 0 END),0) pending,
                    COALESCE(SUM(CASE WHEN p.status='pending' AND p.due_date < CURDATE() THEN 1 ELSE 0 END),0) pending_overdue,
                    COALESCE(AVG(CASE WHEN p.status='paid' THEN p.amount_brl END),0) average_ticket
             FROM payments p
             WHERE p.status IN ('paid','failed','pending'){$buWherePay}
               AND COALESCE(p.due_date,p.payment_date,DATE(p.created_at))
                   BETWEEN DATE_SUB(CURDATE(),INTERVAL 90 DAY) AND DATE_ADD(CURDATE(),INTERVAL 90 DAY)"
        ) ?? [];
        $topProducts = $this->db->fetchAll(
            "SELECT p.id,p.name,COUNT(*) subscriptions,COUNT(DISTINCT s.client_id) clients,
                    SUM(GREATEST(0,(s.unit_price * s.quantity)-s.discount)
                        / CASE p.billing_cycle WHEN 'quarterly' THEN 3 WHEN 'semiannual' THEN 6 WHEN 'annual' THEN 12 ELSE 1 END
                        * CASE WHEN s.currency='USD' THEN ? ELSE 1 END) mrr
             FROM subscriptions s JOIN clients c ON c.id=s.client_id JOIN products p ON p.id=s.product_id
             WHERE s.status='active'{$buWhereSub}
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
             WHERE s.status='active'{$buWhereSub}
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

    public function monthlySeries(int $months = 6, ?int $businessUnitId = null): array
    {
        $start = (new DateTimeImmutable('first day of this month'))->modify('-' . ($months - 1) . ' months');
        $buWherePay = $businessUnitId ? ' AND business_unit_id = ' . (int) $businessUnitId : '';
        $buWhereExp = $businessUnitId ? ' AND business_unit_id = ' . (int) $businessUnitId : '';
        $buWhereCash = $businessUnitId ? ' AND business_unit_id = ' . (int) $businessUnitId : '';

        $rows = $this->db->fetchAll(
            "SELECT month_key, SUM(revenue) revenue, SUM(cost) cost FROM (
                SELECT DATE_FORMAT(CASE WHEN currency='USD' THEN COALESCE(settlement_date,payment_date) ELSE payment_date END, '%Y-%m') month_key, SUM(net_brl) revenue, 0 cost
                FROM payments WHERE status='paid'{$buWherePay} AND (CASE WHEN currency='USD' THEN COALESCE(settlement_date,payment_date) ELSE payment_date END) >= ?
                GROUP BY DATE_FORMAT(CASE WHEN currency='USD' THEN COALESCE(settlement_date,payment_date) ELSE payment_date END, '%Y-%m')
                UNION ALL
                SELECT DATE_FORMAT(payment_date, '%Y-%m') month_key, 0 revenue, SUM(amount_brl) cost
                FROM expenses WHERE status='paid'{$buWhereExp} AND payment_date >= ? GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
                UNION ALL
                SELECT DATE_FORMAT(entry_date, '%Y-%m') month_key,
                       SUM(CASE WHEN direction='in' THEN amount_brl ELSE 0 END) revenue,
                       SUM(CASE WHEN direction='out' THEN amount_brl ELSE 0 END) cost
                FROM cash_entries WHERE entry_date >= ?{$buWhereCash} GROUP BY DATE_FORMAT(entry_date, '%Y-%m')
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

    public function balanceBefore(string $date, ?int $businessUnitId = null): float
    {
        $initial = $businessUnitId ? 0 : (float) ($this->db->value("SELECT setting_value FROM settings WHERE setting_key='initial_balance_brl'") ?: 0);
        $buWherePay = $businessUnitId ? ' AND business_unit_id = ' . (int) $businessUnitId : '';
        $buWhereExp = $businessUnitId ? ' AND business_unit_id = ' . (int) $businessUnitId : '';
        $buWhereCash = $businessUnitId ? ' AND business_unit_id = ' . (int) $businessUnitId : '';
        
        $payments = (float) $this->db->value(
            "SELECT COALESCE(SUM(net_brl),0) FROM payments WHERE status='paid'{$buWherePay} AND (CASE WHEN currency='USD' THEN COALESCE(settlement_date,payment_date) ELSE payment_date END) < ?",
            [$date]
        );
        $expenses = (float) $this->db->value(
            "SELECT COALESCE(SUM(amount_brl),0) FROM expenses WHERE status='paid'{$buWhereExp} AND payment_date < ?",
            [$date]
        );
        $cashIn = (float) $this->db->value(
            "SELECT COALESCE(SUM(amount_brl),0) FROM cash_entries WHERE direction='in'{$buWhereCash} AND entry_date < ?",
            [$date]
        );
        $cashOut = (float) $this->db->value(
            "SELECT COALESCE(SUM(amount_brl),0) FROM cash_entries WHERE direction='out'{$buWhereCash} AND entry_date < ?",
            [$date]
        );

        return $initial + $payments + $cashIn - $expenses - $cashOut;
    }

    public function cashBalance(?int $businessUnitId = null): float
    {
        $initial = $businessUnitId ? 0 : (float) ($this->db->value("SELECT setting_value FROM settings WHERE setting_key='initial_balance_brl'") ?: 0);
        $buWherePay = $businessUnitId ? ' AND business_unit_id = ' . (int) $businessUnitId : '';
        $buWhereExp = $businessUnitId ? ' AND business_unit_id = ' . (int) $businessUnitId : '';
        $buWhereCash = $businessUnitId ? ' AND business_unit_id = ' . (int) $businessUnitId : '';

        $payments = (float) $this->db->value("SELECT COALESCE(SUM(net_brl),0) FROM payments WHERE status='paid'{$buWherePay}");
        $expenses = (float) $this->db->value("SELECT COALESCE(SUM(amount_brl),0) FROM expenses WHERE status='paid'{$buWhereExp}");
        $cashIn = (float) $this->db->value("SELECT COALESCE(SUM(amount_brl),0) FROM cash_entries WHERE direction='in'{$buWhereCash}");
        $cashOut = (float) $this->db->value("SELECT COALESCE(SUM(amount_brl),0) FROM cash_entries WHERE direction='out'{$buWhereCash}");

        return $initial + $payments + $cashIn - $expenses - $cashOut;
    }

    public function revenueParticipation(string $from, string $to): array
    {
        $totalRevenue = (float) $this->db->value(
            "SELECT COALESCE(SUM(net_brl), 0) FROM payments WHERE status = 'paid' AND (CASE WHEN currency='USD' THEN COALESCE(settlement_date, payment_date) ELSE payment_date END) BETWEEN ? AND ?",
            [$from, $to]
        );

        $buRows = $this->db->fetchAll(
            "SELECT bu.id, bu.name, bu.icon, bu.color, bu.is_personal,
                    COALESCE(SUM(p.net_brl), 0) revenue_brl,
                    COUNT(p.id) payments_count
             FROM business_units bu
             LEFT JOIN payments p ON p.business_unit_id = bu.id AND p.status = 'paid'
                  AND (CASE WHEN p.currency='USD' THEN COALESCE(p.settlement_date, p.payment_date) ELSE p.payment_date END) BETWEEN ? AND ?
             WHERE bu.active = 1
             GROUP BY bu.id, bu.name, bu.icon, bu.color, bu.is_personal
             ORDER BY revenue_brl DESC, bu.name ASC",
            [$from, $to]
        );

        $unassignedRevenue = (float) $this->db->value(
            "SELECT COALESCE(SUM(net_brl), 0) FROM payments WHERE business_unit_id IS NULL AND status = 'paid' AND (CASE WHEN currency='USD' THEN COALESCE(settlement_date, payment_date) ELSE payment_date END) BETWEEN ? AND ?",
            [$from, $to]
        );
        if ($unassignedRevenue > 0) {
            $buRows[] = [
                'id' => null,
                'name' => 'Sem negócio atribuído',
                'icon' => '📁',
                'color' => '#64748b',
                'is_personal' => 0,
                'revenue_brl' => $unassignedRevenue,
                'payments_count' => (int) $this->db->value("SELECT COUNT(*) FROM payments WHERE business_unit_id IS NULL AND status = 'paid' AND (CASE WHEN currency='USD' THEN COALESCE(settlement_date, payment_date) ELSE payment_date END) BETWEEN ? AND ?", [$from, $to]),
            ];
        }

        $result = [];
        foreach ($buRows as $row) {
            $rev = (float) $row['revenue_brl'];
            $pct = $totalRevenue > 0 ? round(($rev / $totalRevenue) * 100, 2) : 0.0;
            $row['share_percent'] = $pct;
            $result[] = $row;
        }

        return [
            'total_revenue' => $totalRevenue,
            'units' => $result,
        ];
    }

    public function categoryExpenseIndices(string $from, string $to, ?int $businessUnitId = null): array
    {
        $buWhere = $businessUnitId ? ' AND c.business_unit_id = ' . $businessUnitId : '';
        $buWherePayment = $businessUnitId ? ' AND p.business_unit_id = ' . $businessUnitId : '';
        $buWhereExpense = $businessUnitId ? ' AND e.business_unit_id = ' . $businessUnitId : '';

        $grossRevenue = (float) $this->db->value(
            "SELECT COALESCE(SUM(p.net_brl), 0) FROM payments p WHERE p.status = 'paid'{$buWherePayment} AND (CASE WHEN p.currency='USD' THEN COALESCE(p.settlement_date, p.payment_date) ELSE p.payment_date END) BETWEEN ? AND ?",
            [$from, $to]
        );

        $totalExpenses = (float) $this->db->value(
            "SELECT COALESCE(SUM(e.amount_brl), 0) FROM expenses e WHERE e.status = 'paid'{$buWhereExpense} AND e.payment_date BETWEEN ? AND ?",
            [$from, $to]
        );

        $categories = $this->db->fetchAll(
            "SELECT c.id, c.name, c.icon, c.color, c.budget_limit_percent, c.budget_limit_amount,
                    c.parent_id, pcat.name parent_name, bu.name bu_name, bu.icon bu_icon,
                    COALESCE(SUM(e.amount_brl), 0) spent_brl,
                    COUNT(e.id) expense_count
             FROM categories c
             LEFT JOIN categories pcat ON pcat.id = c.parent_id
             LEFT JOIN business_units bu ON bu.id = c.business_unit_id
             LEFT JOIN expenses e ON e.category_id = c.id AND e.status = 'paid' AND e.payment_date BETWEEN ? AND ?
             WHERE c.active = 1 AND c.type IN ('expense', 'both') {$buWhere}
             GROUP BY c.id, c.name, c.icon, c.color, c.budget_limit_percent, c.budget_limit_amount, c.parent_id, pcat.name, bu.name, bu.icon
             ORDER BY spent_brl DESC, c.name ASC",
            [$from, $to]
        );

        $analyzedCategories = [];
        $alerts = [];

        foreach ($categories as $cat) {
            $spent = (float) $cat['spent_brl'];
            $pctOfRevenue = $grossRevenue > 0 ? round(($spent / $grossRevenue) * 100, 2) : 0.0;
            $pctOfExpenses = $totalExpenses > 0 ? round(($spent / $totalExpenses) * 100, 2) : 0.0;

            $limitPct = $cat['budget_limit_percent'] ? (float) $cat['budget_limit_percent'] : null;
            $limitAmount = $cat['budget_limit_amount'] ? (float) $cat['budget_limit_amount'] : null;

            $status = 'ok';
            $consumptionRatio = 0.0;

            if ($limitPct !== null && $grossRevenue > 0) {
                $maxAllowed = ($limitPct / 100) * $grossRevenue;
                $consumptionRatio = $maxAllowed > 0 ? ($spent / $maxAllowed) * 100 : 0;
            } elseif ($limitAmount !== null) {
                $consumptionRatio = $limitAmount > 0 ? ($spent / $limitAmount) * 100 : 0;
            }

            if ($consumptionRatio >= 100) {
                $status = 'danger';
                $alerts[] = [
                    'category' => $cat['name'],
                    'icon' => $cat['icon'],
                    'spent' => $spent,
                    'limit_pct' => $limitPct,
                    'limit_amount' => $limitAmount,
                    'pct_of_revenue' => $pctOfRevenue,
                    'consumption' => round($consumptionRatio, 1),
                    'message' => "A categoria {$cat['name']} atingiu " . round($consumptionRatio, 1) . "% do limite planejado.",
                ];
            } elseif ($consumptionRatio >= 80) {
                $status = 'warning';
            }

            $cat['spent_brl'] = $spent;
            $cat['pct_of_revenue'] = $pctOfRevenue;
            $cat['pct_of_expenses'] = $pctOfExpenses;
            $cat['consumption_ratio'] = round($consumptionRatio, 1);
            $cat['status'] = $status;
            $analyzedCategories[] = $cat;
        }

        return [
            'gross_revenue' => $grossRevenue,
            'total_expenses' => $totalExpenses,
            'expense_to_revenue_ratio' => $grossRevenue > 0 ? round(($totalExpenses / $grossRevenue) * 100, 2) : 0.0,
            'categories' => $analyzedCategories,
            'alerts' => $alerts,
        ];
    }

    public function categoryRevenueIndices(string $from, string $to, ?int $businessUnitId = null): array
    {
        $buWhere = $businessUnitId ? ' AND (c.business_unit_id = ' . (int) $businessUnitId . ' OR c.business_unit_id IS NULL)' : '';
        $buWherePay = $businessUnitId ? ' AND p.business_unit_id = ' . (int) $businessUnitId : '';

        $totalRevenue = (float) $this->db->value(
            "SELECT COALESCE(SUM(p.amount_brl), 0) FROM payments p WHERE p.status = 'paid'{$buWherePay}
             AND (CASE WHEN p.currency = 'USD' THEN COALESCE(p.settlement_date, p.payment_date) ELSE p.payment_date END) BETWEEN ? AND ?",
            [$from, $to]
        );

        $categories = $this->db->fetchAll(
            "SELECT c.id, c.name, c.icon, c.color, bu.name bu_name, bu.icon bu_icon,
                    COALESCE(SUM(p.amount_brl), 0) revenue_brl,
                    COALESCE(SUM(p.net_brl), 0) net_brl,
                    COUNT(p.id) payment_count
             FROM categories c
             LEFT JOIN business_units bu ON bu.id = c.business_unit_id
             LEFT JOIN payments p ON p.category_id = c.id AND p.status = 'paid'{$buWherePay}
                  AND (CASE WHEN p.currency = 'USD' THEN COALESCE(p.settlement_date, p.payment_date) ELSE p.payment_date END) BETWEEN ? AND ?
             WHERE c.active = 1 AND c.type IN ('income', 'both') {$buWhere}
             GROUP BY c.id, c.name, c.icon, c.color, bu.name, bu.icon
             HAVING revenue_brl > 0
             ORDER BY revenue_brl DESC, c.name ASC",
            [$from, $to]
        );

        $uncategorized = $this->db->fetch(
            "SELECT COALESCE(SUM(p.amount_brl), 0) revenue_brl,
                    COALESCE(SUM(p.net_brl), 0) net_brl,
                    COUNT(p.id) payment_count
             FROM payments p
             WHERE p.status = 'paid' AND (p.category_id IS NULL OR p.category_id = 0){$buWherePay}
             AND (CASE WHEN p.currency = 'USD' THEN COALESCE(p.settlement_date, p.payment_date) ELSE p.payment_date END) BETWEEN ? AND ?",
            [$from, $to]
        );

        if ((float) ($uncategorized['revenue_brl'] ?? 0) > 0) {
            $categories[] = [
                'id' => 0,
                'name' => 'Outras / Sem categoria',
                'icon' => '📁',
                'color' => '#64748b',
                'bu_name' => null,
                'bu_icon' => null,
                'revenue_brl' => (float) $uncategorized['revenue_brl'],
                'net_brl' => (float) $uncategorized['net_brl'],
                'payment_count' => (int) $uncategorized['payment_count'],
            ];
        }

        $analyzedCategories = [];
        foreach ($categories as $cat) {
            $rev = (float) $cat['revenue_brl'];
            $pct = $totalRevenue > 0 ? round(($rev / $totalRevenue) * 100, 2) : 0.0;
            $cat['revenue_brl'] = $rev;
            $cat['pct_of_revenue'] = $pct;
            $analyzedCategories[] = $cat;
        }

        return [
            'total_revenue' => $totalRevenue,
            'categories' => $analyzedCategories,
        ];
    }

    public function financialAgenda(string $from, string $to, ?int $businessUnitId = null, float $usdRate = 5.5): array
    {
        $events = [];
        $buWhereSub = $businessUnitId ? ' AND c.business_unit_id = ' . $businessUnitId : '';
        $buWherePay = $businessUnitId ? ' AND p.business_unit_id = ' . $businessUnitId : '';
        $buWhereExp = $businessUnitId ? ' AND e.business_unit_id = ' . $businessUnitId : '';
        $buWhereInst = $businessUnitId ? ' AND i.business_unit_id = ' . $businessUnitId : '';
        $buWhereCard = $businessUnitId ? ' AND cc.business_unit_id = ' . $businessUnitId : '';

        // 1. Expected subscription renewals
        $subs = $this->db->fetchAll(
            "SELECT s.id, s.next_billing_date due_date, s.currency, s.unit_price, s.quantity, s.discount,
                    c.name client_name, pr.name product_name, bu.name bu_name, bu.icon bu_icon, bu.color bu_color
             FROM subscriptions s
             JOIN clients c ON c.id = s.client_id
             LEFT JOIN products pr ON pr.id = s.product_id
             LEFT JOIN business_units bu ON bu.id = c.business_unit_id
             WHERE s.status = 'active' AND s.next_billing_date BETWEEN ? AND ?{$buWhereSub}",
            [$from, $to]
        );
        foreach ($subs as $s) {
            $val = max(0, ((float) $s['unit_price'] * (int) $s['quantity']) - (float) $s['discount']);
            $valBrl = $s['currency'] === 'USD' ? round($val * $usdRate, 2) : $val;
            $events[] = [
                'id' => 'sub-' . $s['id'],
                'type' => 'subscription',
                'direction' => 'in',
                'date' => $s['due_date'],
                'title' => 'Renovação: ' . ($s['product_name'] ?: 'Assinatura'),
                'subtitle' => $s['client_name'],
                'amount' => $val,
                'currency' => $s['currency'],
                'amount_brl' => $valBrl,
                'status' => 'pending',
                'bu_name' => $s['bu_name'] ?? 'Geral',
                'bu_icon' => $s['bu_icon'] ?? '💼',
                'bu_color' => $s['bu_color'] ?? '#2b826b',
                'url' => '?page=subscriptions',
            ];
        }

        // 2. Pending Client Payments
        $payments = $this->db->fetchAll(
            "SELECT p.id, p.payment_date due_date, p.amount, p.currency, p.net_brl, p.description,
                    c.name client_name, bu.name bu_name, bu.icon bu_icon, bu.color bu_color
             FROM payments p
             JOIN clients c ON c.id = p.client_id
             LEFT JOIN business_units bu ON bu.id = p.business_unit_id
             WHERE p.status = 'pending' AND p.payment_date BETWEEN ? AND ?{$buWherePay}",
            [$from, $to]
        );
        foreach ($payments as $p) {
            $events[] = [
                'id' => 'payment-' . $p['id'],
                'type' => 'payment',
                'direction' => 'in',
                'date' => $p['due_date'],
                'title' => 'Recebimento: ' . ($p['description'] ?: 'Pagamento'),
                'subtitle' => $p['client_name'],
                'amount' => (float) $p['amount'],
                'currency' => $p['currency'],
                'amount_brl' => (float) $p['net_brl'],
                'status' => 'pending',
                'bu_name' => $p['bu_name'] ?? 'Geral',
                'bu_icon' => $p['bu_icon'] ?? '💼',
                'bu_color' => $p['bu_color'] ?? '#2b826b',
                'url' => '?page=payments',
            ];
        }

        // 3. Pending Expenses
        $expenses = $this->db->fetchAll(
            "SELECT e.id, e.payment_date due_date, e.amount, e.currency, e.amount_brl, e.description, e.supplier,
                    cat.name cat_name, bu.name bu_name, bu.icon bu_icon, bu.color bu_color
             FROM expenses e
             LEFT JOIN categories cat ON cat.id = e.category_id
             LEFT JOIN business_units bu ON bu.id = e.business_unit_id
             WHERE e.status = 'pending' AND e.payment_date BETWEEN ? AND ?{$buWhereExp}",
            [$from, $to]
        );
        foreach ($expenses as $e) {
            $events[] = [
                'id' => 'expense-' . $e['id'],
                'type' => 'expense',
                'direction' => 'out',
                'date' => $e['due_date'],
                'title' => $e['description'],
                'subtitle' => $e['supplier'] ?: ($e['cat_name'] ?: 'Despesa'),
                'amount' => (float) $e['amount'],
                'currency' => $e['currency'],
                'amount_brl' => (float) $e['amount_brl'],
                'status' => 'pending',
                'bu_name' => $e['bu_name'] ?? 'Geral',
                'bu_icon' => $e['bu_icon'] ?? '💼',
                'bu_color' => $e['bu_color'] ?? '#ef4444',
                'url' => '?page=expenses&edit=' . $e['id'],
            ];
        }

        // 4. Installments & Financing
        $installments = $this->db->fetchAll(
            "SELECT i.id, i.due_date, i.amount, i.currency, i.amount_brl, i.description, i.supplier, i.installment_number, i.total_installments,
                    cat.name cat_name, bu.name bu_name, bu.icon bu_icon, bu.color bu_color
             FROM installments i
             LEFT JOIN categories cat ON cat.id = i.category_id
             LEFT JOIN business_units bu ON bu.id = i.business_unit_id
             WHERE i.status = 'pending' AND i.due_date BETWEEN ? AND ?{$buWhereInst}",
            [$from, $to]
        );
        foreach ($installments as $i) {
            $events[] = [
                'id' => 'inst-' . $i['id'],
                'type' => 'installment',
                'direction' => 'out',
                'date' => $i['due_date'],
                'title' => $i['description'],
                'subtitle' => $i['supplier'] ?: ($i['cat_name'] ?: 'Parcela'),
                'amount' => (float) $i['amount'],
                'currency' => $i['currency'],
                'amount_brl' => (float) $i['amount_brl'],
                'status' => 'pending',
                'bu_name' => $i['bu_name'] ?? 'Geral',
                'bu_icon' => $i['bu_icon'] ?? '💼',
                'bu_color' => $i['bu_color'] ?? '#f59e0b',
                'url' => '?page=recurring&pay_inst=' . $i['id'],
            ];
        }

        // 5. Credit Card Invoices
        $cardInvoices = $this->db->fetchAll(
            "SELECT inv.id, inv.due_date, inv.total_amount, inv.reference_month,
                    cc.name card_name, cc.brand, cc.color card_color, bu.name bu_name, bu.icon bu_icon, bu.color bu_color
             FROM credit_card_invoices inv
             JOIN credit_cards cc ON cc.id = inv.card_id
             LEFT JOIN business_units bu ON bu.id = cc.business_unit_id
             WHERE inv.status != 'paid' AND inv.total_amount > 0 AND inv.due_date BETWEEN ? AND ?{$buWhereCard}",
            [$from, $to]
        );
        foreach ($cardInvoices as $inv) {
            $events[] = [
                'id' => 'card-inv-' . $inv['id'],
                'type' => 'card_invoice',
                'direction' => 'out',
                'date' => $inv['due_date'],
                'title' => 'Fatura Cartão: ' . $inv['card_name'],
                'subtitle' => 'Mês de Ref.: ' . $inv['reference_month'],
                'amount' => (float) $inv['total_amount'],
                'currency' => 'BRL',
                'amount_brl' => (float) $inv['total_amount'],
                'status' => 'pending',
                'bu_name' => $inv['bu_name'] ?? 'Geral',
                'bu_icon' => $inv['bu_icon'] ?? '💳',
                'bu_color' => $inv['card_color'] ?? '#8b5cf6',
                'url' => '?page=cards&invoice=' . $inv['id'],
            ];
        }

        // Sort chronological ASC
        usort($events, static fn($a, $b) => strcmp($a['date'], $b['date']) ?: strcmp($a['title'], $b['title']));

        $expectedIn = 0.0;
        $expectedOut = 0.0;
        $byDate = [];

        foreach ($events as $ev) {
            if ($ev['direction'] === 'in') {
                $expectedIn += $ev['amount_brl'];
            } else {
                $expectedOut += $ev['amount_brl'];
            }
            $d = $ev['date'];
            if (!isset($byDate[$d])) {
                $byDate[$d] = [];
            }
            $byDate[$d][] = $ev;
        }

        return [
            'events' => $events,
            'by_date' => $byDate,
            'expected_in' => $expectedIn,
            'expected_out' => $expectedOut,
            'expected_net' => $expectedIn - $expectedOut,
            'total_count' => count($events),
        ];
    }
}
