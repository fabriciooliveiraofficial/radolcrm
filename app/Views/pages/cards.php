<?php
$selectedCardId = isset($_GET['card']) ? (int) $_GET['card'] : null;
$selectedInvoiceId = isset($_GET['invoice']) ? (int) $_GET['invoice'] : null;
$buFilter = isset($_GET['bu']) && $_GET['bu'] !== '' ? (int) $_GET['bu'] : null;
$currentMonth = date('Y-m');

$allBusinesses = $db->fetchAll('SELECT id, name, icon, color, is_personal FROM business_units WHERE active = 1 ORDER BY sort_order ASC, id ASC');
$allCategories = $db->fetchAll('SELECT c.*, p.name parent_name, bu.name bu_name FROM categories c LEFT JOIN categories p ON p.id = c.parent_id LEFT JOIN business_units bu ON bu.id = c.business_unit_id WHERE c.active = 1 ORDER BY COALESCE(c.parent_id, c.id) ASC, (c.parent_id IS NOT NULL) ASC, c.name ASC');

$whereCards = ' WHERE 1=1';
$paramsCards = [];
if ($buFilter !== null) {
    $whereCards .= ' AND c.business_unit_id = ?';
    $paramsCards[] = $buFilter;
}

$cards = $db->fetchAll(
    'SELECT c.*, bu.name bu_name, bu.icon bu_icon, bu.color bu_color,
        (SELECT COALESCE(SUM(inv.total_amount), 0) FROM credit_card_invoices inv WHERE inv.card_id = c.id AND inv.status != "paid") open_invoices_sum,
        (SELECT inv.id FROM credit_card_invoices inv WHERE inv.card_id = c.id AND inv.status != "paid" ORDER BY inv.due_date ASC LIMIT 1) current_open_invoice_id,
        (SELECT inv.total_amount FROM credit_card_invoices inv WHERE inv.card_id = c.id AND inv.status != "paid" ORDER BY inv.due_date ASC LIMIT 1) current_open_invoice_amount,
        (SELECT inv.due_date FROM credit_card_invoices inv WHERE inv.card_id = c.id AND inv.status != "paid" ORDER BY inv.due_date ASC LIMIT 1) current_open_invoice_due
     FROM credit_cards c
     LEFT JOIN business_units bu ON bu.id = c.business_unit_id' . $whereCards . '
     ORDER BY c.active DESC, c.name ASC',
    $paramsCards
);

if (!$selectedCardId && !empty($cards)) {
    $selectedCardId = (int) $cards[0]['id'];
}

$selectedCard = null;
foreach ($cards as $c) {
    if ((int) $c['id'] === $selectedCardId) {
        $selectedCard = $c;
        break;
    }
}

$invoices = [];
$currentInvoice = null;
$transactions = [];

if ($selectedCard) {
    $invoices = $db->fetchAll(
        'SELECT inv.*,
            (SELECT COUNT(*) FROM credit_card_transactions tx WHERE tx.invoice_id = inv.id) tx_count
         FROM credit_card_invoices inv
         WHERE inv.card_id = ?
         ORDER BY inv.reference_month DESC',
        [$selectedCardId]
    );

    if ($selectedInvoiceId) {
        foreach ($invoices as $inv) {
            if ((int) $inv['id'] === $selectedInvoiceId) {
                $currentInvoice = $inv;
                break;
            }
        }
    }
    if (!$currentInvoice && !empty($invoices)) {
        // default to first open invoice, or the latest
        foreach ($invoices as $inv) {
            if ($inv['status'] !== 'paid') {
                $currentInvoice = $inv;
                break;
            }
        }
        if (!$currentInvoice) {
            $currentInvoice = $invoices[0];
        }
    }

    if ($currentInvoice) {
        $transactions = $db->fetchAll(
            'SELECT tx.*, cat.name cat_name, cat.icon cat_icon, bu.name bu_name
             FROM credit_card_transactions tx
             LEFT JOIN categories cat ON cat.id = tx.category_id
             LEFT JOIN business_units bu ON bu.id = tx.business_unit_id
             WHERE tx.invoice_id = ?
             ORDER BY tx.transaction_date DESC, tx.id DESC',
            [(int) $currentInvoice['id']]
        );
    }
}

