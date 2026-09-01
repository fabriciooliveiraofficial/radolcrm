<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Services\ExchangeRateService;
use App\Services\WhatsAppReminderService;
use RuntimeException;

final class ActionHandler
{
    public function __construct(
        private readonly Database $db,
        private readonly Auth $auth,
        private readonly ExchangeRateService $rates
    ) {
    }

    public function handle(string $action): never
    {
        if (!Csrf::validate($_POST['_token'] ?? null)) {
            http_response_code(419);
            exit('Sessão expirada. Volte e tente novamente.');
        }
        if (!$this->auth->canWrite() && !in_array($action, ['save_profile', 'refresh_rate'], true)) {
            http_response_code(403);
            exit('Seu perfil não permite alterações.');
        }

        $redirect = '?page=dashboard';
        try {
            $redirect = match ($action) {
                'save_credit_card' => $this->saveCreditCard(),
                'delete_credit_card' => $this->deleteCreditCard(),
                'save_card_transaction' => $this->saveCardTransaction(),
                'delete_card_transaction' => $this->deleteCardTransaction(),
                'pay_card_invoice' => $this->payCardInvoice(),
                'save_recurring_template' => $this->saveRecurringTemplate(),
                'delete_recurring_template' => $this->deleteRecurringTemplate(),
                'pay_installment' => $this->payInstallment(),
                'edit_installment' => $this->editInstallment(),
                'delete_installment' => $this->deleteInstallment(),
                'save_business_unit' => $this->saveBusinessUnit(),
                'delete_business_unit' => $this->deleteBusinessUnit(),
                'save_category' => $this->saveCategory(),
                'delete_category' => $this->deleteCategory(),
                'save_client' => $this->saveClient(),
                'delete_client' => $this->deleteClient(),
                'save_product' => $this->saveProduct(),
                'delete_product' => $this->deleteProduct(),
                'save_service_badge' => $this->saveServiceBadge(),
                'delete_service_badge' => $this->deleteServiceBadge(),
                'save_subscription' => $this->saveSubscription(),
                'save_subscription_badges' => $this->saveSubscriptionBadges(),
                'delete_subscription' => $this->deleteSubscription(),
                'generate_due_payments', 'generate_upcoming_payments' => $this->generateUpcomingPayments(),
                'process_subscription_renewals' => $this->processSubscriptionRenewals(),
                'save_payment' => $this->savePayment(),
                'mark_payments_paid' => $this->markPaymentsPaid(),
                'delete_payment' => $this->deletePayment(),
                'save_expense' => $this->saveExpense(),
                'delete_expense' => $this->deleteExpense(),
                'save_cash' => $this->saveCash(),
                'delete_cash' => $this->deleteCash(),
                'refresh_rate' => $this->refreshRate(),
                'save_settings' => $this->saveSettings(),
                'save_whatsapp_reminders' => $this->saveWhatsAppReminders(),
                'test_whatsapp_connection' => $this->testWhatsAppConnection(),
                'send_whatsapp_test' => $this->sendWhatsAppTest(),
                'run_whatsapp_reminders' => $this->runWhatsAppReminders(),
                'retry_whatsapp_reminder' => $this->retryWhatsAppReminder(),
                'save_profile' => $this->saveProfile(),
                'save_user' => $this->saveUser(),
                'toggle_user' => $this->toggleUser(),
                'run_financial_automation' => $this->runFinancialAutomation(),
                default => throw new RuntimeException('Ação desconhecida.'),
            };
        } catch (\Throwable $exception) {
            Flash::add('danger', $exception->getMessage());
            $redirect = $this->returnUrl($redirect);
        }

        if (isset($_GET['bu']) && $_GET['bu'] !== '' && !str_contains($redirect, 'bu=')) {
            if ($action !== 'delete_business_unit') {
                $redirect .= (str_contains($redirect, '?') ? '&' : '?') . 'bu=' . (int) $_GET['bu'];
            }
        }

        header('Location: ' . $redirect);
        exit;
    }

    private function runFinancialAutomation(): string
    {
        $service = new \App\Services\FinancialAutomationService($this->db);
        $res = $service->runDailyFinancialAutomation();

        Flash::add(
            'success',
            "Automação executada: {$res['closed_invoices']} faturas fechadas, {$res['overdue_installments']} parcelas vencidas marcadas, {$res['generated_installments']} novas parcelas geradas."
        );
        return $this->returnUrl('?page=settings#automation');
    }

    private function saveCreditCard(): string
    {
        $id = $this->id();
        $businessUnitId = $this->businessUnitId();
        $name = $this->required('name', 'Informe o nome do cartão.');
        $brand = $this->nullable('brand') ?: 'Mastercard';
        $lastFour = $this->nullable('last_four_digits');
        $creditLimit = normalize_decimal($_POST['credit_limit'] ?? 0);
        $closingDay = max(1, min(31, (int) ($_POST['closing_day'] ?? 1)));
        $dueDay = max(1, min(31, (int) ($_POST['due_day'] ?? 10)));
        $color = $this->nullable('color') ?: '#6366f1';
        $active = isset($_POST['active']) ? 1 : 0;
        $notes = $this->nullable('notes');

        $params = [$businessUnitId, $name, $brand, $lastFour, $creditLimit, $closingDay, $dueDay, $color, $active, $notes];
        if ($id) {
            $params[] = $id;
            $this->db->query(
                'UPDATE credit_cards SET business_unit_id=?, name=?, brand=?, last_four_digits=?, credit_limit=?, closing_day=?, due_day=?, color=?, active=?, notes=? WHERE id=?',
                $params
            );
            audit($this->db, 'update', 'credit_card', $id, ['name' => $name]);
        } else {
            $id = $this->db->insert(
                'INSERT INTO credit_cards (business_unit_id, name, brand, last_four_digits, credit_limit, closing_day, due_day, color, active, notes) VALUES (?,?,?,?,?,?,?,?,?,?)',
                $params
            );
            audit($this->db, 'create', 'credit_card', $id, ['name' => $name]);
        }

        Flash::add('success', 'Cartão de crédito salvo com sucesso.');
        return '?page=cards';
    }

    private function deleteCreditCard(): string
    {
        $id = $this->id(true);
        $this->db->query('DELETE FROM credit_cards WHERE id = ?', [$id]);
        audit($this->db, 'delete', 'credit_card', $id);
        Flash::add('success', 'Cartão de crédito e faturas excluídos.');
        return '?page=cards';
    }

    private function saveCardTransaction(): string
    {
        $cardId = (int) ($_POST['card_id'] ?? 0);
        $card = $this->db->fetch('SELECT * FROM credit_cards WHERE id = ?', [$cardId]);
        if (!$card) {
            throw new RuntimeException('Selecione um cartão de crédito válido.');
        }

        $businessUnitId = $this->businessUnitId() ?: $card['business_unit_id'];
        $categoryId = $this->categoryId();
        $txDate = $this->required('transaction_date', 'Informe a data da compra.');
        $description = $this->required('description', 'Informe a descrição da compra.');
        $totalAmount = normalize_decimal($_POST['amount'] ?? 0);
        if ($totalAmount <= 0) {
            throw new RuntimeException('Informe um valor de compra válido.');
        }
        $currency = $this->choice('currency', ['BRL', 'USD']);
        $rate = $currency === 'USD' ? normalize_decimal($_POST['exchange_rate'] ?? 0) : 1.0;
        $totalInstallments = max(1, min(36, (int) ($_POST['total_installments'] ?? 1)));
        $notes = $this->nullable('notes');

        $installmentAmount = round($totalAmount / $totalInstallments, 2);
        $lastInstallmentAmount = round($totalAmount - ($installmentAmount * ($totalInstallments - 1)), 2);

        // Determine base invoice reference month
        $txDay = (int) date('d', strtotime($txDate));
        $closingDay = (int) $card['closing_day'];
        
        $baseDate = new \DateTimeImmutable($txDate);
        if ($txDay >= $closingDay) {
            $baseDate = $baseDate->modify('first day of next month');
        }

        for ($k = 1; $k <= $totalInstallments; $k++) {
            $instAmount = ($k === $totalInstallments) ? $lastInstallmentAmount : $installmentAmount;
            $instAmountBrl = round($instAmount * $rate, 2);
            $instDesc = $totalInstallments > 1 ? "{$description} ({$k}/{$totalInstallments})" : $description;

            $invMonthDate = $baseDate->modify('+' . ($k - 1) . ' months');
            $refMonth = $invMonthDate->format('Y-m');

            $invoiceId = $this->getOrCreateCardInvoice($card, $refMonth);

            $this->db->insert(
                'INSERT INTO credit_card_transactions (card_id, invoice_id, business_unit_id, category_id, transaction_date, description, amount, currency, exchange_rate, amount_brl, installment_number, total_installments, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$cardId, $invoiceId, $businessUnitId, $categoryId, $txDate, $instDesc, $instAmount, $currency, $rate, $instAmountBrl, $k, $totalInstallments, $notes]
            );

            // Update invoice total amount
            $this->recalculateCardInvoiceTotal($invoiceId);
        }

        Flash::add('success', 'Compra no cartão lançada com sucesso.');
        return $this->returnUrl('?page=cards&card=' . $cardId);
    }

    private function deleteCardTransaction(): string
    {
        $id = $this->id(true);
        $tx = $this->db->fetch('SELECT * FROM credit_card_transactions WHERE id = ?', [$id]);
        if (!$tx) {
            throw new RuntimeException('Lançamento não encontrado.');
        }

        $this->db->query('DELETE FROM credit_card_transactions WHERE id = ?', [$id]);
        if ($tx['invoice_id']) {
            $this->recalculateCardInvoiceTotal((int) $tx['invoice_id']);
        }

        audit($this->db, 'delete', 'credit_card_transaction', $id);
        Flash::add('success', 'Lançamento de cartão removido.');
        return $this->returnUrl('?page=cards&card=' . $tx['card_id']);
    }

    private function payCardInvoice(): string
    {
        $id = $this->id(true);
        $invoice = $this->db->fetch(
            'SELECT inv.*, c.name card_name, c.business_unit_id FROM credit_card_invoices inv JOIN credit_cards c ON c.id = inv.card_id WHERE inv.id = ?',
            [$id]
        );
        if (!$invoice) {
            throw new RuntimeException('Fatura não encontrada.');
        }
        if ($invoice['status'] === 'paid') {
            throw new RuntimeException('Esta fatura já está paga.');
        }

        $paymentDate = $this->required('payment_date', 'Informe a data de pagamento da fatura.');
        $catId = (int) $this->db->value('SELECT id FROM categories WHERE name LIKE "%Cartão%" OR name LIKE "%Crédito%" LIMIT 1') ?: null;

        // Create expense for the invoice payment
        $expenseId = $this->db->insert(
            'INSERT INTO expenses (business_unit_id, category_id, type, category, description, supplier, amount, currency, exchange_rate, amount_brl, status, payment_date, is_recurring, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,?)',
            [
                $invoice['business_unit_id'],
                $catId,
                'expense',
                'Cartão de Crédito',
                "Fatura Cartão {$invoice['card_name']} ({$invoice['reference_month']})",
                $invoice['card_name'],
                $invoice['total_amount'],
                'BRL',
                1.0,
                $invoice['total_amount'],
                'paid',
                $paymentDate,
                "Pagamento consolidado da fatura {$invoice['reference_month']} do cartão {$invoice['card_name']}",
            ]
        );

        $this->db->query(
            "UPDATE credit_card_invoices SET status = 'paid', payment_date = ?, expense_id = ? WHERE id = ?",
            [$paymentDate, $expenseId, $id]
        );

        audit($this->db, 'pay', 'credit_card_invoice', $id, ['expense_id' => $expenseId, 'amount' => $invoice['total_amount']]);
        Flash::add('success', 'Fatura paga com sucesso e lançada em Gastos.');
        return $this->returnUrl('?page=cards&card=' . $invoice['card_id']);
    }

