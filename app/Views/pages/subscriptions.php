<?php
$search = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$where = ' WHERE 1=1';
$params = [];
if ($search !== '') {
    $where .= ' AND (c.name LIKE ? OR p.name LIKE ?)';
    $params = ['%' . $search . '%', '%' . $search . '%'];
}
if (in_array($status, ['trial', 'active', 'past_due', 'paused', 'canceled'], true)) {
    $where .= ' AND s.status=?';
    $params[] = $status;
}
$countSql = 'SELECT COUNT(*) FROM subscriptions s JOIN clients c ON c.id=s.client_id JOIN products p ON p.id=s.product_id' . $where;
$dataSql = "SELECT s.*,c.name client,c.country,p.name product,p.billing_cycle,((s.unit_price*s.quantity)-s.discount) recurring_value FROM subscriptions s JOIN clients c ON c.id=s.client_id JOIN products p ON p.id=s.product_id{$where} ORDER BY FIELD(s.status,'past_due','trial','active','paused','canceled'),s.next_billing_date";
$pagination = pagination($db, $countSql, $dataSql, $params);

$edit = isset($_GET['edit']) ? $db->fetch('SELECT * FROM subscriptions WHERE id=?', [(int) $_GET['edit']]) : null;
$showForm = isset($_GET['new']) || $edit;
$showRenewals = isset($_GET['renewals']);
$productRate = null;
if ($showForm || $showRenewals) {
    $productRate = (float) $rates->current()['bid'];
}
$clients = $showForm ? $db->fetchAll("SELECT id,name,country,preferred_currency FROM clients WHERE status!='inactive' OR id=? ORDER BY name", [(int) ($edit['client_id'] ?? 0)]) : [];
$products = $showForm ? $db->fetchAll('SELECT * FROM products WHERE active=1 OR id=? ORDER BY name', [(int) ($edit['product_id'] ?? 0)]) : [];
foreach ($products as $key => $product) {
    $products[$key] = product_with_current_prices($product, $productRate ?: 1.0);
}

$activeCount = (int) $db->value("SELECT COUNT(*) FROM subscriptions WHERE status='active'");
$trialCount = (int) $db->value("SELECT COUNT(*) FROM subscriptions WHERE status='trial'");
$overdueCount = (int) $db->value("SELECT COUNT(*) FROM subscriptions WHERE status='past_due'");
$cutoff = (new DateTimeImmutable('today'))->modify('+45 days')->format('Y-m-d');
$dueCount = (int) $db->value(
    "SELECT COUNT(*) FROM subscriptions s
     WHERE s.status IN ('active','trial','past_due')
       AND (EXISTS (SELECT 1 FROM payments pending WHERE pending.subscription_id=s.id AND pending.status='pending')
            OR (s.next_billing_date IS NOT NULL AND s.next_billing_date<=?
                AND NOT EXISTS (SELECT 1 FROM payments paid WHERE paid.subscription_id=s.id AND paid.due_date=s.next_billing_date AND paid.status='paid'))) ",
    [$cutoff]
);

