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
            $this->required('name', 'Informe o nome do produto.'), $this->nullable('sku'), $this->nullable('description'),
            $priceBrl, $priceUsd, $pricingMode, $quote['bid'] ?? null, $quote['source'] ?? null,
            isset($quote['quoted_at']) ? substr((string) $quote['quoted_at'], 0, 10) : null,
            $this->choice('billing_cycle', ['monthly','quarterly','semiannual','annual']), isset($_POST['active']) ? 1 : 0,
        ];
        if ($id) {
            $params[] = $id;
            $this->db->query('UPDATE products SET name=?, sku=?, description=?, price_brl=?, price_usd=?, pricing_mode=?, price_exchange_rate=?, price_rate_source=?, price_rate_date=?, billing_cycle=?, active=? WHERE id=?', $params);
            audit($this->db, 'update', 'product', $id, ['pricing_mode'=>$pricingMode,'price_brl'=>$priceBrl,'price_usd'=>$priceUsd,'exchange_rate'=>$quote['bid'] ?? null]);
        } else {
            $id = $this->db->insert('INSERT INTO products (name, sku, description, price_brl, price_usd, pricing_mode, price_exchange_rate, price_rate_source, price_rate_date, billing_cycle, active) VALUES (?,?,?,?,?,?,?,?,?,?,?)', $params);
            audit($this->db, 'create', 'product', $id, ['pricing_mode'=>$pricingMode,'price_brl'=>$priceBrl,'price_usd'=>$priceUsd,'exchange_rate'=>$quote['bid'] ?? null]);
        }
        Flash::add('success', $pricingMode === 'manual' ? 'Produto salvo com preços locais.' : 'Produto salvo e convertido pela cotação diária.');
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
            $this->db->transaction(function (Database $db) use ($id, $params): void {
                $previous = $db->fetch('SELECT s.*,p.name product FROM subscriptions s JOIN products p ON p.id=s.product_id WHERE s.id=? FOR UPDATE', [$id]);
                if (!$previous) {
                    throw new RuntimeException('A assinatura não existe mais. Atualize a página.');
                }
                $db->query('UPDATE subscriptions SET client_id=?, product_id=?, quantity=?, currency=?, unit_price=?, discount=?, status=?, start_date=?, next_billing_date=?, canceled_at=?, payment_method=?, notes=? WHERE id=?', $params);
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
            $this->db->transaction(function (Database $db) use (&$id, $params): void {
                $id = $db->insert('INSERT INTO subscriptions (client_id, product_id, quantity, currency, unit_price, discount, status, start_date, next_billing_date, canceled_at, payment_method, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', $params);
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
            $this->validateDate($receiptDate, true, 'A data de pagamento/resgate deve ser válida e não pode estar no futuro.');

            $unitPrice = normalize_decimal($posted['unit_price'] ?? 0);
            $quantity = max(1, (int) ($posted['quantity'] ?? 1));
            $discount = max(0, normalize_decimal($posted['discount'] ?? 0));
            $expected = round(($unitPrice * $quantity) - $discount, 2);
            $received = normalize_decimal($posted['amount'] ?? 0);
            $fee = max(0, normalize_decimal($posted['fee_amount'] ?? 0));
            if ($unitPrice <= 0 || $expected <= 0 || $received <= 0 || $fee > $received) {
                throw new RuntimeException('Revise os valores da renovação. O total e o valor recebido devem ser positivos.');
            }
            if (abs($expected - $received) > 0.009) {
                throw new RuntimeException('O valor recebido deve ser igual ao total devido. Revise quantidade, preço e desconto.');
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
                if (!$subscription || !in_array($subscription['status'], ['active', 'trial', 'past_due'], true)) {
                    throw new RuntimeException('Uma das assinaturas foi pausada, cancelada ou não existe mais. Atualize a página.');
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
                    if (!$payment && $subscription['next_billing_date'] !== $row['due_date']) {
                        throw new RuntimeException('A data de vencimento da assinatura mudou. Atualize a página antes de confirmar.');
                    }
                    if (!$payment && (int) $db->value(
                        "SELECT COUNT(*) FROM payments WHERE subscription_id=? AND due_date=? AND status='paid'",
                        [$row['subscription_id'], $row['due_date']]
                    ) > 0) {
                        throw new RuntimeException('Esta renovação já foi paga e não será lançada novamente.');
                    }
                }

                $quote = $row['currency'] === 'USD' ? $quotes[$row['receipt_date']] : ['bid' => 1.0, 'source' => 'BRL'];
                $rate = (float) $quote['bid'];
                $amountBrl = round($row['amount'] * $rate, 2);
                $feeBrl = round($row['fee_amount'] * $rate, 2);
                $netBrl = $amountBrl - $feeBrl;
                $description = 'Renovação · ' . $product['name'];
                $settlementDate = $row['currency'] === 'USD' ? $row['receipt_date'] : null;

                if ($payment) {
                    $paymentId = (int) $payment['id'];
                    $db->query(
                        "UPDATE payments SET client_id=?,description=?,amount=?,currency=?,exchange_rate=?,exchange_rate_source=?,amount_brl=?,fee_amount=?,fee_brl=?,net_brl=?,status='paid',due_date=?,payment_date=?,settlement_date=?,payment_method=?,external_reference=?,notes=? WHERE id=? AND status='pending'",
                        [$subscription['client_id'],$description,$row['amount'],$row['currency'],$rate,$quote['source'],$amountBrl,$row['fee_amount'],$feeBrl,$netBrl,$row['due_date'],$row['receipt_date'],$settlementDate,$row['payment_method'] ?: null,$row['external_reference'] ?: null,$row['notes'] ?: null,$paymentId]
                    );
                } else {
                    $paymentId = $db->insert(
                        "INSERT INTO payments (subscription_id,client_id,description,amount,currency,exchange_rate,exchange_rate_source,amount_brl,fee_amount,fee_brl,net_brl,status,due_date,payment_date,settlement_date,payment_method,external_reference,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,'paid',?,?,?,?,?,?)",
                        [$row['subscription_id'],$subscription['client_id'],$description,$row['amount'],$row['currency'],$rate,$quote['source'],$amountBrl,$row['fee_amount'],$feeBrl,$netBrl,$row['due_date'],$row['receipt_date'],$settlementDate,$row['payment_method'] ?: null,$row['external_reference'] ?: null,$row['notes'] ?: null]
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
                $db->query('UPDATE payments SET subscription_id=?, client_id=?, description=?, amount=?, currency=?, exchange_rate=?, exchange_rate_source=?, amount_brl=?, fee_amount=?, fee_brl=?, net_brl=?, status=?, due_date=?, payment_date=?, settlement_date=?, payment_method=?, external_reference=?, notes=? WHERE id=?', $updateParams);
                audit($db, 'update', 'payment', $id, ['amount_brl' => $amountBrl]);
            } else {
                $id = $db->insert('INSERT INTO payments (subscription_id, client_id, description, amount, currency, exchange_rate, exchange_rate_source, amount_brl, fee_amount, fee_brl, net_brl, status, due_date, payment_date, settlement_date, payment_method, external_reference, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', $params);
                audit($db, 'create', 'payment', $id, ['amount_brl' => $amountBrl]);
            }
            if ($status === 'paid' && $previousStatus !== 'paid' && $subscriptionId) {
                $savedPayment = $db->fetch('SELECT * FROM payments WHERE id=?', [$id]);
                if ($savedPayment) {
                    $this->renewSubscriptionFromPayment($db, $savedPayment, $paymentDate ?: date('Y-m-d'));
                }
            }
        });
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

    private function renewSubscription(Database $db, array $subscription, array $product, array $row, int $paymentId): string
    {
        $planChanged = (int) $subscription['product_id'] !== (int) $product['id'];
        $termsChanged = $planChanged
            || (int) $subscription['quantity'] !== (int) $row['quantity']
            || $subscription['currency'] !== $row['currency']
            || abs((float) $subscription['unit_price'] - (float) $row['unit_price']) > 0.009
            || abs((float) $subscription['discount'] - (float) $row['discount']) > 0.009
            || trim((string) $subscription['payment_method']) !== trim((string) $row['payment_method']);

        $calculatedNextDate = $this->addBillingMonths($row['due_date'], (string) $product['billing_cycle']);
        $nextBillingDate = $calculatedNextDate;
        if (!$planChanged && $subscription['next_billing_date'] && $subscription['next_billing_date'] > $calculatedNextDate) {
            $nextBillingDate = $subscription['next_billing_date'];
        }

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
        $summary = $planChanged
            ? 'Plano alterado de ' . $subscription['product'] . ' para ' . $product['name'] . ' durante a renovação.'
            : ($termsChanged ? 'Assinatura renovada com ajustes nas condições comerciais.' : 'Assinatura renovada após a confirmação do pagamento.');
        $details = [
            'payment_id' => $paymentId,
            'due_date' => $row['due_date'],
            'payment_date' => $row['receipt_date'],
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
        $row = [
            'quantity' => (int) $subscription['quantity'],
            'currency' => $subscription['currency'],
            'unit_price' => (float) $subscription['unit_price'],
            'discount' => (float) $subscription['discount'],
            'payment_method' => $payment['payment_method'] ?: $subscription['payment_method'],
            'due_date' => $dueDate,
            'receipt_date' => $receiptDate,
            'amount' => (float) $payment['amount'],
        ];
        $this->renewSubscription($db, $subscription, $product, $row, (int) $payment['id']);
        return true;
    }

    private function addBillingMonths(string $date, string $cycle): string
    {
        $months = ['monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'annual' => 12][$cycle] ?? 1;
        $source = new \DateTimeImmutable($date);
        $day = (int) $source->format('d');
        $target = $source->modify('first day of this month')->modify('+' . $months . ' months');
        $targetDay = min($day, (int) $target->format('t'));
        return $target->setDate((int) $target->format('Y'), (int) $target->format('m'), $targetDay)->format('Y-m-d');
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