    private function getOrCreateCardInvoice(array $card, string $refMonth): int
    {
        $existing = $this->db->fetch('SELECT id FROM credit_card_invoices WHERE card_id = ? AND reference_month = ?', [$card['id'], $refMonth]);
        if ($existing) {
            return (int) $existing['id'];
        }

        $daysInMonth = (int) date('t', strtotime($refMonth . '-01'));
        $closingDay = min((int) $card['closing_day'], $daysInMonth);
        $dueDay = min((int) $card['due_day'], $daysInMonth);

        $closingDate = sprintf('%s-%02d', $refMonth, $closingDay);
        $dueDate = sprintf('%s-%02d', $refMonth, $dueDay);

        return $this->db->insert(
            'INSERT INTO credit_card_invoices (card_id, reference_month, closing_date, due_date, total_amount, status) VALUES (?,?,?,?,0.00,"open")',
            [$card['id'], $refMonth, $closingDate, $dueDate]
        );
    }

    private function recalculateCardInvoiceTotal(int $invoiceId): void
    {
        $total = (float) $this->db->value(
            'SELECT COALESCE(SUM(amount_brl), 0) FROM credit_card_transactions WHERE invoice_id = ?',
            [$invoiceId]
        );
        $this->db->query('UPDATE credit_card_invoices SET total_amount = ? WHERE id = ?', [$total, $invoiceId]);
    }

    private function saveRecurringTemplate(): string
    {
        $id = $this->id();
        $businessUnitId = $this->businessUnitId();
        $categoryId = $this->categoryId();
        $type = $this->choice('type', ['expense', 'income', 'credit_card']);
        $description = $this->required('description', 'Informe a descrição do lançamento recorrente.');
        $supplier = $this->nullable('supplier');
        $amount = normalize_decimal($_POST['amount'] ?? 0);
        if ($amount <= 0) {
            throw new RuntimeException('Informe um valor de parcela maior que zero.');
        }
        $currency = $this->choice('currency', ['BRL', 'USD']);
        $rate = $currency === 'USD' ? normalize_decimal($_POST['exchange_rate'] ?? 0) : 1.0;
        $recurrence = $this->choice('recurrence', ['monthly', 'weekly', 'biweekly', 'quarterly', 'annual']);
        $totalInstallments = isset($_POST['total_installments']) && (int) $_POST['total_installments'] > 0 ? (int) $_POST['total_installments'] : null;
        $startDate = $this->required('start_date', 'Informe a data de início ou primeiro vencimento.');
        $dayOfMonth = (int) ($_POST['day_of_month'] ?? 0) ?: (int) date('d', strtotime($startDate));
        $autoGenerate = isset($_POST['auto_generate']) ? 1 : 0;
        $notes = $this->nullable('notes');
        $active = isset($_POST['active']) ? 1 : 0;

        $params = [
            $businessUnitId, $categoryId, $type, $description, $supplier, $amount, $currency, $rate,
            $recurrence, $totalInstallments, $startDate, null, $dayOfMonth, $autoGenerate, $notes, $active,
        ];

        if ($id) {
            $params[] = $id;
            $this->db->query(
                'UPDATE recurring_templates SET business_unit_id=?, category_id=?, type=?, description=?, supplier=?, amount=?, currency=?, exchange_rate=?, recurrence=?, total_installments=?, start_date=?, end_date=?, day_of_month=?, auto_generate=?, notes=?, active=? WHERE id=?',
                $params
            );
            audit($this->db, 'update', 'recurring_template', $id, ['description' => $description]);
        } else {
            $id = $this->db->insert(
                'INSERT INTO recurring_templates (business_unit_id, category_id, type, description, supplier, amount, currency, exchange_rate, recurrence, total_installments, start_date, end_date, day_of_month, auto_generate, notes, active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                $params
            );
            audit($this->db, 'create', 'recurring_template', $id, ['description' => $description]);

            $installmentsPayload = is_array($_POST['installments'] ?? null) ? $_POST['installments'] : [];
            if (!empty($installmentsPayload)) {
                foreach ($installmentsPayload as $inst) {
                    $instNum = (int) ($inst['installment_number'] ?? 1);
                    $instTotal = $totalInstallments;
                    $instDesc = trim((string) ($inst['description'] ?? '')) ?: ($description . ($instTotal ? " ({$instNum}/{$instTotal})" : " ({$instNum})"));
                    $instAmount = normalize_decimal($inst['amount'] ?? $amount);
                    $instDue = trim((string) ($inst['due_date'] ?? $startDate));
                    $instRate = $rate;
                    $instBrl = round($instAmount * $instRate, 2);

                    $this->db->insert(
                        'INSERT INTO installments (template_id, business_unit_id, category_id, installment_number, total_installments, description, supplier, amount, currency, exchange_rate, amount_brl, due_date, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        [$id, $businessUnitId, $categoryId, $instNum, $instTotal, $instDesc, $supplier, $instAmount, $currency, $instRate, $instBrl, $instDue, 'pending', $notes]
                    );
                }
            } else {
                $countToGenerate = $totalInstallments ?: 12;
                $currentDate = new \DateTimeImmutable($startDate);
                for ($num = 1; $num <= $countToGenerate; $num++) {
                    $instDesc = $description . ($totalInstallments ? " ({$num}/{$totalInstallments})" : " ({$num})");
                    $instDue = $currentDate->format('Y-m-d');
                    $instBrl = round($amount * $rate, 2);

                    $this->db->insert(
                        'INSERT INTO installments (template_id, business_unit_id, category_id, installment_number, total_installments, description, supplier, amount, currency, exchange_rate, amount_brl, due_date, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        [$id, $businessUnitId, $categoryId, $num, $totalInstallments, $instDesc, $supplier, $amount, $currency, $rate, $instBrl, $instDue, 'pending', $notes]
                    );

                    $currentDate = match ($recurrence) {
                        'weekly' => $currentDate->modify('+1 week'),
                        'biweekly' => $currentDate->modify('+2 weeks'),
                        'quarterly' => $currentDate->modify('+3 months'),
                        'annual' => $currentDate->modify('+1 year'),
                        default => $currentDate->modify('+1 month'),
                    };
                }
            }
        }

        Flash::add('success', 'Lançamento recorrente e parcelas salvas com sucesso.');
        return '?page=recurring';
    }

    private function payInstallment(): string
    {
        $id = $this->id(true);
        $installment = $this->db->fetch('SELECT i.*, rt.type rt_type FROM installments i LEFT JOIN recurring_templates rt ON rt.id = i.template_id WHERE i.id = ?', [$id]);
        if (!$installment) {
            throw new RuntimeException('Parcela não encontrada.');
        }
        if ($installment['status'] === 'paid') {
            throw new RuntimeException('Esta parcela já foi paga.');
        }

        $paymentDate = $this->required('payment_date', 'Informe a data do pagamento.');
        $category = (string) $this->db->value('SELECT name FROM categories WHERE id = ?', [$installment['category_id']]) ?: 'Recorrente';

        $expenseId = $this->db->insert(
            'INSERT INTO expenses (business_unit_id, category_id, type, category, description, supplier, amount, currency, exchange_rate, amount_brl, status, payment_date, is_recurring, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?)',
            [
                $installment['business_unit_id'],
                $installment['category_id'],
                $installment['rt_type'] === 'investment' ? 'investment' : 'expense',
                $category,
                $installment['description'],
                $installment['supplier'],
                $installment['amount'],
                $installment['currency'],
                $installment['exchange_rate'],
                $installment['amount_brl'],
                'paid',
                $paymentDate,
                $installment['notes'] ? "Parcela {$installment['installment_number']} · {$installment['notes']}" : "Parcela {$installment['installment_number']}",
            ]
        );

        $this->db->query(
            "UPDATE installments SET status = 'paid', payment_date = ?, expense_id = ? WHERE id = ?",
            [$paymentDate, $expenseId, $id]
        );

        audit($this->db, 'pay', 'installment', $id, ['expense_id' => $expenseId, 'payment_date' => $paymentDate]);
        Flash::add('success', 'Parcela confirmada como paga e lançada no financeiro.');
        return $this->returnUrl('?page=recurring');
    }

    private function editInstallment(): string
    {
        $id = $this->id(true);
        $amount = normalize_decimal($_POST['amount'] ?? 0);
        if ($amount <= 0) {
            throw new RuntimeException('Informe um valor válido.');
        }
        $dueDate = $this->required('due_date', 'Informe a data de vencimento.');
        $description = $this->required('description', 'Informe a descrição.');
        $notes = $this->nullable('notes');

        $inst = $this->db->fetch('SELECT exchange_rate FROM installments WHERE id = ?', [$id]);
        $rate = (float) ($inst['exchange_rate'] ?? 1.0);
        $amountBrl = round($amount * $rate, 2);

        $this->db->query(
            'UPDATE installments SET amount = ?, amount_brl = ?, due_date = ?, description = ?, notes = ? WHERE id = ?',
            [$amount, $amountBrl, $dueDate, $description, $notes, $id]
        );

        audit($this->db, 'update', 'installment', $id, ['amount' => $amount, 'due_date' => $dueDate]);
        Flash::add('success', 'Parcela atualizada com sucesso.');
        return $this->returnUrl('?page=recurring');
    }

    private function deleteInstallment(): string
    {
        $id = $this->id(true);
        $this->db->query('DELETE FROM installments WHERE id = ?', [$id]);
        audit($this->db, 'delete', 'installment', $id);
        Flash::add('success', 'Parcela excluída.');
        return $this->returnUrl('?page=recurring');
    }

    private function deleteRecurringTemplate(): string
    {
        $id = $this->id(true);
        $this->db->query('DELETE FROM installments WHERE template_id = ? AND status != "paid"', [$id]);
        $this->db->query('DELETE FROM recurring_templates WHERE id = ?', [$id]);
        audit($this->db, 'delete', 'recurring_template', $id);
        Flash::add('success', 'Lançamento recorrente e parcelas pendentes excluídas.');
        return $this->returnUrl('?page=recurring');
    }