$renewalRows = [];
$renewalProducts = [];
if ($showRenewals) {
    $renewalRows = $db->fetchAll(
        "SELECT s.*,c.name client,c.country,p.name product,p.billing_cycle,
                p.price_brl product_price_brl,p.price_usd product_price_usd,p.pricing_mode product_pricing_mode,
                pending.id pending_payment_id,pending.due_date pending_due_date,
                pending.amount pending_amount,pending.fee_amount pending_fee_amount,
                pending.payment_method pending_payment_method,pending.external_reference pending_external_reference,
                pending.notes pending_notes
         FROM subscriptions s
         JOIN clients c ON c.id=s.client_id
         JOIN products p ON p.id=s.product_id
         LEFT JOIN payments pending ON pending.id=(
             SELECT MIN(p2.id) FROM payments p2 WHERE p2.subscription_id=s.id AND p2.status='pending'
         )
         WHERE s.status IN ('active','trial','past_due')
           AND (pending.id IS NOT NULL OR (s.next_billing_date IS NOT NULL AND s.next_billing_date<=?
                AND NOT EXISTS (SELECT 1 FROM payments paid WHERE paid.subscription_id=s.id AND paid.due_date=s.next_billing_date AND paid.status='paid')))
         ORDER BY COALESCE(pending.due_date,s.next_billing_date),c.name
         LIMIT 100",
        [$cutoff]
    );
    $renewalProducts = $db->fetchAll('SELECT * FROM products ORDER BY active DESC,name');
    foreach ($renewalProducts as $key => $product) {
        $renewalProducts[$key] = product_with_current_prices($product, $productRate ?: 1.0);
    }
    foreach ($renewalRows as $key => $row) {
        $pricedProduct = product_with_current_prices([
            'pricing_mode' => $row['product_pricing_mode'] ?? 'manual',
            'price_brl' => $row['product_price_brl'] ?? 0,
            'price_usd' => $row['product_price_usd'] ?? 0,
        ], $productRate ?: 1.0);
        $renewalRows[$key]['renewal_unit_price'] = ($pricedProduct['pricing_mode'] ?? 'manual') === 'manual'
            ? (float) $row['unit_price']
            : (float) $pricedProduct[$row['currency'] === 'USD' ? 'price_usd' : 'price_brl'];
    }
}

$historyId = max(0, (int) ($_GET['history'] ?? 0));
$historySubscription = null;
$historyEvents = [];
$historyPayments = [];
if ($historyId > 0) {
    $historySubscription = $db->fetch('SELECT s.id,c.name client,p.name product FROM subscriptions s JOIN clients c ON c.id=s.client_id JOIN products p ON p.id=s.product_id WHERE s.id=?', [$historyId]);
    if ($historySubscription) {
        $historyEvents = $db->fetchAll(
            'SELECT e.*,u.name user_name,p.amount,p.currency,p.due_date,p.payment_date FROM subscription_events e LEFT JOIN users u ON u.id=e.user_id LEFT JOIN payments p ON p.id=e.payment_id WHERE e.subscription_id=? ORDER BY e.created_at DESC,e.id DESC LIMIT 100',
            [$historyId]
        );
        $historyPayments = $db->fetchAll('SELECT id,description,amount,currency,status,due_date,payment_date,settlement_date FROM payments WHERE subscription_id=? ORDER BY COALESCE(payment_date,due_date) DESC,id DESC LIMIT 30', [$historyId]);
    }
}
?>

<section class="mini-stats"><div><span class="dot green"></span><b><?= $activeCount ?></b><small>Ativas</small></div><div><span class="dot gold"></span><b><?= $trialCount ?></b><small>Em teste</small></div><div><span class="dot red"></span><b><?= $overdueCount ?></b><small>Em atraso</small></div></section>

<section class="toolbar list-toolbar">
    <form class="search-filters" method="get"><input type="hidden" name="page" value="subscriptions"><label class="search-box">⌕<input name="q" placeholder="Buscar cliente ou produto" value="<?= h($search) ?>"></label><select name="status" onchange="this.form.submit()"><option value="">Todos os status</option><?php foreach (['active'=>'Ativas','trial'=>'Em teste','past_due'=>'Em atraso','paused'=>'Pausadas','canceled'=>'Canceladas'] as $value => $label): ?><option value="<?= $value ?>" <?= $status === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select><button class="button secondary">Buscar</button></form>
    <div><a class="button ghost" href="?page=export&type=subscriptions">⇩ Exportar</a><?php if ($auth->canWrite() && $dueCount > 0): ?><a class="button secondary" href="?page=subscriptions&renewals=1">⚡ Gerar próximas cobranças (<?= $dueCount ?>)</a><?php endif; ?><?php if ($auth->canWrite()): ?><a class="button primary" href="?page=subscriptions&new=1">＋ Nova assinatura</a><?php endif; ?></div>
