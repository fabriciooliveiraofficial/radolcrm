<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;

final class DailyFinanceService
{
    public function __construct(private readonly Database $db)
    {
    }

    public function summary(string $from, string $to, string $search = '', string $typeFilter = '', string $methodFilter = ''): array
    {
        $today = date('Y-m-d');
        $in15Days = date('Y-m-d', strtotime('+15 days'));

        $where = "transaction_date BETWEEN ? AND ?";
        $params = [$from, $to];

        if ($search !== '') {
            $where .= " AND (payee_name LIKE ? OR description LIKE ? OR notes LIKE ?)";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        if (in_array($typeFilter, ['expense', 'income'], true)) {
            $where .= " AND type = ?";
            $params[] = $typeFilter;
        }
        if (in_array($methodFilter, ['pix', 'credit_card', 'debit_card', 'cash', 'transfer', 'boleto'], true)) {
            $where .= " AND payment_method = ?";
            $params[] = $methodFilter;
        }

        $totals = $this->db->fetch(
            "SELECT 
                COALESCE(SUM(CASE WHEN type = 'income' AND status = 'realized' THEN amount ELSE 0 END), 0) total_income,
                COALESCE(SUM(CASE WHEN type = 'expense' AND status = 'realized' THEN amount ELSE 0 END), 0) total_expense,
                COALESCE(SUM(CASE WHEN type = 'income' AND status = 'pending' THEN amount ELSE 0 END), 0) pending_income,
                COALESCE(SUM(CASE WHEN type = 'expense' AND status = 'pending' THEN amount ELSE 0 END), 0) pending_expense,
                COUNT(id) tx_count
             FROM daily_transactions
             WHERE {$where}",
            $params
        );

        $totalIncome = (float) ($totals['total_income'] ?? 0);
        $totalExpense = (float) ($totals['total_expense'] ?? 0);
        $netBalance = $totalIncome - $totalExpense;

        // Faturas de cartões em aberto
        $cardsOpenTotal = 0.0;
        if ($typeFilter !== 'income' && !in_array($methodFilter, ['pix', 'debit_card', 'cash', 'transfer', 'boleto'], true)) {
            $cardsOpenTotal = (float) $this->db->value(
                "SELECT COALESCE(SUM(total_amount), 0) FROM daily_card_invoices WHERE status != 'paid'"
            );
        }

        // Obrigações a vencer nos próximos 15 dias (faturas de cartão + parcelas pendentes)
        $upcomingObligationsTotal = 0.0;
        if ($typeFilter !== 'income') {
            $agendaData = $this->agenda($today, $in15Days);
            $upcomingObligationsTotal = $agendaData['expected_out'];
        }

        return [
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'net_balance' => $netBalance,
            'pending_income' => (float) ($totals['pending_income'] ?? 0),
            'pending_expense' => (float) ($totals['pending_expense'] ?? 0),
            'tx_count' => (int) ($totals['tx_count'] ?? 0),
            'cards_open_total' => $cardsOpenTotal,
            'upcoming_obligations_total' => $upcomingObligationsTotal,
        ];
    }

    public function agenda(string $from, string $to): array
    {
        $events = [];
        $fromDate = new DateTimeImmutable($from);
        $toDate = new DateTimeImmutable($to);

        // 1. Faturas de cartão de crédito a vencer
        $invoices = $this->db->fetchAll(
            "SELECT inv.*, c.name card_name, c.brand, c.color card_color
             FROM daily_card_invoices inv
             JOIN daily_credit_cards c ON c.id = inv.card_id
             WHERE inv.status != 'paid' AND inv.due_date BETWEEN ? AND ?
             ORDER BY inv.due_date ASC",
            [$from, $to]
        );

        foreach ($invoices as $inv) {
            $invTxs = $this->db->fetchAll(
                "SELECT t.*, cat.name cat_name, cat.icon cat_icon, cat.color cat_color,
                        c.name card_name, c.brand card_brand, c.color card_color
                 FROM daily_transactions t
                 LEFT JOIN daily_categories cat ON cat.id = t.category_id
                 LEFT JOIN daily_credit_cards c ON c.id = t.card_id
                 WHERE t.invoice_id = ?
                 ORDER BY t.transaction_date ASC, t.id ASC",
                [$inv['id']]
            );

            $events[] = [
                'id' => 'card-inv-' . $inv['id'],
                'type' => 'card_invoice',
                'direction' => 'out',
                'date' => $inv['due_date'],
                'title' => 'Fatura Cartão: ' . $inv['card_name'],
                'subtitle' => 'Mês de Ref.: ' . $inv['reference_month'] . ' · ' . $inv['brand'],
                'amount' => (float) $inv['total_amount'],
                'color' => $inv['card_color'] ?: '#6366f1',
                'icon' => '💳',
                'status' => $inv['status'],
                'invoice_id' => (int) $inv['id'],
                'card_id' => (int) $inv['card_id'],
                'transactions' => $invTxs,
            ];
        }

        // 2. Transações pendentes agendadas
        $pendingTxs = $this->db->fetchAll(
            "SELECT t.*, cat.name cat_name, cat.icon cat_icon, cat.color cat_color,
                    c.name card_name, c.brand card_brand, c.color card_color
             FROM daily_transactions t
             LEFT JOIN daily_categories cat ON cat.id = t.category_id
             LEFT JOIN daily_credit_cards c ON c.id = t.card_id
             WHERE t.status = 'pending' AND t.transaction_date BETWEEN ? AND ?
             ORDER BY t.transaction_date ASC",
            [$from, $to]
        );

        foreach ($pendingTxs as $pt) {
            $events[] = [
                'id' => 'tx-pending-' . $pt['id'],
                'type' => 'pending_tx',
                'direction' => $pt['type'] === 'income' ? 'in' : 'out',
                'date' => $pt['transaction_date'],
                'title' => $pt['payee_name'] ?: $pt['description'],
                'subtitle' => ($pt['cat_name'] ? $pt['cat_name'] . ' · ' : '') . $pt['description'],
                'amount' => (float) $pt['amount'],
                'color' => $pt['type'] === 'income' ? '#10b981' : '#ef4444',
                'icon' => $pt['cat_icon'] ?: ($pt['type'] === 'income' ? '💰' : '💸'),
                'status' => 'pending',
                'tx_id' => (int) $pt['id'],
                'raw_tx' => $pt,
            ];
        }

        // 3. Compromissos recorrentes ativos (mensalidades, escola, cursos, internet, fixos)
        $commitments = $this->db->fetchAll(
            "SELECT r.*, cat.name cat_name, cat.icon cat_icon, cat.color cat_color
             FROM daily_recurring_commitments r
             LEFT JOIN daily_categories cat ON cat.id = r.category_id
             WHERE r.active = 1 AND r.start_date <= ? AND (r.end_date IS NULL OR r.end_date >= ?)",
            [$to, $from]
        );

        // Iterar pelos meses abrangidos pelo intervalo [from, to]
        $cursor = $fromDate->modify('first day of this month');
        $endCursor = $toDate->modify('last day of this month');

        while ($cursor <= $endCursor) {
            $yearMonth = $cursor->format('Y-m');
            $daysInMonth = (int) $cursor->format('t');

            foreach ($commitments as $com) {
                $dueDay = min((int) $com['due_day'], $daysInMonth);
                $eventDateStr = sprintf('%s-%02d', $yearMonth, $dueDay);

                if ($eventDateStr < $from || $eventDateStr > $to) {
                    continue;
                }
                if ($eventDateStr < $com['start_date']) {
                    continue;
                }
                if (!empty($com['end_date']) && $eventDateStr > $com['end_date']) {
                    continue;
                }

                // Checar se já houve transação realizada com o mesmo favorecido na mesma data aproximada (ou vinculada)
                $alreadyPosted = (int) $this->db->value(
                    "SELECT COUNT(*) FROM daily_transactions 
                     WHERE payee_name = ? AND type = ? AND transaction_date BETWEEN DATE_SUB(?, INTERVAL 4 DAY) AND DATE_ADD(?, INTERVAL 4 DAY) AND status = 'realized'",
                    [$com['payee_name'], $com['type'], $eventDateStr, $eventDateStr]
                );

                if ($alreadyPosted > 0) {
                    continue; // já foi pago no mês
                }

                $instLabel = !empty($com['total_installments'])
                    ? "Parcela {$com['current_installment']}/{$com['total_installments']}"
                    : 'Mensalidade / Despesa Fixa';

                $events[] = [
                    'id' => 'com-' . $com['id'] . '-' . $yearMonth,
                    'type' => 'recurring_commitment',
                    'direction' => $com['type'] === 'income' ? 'in' : 'out',
                    'date' => $eventDateStr,
                    'title' => $com['payee_name'],
                    'subtitle' => ($com['cat_name'] ? $com['cat_name'] . ' · ' : '') . $com['description'] . " ({$instLabel})",
                    'amount' => (float) $com['amount'],
                    'color' => $com['cat_color'] ?: '#3b82f6',
                    'icon' => $com['cat_icon'] ?: '🎓',
                    'status' => 'pending',
                    'commitment_id' => (int) $com['id'],
                    'raw_commitment' => $com,
                ];
            }

            $cursor = $cursor->modify('+1 month');
        }

        // Ordenar cronologicamente ASC
        usort($events, static fn(array $a, array $b): int => strcmp($a['date'], $b['date']) ?: strcmp($a['title'], $b['title']));

        $expectedIn = 0.0;
        $expectedOut = 0.0;
        $byDate = [];

        foreach ($events as $ev) {
            if ($ev['direction'] === 'in') {
                $expectedIn += (float) $ev['amount'];
            } else {
                $expectedOut += (float) $ev['amount'];
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

    public function categoriesWithBudgets(string $month): array
    {
        $startOfMonth = $month . '-01';
        $endOfMonth = date('Y-m-t', strtotime($startOfMonth));

        $macros = $this->db->fetchAll(
            "SELECT c.* FROM daily_categories c 
             WHERE c.parent_id IS NULL AND c.type = 'expense' AND c.active = 1
             ORDER BY c.sort_order ASC, c.name ASC"
        );

        $result = [];
        $totalSpentAll = 0.0;
        $totalBudgetPlanned = 0.0;

        foreach ($macros as $macro) {
            $macroId = (int) $macro['id'];

            // Buscar subcategorias
            $subs = $this->db->fetchAll(
                "SELECT c.* FROM daily_categories c WHERE c.parent_id = ? AND c.active = 1 ORDER BY c.sort_order ASC, c.name ASC",
                [$macroId]
            );
            $subIds = array_map(static fn(array $s): int => (int) $s['id'], $subs);
            $allIds = array_merge([$macroId], $subIds);
            $inClause = implode(',', $allIds);

            // Gasto no mês
            $spent = (float) $this->db->value(
                "SELECT COALESCE(SUM(amount), 0) FROM daily_transactions 
                 WHERE category_id IN ({$inClause}) AND type = 'expense' AND status = 'realized' AND transaction_date BETWEEN ? AND ?",
                [$startOfMonth, $endOfMonth]
            );

            $limit = $macro['monthly_budget_limit'] ? (float) $macro['monthly_budget_limit'] : null;
            $consumption = ($limit !== null && $limit > 0) ? round(($spent / $limit) * 100, 1) : null;

            $status = 'ok';
            if ($consumption !== null) {
                if ($consumption >= 100) {
                    $status = 'danger';
                } elseif ($consumption >= 75) {
                    $status = 'warning';
                }
            }

            $macro['spent'] = $spent;
            $macro['consumption_percent'] = $consumption;
            $macro['status'] = $status;
            $macro['subcategories'] = $subs;

            $result[] = $macro;
            $totalSpentAll += $spent;
            if ($limit !== null) {
                $totalBudgetPlanned += $limit;
            }
        }

        return [
            'categories' => $result,
            'total_spent' => $totalSpentAll,
            'total_budget_planned' => $totalBudgetPlanned,
        ];
    }

    public function recentPayees(string $query = '', int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $where = '';
        $params = [];

        if (trim($query) !== '') {
            $where = ' WHERE p.name LIKE ?';
            $params[] = '%' . trim($query) . '%';
        }

        return $this->db->fetchAll(
            "SELECT p.*, cat.name default_category_name, cat.icon default_category_icon
             FROM daily_payees p
             LEFT JOIN daily_categories cat ON cat.id = p.default_category_id
             {$where}
             ORDER BY p.usage_count DESC, p.last_used_at DESC
             LIMIT {$limit}",
            $params
        );
    }

    public function cardsList(): array
    {
        $cards = $this->db->fetchAll(
            "SELECT c.*,
                (SELECT COALESCE(SUM(inv.total_amount), 0) FROM daily_card_invoices inv WHERE inv.card_id = c.id AND inv.status != 'paid') open_invoices_sum,
                (SELECT inv.id FROM daily_card_invoices inv WHERE inv.card_id = c.id AND inv.status != 'paid' ORDER BY inv.due_date ASC LIMIT 1) current_open_invoice_id,
                (SELECT inv.total_amount FROM daily_card_invoices inv WHERE inv.card_id = c.id AND inv.status != 'paid' ORDER BY inv.due_date ASC LIMIT 1) current_open_invoice_amount,
                (SELECT inv.due_date FROM daily_card_invoices inv WHERE inv.card_id = c.id AND inv.status != 'paid' ORDER BY inv.due_date ASC LIMIT 1) current_open_invoice_due,
                (SELECT inv.reference_month FROM daily_card_invoices inv WHERE inv.card_id = c.id AND inv.status != 'paid' ORDER BY inv.due_date ASC LIMIT 1) current_open_invoice_month
             FROM daily_credit_cards c
             ORDER BY c.active DESC, c.name ASC"
        );

        foreach ($cards as &$card) {
            $card['available_limit'] = max(0, (float) $card['credit_limit'] - (float) $card['open_invoices_sum']);
        }
        unset($card);

        return $cards;
    }

    public function commitmentsList(bool $onlyActive = true): array
    {
        $where = $onlyActive ? ' WHERE r.active = 1' : '';
        return $this->db->fetchAll(
            "SELECT r.*, cat.name cat_name, cat.icon cat_icon, cat.color cat_color
             FROM daily_recurring_commitments r
             LEFT JOIN daily_categories cat ON cat.id = r.category_id
             {$where}
             ORDER BY r.due_day ASC, r.payee_name ASC"
        );
    }

    public function getOrCreateInvoice(int $cardId, string $transactionDate): int
    {
        $card = $this->db->fetch("SELECT * FROM daily_credit_cards WHERE id = ?", [$cardId]);
        if (!$card) {
            throw new \RuntimeException('Cartão não encontrado.');
        }

        $txTime = strtotime($transactionDate);
        $closingDay = (int) $card['closing_day'];
        $dueDay = (int) $card['due_day'];

        $txDay = (int) date('j', $txTime);
        $year = (int) date('Y', $txTime);
        $month = (int) date('n', $txTime);

        // Se a transação é feita no dia de fechamento ou após, cai na fatura do mês seguinte
        if ($txDay >= $closingDay) {
            $month++;
            if ($month > 12) {
                $month = 1;
                $year++;
            }
        }

        $refMonth = sprintf('%04d-%02d', $year, $month);

        // Verificar se já existe fatura para o mês
        $existing = $this->db->fetch(
            "SELECT id FROM daily_card_invoices WHERE card_id = ? AND reference_month = ?",
            [$cardId, $refMonth]
        );
        if ($existing) {
            return (int) $existing['id'];
        }

        // Calcular closing_date e due_date
        $daysInRefMonth = (int) date('t', strtotime("{$refMonth}-01"));
        $actualClosingDay = min($closingDay, $daysInRefMonth);
        $closingDate = sprintf('%s-%02d', $refMonth, $actualClosingDay);

        // O vencimento costuma ser no mesmo mês de referência ou no mês seguinte se dueDay < closingDay
        $dueYear = $year;
        $dueMonth = $month;
        if ($dueDay < $closingDay) {
            $dueMonth++;
            if ($dueMonth > 12) {
                $dueMonth = 1;
                $dueYear++;
            }
        }
        $daysInDueMonth = (int) date('t', strtotime(sprintf('%04d-%02d-01', $dueYear, $dueMonth)));
        $actualDueDay = min($dueDay, $daysInDueMonth);
        $dueDate = sprintf('%04d-%02d-%02d', $dueYear, $dueMonth, $actualDueDay);

        return (int) $this->db->insert(
            "INSERT INTO daily_card_invoices (card_id, reference_month, closing_date, due_date, total_amount, status) VALUES (?, ?, ?, ?, 0.00, 'open')",
            [$cardId, $refMonth, $closingDate, $dueDate]
        );
    }

    public function recalculateInvoiceTotal(int $invoiceId): void
    {
        $sum = (float) $this->db->value(
            "SELECT COALESCE(SUM(amount), 0) FROM daily_transactions WHERE invoice_id = ?",
            [$invoiceId]
        );
        if ($sum <= 0) {
            $this->db->query("DELETE FROM daily_card_invoices WHERE id = ? AND status = 'open'", [$invoiceId]);
        } else {
            $this->db->query(
                "UPDATE daily_card_invoices SET total_amount = ? WHERE id = ?",
                [$sum, $invoiceId]
            );
        }
    }

    public function getOrCreateInvoiceForDueDate(int $cardId, string $dueDate): int
    {
        $card = $this->db->fetch("SELECT * FROM daily_credit_cards WHERE id = ?", [$cardId]);
        if (!$card) {
            throw new \RuntimeException('Cartão não encontrado.');
        }

        $dueTime = strtotime($dueDate);
        $dueYear = (int) date('Y', $dueTime);
        $dueMonth = (int) date('n', $dueTime);
        $closingDay = (int) $card['closing_day'];
        $dueDay = (int) $card['due_day'];

        // Determinar o mês de referência da fatura (refMonth)
        $refYear = $dueYear;
        $refMonthNum = $dueMonth;
        if ($dueDay < $closingDay) {
            $refMonthNum--;
            if ($refMonthNum < 1) {
                $refMonthNum = 12;
                $refYear--;
            }
        }

        $refMonth = sprintf('%04d-%02d', $refYear, $refMonthNum);

        $existing = $this->db->fetch(
            "SELECT id FROM daily_card_invoices WHERE card_id = ? AND reference_month = ?",
            [$cardId, $refMonth]
        );
        if ($existing) {
            return (int) $existing['id'];
        }

        $daysInRefMonth = (int) date('t', strtotime("{$refMonth}-01"));
        $actualClosingDay = min($closingDay, $daysInRefMonth);
        $closingDate = sprintf('%s-%02d', $refMonth, $actualClosingDay);

        return (int) $this->db->insert(
            "INSERT INTO daily_card_invoices (card_id, reference_month, closing_date, due_date, total_amount, status) VALUES (?, ?, ?, ?, 0.00, 'open')",
            [$cardId, $refMonth, $closingDate, $dueDate]
        );
    }
}