    private function saveBusinessUnit(): string
    {
        $id = $this->id();
        $name = $this->required('name', 'Informe o nome da unidade de negócio.');
        $icon = $this->nullable('icon') ?: '💼';
        $color = $this->nullable('color') ?: '#2b826b';
        $isPersonal = isset($_POST['is_personal']) ? 1 : 0;
        $active = isset($_POST['active']) ? 1 : 0;
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);

        $params = [$name, $icon, $color, $isPersonal, $active, $sortOrder];
        if ($id) {
            $params[] = $id;
            $this->db->query('UPDATE business_units SET name=?, icon=?, color=?, is_personal=?, active=?, sort_order=? WHERE id=?', $params);
            audit($this->db, 'update', 'business_unit', $id, ['name' => $name]);
        } else {
            $id = $this->db->insert('INSERT INTO business_units (name, icon, color, is_personal, active, sort_order) VALUES (?,?,?,?,?,?)', $params);
            audit($this->db, 'create', 'business_unit', $id, ['name' => $name]);
        }
        Flash::add('success', 'Negócio salvo com sucesso.');
        return $this->returnUrl('?page=businesses');
    }

    private function deleteBusinessUnit(): string
    {
        $id = $this->id(true);
        $count = (int) $this->db->value('SELECT COUNT(*) FROM business_units');
        if ($count <= 1) {
            throw new RuntimeException('Você precisa manter ao menos um negócio cadastrado.');
        }
        $this->db->query('DELETE FROM business_units WHERE id=?', [$id]);
        audit($this->db, 'delete', 'business_unit', $id);
        Flash::add('success', 'Negócio excluído.');
        return $this->returnUrl('?page=businesses');
    }

    private function saveCategory(): string
    {
        $id = $this->id();
        $name = $this->required('name', 'Informe o nome da categoria.');
        $businessUnitId = $this->businessUnitId();
        $parentId = (int) ($_POST['parent_id'] ?? 0);
        $parentId = $parentId > 0 ? $parentId : null;
        if ($id && $parentId === $id) {
            $parentId = null;
        }
        $type = $this->choice('type', ['expense', 'income', 'investment', 'both']);
        $icon = $this->nullable('icon') ?: '📁';
        $color = $this->nullable('color') ?: '#2b826b';
        $budgetPercent = isset($_POST['budget_limit_percent']) && $_POST['budget_limit_percent'] !== '' ? normalize_decimal($_POST['budget_limit_percent']) : null;
        $budgetAmount = isset($_POST['budget_limit_amount']) && $_POST['budget_limit_amount'] !== '' ? normalize_decimal($_POST['budget_limit_amount']) : null;
        $active = isset($_POST['active']) ? 1 : 0;
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);

        $params = [$businessUnitId, $parentId, $name, $type, $icon, $color, $budgetPercent, $budgetAmount, $active, $sortOrder];
        if ($id) {
            $params[] = $id;
            $this->db->query('UPDATE categories SET business_unit_id=?, parent_id=?, name=?, type=?, icon=?, color=?, budget_limit_percent=?, budget_limit_amount=?, active=?, sort_order=? WHERE id=?', $params);
            audit($this->db, 'update', 'category', $id, ['name' => $name]);
        } else {
            $id = $this->db->insert('INSERT INTO categories (business_unit_id, parent_id, name, type, icon, color, budget_limit_percent, budget_limit_amount, active, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)', $params);
            audit($this->db, 'create', 'category', $id, ['name' => $name]);
        }
        Flash::add('success', 'Categoria salva com sucesso.');
        return $this->returnUrl('?page=categories');
    }

    private function deleteCategory(): string
    {
        $id = $this->id(true);
        $force = isset($_POST['force']) && (int) $_POST['force'] === 1;

        // Count direct children
        $children = (int) $this->db->value('SELECT COUNT(*) FROM categories WHERE parent_id=?', [$id]);
        
        // Count linked records
        $expenseCount = (int) $this->db->value('SELECT COUNT(*) FROM expenses WHERE category_id=?', [$id]);
        $paymentCount = (int) $this->db->value('SELECT COUNT(*) FROM payments WHERE category_id=?', [$id]);
        $cashCount = (int) $this->db->value('SELECT COUNT(*) FROM cash_entries WHERE category_id=?', [$id]);
        $cardCount = (int) $this->db->value('SELECT COUNT(*) FROM credit_card_transactions WHERE category_id=?', [$id]);
        $recurringCount = (int) $this->db->value('SELECT COUNT(*) FROM recurring_templates WHERE category_id=?', [$id]);
        
        $totalLinked = $expenseCount + $paymentCount + $cashCount + $cardCount + $recurringCount;

        if (!$force && ($children > 0 || $totalLinked > 0)) {
            $parts = [];
            if ($totalLinked > 0) {
                $parts[] = "{$totalLinked} lançamento(s) financeiro(s)";
            }
            if ($children > 0) {
                $parts[] = "{$children} subcategoria(s)";
            }
            $reason = implode(' e ', $parts);
            throw new RuntimeException("Aviso de segurança: Esta categoria possui {$reason}. Para confirmar a exclusão e desvincular os registros com segurança, confirme a ação.");
        }

        // Unlink children safely
        if ($children > 0) {
            $this->db->query('UPDATE categories SET parent_id=NULL WHERE parent_id=?', [$id]);
        }
        // Unlink financial records
        $this->db->query('UPDATE expenses SET category_id=NULL WHERE category_id=?', [$id]);
        $this->db->query('UPDATE payments SET category_id=NULL WHERE category_id=?', [$id]);
        $this->db->query('UPDATE cash_entries SET category_id=NULL WHERE category_id=?', [$id]);
        $this->db->query('UPDATE credit_card_transactions SET category_id=NULL WHERE category_id=?', [$id]);
        $this->db->query('UPDATE recurring_templates SET category_id=NULL WHERE category_id=?', [$id]);

        $this->db->query('DELETE FROM categories WHERE id=?', [$id]);
        audit($this->db, 'delete', 'category', $id);
        Flash::add('success', 'Categoria excluída e registros desvinculados com segurança.');
        return $this->returnUrl('?page=categories');
    }

    private function saveClient(): string
    {
        $id = $this->id();
        $businessUnitId = $this->businessUnitId();
        $name = $this->required('name', 'Informe o nome do cliente.');
        $country = $this->choice('country', ['BR', 'US']);
        $currency = $this->choice('preferred_currency', ['BRL', 'USD']);
        $status = $this->choice('status', ['lead', 'active', 'inactive']);
        $email = $this->nullable('email');
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Informe um e-mail válido.');
        }
        $params = [
            $businessUnitId, $name, $this->nullable('company'), $email, $this->nullable('phone'),
            isset($_POST['whatsapp_reminders_enabled']) ? 1 : 0,
            $this->nullable('document'), $country, $currency, $status, $this->nullable('notes'),
        ];
        if ($id) {
            $params[] = $id;
            $this->db->query('UPDATE clients SET business_unit_id=?, name=?, company=?, email=?, phone=?, whatsapp_reminders_enabled=?, document=?, country=?, preferred_currency=?, status=?, notes=? WHERE id=?', $params);
            audit($this->db, 'update', 'client', $id);
        } else {
            $id = $this->db->insert('INSERT INTO clients (business_unit_id, name, company, email, phone, whatsapp_reminders_enabled, document, country, preferred_currency, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)', $params);
            audit($this->db, 'create', 'client', $id);
        }
        Flash::add('success', 'Cliente salvo com sucesso.');
        return $this->returnUrl('?page=clients');
    }

    private function deleteClient(): string
    {
        $id = $this->id(true);
        $links = (int) $this->db->value('SELECT (SELECT COUNT(*) FROM subscriptions WHERE client_id=?) + (SELECT COUNT(*) FROM payments WHERE client_id=?)', [$id, $id]);
        if ($links > 0) {
            throw new RuntimeException('Este cliente possui assinaturas ou pagamentos. Marque-o como inativo em vez de excluir.');
        }
        $this->db->query('DELETE FROM clients WHERE id=?', [$id]);
        audit($this->db, 'delete', 'client', $id);
        Flash::add('success', 'Cliente excluído.');
        return $this->returnUrl('?page=clients');
    }

    private function saveProduct(): string
    {
        $id = $this->id();
        $businessUnitId = $this->businessUnitId();
        $pricingMode = $this->choice('pricing_mode', ['manual', 'brl', 'usd']);
        $priceBrl = normalize_decimal($_POST['price_brl'] ?? 0);
        $priceUsd = normalize_decimal($_POST['price_usd'] ?? 0);
        $quote = null;
        if ($pricingMode !== 'manual') {
            $quote = $this->rates->current();
            $rate = (float) $quote['bid'];
            if ($pricingMode === 'usd' && $priceUsd > 0) {
                $priceBrl = round($priceUsd * $rate, 2);
            } elseif ($pricingMode === 'brl' && $priceBrl > 0) {
                $priceUsd = round($priceBrl / $rate, 2);
            }
        }
        if (($pricingMode === 'manual' && ($priceBrl <= 0 || $priceUsd <= 0))
            || ($pricingMode === 'usd' && $priceUsd <= 0)
            || ($pricingMode === 'brl' && $priceBrl <= 0)) {
            throw new RuntimeException('Informe um preço positivo na moeda-base selecionada.');
        }
        $params = [
            $businessUnitId,
            $this->required('name', 'Informe o nome do produto.'), $this->nullable('sku'), $this->nullable('description'),
            $priceBrl, $priceUsd, $pricingMode, $quote['bid'] ?? null, $quote['source'] ?? null,
            isset($quote['quoted_at']) ? substr((string) $quote['quoted_at'], 0, 10) : null,
            $this->choice('billing_cycle', ['monthly','quarterly','semiannual','annual']), isset($_POST['active']) ? 1 : 0,
        ];
        if ($id) {
            $params[] = $id;
            $this->db->query('UPDATE products SET business_unit_id=?, name=?, sku=?, description=?, price_brl=?, price_usd=?, pricing_mode=?, price_exchange_rate=?, price_rate_source=?, price_rate_date=?, billing_cycle=?, active=? WHERE id=?', $params);
            audit($this->db, 'update', 'product', $id, ['pricing_mode'=>$pricingMode,'price_brl'=>$priceBrl,'price_usd'=>$priceUsd,'exchange_rate'=>$quote['bid'] ?? null]);
        } else {
            $id = $this->db->insert('INSERT INTO products (business_unit_id, name, sku, description, price_brl, price_usd, pricing_mode, price_exchange_rate, price_rate_source, price_rate_date, billing_cycle, active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', $params);
            audit($this->db, 'create', 'product', $id, ['pricing_mode'=>$pricingMode,'price_brl'=>$priceBrl,'price_usd'=>$priceUsd,'exchange_rate'=>$quote['bid'] ?? null]);
        }
        Flash::add('success', $pricingMode === 'manual' ? 'Produto salvo com preços locais.' : 'Produto salvo e convertido pela cotação diária.');
        return $this->returnUrl('?page=products');
    }

    private function deleteProduct(): string
    {
        $id = $this->id(true);
        if ((int) $this->db->value('SELECT COUNT(*) FROM subscriptions WHERE product_id=?', [$id]) > 0) {
            throw new RuntimeException('Este produto possui assinaturas. Desative-o em vez de excluir.');
        }
        $this->db->query('DELETE FROM products WHERE id=?', [$id]);
        audit($this->db, 'delete', 'product', $id);
        Flash::add('success', 'Produto excluído.');
        return $this->returnUrl('?page=products');
    }

    private function saveServiceBadge(): string
    {
        $id = $this->id();
        $name = mb_substr($this->required('name', 'Informe o nome do badge.'), 0, 80);
        $icon = $this->choice('icon', array_keys(service_badge_icon_options()));
        $tone = $this->choice('tone', array_keys(service_badge_tone_options()));
        $active = isset($_POST['active']) ? 1 : 0;
        $duplicateParams = [$name];
        $duplicateSql = 'SELECT id FROM service_badges WHERE LOWER(name)=LOWER(?)';
        if ($id) {
            $duplicateSql .= ' AND id<>?';
            $duplicateParams[] = $id;
        }
        if ($this->db->value($duplicateSql, $duplicateParams)) {
            throw new RuntimeException('Já existe um badge com este nome.');
        }

        if ($id) {
            $this->db->query(
                'UPDATE service_badges SET name=?,icon=?,tone=?,active=? WHERE id=?',
                [$name,$icon,$tone,$active,$id]
            );
            audit($this->db, 'update', 'service_badge', $id, compact('name', 'icon', 'tone', 'active'));
        } else {
            $id = $this->db->insert(
                'INSERT INTO service_badges (name,icon,tone,active) VALUES (?,?,?,?)',
                [$name,$icon,$tone,$active]
            );
            audit($this->db, 'create', 'service_badge', $id, compact('name', 'icon', 'tone', 'active'));
        }
        Flash::add('success', 'Badge de serviço salvo com sucesso.');
        return '?page=service-badges';
    }

    private function deleteServiceBadge(): string
    {
        $id = $this->id(true);
        $assignments = (int) $this->db->value(
            'SELECT COUNT(*) FROM subscription_service_badges WHERE badge_id=?',
            [$id]
        );
        $this->db->query('DELETE FROM service_badges WHERE id=?', [$id]);
        audit($this->db, 'delete', 'service_badge', $id, ['removed_assignments' => $assignments]);
        Flash::add('success', $assignments > 0
            ? 'Badge excluído e removido das assinaturas vinculadas.'
            : 'Badge excluído.');
        return '?page=service-badges';
    }

    private function saveSubscription(): string
    {
        $id = $this->id();
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $productId = (int) ($_POST['product_id'] ?? 0);
        if (!$this->db->value('SELECT id FROM clients WHERE id=?', [$clientId]) || !$this->db->value('SELECT id FROM products WHERE id=?', [$productId])) {
            throw new RuntimeException('Selecione cliente e produto válidos.');
        }
        $status = $this->choice('status', ['trial','active','past_due','paused','canceled']);
        $badgeIds = $this->postedServiceBadgeIds();
        $canceledAt = $status === 'canceled' ? ($this->nullable('canceled_at') ?: date('Y-m-d')) : null;
        $paymentLink = $this->nullable('payment_link');
        if ($paymentLink !== null && (!filter_var($paymentLink, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $paymentLink))) {
            throw new RuntimeException('Informe um link de pagamento HTTP/HTTPS válido.');
        }
        $params = [
            $clientId, $productId, max(1, (int) ($_POST['quantity'] ?? 1)), $this->choice('currency', ['BRL','USD']),
            normalize_decimal($_POST['unit_price'] ?? 0), max(0, normalize_decimal($_POST['discount'] ?? 0)), $status,
            $this->required('start_date', 'Informe a data de início.'), $this->nullable('next_billing_date'), $canceledAt,
            $this->nullable('payment_method'), $paymentLink, $this->nullable('notes'),
        ];
        if ($id) {
            $params[] = $id;
            $this->db->transaction(function (Database $db) use ($id, $params, $badgeIds): void {
                $previous = $db->fetch('SELECT s.*,p.name product FROM subscriptions s JOIN products p ON p.id=s.product_id WHERE s.id=? FOR UPDATE', [$id]);
                if (!$previous) {
                    throw new RuntimeException('A assinatura não existe mais. Atualize a página.');
                }
                $db->query('UPDATE subscriptions SET client_id=?, product_id=?, quantity=?, currency=?, unit_price=?, discount=?, status=?, start_date=?, next_billing_date=?, canceled_at=?, payment_method=?, payment_link=?, notes=? WHERE id=?', $params);
                $this->syncSubscriptionBadges($db, $id, $badgeIds);
                $current = $db->fetch('SELECT s.*,p.name product FROM subscriptions s JOIN products p ON p.id=s.product_id WHERE s.id=?', [$id]);
                $eventType = (int) $previous['product_id'] !== (int) $current['product_id'] ? 'plan_change' : 'subscription_update';
                $summary = $eventType === 'plan_change'
                    ? 'Plano alterado manualmente de ' . $previous['product'] . ' para ' . $current['product'] . '.'
                    : 'Condições da assinatura atualizadas manualmente.';
                $details = ['previous' => $previous, 'current' => $current];
                $db->insert(
                    'INSERT INTO subscription_events (subscription_id,user_id,event_type,event_date,summary,details) VALUES (?,?,?,?,?,?)',
                    [$id,$_SESSION['auth_user_id'] ?? null,$eventType,date('Y-m-d'),$summary,json_encode($details, JSON_UNESCAPED_UNICODE)]
                );
                audit($db, $eventType, 'subscription', $id, $details);
            });
        } else {
            $this->db->transaction(function (Database $db) use (&$id, $params, $badgeIds): void {
                $id = $db->insert('INSERT INTO subscriptions (client_id, product_id, quantity, currency, unit_price, discount, status, start_date, next_billing_date, canceled_at, payment_method, payment_link, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', $params);
                $this->syncSubscriptionBadges($db, $id, $badgeIds);
                $current = $db->fetch('SELECT s.*,p.name product FROM subscriptions s JOIN products p ON p.id=s.product_id WHERE s.id=?', [$id]);
                $details = ['current' => $current];
                $db->insert(
                    'INSERT INTO subscription_events (subscription_id,user_id,event_type,event_date,summary,details) VALUES (?,?,?,?,?,?)',
                    [$id,$_SESSION['auth_user_id'] ?? null,'subscription_created',date('Y-m-d'),'Assinatura criada.',json_encode($details, JSON_UNESCAPED_UNICODE)]
                );
                audit($db, 'create', 'subscription', $id, $details);
            });
        }
        Flash::add('success', 'Assinatura salva com sucesso.');
        return $this->returnUrl('?page=subscriptions');
    }

    private function saveSubscriptionBadges(): string
    {
        $id = $this->id(true);
        $badgeIds = $this->postedServiceBadgeIds();
        $this->db->transaction(function (Database $db) use ($id, $badgeIds): void {
            if (!$db->fetch('SELECT id FROM subscriptions WHERE id=? FOR UPDATE', [$id])) {
                throw new RuntimeException('A assinatura não existe mais. Atualize a página.');
            }
            $previousBadgeIds = array_map(
                'intval',
                array_column(
                    $db->fetchAll(
                        'SELECT badge_id FROM subscription_service_badges WHERE subscription_id=? ORDER BY badge_id',
                        [$id]
                    ),
                    'badge_id'
                )
            );
            $this->syncSubscriptionBadges($db, $id, $badgeIds);
            $details = ['previous_badge_ids' => $previousBadgeIds, 'badge_ids' => $badgeIds];
            $db->insert(
                'INSERT INTO subscription_events (subscription_id,user_id,event_type,event_date,summary,details) VALUES (?,?,?,?,?,?)',
                [$id,$_SESSION['auth_user_id'] ?? null,'subscription_update',date('Y-m-d'),'Badges de serviços atualizados.',json_encode($details, JSON_UNESCAPED_UNICODE)]
            );
            audit($db, 'update_badges', 'subscription', $id, $details);
        });
        Flash::add('success', 'Badges vinculados à assinatura com sucesso.');
        return $this->returnUrl('?page=subscriptions');
    }

    private function deleteSubscription(): string
    {
        $id = $this->id(true);
        if ((int) $this->db->value('SELECT COUNT(*) FROM payments WHERE subscription_id=?', [$id]) > 0) {
            throw new RuntimeException('Esta assinatura possui pagamentos. Cancele-a em vez de excluir.');
        }
        $this->db->query('DELETE FROM subscriptions WHERE id=?', [$id]);
        audit($this->db, 'delete', 'subscription', $id);
        Flash::add('success', 'Assinatura excluída.');
        return $this->returnUrl('?page=subscriptions');
    }

    private function generateUpcomingPayments(): string
    {
        return '?page=subscriptions&renewals=1';
    }

    private function processSubscriptionRenewals(): string
    {
        $postedRows = $_POST['renewals'] ?? null;
        if (!is_array($postedRows)) {
            throw new RuntimeException('Nenhuma renovação foi enviada para conferência.');
        }

        $rows = [];
        foreach ($postedRows as $subscriptionKey => $posted) {
            if (!is_array($posted) || !isset($posted['selected'])) {
                continue;
            }
            $subscriptionId = (int) $subscriptionKey;
            $currency = (string) ($posted['currency'] ?? '');
            if ($subscriptionId < 1 || !in_array($currency, ['BRL', 'USD'], true)) {
                throw new RuntimeException('Há uma renovação com assinatura ou moeda inválida.');
            }

            $dueDate = trim((string) ($posted['due_date'] ?? ''));
            $receiptDate = trim((string) ($posted['receipt_date'] ?? ''));
            $this->validateDate($dueDate, false, 'O vencimento de uma renovação é inválido.');
            $this->validateDate($receiptDate, false, 'A data de pagamento/resgate deve ser válida.');

            $unitPrice = normalize_decimal($posted['unit_price'] ?? 0);
            $quantity = max(1, (int) ($posted['quantity'] ?? 1));
            $discount = max(0, normalize_decimal($posted['discount'] ?? 0));
            $contractCycleAmount = round(($unitPrice * $quantity) - $discount, 2);
            $renewalMode = in_array(($posted['renewal_mode'] ?? ''), ['months', 'date'], true)
                ? (string) $posted['renewal_mode']
                : 'months';
            if ($renewalMode === 'date') {
                $renewalEndDate = trim((string) ($posted['renewal_end_date'] ?? ''));
                $this->validateDate($renewalEndDate, false, 'Informe uma próxima cobrança válida para a renovação.');
                [$renewalMonths, $renewalDays] = $this->renewalPeriodBetween($receiptDate, $renewalEndDate);
            } else {
                $renewalMonths = (int) ($posted['renewal_months'] ?? 1);
                if ($renewalMonths < 1 || $renewalMonths > 24) {
                    throw new RuntimeException('A renovação automática deve cobrir entre 1 e 24 meses.');
                }
                $renewalDays = 0;
                $renewalEndDate = $this->addCalendarMonths($receiptDate, $renewalMonths);
            }
            $baseAmount = normalize_decimal($posted['base_amount'] ?? 0);
            $paymentDiscount = max(0, normalize_decimal($posted['payment_discount'] ?? 0));
            $surchargeAmount = max(0, normalize_decimal($posted['surcharge_amount'] ?? 0));
            $calculatedFinal = round($baseAmount - $paymentDiscount + $surchargeAmount, 2);
            $received = normalize_decimal($posted['amount'] ?? 0);
            $manualAdjustment = round($received - $calculatedFinal, 2);
            $fee = max(0, normalize_decimal($posted['fee_amount'] ?? 0));
            if ($unitPrice <= 0 || $contractCycleAmount <= 0 || $baseAmount <= 0 || $received <= 0 || $fee > $received) {
                throw new RuntimeException('Revise os valores da renovação. O plano, a base e o valor final recebido devem ser positivos.');
            }

            $rows[] = [
                'subscription_id' => $subscriptionId,
                'subscription_updated_at' => trim((string) ($posted['subscription_updated_at'] ?? '')),
                'pending_payment_id' => max(0, (int) ($posted['pending_payment_id'] ?? 0)),
                'product_id' => max(0, (int) ($posted['product_id'] ?? 0)),
                'currency' => $currency,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'discount' => $discount,
                'renewal_mode' => $renewalMode,
                'renewal_months' => $renewalMonths,
                'renewal_days' => $renewalDays,
                'renewal_start_date' => $receiptDate,
                'renewal_end_date' => $renewalEndDate,
                'base_amount' => $baseAmount,
                'payment_discount' => $paymentDiscount,
                'surcharge_amount' => $surchargeAmount,
                'manual_adjustment_amount' => $manualAdjustment,
                'amount' => $received,
                'fee_amount' => $fee,
                'due_date' => $dueDate,
                'receipt_date' => $receiptDate,
                'payment_method' => mb_substr(trim((string) ($posted['payment_method'] ?? '')), 0, 80),
                'external_reference' => mb_substr(trim((string) ($posted['external_reference'] ?? '')), 0, 120),
                'notes' => trim((string) ($posted['notes'] ?? '')),
            ];
        }

        if (!$rows) {
            throw new RuntimeException('Selecione pelo menos uma assinatura para renovar.');
        }
        if (count($rows) > 100) {
            throw new RuntimeException('Processe no máximo 100 renovações por vez.');
        }

        $quotes = [];
        foreach ($rows as $row) {
            if ($row['currency'] === 'USD' && !isset($quotes[$row['receipt_date']])) {
                $quotes[$row['receipt_date']] = $this->rates->forDate($row['receipt_date']);
            }
        }

        $processed = 0;
        $planChanges = 0;
        $this->db->transaction(function (Database $db) use ($rows, $quotes, &$processed, &$planChanges): void {
            foreach ($rows as $row) {
                $subscription = $db->fetch(
                    "SELECT s.*,p.name product,p.billing_cycle
                     FROM subscriptions s JOIN products p ON p.id=s.product_id
                     WHERE s.id=? FOR UPDATE",
                    [$row['subscription_id']]
                );
                if (!$subscription) {
                    throw new RuntimeException('A assinatura selecionada não existe mais. Atualize a página.');
                }
                if ($row['subscription_updated_at'] !== '' && $subscription['updated_at'] !== $row['subscription_updated_at']) {
                    throw new RuntimeException('Uma assinatura foi alterada depois que a conferência foi aberta. Atualize a página antes de confirmar.');
                }

                $product = $db->fetch('SELECT * FROM products WHERE id=?', [$row['product_id']]);
                if (!$product) {
                    throw new RuntimeException('O plano selecionado em uma renovação não existe mais.');
                }

                $payment = null;
                if ($row['pending_payment_id'] > 0) {
                    $payment = $db->fetch(
                        "SELECT * FROM payments WHERE id=? AND subscription_id=? AND status='pending' FOR UPDATE",
                        [$row['pending_payment_id'], $row['subscription_id']]
                    );
                    if (!$payment) {
                        throw new RuntimeException('Uma cobrança pendente já foi processada por outro usuário. Atualize a página.');
                    }
                } else {
                    $payment = $db->fetch(
                        "SELECT * FROM payments WHERE subscription_id=? AND due_date=? AND status='pending' ORDER BY id LIMIT 1 FOR UPDATE",
                        [$row['subscription_id'], $row['due_date']]
                    );
                }

                $quote = $row['currency'] === 'USD' ? $quotes[$row['receipt_date']] : ['bid' => 1.0, 'source' => 'BRL'];
                $rate = (float) $quote['bid'];
                $amountBrl = round($row['amount'] * $rate, 2);
                $feeBrl = round($row['fee_amount'] * $rate, 2);
                $netBrl = $amountBrl - $feeBrl;
                $periodLabel = $this->renewalPeriodLabel($row['renewal_months'], $row['renewal_days']);
                $description = ($row['renewal_months'] > $this->billingCycleMonths((string) $product['billing_cycle']) || $row['renewal_days'] > 0
                    ? 'Renovação antecipada · '
                    : 'Renovação · ') . $periodLabel . ' · ' . $product['name'];
                $settlementDate = $row['currency'] === 'USD' ? $row['receipt_date'] : null;

                if ($payment) {
                    $paymentId = (int) $payment['id'];
                    $db->query(
                        "UPDATE payments SET client_id=?,description=?,amount=?,base_amount=?,discount_amount=?,surcharge_amount=?,manual_adjustment_amount=?,
                            renewal_mode=?,renewal_months=?,renewal_days=?,renewal_start_date=?,renewal_end_date=?,
                            currency=?,exchange_rate=?,exchange_rate_source=?,amount_brl=?,fee_amount=?,fee_brl=?,net_brl=?,status='paid',
                            due_date=?,payment_date=?,settlement_date=?,payment_method=?,external_reference=?,notes=?
                         WHERE id=? AND status='pending'",
                        [
                            $subscription['client_id'],$description,$row['amount'],$row['base_amount'],$row['payment_discount'],
                            $row['surcharge_amount'],$row['manual_adjustment_amount'],$row['renewal_mode'],$row['renewal_months'],
                            $row['renewal_days'],$row['renewal_start_date'],$row['renewal_end_date'],$row['currency'],$rate,
                            $quote['source'],$amountBrl,$row['fee_amount'],$feeBrl,$netBrl,$row['due_date'],$row['receipt_date'],
                            $settlementDate,$row['payment_method'] ?: null,$row['external_reference'] ?: null,$row['notes'] ?: null,$paymentId,
                        ]
                    );
                } else {
                    $paymentId = $db->insert(
                        "INSERT INTO payments (
                            subscription_id,client_id,description,amount,base_amount,discount_amount,surcharge_amount,manual_adjustment_amount,
                            renewal_mode,renewal_months,renewal_days,renewal_start_date,renewal_end_date,
                            currency,exchange_rate,exchange_rate_source,amount_brl,fee_amount,fee_brl,net_brl,status,
                            due_date,payment_date,settlement_date,payment_method,external_reference,notes
                         ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'paid',?,?,?,?,?,?)",
                        [
                            $row['subscription_id'],$subscription['client_id'],$description,$row['amount'],$row['base_amount'],
                            $row['payment_discount'],$row['surcharge_amount'],$row['manual_adjustment_amount'],$row['renewal_mode'],
                            $row['renewal_months'],$row['renewal_days'],$row['renewal_start_date'],$row['renewal_end_date'],$row['currency'],
                            $rate,$quote['source'],$amountBrl,$row['fee_amount'],$feeBrl,$netBrl,$row['due_date'],$row['receipt_date'],
                            $settlementDate,$row['payment_method'] ?: null,$row['external_reference'] ?: null,$row['notes'] ?: null,
                        ]
                    );
                }

                $eventType = $this->renewSubscription($db, $subscription, $product, $row, $paymentId);
                if ($eventType === 'plan_change') {
                    $planChanges++;
                }
                audit($db, 'receive', 'payment', $paymentId, [
                    'subscription_id' => $row['subscription_id'],
                    'due_date' => $row['due_date'],
                    'payment_date' => $row['receipt_date'],
                    'amount' => $row['amount'],
                    'base_amount' => $row['base_amount'],
                    'discount_amount' => $row['payment_discount'],
                    'surcharge_amount' => $row['surcharge_amount'],
                    'manual_adjustment_amount' => $row['manual_adjustment_amount'],
                    'renewal_months' => $row['renewal_months'],
                    'renewal_days' => $row['renewal_days'],
                    'renewal_end_date' => $row['renewal_end_date'],
                    'currency' => $row['currency'],
                    'exchange_rate' => $rate,
                    'amount_brl' => $amountBrl,
                ]);
                $processed++;
            }
        });

        $message = $processed . ' renovação(ões) confirmada(s), recebida(s) e registrada(s).';
        if ($planChanges > 0) {
            $message .= ' ' . $planChanges . ' alteração(ões) de plano entrou(aram) no histórico.';
        }
        Flash::add('success', $message);
        return '?page=subscriptions';
    }

    private function savePayment(): string
    {
        $id = $this->id();
        $clientId = (int) ($_POST['client_id'] ?? 0);
        if (!$this->db->value('SELECT id FROM clients WHERE id=?', [$clientId])) {
            throw new RuntimeException('Selecione um cliente válido.');
        }
        $subscriptionId = (int) ($_POST['subscription_id'] ?? 0) ?: null;
        if ($subscriptionId && !$this->db->value('SELECT id FROM subscriptions WHERE id=? AND client_id=?', [$subscriptionId, $clientId])) {
            throw new RuntimeException('A assinatura selecionada não pertence ao cliente.');
        }
        $businessUnitId = $this->businessUnitId() ?: (int) $this->db->value('SELECT business_unit_id FROM clients WHERE id=?', [$clientId]) ?: null;
        $categoryId = $this->categoryId();
        $amount = normalize_decimal($_POST['amount'] ?? 0);
        $fee = max(0, normalize_decimal($_POST['fee_amount'] ?? 0));
        if ($amount <= 0 || $fee > $amount) {
            throw new RuntimeException('Informe um valor válido e uma taxa menor que o pagamento.');
        }
        $status = $this->choice('status', ['pending','paid','failed','refunded']);
        $paymentDate = $this->nullable('payment_date');
        if ($status === 'paid' && $paymentDate === null) {
            $paymentDate = date('Y-m-d');
        }
        $settlementDate = $this->nullable('settlement_date');
        $currency = $this->choice('currency', ['BRL','USD']);
        $rate = $currency === 'USD' ? normalize_decimal($_POST['exchange_rate'] ?? 0) : 1.0;
        $rateSource = $currency === 'USD' ? $this->nullable('exchange_rate_source') : 'BRL';
        if ($currency === 'USD' && $status === 'paid' && $settlementDate === null) {
            throw new RuntimeException('Informe a data em que o valor em dólar foi resgatado.');
        }
        if ($currency === 'USD' && $rate <= 0) {
            $quote = $this->rates->forDate($settlementDate ?: $paymentDate ?: date('Y-m-d'));
            $rate = $quote['bid'];
            $rateSource = $quote['source'];
        }
        $rateSource = $rateSource ?: ($currency === 'USD' ? 'Manual' : 'BRL');
        $amountBrl = round($amount * $rate, 2);
        $feeBrl = round($fee * $rate, 2);
        $netBrl = $amountBrl - $feeBrl;
        $params = [
            $businessUnitId, $subscriptionId, $clientId, $categoryId, $this->nullable('description'), $amount, $currency, $rate, $rateSource, $amountBrl, $fee, $feeBrl, $netBrl,
            $status, $this->nullable('due_date'), $paymentDate, $settlementDate,
            $this->nullable('payment_method'), $this->nullable('external_reference'), $this->nullable('notes'),
        ];
        $this->db->transaction(function (Database $db) use (&$id, $params, $amountBrl, $status, $subscriptionId, $paymentDate): void {
            $previousStatus = null;
            if ($id) {
                $previous = $db->fetch('SELECT status FROM payments WHERE id=? FOR UPDATE', [$id]);
                if (!$previous) {
                    throw new RuntimeException('O pagamento não existe mais. Atualize a página.');
                }
                $previousStatus = (string) $previous['status'];
                $updateParams = $params;
                $updateParams[] = $id;
                $db->query('UPDATE payments SET business_unit_id=?, subscription_id=?, client_id=?, category_id=?, description=?, amount=?, currency=?, exchange_rate=?, exchange_rate_source=?, amount_brl=?, fee_amount=?, fee_brl=?, net_brl=?, status=?, due_date=?, payment_date=?, settlement_date=?, payment_method=?, external_reference=?, notes=? WHERE id=?', $updateParams);
                audit($db, 'update', 'payment', $id, ['amount_brl' => $amountBrl]);
            } else {
                $id = $db->insert('INSERT INTO payments (business_unit_id, subscription_id, client_id, category_id, description, amount, currency, exchange_rate, exchange_rate_source, amount_brl, fee_amount, fee_brl, net_brl, status, due_date, payment_date, settlement_date, payment_method, external_reference, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', $params);
                audit($db, 'create', 'payment', $id, ['amount_brl' => $amountBrl]);
            }
            $db->query(
                'UPDATE payments SET manual_adjustment_amount=ROUND(amount-(base_amount-discount_amount+surcharge_amount),2) WHERE id=? AND base_amount IS NOT NULL',
                [$id]
            );
            if ($status === 'paid' && $previousStatus !== 'paid' && $subscriptionId) {
                $savedPayment = $db->fetch('SELECT * FROM payments WHERE id=?', [$id]);
                if ($savedPayment) {
                    $this->renewSubscriptionFromPayment($db, $savedPayment, $paymentDate ?: date('Y-m-d'));
                }
            }
        });
        Flash::add('success', 'Pagamento salvo. Conversão registrada em ' . money($amountBrl) . '.');
        return $this->returnUrl('?page=payments');
    }

    private function markPaymentsPaid(): string
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            is_array($_POST['payment_ids'] ?? null) ? $_POST['payment_ids'] : []
        ), static fn (int $id): bool => $id > 0)));
        if (!$ids) {
            throw new RuntimeException('Selecione pelo menos um pagamento pendente.');
        }
        if (count($ids) > 100) {
            throw new RuntimeException('Confirme no máximo 100 pagamentos por vez.');
        }

        $settlementDate = $this->required('settlement_date', 'Informe a data do recebimento ou resgate.');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $settlementDate);
        $dateErrors = \DateTimeImmutable::getLastErrors();
        if (!$parsed || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count'])) || $settlementDate > date('Y-m-d')) {
            throw new RuntimeException('Informe uma data de recebimento válida, igual ou anterior a hoje.');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $payments = $this->db->fetchAll(
            "SELECT * FROM payments WHERE status='pending' AND id IN ({$placeholders}) ORDER BY id",
            $ids
        );
        if (!$payments) {
            throw new RuntimeException('Os pagamentos selecionados já foram processados ou não existem.');
        }

        $needsUsd = count(array_filter($payments, static fn (array $payment): bool => $payment['currency'] === 'USD')) > 0;
        $usdQuote = $needsUsd ? $this->rates->forDate($settlementDate) : null;
        $updated = 0;
        $renewed = 0;
        $this->db->transaction(function (Database $db) use ($payments, $settlementDate, $usdQuote, &$updated, &$renewed): void {
            foreach ($payments as $payment) {
                $rate = $payment['currency'] === 'USD' ? (float) $usdQuote['bid'] : 1.0;
                $rateSource = $payment['currency'] === 'USD' ? $usdQuote['source'] : 'BRL';
                $amountBrl = round((float) $payment['amount'] * $rate, 2);
                $feeBrl = round((float) $payment['fee_amount'] * $rate, 2);
                $netBrl = $amountBrl - $feeBrl;
                $statement = $db->query(
                    "UPDATE payments SET status='paid', payment_date=COALESCE(payment_date,?), settlement_date=?, exchange_rate=?, exchange_rate_source=?, amount_brl=?, fee_brl=?, net_brl=? WHERE id=? AND status='pending'",
                    [$settlementDate, $payment['currency'] === 'USD' ? $settlementDate : null, $rate, $rateSource, $amountBrl, $feeBrl, $netBrl, $payment['id']]
                );
                if ($statement->rowCount() !== 1) {
                    throw new RuntimeException('Um pagamento selecionado já foi processado. Atualize a página.');
                }
                audit($db, 'receive', 'payment', (int) $payment['id'], ['settlement_date'=>$settlementDate,'amount_brl'=>$amountBrl]);
                if ($this->renewSubscriptionFromPayment($db, $payment, $settlementDate)) {
                    $renewed++;
                }
                $updated++;
            }
        });

        Flash::add('success', $updated . ' pagamento(s) confirmado(s) e ' . $renewed . ' assinatura(s) renovada(s). O dashboard financeiro foi atualizado.');
        return $this->returnUrl('?page=payments&status=paid');
    }

    private function deletePayment(): string
    {
        $id = $this->id(true);
        $this->db->query('DELETE FROM payments WHERE id=?', [$id]);
        audit($this->db, 'delete', 'payment', $id);
        Flash::add('success', 'Pagamento excluído.');
        return $this->returnUrl('?page=payments');
    }

    private function saveExpense(): string
    {
        $id = $this->id();
        $amount = normalize_decimal($_POST['amount'] ?? 0);
        if ($amount <= 0) {
            throw new RuntimeException('Informe um valor maior que zero.');
        }
        $currency = $this->choice('currency', ['BRL','USD']);
        $rate = $currency === 'USD' ? normalize_decimal($_POST['exchange_rate'] ?? 0) : 1.0;
        if ($currency === 'USD' && $rate <= 0) {
            $rate = $this->rates->forDate($this->required('payment_date', 'Informe a data.'))['bid'];
        }
        $businessUnitId = $this->businessUnitId();
        $categoryId = $this->categoryId();
        $categoryName = $this->nullable('category');
        if ($categoryId && empty($categoryName)) {
            $categoryName = (string) $this->db->value('SELECT name FROM categories WHERE id=?', [$categoryId]);
        }
        if (empty($categoryName)) {
            $categoryName = 'Outros';
        }

        $params = [
            $businessUnitId, $categoryId,
            $this->choice('type', ['expense','investment']), $categoryName,
            $this->required('description', 'Informe a descrição.'), $this->nullable('supplier'), $amount, $currency, $rate,
            round($amount * $rate, 2), $this->choice('status', ['pending','paid']),
            $this->required('payment_date', 'Informe a data.'), isset($_POST['is_recurring']) ? 1 : 0, $this->nullable('notes'),
        ];
        if ($id) {
            $params[] = $id;
            $this->db->query('UPDATE expenses SET business_unit_id=?, category_id=?, type=?, category=?, description=?, supplier=?, amount=?, currency=?, exchange_rate=?, amount_brl=?, status=?, payment_date=?, is_recurring=?, notes=? WHERE id=?', $params);
            audit($this->db, 'update', 'expense', $id);
        } else {
            $id = $this->db->insert('INSERT INTO expenses (business_unit_id, category_id, type, category, description, supplier, amount, currency, exchange_rate, amount_brl, status, payment_date, is_recurring, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', $params);
            audit($this->db, 'create', 'expense', $id);
        }
        Flash::add('success', 'Gasto ou investimento salvo.');
        return $this->returnUrl('?page=expenses');
    }

    private function deleteExpense(): string
    {
        $id = $this->id(true);
        $this->db->query('DELETE FROM expenses WHERE id=?', [$id]);
        audit($this->db, 'delete', 'expense', $id);
        Flash::add('success', 'Lançamento excluído.');
        return $this->returnUrl('?page=expenses');
    }

    private function saveCash(): string
    {
        $id = $this->id();
        $amount = normalize_decimal($_POST['amount'] ?? 0);
        if ($amount <= 0) {
            throw new RuntimeException('Informe um valor maior que zero.');
        }
        $currency = $this->choice('currency', ['BRL','USD']);
        $rate = $currency === 'USD' ? normalize_decimal($_POST['exchange_rate'] ?? 0) : 1.0;
        if ($currency === 'USD' && $rate <= 0) {
            $rate = $this->rates->forDate($this->required('entry_date', 'Informe a data.'))['bid'];
        }
        $businessUnitId = $this->businessUnitId();
        $categoryId = $this->categoryId();
        $categoryName = $this->nullable('category');
        if ($categoryId && empty($categoryName)) {
            $categoryName = (string) $this->db->value('SELECT name FROM categories WHERE id=?', [$categoryId]);
        }
        if (empty($categoryName)) {
            $categoryName = 'Ajuste';
        }

        $params = [
            $businessUnitId, $categoryId,
            $this->choice('direction', ['in','out']), $categoryName,
            $this->required('description', 'Informe a descrição.'), $amount, $currency, $rate, round($amount * $rate, 2),
            $this->required('entry_date', 'Informe a data.'), $this->nullable('notes'),
        ];
        if ($id) {
            $params[] = $id;
            $this->db->query('UPDATE cash_entries SET business_unit_id=?, category_id=?, direction=?, category=?, description=?, amount=?, currency=?, exchange_rate=?, amount_brl=?, entry_date=?, notes=? WHERE id=?', $params);
            audit($this->db, 'update', 'cash_entry', $id);
        } else {
            $id = $this->db->insert('INSERT INTO cash_entries (business_unit_id, category_id, direction, category, description, amount, currency, exchange_rate, amount_brl, entry_date, notes) VALUES (?,?,?,?,?,?,?,?,?,?)', $params);
            audit($this->db, 'create', 'cash_entry', $id);
        }
        Flash::add('success', 'Movimentação de caixa salva.');
        return $this->returnUrl('?page=cash');
    }

    private function deleteCash(): string
    {
        $id = $this->id(true);
        $this->db->query('DELETE FROM cash_entries WHERE id=?', [$id]);
        audit($this->db, 'delete', 'cash_entry', $id);
        Flash::add('success', 'Movimentação excluída.');
        return $this->returnUrl('?page=cash');
    }

    private function refreshRate(): string
    {
        $rate = $this->rates->current(true);
        Flash::add('success', 'Cotação diária atualizada: US$ 1 = ' . money($rate['bid']) . '.');
        return $this->returnUrl('?page=dashboard');
    }

    private function saveSettings(): string
    {
        if (!$this->auth->isAdmin()) {
            throw new RuntimeException('Somente administradores podem alterar configurações.');
        }
        $allowed = ['company_name','manual_exchange_rate','exchange_cache_minutes','initial_balance_brl'];
        foreach ($allowed as $key) {
            $value = trim((string) ($_POST[$key] ?? ''));
            if (in_array($key, ['manual_exchange_rate','initial_balance_brl'], true)) {
                $value = (string) normalize_decimal($value);
            }
            if ($key === 'exchange_cache_minutes') {
                $value = (string) max(60, min(1440, (int) $value));
            }
            $this->db->query('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)', [$key, $value]);
        }
        audit($this->db, 'update', 'settings');
        Flash::add('success', 'Configurações salvas.');
        return '?page=settings';
    }

    private function saveWhatsAppReminders(): string
    {
        if (!$this->auth->isAdmin()) {
            throw new RuntimeException('Somente administradores podem configurar os lembretes.');
        }
        $input = $_POST;
        $this->storeReminderUploads($input);
        (new WhatsAppReminderService($this->db))->saveConfig($input);
        audit($this->db, 'update', 'whatsapp_reminders', null, [
            'enabled' => isset($_POST['whatsapp_enabled']),
            'upcoming_enabled' => isset($_POST['whatsapp_upcoming_enabled']),
            'overdue_enabled' => isset($_POST['whatsapp_overdue_enabled']),
            'upcoming_steps' => count((array) ($input['steps']['upcoming'] ?? [])),
            'overdue_steps' => count((array) ($input['steps']['overdue'] ?? [])),
        ]);
        Flash::add('success', 'Automações, etapas e mensagens salvas.');
        return '?page=reminders';
    }

    private function testWhatsAppConnection(): string
    {
        if (!$this->auth->isAdmin()) {
            throw new RuntimeException('Somente administradores podem testar a integração.');
        }
        $status = (new WhatsAppReminderService($this->db))->testConnection();
        if (!$status['connected']) {
            throw new RuntimeException('A instância respondeu, mas o WhatsApp não está conectado. ' . $status['message']);
        }
        Flash::add('success', 'Z-API conectada ao WhatsApp' . ($status['smartphoneConnected'] ? ' e ao telefone.' : '.'));
        return '?page=reminders';
    }

    private function sendWhatsAppTest(): string
    {
        if (!$this->auth->isAdmin()) {
            throw new RuntimeException('Somente administradores podem enviar mensagens de teste.');
        }
        $phone = $this->required('test_phone', 'Informe o telefone que receberá o teste.');
        $country = $this->choice('test_country', ['BR','US']);
        $stepId = max(0, (int) ($_POST['step_id'] ?? 0));
        $response = (new WhatsAppReminderService($this->db))->sendTest($phone, $country, $stepId);
        audit($this->db, 'test', 'whatsapp_reminders', $stepId, [
            'phone_suffix' => substr(preg_replace('/\D+/', '', $phone) ?: '', -4),
            'provider_message_id' => $response['messageId'] ?? $response['id'] ?? null,
        ]);
        Flash::add('success', 'Mensagem de teste enviada pela Z-API.');
        return '?page=reminders';
    }

    private function runWhatsAppReminders(): string
    {
        if (!$this->auth->isAdmin()) {
            throw new RuntimeException('Somente administradores podem executar os lembretes manualmente.');
        }
        $summary = (new WhatsAppReminderService($this->db))->run(true);
        audit($this->db, 'run', 'whatsapp_reminders', null, $summary);
        $message = sprintf(
            'Processamento concluído: %d enviado(s), %d falha(s), %d ignorado(s) e %d duplicado(s) bloqueado(s).',
            $summary['sent'],
            $summary['failed'],
            $summary['skipped'],
            $summary['duplicates']
        );
        if (!empty($summary['limit_reached'])) {
            $message .= ' O limite diário foi alcançado.';
        }
        Flash::add($summary['failed'] > 0 ? 'warning' : 'success', $message);
        return '?page=reminders';
    }

    private function retryWhatsAppReminder(): string
    {
        if (!$this->auth->isAdmin()) {
            throw new RuntimeException('Somente administradores podem reenviar lembretes.');
        }
        $id = $this->id(true);
        $summary = (new WhatsAppReminderService($this->db))->retry($id);
        audit($this->db, 'retry', 'whatsapp_reminder', $id, $summary);
        if ($summary['sent'] > 0) {
            Flash::add('success', 'Lembrete reenviado com sucesso.');
        } else {
            Flash::add('warning', 'O reenvio foi processado, mas a Z-API não confirmou o envio.');
        }
        return '?page=reminders';
    }

    private function saveProfile(): string
    {
        $user = $this->auth->user();
        $name = $this->required('name', 'Informe seu nome.');
        $email = mb_strtolower($this->required('email', 'Informe seu e-mail.'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Informe um e-mail válido.');
        }
        $params = [$name, $email];
        $sql = 'UPDATE users SET name=?, email=?';
        $password = (string) ($_POST['password'] ?? '');
        if ($password !== '') {
            if (strlen($password) < 8) {
                throw new RuntimeException('A nova senha precisa ter pelo menos 8 caracteres.');
            }
            $sql .= ', password_hash=?';
            $params[] = password_hash($password, PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE id=?';
        $params[] = $user['id'];
        $this->db->query($sql, $params);
        Flash::add('success', 'Perfil atualizado.');
        return '?page=settings';
    }

    private function saveUser(): string
    {
        if (!$this->auth->isAdmin()) {
            throw new RuntimeException('Somente administradores podem gerenciar usuários.');
        }
        $name = $this->required('name', 'Informe o nome.');
        $email = mb_strtolower($this->required('email', 'Informe o e-mail.'));
        $password = (string) ($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            throw new RuntimeException('Informe e-mail válido e senha com ao menos 8 caracteres.');
        }
        $id = $this->db->insert('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)', [$name, $email, password_hash($password, PASSWORD_DEFAULT), $this->choice('role', ['admin','manager','viewer'])]);
        audit($this->db, 'create', 'user', $id);
        Flash::add('success', 'Usuário criado.');
        return '?page=settings';
    }

    private function toggleUser(): string
    {
        if (!$this->auth->isAdmin()) {
            throw new RuntimeException('Somente administradores podem gerenciar usuários.');
        }
        $id = $this->id(true);
        if ($id === (int) $this->auth->user()['id']) {
            throw new RuntimeException('Você não pode desativar o próprio acesso.');
        }
        $this->db->query('UPDATE users SET active=IF(active=1,0,1) WHERE id=?', [$id]);
        audit($this->db, 'toggle', 'user', $id);
        Flash::add('success', 'Acesso do usuário atualizado.');
        return '?page=settings';
    }

    private function renewSubscription(Database $db, array $subscription, array $product, array $row, int $paymentId): string
    {
        $planChanged = (int) $subscription['product_id'] !== (int) $product['id'];
        $termsChanged = $planChanged
            || (int) $subscription['quantity'] !== (int) $row['quantity']
            || $subscription['currency'] !== $row['currency']
            || abs((float) $subscription['unit_price'] - (float) $row['unit_price']) > 0.009
            || abs((float) $subscription['discount'] - (float) $row['discount']) > 0.009
            || trim((string) $subscription['payment_method']) !== trim((string) $row['payment_method']);

        $nextBillingDate = (string) $row['renewal_end_date'];

        $before = [
            'product_id' => (int) $subscription['product_id'],
            'product' => $subscription['product'],
            'billing_cycle' => $subscription['billing_cycle'],
            'quantity' => (int) $subscription['quantity'],
            'currency' => $subscription['currency'],
            'unit_price' => (float) $subscription['unit_price'],
            'discount' => (float) $subscription['discount'],
            'payment_method' => $subscription['payment_method'],
            'next_billing_date' => $subscription['next_billing_date'],
            'status' => $subscription['status'],
        ];
        $after = [
            'product_id' => (int) $product['id'],
            'product' => $product['name'],
            'billing_cycle' => $product['billing_cycle'],
            'quantity' => (int) $row['quantity'],
            'currency' => $row['currency'],
            'unit_price' => (float) $row['unit_price'],
            'discount' => (float) $row['discount'],
            'payment_method' => $row['payment_method'] ?: null,
            'next_billing_date' => $nextBillingDate,
            'status' => 'active',
        ];

        $db->query(
            "UPDATE subscriptions SET product_id=?,quantity=?,currency=?,unit_price=?,discount=?,payment_method=?,status='active',next_billing_date=?,canceled_at=NULL WHERE id=?",
            [$after['product_id'],$after['quantity'],$after['currency'],$after['unit_price'],$after['discount'],$after['payment_method'],$nextBillingDate,$subscription['id']]
        );

        $eventType = $planChanged ? 'plan_change' : ($termsChanged ? 'renewal_adjusted' : 'renewal');
        $periodLabel = $this->renewalPeriodLabel((int) $row['renewal_months'], (int) $row['renewal_days']);
        $summary = $planChanged
            ? 'Plano alterado de ' . $subscription['product'] . ' para ' . $product['name'] . ' durante a renovação de ' . $periodLabel . '.'
            : ($termsChanged
                ? 'Assinatura renovada por ' . $periodLabel . ' com ajustes nas condições comerciais.'
                : 'Assinatura renovada por ' . $periodLabel . ' após a confirmação do pagamento.');
        $details = [
            'payment_id' => $paymentId,
            'due_date' => $row['due_date'],
            'payment_date' => $row['receipt_date'],
            'renewal_mode' => $row['renewal_mode'],
            'renewal_months' => (int) $row['renewal_months'],
            'renewal_days' => (int) $row['renewal_days'],
            'renewal_start_date' => $row['renewal_start_date'],
            'renewal_end_date' => $row['renewal_end_date'],
            'base_amount' => (float) $row['base_amount'],
            'discount_amount' => (float) $row['payment_discount'],
            'surcharge_amount' => (float) $row['surcharge_amount'],
            'manual_adjustment_amount' => (float) $row['manual_adjustment_amount'],
            'amount' => (float) $row['amount'],
            'currency' => $row['currency'],
            'previous' => $before,
            'current' => $after,
        ];
        $db->insert(
            'INSERT INTO subscription_events (subscription_id,payment_id,user_id,event_type,event_date,summary,details) VALUES (?,?,?,?,?,?,?)',
            [(int) $subscription['id'],$paymentId,$_SESSION['auth_user_id'] ?? null,$eventType,$row['receipt_date'],$summary,json_encode($details, JSON_UNESCAPED_UNICODE)]
        );
        audit($db, $eventType, 'subscription', (int) $subscription['id'], $details);

        return $eventType;
    }

    private function renewSubscriptionFromPayment(Database $db, array $payment, string $receiptDate): bool
    {
        $subscriptionId = (int) ($payment['subscription_id'] ?? 0);
        if ($subscriptionId < 1) {
            return false;
        }
        $subscription = $db->fetch(
            'SELECT s.*,p.name product,p.billing_cycle FROM subscriptions s JOIN products p ON p.id=s.product_id WHERE s.id=? FOR UPDATE',
            [$subscriptionId]
        );
        if (!$subscription || in_array($subscription['status'], ['paused', 'canceled'], true)) {
            return false;
        }
        $product = $db->fetch('SELECT * FROM products WHERE id=?', [$subscription['product_id']]);
        if (!$product) {
            return false;
        }
        $dueDate = $payment['due_date'] ?: $subscription['next_billing_date'] ?: $receiptDate;
        $renewalMode = in_array(($payment['renewal_mode'] ?? ''), ['months', 'date'], true)
            ? (string) $payment['renewal_mode']
            : 'months';
        $renewalMonths = max(1, min(24, (int) ($payment['renewal_months'] ?? $this->billingCycleMonths((string) $product['billing_cycle']))));
        $renewalDays = max(0, (int) ($payment['renewal_days'] ?? 0));
        $renewalEndDate = (string) ($payment['renewal_end_date'] ?? '');
        if ($renewalMode !== 'date' || $renewalEndDate === '' || $renewalEndDate <= $receiptDate) {
            $renewalMode = 'months';
            $renewalDays = 0;
            $renewalEndDate = $this->addCalendarMonths($receiptDate, $renewalMonths);
        } else {
            [$renewalMonths, $renewalDays] = $this->renewalPeriodBetween($receiptDate, $renewalEndDate);
        }
        $baseAmount = ($payment['base_amount'] ?? null) !== null
            ? (float) $payment['base_amount']
            : (float) $payment['amount'];
        $paymentDiscount = (float) ($payment['discount_amount'] ?? 0);
        $surchargeAmount = (float) ($payment['surcharge_amount'] ?? 0);
        $manualAdjustment = (float) ($payment['manual_adjustment_amount']
            ?? round((float) $payment['amount'] - ($baseAmount - $paymentDiscount + $surchargeAmount), 2));
        $row = [
            'quantity' => (int) $subscription['quantity'],
            'currency' => $subscription['currency'],
            'unit_price' => (float) $subscription['unit_price'],
            'discount' => (float) $subscription['discount'],
            'renewal_mode' => $renewalMode,
            'renewal_months' => $renewalMonths,
            'renewal_days' => $renewalDays,
            'renewal_start_date' => $receiptDate,
            'renewal_end_date' => $renewalEndDate,
            'base_amount' => $baseAmount,
            'payment_discount' => $paymentDiscount,
            'surcharge_amount' => $surchargeAmount,
            'manual_adjustment_amount' => $manualAdjustment,
            'payment_method' => $payment['payment_method'] ?: $subscription['payment_method'],
            'due_date' => $dueDate,
            'receipt_date' => $receiptDate,
            'amount' => (float) $payment['amount'],
        ];
        $db->query(
            'UPDATE payments SET base_amount=?,discount_amount=?,surcharge_amount=?,manual_adjustment_amount=?,
                renewal_mode=?,renewal_months=?,renewal_days=?,renewal_start_date=?,renewal_end_date=? WHERE id=?',
            [
                $row['base_amount'],$row['payment_discount'],$row['surcharge_amount'],$row['manual_adjustment_amount'],
                $row['renewal_mode'],$row['renewal_months'],$row['renewal_days'],$row['renewal_start_date'],
                $row['renewal_end_date'],$payment['id'],
            ]
        );
        $this->renewSubscription($db, $subscription, $product, $row, (int) $payment['id']);
        return true;
    }

    private function postedServiceBadgeIds(): array
    {
        $posted = $_POST['badge_ids'] ?? [];
        if (!is_array($posted)) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $posted),
            static fn(int $id): bool => $id > 0
        )));
        if (count($ids) > 30) {
            throw new RuntimeException('Selecione no máximo 30 badges por assinatura.');
        }
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $existing = (int) $this->db->value(
                "SELECT COUNT(*) FROM service_badges WHERE id IN ({$placeholders})",
                $ids
            );
            if ($existing !== count($ids)) {
                throw new RuntimeException('Um dos badges selecionados não existe mais.');
            }
        }
        return $ids;
    }

    private function storeReminderUploads(array &$input): void
    {
        $uploads = $_FILES['step_image_file'] ?? null;
        if (!is_array($uploads) || !isset($uploads['tmp_name']) || !is_array($uploads['tmp_name'])) {
            return;
        }
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        $root = dirname(__DIR__, 2);
        $directory = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'reminders';

        foreach ($uploads['tmp_name'] as $type => $files) {
            if (!in_array($type, ['upcoming','overdue'], true) || !is_array($files)) {
                continue;
            }
            foreach ($files as $key => $temporaryPath) {
                $error = (int) ($uploads['error'][$type][$key] ?? UPLOAD_ERR_NO_FILE);
                if ($error === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) $temporaryPath)) {
                    throw new RuntimeException('Não foi possível receber uma das imagens.');
                }
                $size = (int) ($uploads['size'][$type][$key] ?? 0);
                if ($size < 1 || $size > 6 * 1024 * 1024) {
                    throw new RuntimeException('Cada imagem deve ter no máximo 6 MB.');
                }
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $temporaryPath);
                if (!isset($allowed[$mime])) {
                    throw new RuntimeException('Envie imagens JPG, PNG ou WebP.');
                }
                if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                    throw new RuntimeException('Não foi possível preparar o armazenamento das imagens.');
                }
                $filename = date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
                $target = $directory . DIRECTORY_SEPARATOR . $filename;
                if (!move_uploaded_file((string) $temporaryPath, $target)) {
                    throw new RuntimeException('Não foi possível salvar uma das imagens.');
                }
                if (isset($input['steps'][$type][$key]) && is_array($input['steps'][$type][$key])) {
                    $input['steps'][$type][$key]['uploaded_image'] = 'storage/reminders/' . $filename;
                }
            }
        }
    }

    private function syncSubscriptionBadges(Database $db, int $subscriptionId, array $badgeIds): void
    {
        $db->query('DELETE FROM subscription_service_badges WHERE subscription_id=?', [$subscriptionId]);
        foreach ($badgeIds as $badgeId) {
            $db->query(
                'INSERT INTO subscription_service_badges (subscription_id,badge_id) VALUES (?,?)',
                [$subscriptionId, $badgeId]
            );
        }
    }

    private function billingCycleMonths(string $cycle): int
    {
        return ['monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'annual' => 12][$cycle] ?? 1;
    }

    private function addCalendarMonths(string $date, int $months): string
    {
        $months = max(1, min(24, $months));
        $source = new \DateTimeImmutable($date);
        $day = (int) $source->format('d');
        $target = $source->modify('first day of this month')->modify('+' . $months . ' months');
        $targetDay = min($day, (int) $target->format('t'));
        return $target->setDate((int) $target->format('Y'), (int) $target->format('m'), $targetDay)->format('Y-m-d');
    }

    private function addBillingMonths(string $date, string $cycle): string
    {
        return $this->addCalendarMonths($date, $this->billingCycleMonths($cycle));
    }

    private function renewalPeriodBetween(string $startDate, string $endDate): array
    {
        $start = new \DateTimeImmutable($startDate);
        $end = new \DateTimeImmutable($endDate);
        if ($end <= $start) {
            throw new RuntimeException('A próxima cobrança deve ser posterior à data do pagamento.');
        }
        if ($end > new \DateTimeImmutable($this->addCalendarMonths($startDate, 24))) {
            throw new RuntimeException('O período máximo de uma renovação é de 24 meses.');
        }

        $months = 0;
        for ($candidate = 1; $candidate <= 24; $candidate++) {
            if ($this->addCalendarMonths($startDate, $candidate) > $endDate) {
                break;
            }
            $months = $candidate;
        }
        $anchor = new \DateTimeImmutable($months > 0 ? $this->addCalendarMonths($startDate, $months) : $startDate);
        $days = (int) $anchor->diff($end)->days;
        return [$months, $days];
    }

    private function renewalPeriodLabel(int $months, int $days): string
    {
        $parts = [];
        if ($months > 0) {
            $parts[] = $months . ' ' . ($months === 1 ? 'mês' : 'meses');
        }
        if ($days > 0) {
            $parts[] = $days . ' ' . ($days === 1 ? 'dia' : 'dias');
        }
        return $parts ? implode(' e ', $parts) : '1 mês';
    }

    private function validateDate(string $value, bool $notFuture, string $message): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        $invalid = !$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0));
        if ($invalid || ($notFuture && $value > date('Y-m-d'))) {
            throw new RuntimeException($message);
        }
    }

    private function id(bool $required = false): ?int
    {
        $id = (int) ($_POST['id'] ?? 0);
        if ($required && $id < 1) {
            throw new RuntimeException('Registro inválido.');
        }
        return $id > 0 ? $id : null;
    }

    private function businessUnitId(): ?int
    {
        $id = (int) ($_POST['business_unit_id'] ?? 0);
        if ($id > 0) {
            return $id;
        }
        $returnUrl = (string) ($_POST['_return'] ?? '');
        if (preg_match('/[?&]bu=(\d+)/', $returnUrl, $matches)) {
            return (int) $matches[1];
        }
        if (isset($_GET['bu']) && (int) $_GET['bu'] > 0) {
            return (int) $_GET['bu'];
        }
        return null;
    }

    private function categoryId(): ?int
    {
        $id = (int) ($_POST['category_id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    private function required(string $field, string $message): string
    {
        $value = trim((string) ($_POST[$field] ?? ''));
        if ($value === '') {
            throw new RuntimeException($message);
        }
        return $value;
    }

    private function nullable(string $field): ?string
    {
        $value = trim((string) ($_POST[$field] ?? ''));
        return $value === '' ? null : $value;
    }

    private function choice(string $field, array $allowed): string
    {
        $value = (string) ($_POST[$field] ?? '');
        if (!in_array($value, $allowed, true)) {
            throw new RuntimeException('Valor inválido no campo ' . $field . '.');
        }
        return $value;
    }

    private function returnUrl(string $fallback): string
    {
        $url = trim((string) ($_POST['_return'] ?? ''));
        $isQuery = str_starts_with($url, '?');
        $isLocalPath = str_starts_with($url, '/') && !str_starts_with($url, '//');
        if (($isQuery || $isLocalPath) && !str_contains($url, "\r") && !str_contains($url, "\n")) {
            $cleanUrl = preg_replace('/([?&])(new|new_sub|edit|parent|renewal|renewals|badges)(=\d*)?(&|$)/', '$1', $url);
            $cleanUrl = rtrim($cleanUrl, '?&');
            if ($cleanUrl !== '' && (str_starts_with($cleanUrl, '?') || str_starts_with($cleanUrl, '/'))) {
                return $cleanUrl;
            }
        }

        return $fallback;
    }
}