</section>

<section class="card table-card"><div class="table-meta"><span><b><?= $pagination['total'] ?></b> assinaturas</span><small>Renovações confirmadas atualizam o financeiro e ficam no histórico.</small></div><div class="table-wrap"><table><thead><tr><th>Cliente / Produto</th><th>Valor recorrente</th><th>Ciclo</th><th>Próxima cobrança</th><th>Status</th><th></th></tr></thead><tbody>
<?php if (!$pagination['rows']): ?><tr><td colspan="6" class="empty-cell">Nenhuma assinatura encontrada.</td></tr><?php endif; ?>
<?php foreach ($pagination['rows'] as $item): ?><tr><td><div class="entity"><span class="avatar-sm"><?= h(mb_strtoupper(mb_substr($item['client'], 0, 1))) ?></span><span><b><?= h($item['client']) ?></b><small><?= h($item['product']) ?> · <?= $item['country'] === 'BR' ? '🇧🇷' : '🇺🇸' ?></small></span></div></td><td><b><?= money($item['recurring_value'], $item['currency']) ?></b><small class="block"><?= (int) $item['quantity'] ?> unidade(s)</small></td><td><?= cycle_label($item['billing_cycle']) ?></td><td><?= date_br($item['next_billing_date']) ?></td><td><span class="badge <?= status_class($item['status']) ?>"><?= status_label($item['status']) ?></span></td><td><div class="row-actions"><a href="?page=subscriptions&history=<?= (int) $item['id'] ?>" title="Histórico">Histórico</a><?php if ($auth->canWrite()): ?><a class="row-action" href="?page=subscriptions&edit=<?= (int) $item['id'] ?>" title="Editar">•••</a><?php endif; ?></div></td></tr><?php endforeach; ?>
</tbody></table></div><?= render_pagination($pagination) ?></section>

