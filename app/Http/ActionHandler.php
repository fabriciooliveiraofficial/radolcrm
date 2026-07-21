<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Services\ExchangeRateService;
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
                'save_client' => $this->saveClient(),
                'delete_client' => $this->deleteClient(),
                'save_product' => $this->saveProduct(),
                'delete_product' => $this->deleteProduct(),
                'save_subscription' => $this->saveSubscription(),
                'delete_subscription' => $this->deleteSubscription(),
                'generate_due_payments', 'generate_upcoming_payments' => $this->generateUpcomingPayments(),
                'save_payment' => $this->savePayment(),
                'mark_payments_paid' => $this->markPaymentsPaid(),
                'delete_payment' => $this->deletePayment(),
                'save_expense' => $this->saveExpense(),
                'delete_expense' => $this->deleteExpense(),
                'save_cash' => $this->saveCash(),
                'delete_cash' => $this->deleteCash(),
                'refresh_rate' => $this->refreshRate(),
                'save_settings' => $this->saveSettings(),
                'save_profile' => $this->saveProfile(),
                'save_user' => $this->saveUser(),
                'toggle_user' => $this->toggleUser(),
                default => throw new RuntimeException('Ação desconhecida.'),
            };
        } catch (\Throwable $exception) {
            Flash::add('danger', $exception->getMessage());
            $redirect = $this->returnUrl($redirect);
        }

        header('Location: ' . $redirect);
        exit;
    }

    private function saveClient(): string
    {
        $id = $this->id();
        $name = $this->required('name', 'Informe o nome do cliente.');
        $country = $this->choice('country', ['BR', 'US']);
        $currency = $this->choice('preferred_currency', ['BRL', 'USD']);
        $status = $this->choice('status', ['lead', 'active', 'inactive']);
        $email = $this->nullable('email');
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Informe um e-mail válido.');
        }
        $params = [$name, $this->nullable('company'), $email, $this->nullable('phone'), $this->nullable('document'), $country, $currency, $status, $this->nullable('notes')];
        if ($id) {
            $params[] = $id;
            $this->db->query('UPDATE clients SET name=?, company=?, email=?, phone=?, document=?, country=?, preferred_currency=?, status=?, notes=? WHERE id=?', $params);
            audit($this->db, 'update', 'client', $id);
        } else {
            $id = $this->db->insert('INSERT INTO clients (name, company, email, phone, document, country, preferred_currency, status, notes) VALUES (?,?,?,?,?,?,?,?,?)', $params);
            audit($this->db, 'create', 'client', $id);
        }
        Flash::add('success', 'Cliente salvo com sucesso.');
        return '?page=clients';
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
        return '?page=clients';
    }

    private function saveProduct(): string
    {
        $id = $this->id();
        $params = [
            $this->required('name', 'Informe o nome do produto.'), $this->nullable('sku'), $this->nullable('description'),
            normalize_decimal($_POST['price_brl'] ?? 0), normalize_decimal($_POST['price_usd'] ?? 0),
            $this->choice('billing_cycle', ['monthly','quarterly','semiannual','annual']), isset($_POST['active']) ? 1 : 0,
        ];
        if ($id) {
            $params[] = $id;
            $this->db->query('UPDATE products SET name=?, sku=?, description=?, price_brl=?, price_usd=?, billing_cycle=?, active=? WHERE id=?', $params);
            audit($this->db, 'update', 'product', $id);
        } else {
            $id = $this->db->insert('INSERT INTO products (name, sku, description, price_brl, price_usd, billing_cycle, active) VALUES (?,?,?,?,?,?,?)', $params);
            audit($this->db, 'create', 'product', $id);
        }
        Flash::add('success', 'Produto salvo com sucesso.');
        return '?page=products';
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
        return '?page=products';
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
        $canceledAt = $status === 'canceled' ? ($this->nullable('canceled_at') ?: date('Y-m-d')) : null;
        $params = [
            $clientId, $productId, max(1, (int) ($_POST['quantity'] ?? 1)), $this->choice('currency', ['BRL','USD']),
            normalize_decimal($_POST['unit_price'] ?? 0), max(0, normalize_decimal($_POST['discount'] ?? 0)), $status,
            $this->required('start_date', 'Informe a data de início.'), $this->nullable('next_billing_date'), $canceledAt,
            $this->nullable('payment_method'), $this->nullable('notes'),
        ];
        if ($id) {
            $params[] = $id;
            $this->db->query('UPDATE subscriptions SET client_id=?, product_id=?, quantity=?, currency=?, unit_price=?, discount=?, status=?, start_date=?, next_billing_date=?, canceled_at=?, payment_method=?, notes=? WHERE id=?', $params);
            audit($this->db, 'update', 'subscription', $id);
        } else {
            $id = $this->db->insert('INSERT INTO subscriptions (client_id, product_id, quantity, currency, unit_price, discount, status, start_date, next_billing_date, canceled_at, payment_method, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', $params);
            audit($this->db, 'create', 'subscription', $id);
        }
        Flash::add('success', 'Assinatura salva com sucesso.');
        return '?page=subscriptions';
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
        return '?page=subscriptions';
    }

    private function generateUpcomingPayments(): string
    {
        $cutoff = (new \DateTimeImmutable('today'))->modify('+45 days')->format('Y-m-d');
        $subscriptions = $this->db->fetchAll(
            "SELECT s.*,p.name product,p.billing_cycle,c.name client
             FROM subscriptions s JOIN products p ON p.id=s.product_id JOIN clients c ON c.id=s.client_id
             WHERE s.status IN ('active','trial','past_due') AND s.next_billing_date IS NOT NULL AND s.next_billing_date <= ?
             ORDER BY s.next_billing_date LIMIT 500",
            [$cutoff]
        );
        if (!$subscriptions) {
            Flash::add('success', 'Não há cobranças previstas nos próximos 45 dias.');
            return '?page=subscriptions';
        }

        $usdRate = $this->rates->current()['bid'];
        $created = 0;
        $this->db->transaction(function (Database $db) use ($subscriptions, $usdRate, $cutoff, &$created): void {
            foreach ($subscriptions as $subscription) {
                $dueDate = new \DateTimeImmutable($subscription['next_billing_date']);
                $cycles = 0;
                $months = ['monthly'=>1,'quarterly'=>3,'semiannual'=>6,'annual'=>12][$subscription['billing_cycle']] ?? 1;
                while ($dueDate->format('Y-m-d') <= $cutoff && $cycles < 24) {
                    $exists = (int) $db->value(
                        'SELECT COUNT(*) FROM payments WHERE subscription_id=? AND due_date=?',
                        [$subscription['id'], $dueDate->format('Y-m-d')]
                    );
                    if ($exists === 0) {
                        $amount = max(0, ((float) $subscription['unit_price'] * (int) $subscription['quantity']) - (float) $subscription['discount']);
                        $rate = $subscription['currency'] === 'USD' ? $usdRate : 1.0;
                        $amountBrl = round($amount * $rate, 2);
                        $paymentId = $db->insert(
                            'INSERT INTO payments (subscription_id,client_id,description,amount,currency,exchange_rate,amount_brl,fee_amount,fee_brl,net_brl,status,due_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                            [$subscription['id'],$subscription['client_id'],'Cobrança · '.$subscription['product'],$amount,$subscription['currency'],$rate,$amountBrl,0,0,$amountBrl,'pending',$dueDate->format('Y-m-d')]
                        );
                        audit($db, 'generate', 'payment', $paymentId, ['subscription_id'=>(int)$subscription['id']]);
                        $created++;
                    }
                    $dueDate = $dueDate->modify('+' . $months . ' months');
                    $cycles++;
                }
                $db->query('UPDATE subscriptions SET next_billing_date=? WHERE id=?', [$dueDate->format('Y-m-d'),$subscription['id']]);
            }
        });

        Flash::add('success', $created . ' cobrança(s) gerada(s). Agora confirme os recebimentos no módulo Pagamentos.');
        return '?page=payments&status=pending';
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
            $subscriptionId, $clientId, $this->nullable('description'), $amount, $currency, $rate, $rateSource, $amountBrl, $fee, $feeBrl, $netBrl,
            $status, $this->nullable('due_date'), $paymentDate, $settlementDate,
            $this->nullable('payment_method'), $this->nullable('external_reference'), $this->nullable('notes'),
        ];
        if ($id) {
            $params[] = $id;
            $this->db->query('UPDATE payments SET subscription_id=?, client_id=?, description=?, amount=?, currency=?, exchange_rate=?, exchange_rate_source=?, amount_brl=?, fee_amount=?, fee_brl=?, net_brl=?, status=?, due_date=?, payment_date=?, settlement_date=?, payment_method=?, external_reference=?, notes=? WHERE id=?', $params);
            audit($this->db, 'update', 'payment', $id, ['amount_brl' => $amountBrl]);
        } else {
            $id = $this->db->insert('INSERT INTO payments (subscription_id, client_id, description, amount, currency, exchange_rate, exchange_rate_source, amount_brl, fee_amount, fee_brl, net_brl, status, due_date, payment_date, settlement_date, payment_method, external_reference, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', $params);
            audit($this->db, 'create', 'payment', $id, ['amount_brl' => $amountBrl]);
        }
        Flash::add('success', 'Pagamento salvo. Conversão registrada em ' . money($amountBrl) . '.');
        return '?page=payments';
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
        $this->db->transaction(function (Database $db) use ($payments, $settlementDate, $usdQuote, &$updated): void {
            foreach ($payments as $payment) {
                $rate = $payment['currency'] === 'USD' ? (float) $usdQuote['bid'] : 1.0;
                $rateSource = $payment['currency'] === 'USD' ? $usdQuote['source'] : 'BRL';
                $amountBrl = round((float) $payment['amount'] * $rate, 2);
                $feeBrl = round((float) $payment['fee_amount'] * $rate, 2);
                $netBrl = $amountBrl - $feeBrl;
                $db->query(
                    "UPDATE payments SET status='paid', payment_date=COALESCE(payment_date,?), settlement_date=?, exchange_rate=?, exchange_rate_source=?, amount_brl=?, fee_brl=?, net_brl=? WHERE id=? AND status='pending'",
                    [$settlementDate, $payment['currency'] === 'USD' ? $settlementDate : null, $rate, $rateSource, $amountBrl, $feeBrl, $netBrl, $payment['id']]
                );
                audit($db, 'receive', 'payment', (int) $payment['id'], ['settlement_date'=>$settlementDate,'amount_brl'=>$amountBrl]);
                $updated++;
            }
        });

        Flash::add('success', $updated . ' pagamento(s) confirmado(s). O dashboard financeiro foi atualizado.');
        return '?page=payments&status=paid';
    }

    private function deletePayment(): string
    {
        $id = $this->id(true);
        $this->db->query('DELETE FROM payments WHERE id=?', [$id]);
        audit($this->db, 'delete', 'payment', $id);
        Flash::add('success', 'Pagamento excluído.');
        return '?page=payments';
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
        $params = [
            $this->choice('type', ['expense','investment']), $this->required('category', 'Informe a categoria.'),
            $this->required('description', 'Informe a descrição.'), $this->nullable('supplier'), $amount, $currency, $rate,
            round($amount * $rate, 2), $this->choice('status', ['pending','paid']),
            $this->required('payment_date', 'Informe a data.'), isset($_POST['is_recurring']) ? 1 : 0, $this->nullable('notes'),
        ];
        if ($id) {
            $params[] = $id;
            $this->db->query('UPDATE expenses SET type=?, category=?, description=?, supplier=?, amount=?, currency=?, exchange_rate=?, amount_brl=?, status=?, payment_date=?, is_recurring=?, notes=? WHERE id=?', $params);
            audit($this->db, 'update', 'expense', $id);
        } else {
            $id = $this->db->insert('INSERT INTO expenses (type, category, description, supplier, amount, currency, exchange_rate, amount_brl, status, payment_date, is_recurring, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', $params);
            audit($this->db, 'create', 'expense', $id);
        }
        Flash::add('success', 'Gasto ou investimento salvo.');
        return '?page=expenses';
    }

    private function deleteExpense(): string
    {
        $id = $this->id(true);
        $this->db->query('DELETE FROM expenses WHERE id=?', [$id]);
        audit($this->db, 'delete', 'expense', $id);
        Flash::add('success', 'Lançamento excluído.');
        return '?page=expenses';
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
        $params = [
            $this->choice('direction', ['in','out']), $this->required('category', 'Informe a categoria.'),
            $this->required('description', 'Informe a descrição.'), $amount, $currency, $rate, round($amount * $rate, 2),
            $this->required('entry_date', 'Informe a data.'), $this->nullable('notes'),
        ];
        if ($id) {
            $params[] = $id;
            $this->db->query('UPDATE cash_entries SET direction=?, category=?, description=?, amount=?, currency=?, exchange_rate=?, amount_brl=?, entry_date=?, notes=? WHERE id=?', $params);
            audit($this->db, 'update', 'cash_entry', $id);
        } else {
            $id = $this->db->insert('INSERT INTO cash_entries (direction, category, description, amount, currency, exchange_rate, amount_brl, entry_date, notes) VALUES (?,?,?,?,?,?,?,?,?)', $params);
            audit($this->db, 'create', 'cash_entry', $id);
        }
        Flash::add('success', 'Movimentação de caixa salva.');
        return '?page=cash';
    }

    private function deleteCash(): string
    {
        $id = $this->id(true);
        $this->db->query('DELETE FROM cash_entries WHERE id=?', [$id]);
        audit($this->db, 'delete', 'cash_entry', $id);
        Flash::add('success', 'Movimentação excluída.');
        return '?page=cash';
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

    private function id(bool $required = false): ?int
    {
        $id = (int) ($_POST['id'] ?? 0);
        if ($required && $id < 1) {
            throw new RuntimeException('Registro inválido.');
        }
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
            return $url;
        }

        return $fallback;
    }
}