// Stats
$totalLimit = array_sum(array_column($cards, 'credit_limit'));
$totalOpen = array_sum(array_column($cards, 'open_invoices_sum'));
$availableLimit = max(0, $totalLimit - $totalOpen);
$monthPaidInvoices = (float) $db->value('SELECT COALESCE(SUM(total_amount), 0) FROM credit_card_invoices WHERE status = "paid" AND payment_date BETWEEN ? AND ?', [date('Y-m-01'), date('Y-m-t')]);

$showNewCard = isset($_GET['new_card']);
$editCard = isset($_GET['edit_card']) ? $db->fetch('SELECT * FROM credit_cards WHERE id = ?', [(int) $_GET['edit_card']]) : null;
$showNewTx = isset($_GET['new_tx']);
$payInvoiceModal = isset($_GET['pay_invoice']) ? $db->fetch('SELECT inv.*, c.name card_name FROM credit_card_invoices inv JOIN credit_cards c ON c.id = inv.card_id WHERE inv.id = ?', [(int) $_GET['pay_invoice']]) : null;

$brandOptions = ['Mastercard', 'Visa', 'Elo', 'American Express', 'Hipercard', 'Outro'];
$colorPresets = ['#6366f1', '#8b5cf6', '#ec4899', '#ef4444', '#f59e0b', '#10b981', '#06b6d4', '#3b82f6', '#1e293b'];
?>
<section class="mini-stats money-stats">
    <div>
        <span class="dot green"></span>
        <span><small>Limite disponível total</small><b><?= money($availableLimit) ?></b></span>
    </div>
    <div>
        <span class="dot gold"></span>
        <span><small>Faturas em aberto</small><b><?= money($totalOpen) ?></b></span>
    </div>
    <div>
        <span class="dot purple"></span>
        <span><small>Faturas pagas no mês</small><b><?= money($monthPaidInvoices) ?></b></span>
    </div>
    <div>
        <span class="dot blue"></span>
        <span><small>Cartões cadastrados</small><b><?= count($cards) ?> cartões</b></span>
    </div>
</section>