<?php if ($showRenewals): ?>
<div class="modal open"><a class="modal-backdrop" href="?page=subscriptions"></a><section class="modal-panel renewal-panel"><header><div><p class="eyebrow">RENOVAÇÃO ASSISTIDA</p><h2>Conferir e receber cobranças</h2><p>Revise os dados. Ao confirmar, cada cobrança será lançada como paga, a assinatura será renovada e todas as alterações ficarão registradas.</p></div><a href="?page=subscriptions" class="modal-close">×</a></header>
<?php if (!$renewalRows): ?><div class="empty-renewals"><b>Tudo em dia</b><p>Não há cobranças pendentes ou previstas nos próximos 45 dias.</p><a class="button secondary" href="?page=subscriptions">Voltar</a></div><?php else: ?>
<form method="post" data-renewal-form data-confirm="Confirmar as renovações selecionadas como pagas e recebidas? Pagamentos, novas datas e alterações de plano serão registrados juntos.">
    <?= csrf_field() ?><input type="hidden" name="action" value="process_subscription_renewals"><input type="hidden" name="_return" value="?page=subscriptions&renewals=1">
    <div class="renewal-toolbar"><label><input type="checkbox" data-renewal-check-all checked> Selecionar todas</label><span><b data-renewal-selected><?= count($renewalRows) ?></b> de <?= count($renewalRows) ?> selecionadas</span></div>
    <div class="renewal-list">
    <?php foreach ($renewalRows as $row):
        $subscriptionId = (int) $row['id'];
        $dueDate = $row['pending_due_date'] ?: $row['next_billing_date'];
        $renewalUnitPrice = (float) ($row['renewal_unit_price'] ?? $row['unit_price']);
        $contractAmount = round(max(0, ($renewalUnitPrice * (int) $row['quantity']) - (float) $row['discount']), 2);
        $receivedAmount = $row['pending_payment_id'] ? (float) $row['pending_amount'] : $contractAmount;
    ?>
    <article class="renewal-card" data-renewal-row data-current-product="<?= (int) $row['product_id'] ?>">
        <div class="renewal-card-head"><label class="renewal-selector"><input type="checkbox" name="renewals[<?= $subscriptionId ?>][selected]" value="1" data-renewal-check checked><span></span></label><div class="entity"><span class="avatar-sm"><?= h(mb_strtoupper(mb_substr($row['client'], 0, 1))) ?></span><span><b><?= h($row['client']) ?></b><small><?= h($row['product']) ?> · <?= $row['country'] === 'BR' ? 'Brasil' : 'Estados Unidos' ?></small></span></div><div class="renewal-due"><small>Vencimento</small><b><?= date_br($dueDate) ?></b><span data-payment-timing>Conferir data</span></div></div>
        <input type="hidden" name="renewals[<?= $subscriptionId ?>][subscription_updated_at]" value="<?= h($row['updated_at']) ?>"><input type="hidden" name="renewals[<?= $subscriptionId ?>][pending_payment_id]" value="<?= (int) ($row['pending_payment_id'] ?? 0) ?>"><input type="hidden" name="renewals[<?= $subscriptionId ?>][due_date]" value="<?= h($dueDate) ?>" data-renewal-due>
        <div class="renewal-grid">
            <label class="span-2">Plano<select name="renewals[<?= $subscriptionId ?>][product_id]" data-renewal-product><?php foreach ($renewalProducts as $product): ?><option value="<?= (int) $product['id'] ?>" data-brl="<?= h($product['price_brl']) ?>" data-usd="<?= h($product['price_usd']) ?>" data-cycle="<?= h($product['billing_cycle']) ?>" <?= (int) $product['id'] === (int) $row['product_id'] ? 'selected' : '' ?>><?= h($product['name']) ?> · <?= cycle_label($product['billing_cycle']) ?><?= $product['active'] ? '' : ' (inativo)' ?></option><?php endforeach; ?></select><small>Trocar o plano aqui altera a assinatura somente após a confirmação.</small></label>
            <label>Moeda<select name="renewals[<?= $subscriptionId ?>][currency]" data-renewal-currency><option value="BRL" <?= $row['currency'] === 'BRL' ? 'selected' : '' ?>>BRL</option><option value="USD" <?= $row['currency'] === 'USD' ? 'selected' : '' ?>>USD</option></select></label>
            <label>Quantidade<input name="renewals[<?= $subscriptionId ?>][quantity]" type="number" min="1" value="<?= (int) $row['quantity'] ?>" data-renewal-quantity></label>
            <label>Valor unitário<input name="renewals[<?= $subscriptionId ?>][unit_price]" type="number" min="0.01" step="0.01" value="<?= decimal_input($renewalUnitPrice) ?>" data-renewal-price><?php if (($row['product_pricing_mode'] ?? 'manual') !== 'manual'): ?><small>Atualizado pela cotação diária do produto</small><?php endif; ?></label>
            <label>Desconto total<input name="renewals[<?= $subscriptionId ?>][discount]" type="number" min="0" step="0.01" value="<?= decimal_input($row['discount']) ?>" data-renewal-discount></label>
            <label>Valor recebido<input name="renewals[<?= $subscriptionId ?>][amount]" type="number" min="0.01" step="0.01" value="<?= decimal_input($receivedAmount) ?>" data-renewal-amount><small data-renewal-balance></small><button type="button" class="renewal-use-total" data-renewal-use-total>Usar total devido</button></label>
            <label>Taxa da plataforma<input name="renewals[<?= $subscriptionId ?>][fee_amount]" type="number" min="0" step="0.01" value="<?= decimal_input($row['pending_fee_amount'] ?? 0) ?>"></label>
            <label>Pagamento / resgate<input name="renewals[<?= $subscriptionId ?>][receipt_date]" type="date" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" data-renewal-receipt required><small>Para USD, esta data define a cotação diária.</small></label>
            <label>Forma de pagamento<input name="renewals[<?= $subscriptionId ?>][payment_method]" value="<?= h($row['pending_payment_method'] ?: $row['payment_method']) ?>" placeholder="PIX, Stripe, cartão…"></label>
            <label class="span-2">Referência externa<input name="renewals[<?= $subscriptionId ?>][external_reference]" value="<?= h($row['pending_external_reference'] ?? '') ?>" placeholder="ID bancário, Stripe ou nota fiscal"></label>
            <label class="span-2">Observações<textarea name="renewals[<?= $subscriptionId ?>][notes]" rows="2" placeholder="Observação opcional desta renovação"><?= h($row['pending_notes'] ?? '') ?></textarea></label>
        </div>
        <div class="renewal-summary"><span>Total devido <b data-renewal-total><?= money($contractAmount, $row['currency']) ?></b></span><span>Próxima cobrança <b data-renewal-next>Calculando…</b></span><strong class="renewal-match" data-renewal-match>✓ Valor confere</strong></div>
    </article>
    <?php endforeach; ?>
    </div>
    <footer class="renewal-footer"><p><b>Operação rastreável:</b> pagamento, cotação, renovação e mudanças comerciais serão salvos no histórico.</p><div><a class="button ghost" href="?page=subscriptions">Cancelar</a><button class="button primary" data-renewal-submit>Confirmar e receber <?= count($renewalRows) ?> renovação(ões)</button></div></footer>
</form>
<?php endif; ?></section></div>
<?php endif; ?>

<?php if ($historySubscription): ?>
<div class="modal open"><a class="modal-backdrop" href="?page=subscriptions"></a><section class="modal-panel wide history-panel"><header><div><p class="eyebrow">TRILHA RASTREÁVEL</p><h2><?= h($historySubscription['client']) ?></h2><p><?= h($historySubscription['product']) ?> · pagamentos, renovações e mudanças de plano</p></div><a href="?page=subscriptions" class="modal-close">×</a></header>
<section class="history-section"><h3>Renovações e alterações</h3><?php if (!$historyEvents): ?><p class="history-empty">Nenhuma renovação processada pelo novo fluxo ainda.</p><?php else: ?><div class="history-timeline"><?php foreach ($historyEvents as $event): ?><article><span class="history-dot <?= $event['event_type'] === 'plan_change' ? 'gold' : '' ?>">↻</span><div><b><?= h($event['summary']) ?></b><p><?= date_br($event['event_date']) ?><?php if ($event['amount'] !== null): ?> · <?= money($event['amount'], $event['currency']) ?><?php endif; ?><?php if ($event['payment_id']): ?> · Pagamento #<?= (int) $event['payment_id'] ?><?php endif; ?></p><small><?= h($event['user_name'] ?: 'Sistema') ?> · <?= date('d/m/Y H:i', strtotime($event['created_at'])) ?></small></div></article><?php endforeach; ?></div><?php endif; ?></section>
<section class="history-section"><h3>Transações da assinatura</h3><?php if (!$historyPayments): ?><p class="history-empty">Nenhum pagamento vinculado.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>ID</th><th>Vencimento</th><th>Pagamento</th><th>Valor</th><th>Status</th></tr></thead><tbody><?php foreach ($historyPayments as $payment): ?><tr><td>#<?= (int) $payment['id'] ?></td><td><?= date_br($payment['due_date']) ?></td><td><?= date_br($payment['payment_date']) ?></td><td><b><?= money($payment['amount'], $payment['currency']) ?></b></td><td><span class="badge <?= status_class($payment['status']) ?>"><?= status_label($payment['status']) ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
</section></div>
<?php endif; ?>