<section class="toolbar list-toolbar">
    <div style="display: flex; gap: 0.75rem; align-items: center;">
        <form method="get" class="search-filters">
            <input type="hidden" name="page" value="cards">
            <?php if ($selectedCardId): ?>
                <input type="hidden" name="card" value="<?= $selectedCardId ?>">
            <?php endif; ?>
            <select name="bu" onchange="this.form.submit()">
                <option value="">Todos os negócios</option>
                <?php foreach ($allBusinesses as $bu): ?>
                    <option value="<?= (int) $bu['id'] ?>" <?= $buFilter === (int) $bu['id'] ? 'selected' : '' ?>>
                        <?= h($bu['icon']) ?> <?= h($bu['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div style="display: flex; gap: 0.5rem;">
        <?php if ($auth->canWrite()): ?>
            <a class="button ghost" href="?page=cards&new_card=1">＋ Cadastrar Cartão</a>
            <?php if ($selectedCard): ?>
                <a class="button primary" href="?page=cards&card=<?= $selectedCardId ?>&new_tx=1">＋ Nova Compra no Cartão</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<!-- Cards Showcase Carousel / Grid -->
<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
    <?php if (!$cards): ?>
        <div class="card" style="padding: 2rem; text-align: center; grid-column: 1 / -1;">
            <p class="muted" style="margin-bottom: 1rem;">Nenhum cartão de crédito cadastrado ainda.</p>
            <a class="button primary" href="?page=cards&new_card=1">＋ Cadastrar Primeiro Cartão</a>
        </div>
    <?php endif; ?>
    <?php foreach ($cards as $c):
        $isSelected = (int) $c['id'] === $selectedCardId;
        $cLimit = (float) $c['credit_limit'];
        $cOpen = (float) $c['open_invoices_sum'];
        $cFree = max(0, $cLimit - $cOpen);
        $usedPercent = $cLimit > 0 ? min(100, round(($cOpen / $cLimit) * 100)) : 0;
    ?>
        <div class="card" style="position: relative; overflow: hidden; border: 2px solid <?= $isSelected ? 'var(--primary, #2b826b)' : 'transparent' ?>; background: linear-gradient(135deg, <?= h($c['color']) ?>22 0%, #151d2a 100%); cursor: pointer; transition: transform 0.15s ease;" onclick="location.href='?page=cards&card=<?= (int)$c['id'] ?>'">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem;">
                <div>
                    <span class="badge" style="background: <?= h($c['color']) ?>; color: #fff; font-size: 0.75rem; font-weight: 700; margin-bottom: 0.35rem; display: inline-block;">
                        <?= h($c['brand']) ?>
                    </span>
                    <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700;"><?= h($c['name']) ?></h3>
                    <?php if ($c['last_four_digits']): ?>
                        <small class="muted" style="letter-spacing: 1px;">•••• <?= h($c['last_four_digits']) ?></small>
                    <?php endif; ?>
                </div>
                <a class="row-action" href="?page=cards&edit_card=<?= (int)$c['id'] ?>" title="Editar cartão" onclick="event.stopPropagation();">✎</a>
            </div>

            <div style="display: flex; justify-content: space-between; font-size: 0.8rem; margin-bottom: 0.5rem;">
                <span>Fecha dia: <b><?= (int) $c['closing_day'] ?></b></span>
                <span>Vence dia: <b><?= (int) $c['due_day'] ?></b></span>
            </div>

            <!-- Limit Progress Bar -->
            <div style="background: rgba(255,255,255,0.1); height: 6px; border-radius: 3px; overflow: hidden; margin-bottom: 0.75rem;">
                <div style="background: <?= $usedPercent > 80 ? '#ef4444' : h($c['color']) ?>; width: <?= $usedPercent ?>%; height: 100%;"></div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: flex-end;">
                <div>
                    <small class="muted block">Fatura atual</small>
                    <b class="negative" style="font-size: 1rem;"><?= money($c['open_invoices_sum']) ?></b>
                </div>
                <div style="text-align: right;">
                    <small class="muted block">Limite livre</small>
                    <b class="positive" style="font-size: 0.95rem;"><?= money($cFree) ?></b>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Selected Card Details: Invoice Management -->
<?php if ($selectedCard): ?>
<section class="card table-card">
    <div class="card-header padded" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div>
            <p class="eyebrow">FATURAS · <?= h(strtoupper($selectedCard['name'])) ?></p>
            <h2>
                Fatura de <?= $currentInvoice ? date('m/Y', strtotime($currentInvoice['reference_month'] . '-01')) : 'Sem faturas' ?>
                <?php if ($currentInvoice): ?>
                    <span class="badge <?= $currentInvoice['status'] === 'paid' ? 'success' : ($currentInvoice['due_date'] < date('Y-m-d') ? 'danger' : 'warning') ?>" style="vertical-align: middle; font-size: 0.75rem;">
                        <?= $currentInvoice['status'] === 'paid' ? 'Paga em ' . date_br($currentInvoice['payment_date']) : ($currentInvoice['due_date'] < date('Y-m-d') ? 'Vencida' : 'Aberta') ?>
                    </span>
                <?php endif; ?>
            </h2>
            <?php if ($currentInvoice): ?>
                <small class="muted">
                    Fechamento: <b><?= date_br($currentInvoice['closing_date']) ?></b> · Vencimento: <b><?= date_br($currentInvoice['due_date']) ?></b>
                </small>
            <?php endif; ?>
        </div>

        <div style="display: flex; gap: 0.5rem; align-items: center;">
            <?php if ($invoices): ?>
                <select onchange="location.href='?page=cards&card=<?= $selectedCardId ?>&invoice=' + this.value" style="padding: 0.4rem 0.6rem; font-size: 0.85rem;">
                    <?php foreach ($invoices as $inv): ?>
                        <option value="<?= (int) $inv['id'] ?>" <?= $currentInvoice && (int) $currentInvoice['id'] === (int) $inv['id'] ? 'selected' : '' ?>>
                            Fatura <?= date('m/Y', strtotime($inv['reference_month'] . '-01')) ?> — <?= money($inv['total_amount']) ?> (<?= $inv['status'] === 'paid' ? 'Paga' : 'Aberta' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <?php if ($currentInvoice && $currentInvoice['status'] !== 'paid' && $auth->canWrite()): ?>
                <a class="button primary" href="?page=cards&card=<?= $selectedCardId ?>&invoice=<?= (int)$currentInvoice['id'] ?>&pay_invoice=<?= (int)$currentInvoice['id'] ?>">
                    ✓ Pagar Esta Fatura
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Invoice Transactions Table -->
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Descrição da Compra</th>
                    <th>Categoria</th>
                    <th>Negócio</th>
                    <th>Parcela</th>
                    <th>Valor</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$transactions): ?>
                    <tr><td colspan="7" class="empty-cell">Nenhum lançamento nesta fatura.</td></tr>
                <?php endif; ?>
                <?php foreach ($transactions as $tx): ?>
                    <tr>
                        <td><?= date_br($tx['transaction_date']) ?></td>
                        <td>
                            <b><?= h($tx['description']) ?></b>
                            <?php if ($tx['notes']): ?>
                                <small class="muted block"><?= h($tx['notes']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($tx['cat_name']): ?>
                                <span><?= h($tx['cat_icon'] ?: '🏷️') ?> <?= h($tx['cat_name']) ?></span>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($tx['bu_name']): ?>
                                <span class="badge muted"><?= h($tx['bu_name']) ?></span>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <b><?= (int) $tx['installment_number'] ?> / <?= (int) $tx['total_installments'] ?></b>
                        </td>
                        <td>
                            <b class="negative">− <?= money($tx['amount_brl']) ?></b>
                            <?php if ($tx['currency'] === 'USD'): ?>
                                <small class="muted block">USD <?= money($tx['amount'], 'USD') ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($currentInvoice && $currentInvoice['status'] !== 'paid' && $auth->canWrite()): ?>
                                <form method="post" data-confirm="Excluir esta transação da fatura?" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_card_transaction">
                                    <input type="hidden" name="id" value="<?= (int) $tx['id'] ?>">
                                    <button class="row-action" style="background:none;border:none;color:#ef4444;cursor:pointer;">✕</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($currentInvoice): ?>
                <tfoot>
                    <tr style="background: rgba(255,255,255,0.03); font-weight: 700;">
                        <td colspan="5" style="text-align: right; padding: 0.75rem 1rem;">Total da Fatura:</td>
                        <td style="padding: 0.75rem 1rem;"><b class="negative" style="font-size: 1.1rem;"><?= money($currentInvoice['total_amount']) ?></b></td>
                        <td></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</section>
<?php endif; ?>

<!-- Modal: Novo / Editar Cartão -->
<?php if ($showNewCard || $editCard): ?>
<div class="modal open">
    <a class="modal-backdrop" href="?page=cards"></a>
    <section class="modal-panel">
        <header>
            <div>
                <p class="eyebrow">CARTÃO DE CRÉDITO</p>
                <h2><?= $editCard ? 'Editar cartão' : 'Novo cartão de crédito' ?></h2>
            </div>
            <a href="?page=cards" class="modal-close">×</a>
        </header>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_credit_card">
            <input type="hidden" name="id" value="<?= (int) ($editCard['id'] ?? 0) ?>">
            <input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">

            <label class="span-2">
                Nome do Cartão / Identificação
                <input name="name" required value="<?= h($editCard['name'] ?? '') ?>" placeholder="Ex.: Nubank Ultravioleta, Inter PJ, XP Infinite">
            </label>

            <label>
                Bandeira
                <select name="brand">
                    <?php foreach ($brandOptions as $brand): ?>
                        <option value="<?= h($brand) ?>" <?= ($editCard['brand'] ?? 'Mastercard') === $brand ? 'selected' : '' ?>><?= h($brand) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Últimos 4 dígitos (opcional)
                <input name="last_four_digits" maxlength="4" value="<?= h($editCard['last_four_digits'] ?? '') ?>" placeholder="1234">
            </label>

            <label>
                Unidade de Negócio Vinculada
                <select name="business_unit_id">
                    <option value="">Geral / Qualquer unidade</option>
                    <?php foreach ($allBusinesses as $bu): ?>
                        <option value="<?= (int) $bu['id'] ?>" <?= ((int) ($editCard['business_unit_id'] ?? 0)) === (int) $bu['id'] ? 'selected' : '' ?>>
                            <?= h($bu['icon']) ?> <?= h($bu['name']) ?><?= $bu['is_personal'] ? ' (Pessoal)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Limite de Crédito Total (R$)
                <input name="credit_limit" type="number" step="0.01" min="0" required value="<?= decimal_input($editCard['credit_limit'] ?? 5000) ?>">
            </label>

            <label>
                Dia do Fechamento da Fatura
                <input name="closing_day" type="number" min="1" max="31" required value="<?= (int) ($editCard['closing_day'] ?? 1) ?>">
            </label>

            <label>
                Dia do Vencimento da Fatura
                <input name="due_day" type="number" min="1" max="31" required value="<?= (int) ($editCard['due_day'] ?? 10) ?>">
            </label>

            <label>
                Cor do Cartão
                <select name="color">
                    <?php foreach ($colorPresets as $col): ?>
                        <option value="<?= h($col) ?>" <?= ($editCard['color'] ?? '#6366f1') === $col ? 'selected' : '' ?> style="background: <?= $col ?>; color: #fff;"><?= $col ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="check-label check-inline">
                <input type="checkbox" name="active" value="1" <?= ($editCard['active'] ?? 1) ? 'checked' : '' ?>>
                <span>Cartão ativo</span>
            </label>

            <label class="span-2">
                Observações
                <textarea name="notes" rows="2"><?= h($editCard['notes'] ?? '') ?></textarea>
            </label>

            <footer class="span-2">
                <a class="button ghost" href="?page=cards">Cancelar</a>
                <button class="button primary">Salvar Cartão</button>
            </footer>
        </form>

        <?php if ($editCard && $auth->canWrite()): ?>
            <form method="post" class="danger-zone" data-confirm="Excluir este cartão de crédito e todas as suas faturas?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_credit_card">
                <input type="hidden" name="id" value="<?= (int) $editCard['id'] ?>">
                <button>Excluir Cartão</button>
            </form>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>

<!-- Modal: Nova Compra no Cartão -->
<?php if ($showNewTx && $selectedCard): ?>
<div class="modal open">
    <a class="modal-backdrop" href="?page=cards&card=<?= $selectedCardId ?>"></a>
    <section class="modal-panel">
        <header>
            <div>
                <p class="eyebrow">LANÇAMENTO NO CARTÃO</p>
                <h2>Nova compra no <?= h($selectedCard['name']) ?></h2>
            </div>
            <a href="?page=cards&card=<?= $selectedCardId ?>" class="modal-close">×</a>
        </header>
        <form method="post" class="form-grid" id="txForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_card_transaction">
            <input type="hidden" name="card_id" value="<?= $selectedCardId ?>">
            <input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">

            <label>
                Unidade de Negócio
                <select name="business_unit_id">
                    <option value="">Do cartão (<?= h($selectedCard['bu_name'] ?: 'Geral') ?>)</option>
                    <?php foreach ($allBusinesses as $bu): ?>
                        <option value="<?= (int) $bu['id'] ?>">
                            <?= h($bu['icon']) ?> <?= h($bu['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Categoria
                <select name="category_id" required>
                    <option value="">Selecione…</option>
                    <?php foreach ($allCategories as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>">
                            <?= h($cat['icon']) ?> <?= $cat['parent_name'] ? h($cat['parent_name']) . ' ↳ ' : '' ?><?= h($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="span-2">
                Descrição da Compra
                <input name="description" required placeholder="Ex.: Supermercado, Pizza Sexta, Licença Figma, Gasolina">
            </label>

            <label>
                Data da Compra
                <input name="transaction_date" type="date" required value="<?= date('Y-m-d') ?>">
            </label>

            <label>
                Valor Total da Compra (R$)
                <input name="amount" id="txAmount" type="number" step="0.01" min="0.01" required placeholder="0,00">
            </label>

            <label>
                Parcelamento
                <select name="total_installments" id="txInstallments">
                    <option value="1">1x (À vista na próxima fatura)</option>
                    <?php for ($i = 2; $i <= 24; $i++): ?>
                        <option value="<?= $i ?>"><?= $i ?>x parcelado</option>
                    <?php endfor; ?>
                </select>
            </label>

            <div class="conversion-preview span-2" id="txInstallmentPreview" style="display:none;">
                <span>Projeção de Parcelas</span>
                <strong id="txPreviewText">R$ 0,00 por mês</strong>
                <small>As parcelas serão distribuídas automaticamente nas faturas mensais seguintes.</small>
            </div>

            <label class="span-2">
                Observações
                <textarea name="notes" rows="2"></textarea>
            </label>

            <footer class="span-2">
                <a class="button ghost" href="?page=cards&card=<?= $selectedCardId ?>">Cancelar</a>
                <button class="button primary">Lançar no Cartão</button>
            </footer>
        </form>
    </section>
</div>

<script>
(function() {
    const amountInp = document.getElementById('txAmount');
    const instInp = document.getElementById('txInstallments');
    const previewBox = document.getElementById('txInstallmentPreview');
    const previewText = document.getElementById('txPreviewText');

    function updateTxPreview() {
        const total = parseFloat(amountInp.value) || 0;
        const count = parseInt(instInp.value, 10) || 1;
        if (count > 1 && total > 0) {
            previewBox.style.display = 'block';
            const perMonth = total / count;
            previewText.textContent = `${count}x de R$ ${perMonth.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        } else {
            previewBox.style.display = 'none';
        }
    }

    if (amountInp && instInp) {
        amountInp.addEventListener('input', updateTxPreview);
        instInp.addEventListener('change', updateTxPreview);
    }
})();
</script>
<?php endif; ?>

<!-- Modal: Pagar Fatura -->
<?php if ($payInvoiceModal && $auth->canWrite()): ?>
<div class="modal open">
    <a class="modal-backdrop" href="?page=cards&card=<?= $selectedCardId ?>"></a>
    <section class="modal-panel">
        <header>
            <div>
                <p class="eyebrow">PAGAMENTO DE FATURA</p>
                <h2>Pagar fatura <?= date('m/Y', strtotime($payInvoiceModal['reference_month'] . '-01')) ?></h2>
            </div>
            <a href="?page=cards&card=<?= $selectedCardId ?>" class="modal-close">×</a>
        </header>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="pay_card_invoice">
            <input type="hidden" name="id" value="<?= (int) $payInvoiceModal['id'] ?>">
            <input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">

            <div class="span-2" style="background: var(--bg-card, #131b26); border-radius: 8px; padding: 1rem;">
                <p style="margin: 0 0 0.25rem 0;"><b>Cartão: <?= h($payInvoiceModal['card_name']) ?></b></p>
                <p style="margin: 0; color: var(--text-muted, #8b9bb4);">Vencimento da fatura: <?= date_br($payInvoiceModal['due_date']) ?></p>
                <h3 class="negative" style="margin: 0.5rem 0 0 0;"><?= money($payInvoiceModal['total_amount']) ?></h3>
            </div>

            <label class="span-2">
                Data do pagamento realizado
                <input name="payment_date" type="date" required value="<?= date('Y-m-d') ?>">
            </label>

            <div class="form-note span-2">Ao confirmar, o valor total da fatura será lançado automaticamente no módulo de Gastos como fatura paga.</div>

            <footer class="span-2">
                <a class="button ghost" href="?page=cards&card=<?= $selectedCardId ?>">Cancelar</a>
                <button class="button primary">Confirmar Pagamento da Fatura</button>
            </footer>
        </form>
    </section>
</div>
<?php endif; ?>