<?php if ($showForm): ?>
<div class="modal open"><a class="modal-backdrop" href="?page=subscriptions"></a><section class="modal-panel wide"><header><div><p class="eyebrow">RECEITA RECORRENTE</p><h2><?= $edit ? 'Editar assinatura' : 'Nova assinatura' ?></h2></div><a href="?page=subscriptions" class="modal-close">×</a></header><form method="post" class="form-grid" data-subscription-form><?= csrf_field() ?><input type="hidden" name="action" value="save_subscription"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>"><input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">
<label>Cliente<select name="client_id" required data-sub-client><option value="">Selecione…</option><?php foreach ($clients as $client): ?><option value="<?= (int) $client['id'] ?>" data-currency="<?= h($client['preferred_currency']) ?>" <?= (int) ($edit['client_id'] ?? 0) === (int) $client['id'] ? 'selected' : '' ?>><?= h($client['name']) ?> · <?= h($client['country']) ?></option><?php endforeach; ?></select></label><label>Produto<select name="product_id" required data-sub-product><option value="">Selecione…</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['id'] ?>" data-brl="<?= h($product['price_brl']) ?>" data-usd="<?= h($product['price_usd']) ?>" <?= (int) ($edit['product_id'] ?? 0) === (int) $product['id'] ? 'selected' : '' ?>><?= h($product['name']) ?> · <?= cycle_label($product['billing_cycle']) ?></option><?php endforeach; ?></select></label><label>Moeda<select name="currency" data-sub-currency><option value="BRL" <?= ($edit['currency'] ?? 'BRL') === 'BRL' ? 'selected' : '' ?>>BRL — Real</option><option value="USD" <?= ($edit['currency'] ?? '') === 'USD' ? 'selected' : '' ?>>USD — Dólar</option></select></label><label>Valor unitário<input name="unit_price" type="number" step="0.01" min="0" required value="<?= decimal_input($edit['unit_price'] ?? 0) ?>" data-sub-price></label><label>Quantidade<input name="quantity" type="number" min="1" required value="<?= (int) ($edit['quantity'] ?? 1) ?>"></label><label>Desconto total<input name="discount" type="number" step="0.01" min="0" value="<?= decimal_input($edit['discount'] ?? 0) ?>"></label><label>Data de início<input name="start_date" type="date" required value="<?= h($edit['start_date'] ?? date('Y-m-d')) ?>"></label><label>Próxima cobrança<input name="next_billing_date" type="date" value="<?= h($edit['next_billing_date'] ?? date('Y-m-d', strtotime('+1 month'))) ?>"></label><label>Status<select name="status"><option value="active" <?= ($edit['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Ativa</option><option value="trial" <?= ($edit['status'] ?? '') === 'trial' ? 'selected' : '' ?>>Teste</option><option value="past_due" <?= ($edit['status'] ?? '') === 'past_due' ? 'selected' : '' ?>>Em atraso</option><option value="paused" <?= ($edit['status'] ?? '') === 'paused' ? 'selected' : '' ?>>Pausada</option><option value="canceled" <?= ($edit['status'] ?? '') === 'canceled' ? 'selected' : '' ?>>Cancelada</option></select></label><label>Data de cancelamento<input name="canceled_at" type="date" value="<?= h($edit['canceled_at'] ?? '') ?>"></label><label class="span-2">Forma de pagamento<input name="payment_method" value="<?= h($edit['payment_method'] ?? '') ?>" placeholder="Cartão, PIX, Stripe, boleto…"></label><label class="span-2">Observações<textarea name="notes" rows="3"><?= h($edit['notes'] ?? '') ?></textarea></label><footer class="span-2"><a class="button ghost" href="?page=subscriptions">Cancelar</a><button class="button primary">Salvar assinatura</button></footer></form><?php if ($edit && $auth->canWrite()): ?><form method="post" class="danger-zone" data-confirm="Excluir esta assinatura?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_subscription"><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><button>Excluir assinatura</button></form><?php endif; ?></section></div>
<?php endif; ?>
