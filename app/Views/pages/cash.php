<?php
use App\Services\FinanceService;

$finance = new FinanceService($db);
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-t');
$search = trim((string) ($_GET['q'] ?? ''));
$buFilter = isset($_GET['bu']) && $_GET['bu'] !== '' ? (int) $_GET['bu'] : null;

$allBusinesses = $db->fetchAll('SELECT id, name, icon, color, is_personal FROM business_units WHERE active = 1 ORDER BY sort_order ASC, id ASC');
$allCategories = $db->fetchAll('SELECT c.*, p.name parent_name FROM categories c LEFT JOIN categories p ON p.id = c.parent_id WHERE c.active = 1 ORDER BY COALESCE(c.parent_id, c.id) ASC, (c.parent_id IS NOT NULL) ASC, c.name ASC');

$openingBalance = $finance->balanceBefore($from);
$balance = $finance->cashBalance();

$buConditionPayment = $buFilter ? ' AND p.business_unit_id = ' . $buFilter : '';
$buConditionExpense = $buFilter ? ' AND e.business_unit_id = ' . $buFilter : '';
$buConditionCash = $buFilter ? ' AND x.business_unit_id = ' . $buFilter : '';

$rawLedger = $db->fetchAll("SELECT * FROM (
 SELECT CONCAT('payment-',p.id) row_id,'payment' source,p.id,
        (CASE WHEN p.currency='USD' THEN COALESCE(p.settlement_date,p.payment_date) ELSE p.payment_date END) entry_date,
        'in' direction,COALESCE(pr.name,p.description,CONCAT('Pagamento · ',c.name)) raw_title,c.name subtitle,
        p.net_brl amount_brl,p.currency,p.amount original_amount,p.status,
        bu.name bu_name, bu.icon bu_icon, bu.color bu_color
 FROM payments p
 JOIN clients c ON c.id=p.client_id
 LEFT JOIN subscriptions s ON s.id=p.subscription_id
 LEFT JOIN products pr ON pr.id=s.product_id
 LEFT JOIN business_units bu ON bu.id=p.business_unit_id
 WHERE p.status='paid'{$buConditionPayment}
   AND (CASE WHEN p.currency='USD' THEN COALESCE(p.settlement_date,p.payment_date) ELSE p.payment_date END) BETWEEN ? AND ?
 UNION ALL
 SELECT CONCAT('expense-',e.id),'expense',e.id,e.payment_date,'out',
        e.description raw_title,CONCAT_WS(' · ',e.supplier,COALESCE(cat.name, e.category)) subtitle,
        e.amount_brl,e.currency,e.amount,e.status,
        bu.name bu_name, bu.icon bu_icon, bu.color bu_color
 FROM expenses e
 LEFT JOIN categories cat ON cat.id=e.category_id
 LEFT JOIN business_units bu ON bu.id=e.business_unit_id
 WHERE e.status='paid'{$buConditionExpense} AND e.payment_date BETWEEN ? AND ?
 UNION ALL
 SELECT CONCAT('cash-',x.id),'cash',x.id,x.entry_date,x.direction,
        x.description raw_title,COALESCE(cat.name, x.category) subtitle,
        x.amount_brl,x.currency,x.amount,'paid',
        bu.name bu_name, bu.icon bu_icon, bu.color bu_color
 FROM cash_entries x
 LEFT JOIN categories cat ON cat.id=x.category_id
 LEFT JOIN business_units bu ON bu.id=x.business_unit_id
 WHERE x.entry_date BETWEEN ? AND ?{$buConditionCash}
 ) ledger ORDER BY entry_date ASC, CASE source WHEN 'cash' THEN 1 WHEN 'expense' THEN 2 ELSE 3 END, id ASC",
 [$from, $to, $from, $to, $from, $to]
);

$running = $openingBalance;
$periodIn = 0.0;
$periodOut = 0.0;
$computedLedger = [];
foreach ($rawLedger as $item) {
    $amount = (float) $item['amount_brl'];
    if ($item['direction'] === 'in') {
        $running += $amount;
        $periodIn += $amount;
    } else {
        $running -= $amount;
        $periodOut += $amount;
    }
    $item['balance_after'] = $running;
    $cleanTitle = (string) $item['raw_title'];
    if ($item['source'] === 'payment') {
        $cleanTitle = preg_replace('/^Renovaç[ãa]o( antecipada)?(\s*·\s*[^·]+)?\s*·\s*/iu', '', $cleanTitle);
    }
    $item['title'] = $cleanTitle ?: $item['raw_title'];
    $computedLedger[] = $item;
}

if ($search !== '') {
    $searchLower = mb_strtolower($search);
    $filtered = [];
    foreach ($computedLedger as $item) {
        $sourceLabel = ['payment' => 'Pagamento', 'expense' => 'Gasto / investimento', 'cash' => 'Avulso'][$item['source']] ?? '';
        $directionLabel = $item['direction'] === 'in' ? 'Entrada' : 'Saída';
        $haystack = mb_strtolower(implode(' ', [
            $item['row_id'], $item['source'], $sourceLabel, $item['entry_date'], date_br($item['entry_date']),
            $item['direction'], $directionLabel, $item['title'], $item['subtitle'], $item['bu_name'] ?? '',
            $item['amount_brl'], str_replace('.', ',', (string) $item['amount_brl']),
            $item['currency'], $item['original_amount'], str_replace('.', ',', (string) $item['original_amount']), $item['status']
        ]));
        if (str_contains($haystack, $searchLower)) {
            $filtered[] = $item;
        }
    }
    $computedLedger = $filtered;
}

$ledger = array_slice(array_reverse($computedLedger), 0, 300);
$periodNet = $periodIn - $periodOut;
$edit = isset($_GET['edit']) ? $db->fetch('SELECT * FROM cash_entries WHERE id=?', [(int) $_GET['edit']]) : null;
$showForm = isset($_GET['new']) || $edit;
?>
<section class="cash-hero" data-live-results>
    <article>
        <span>SALDO DE CAIXA CONSOLIDADO</span>
        <strong class="<?= $balance < 0 ? 'negative' : '' ?>"><?= money($balance) ?></strong>
        <small>Pagamentos líquidos + entradas − gastos − saídas</small>
    </article>
    <article>
        <span>SALDO NO PERÍODO</span>
        <strong class="<?= $periodNet < 0 ? 'negative' : ($periodNet > 0 ? 'positive' : '') ?>">
            <?= ($periodNet > 0 ? '+ ' : ($periodNet < 0 ? '− ' : '')) . money(abs($periodNet)) ?>
        </strong>
        <small><?= date_br($from) ?> a <?= date_br($to) ?> (entradas − saídas)</small>
    </article>
    <article>
        <span>ENTRADAS NO PERÍODO</span>
        <strong class="positive">＋ <?= money($periodIn) ?></strong>
        <small><?= date_br($from) ?> a <?= date_br($to) ?></small>
    </article>
    <article>
        <span>SAÍDAS NO PERÍODO</span>
        <strong class="negative">− <?= money($periodOut) ?></strong>
        <small><?= date_br($from) ?> a <?= date_br($to) ?></small>
    </article>
</section>

<section class="toolbar list-toolbar">
    <form class="search-filters" method="get" data-live-filter>
        <input type="hidden" name="page" value="cash">
        <label class="search-box">⌕<input name="q" autocomplete="off" placeholder="Buscar qualquer informação" value="<?= h($search) ?>"></label>
        
        <select name="bu">
            <option value="">Todos os negócios</option>
            <?php foreach ($allBusinesses as $bu): ?>
                <option value="<?= (int) $bu['id'] ?>" <?= $buFilter === (int) $bu['id'] ? 'selected' : '' ?>>
                    <?= h($bu['icon']) ?> <?= h($bu['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>De<input type="date" name="from" value="<?= h($from) ?>"></label>
        <label>Até<input type="date" name="to" value="<?= h($to) ?>"></label>
        <span class="live-filter-indicator" data-live-filter-indicator aria-live="polite">Busca automática</span>
    </form>
    <?php if ($auth->canWrite()): ?>
        <a class="button primary" href="?page=cash&new=1">＋ Movimento avulso</a>
    <?php endif; ?>
</section>

<div data-live-results>
    <section class="card table-card">
        <div class="card-header padded">
            <div>
                <p class="eyebrow">EXTRATO UNIFICADO</p>
                <h2>Movimentações do período</h2>
            </div>
            <span class="muted">Até 300 lançamentos</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Movimentação</th>
                        <th>Negócio</th>
                        <th>Origem</th>
                        <th>Data</th>
                        <th>Valor original</th>
                        <th>Entrada / saída</th>
                        <th>Saldo após</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$ledger): ?>
                        <tr><td colspan="8" class="empty-cell">Nenhuma movimentação no período.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($ledger as $item): ?>
                        <tr>
                            <td>
                                <div class="entity">
                                    <span class="flow-icon <?= $item['direction'] === 'in' ? 'in' : 'out' ?>">
                                        <?= $item['direction'] === 'in' ? '↓' : '↑' ?>
                                    </span>
                                    <span>
                                        <b><?= h($item['title']) ?></b>
                                        <?php if (!empty($item['subtitle'])): ?>
                                            <small><?= h($item['subtitle']) ?></small>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($item['bu_name'])): ?>
                                    <span class="badge" style="background: <?= h($item['bu_color'] ?: '#2b826b') ?>15; color: <?= h($item['bu_color'] ?: '#2b826b') ?>; border: 1px solid <?= h($item['bu_color'] ?: '#2b826b') ?>44;">
                                        <?= h($item['bu_icon'] ?: '💼') ?> <?= h($item['bu_name']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge muted">Geral</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge muted"><?= ['payment' => 'Pagamento', 'expense' => 'Gasto / investimento', 'cash' => 'Avulso'][$item['source']] ?></span>
                            </td>
                            <td><?= date_br($item['entry_date']) ?></td>
                            <td><?= money($item['original_amount'], $item['currency']) ?></td>
                            <td>
                                <b class="<?= $item['direction'] === 'in' ? 'positive' : 'negative' ?>">
                                    <?= $item['direction'] === 'in' ? '+ ' : '− ' ?><?= money($item['amount_brl']) ?>
                                </b>
                            </td>
                            <td><b class="<?= $item['balance_after'] < 0 ? 'negative' : '' ?>"><?= money($item['balance_after']) ?></b></td>
                            <td>
                                <?= $item['source'] === 'cash' ? '<a class="row-action" href="?page=cash&edit=' . (int) $item['id'] . '">•••</a>' : '<span class="lock-icon" title="Edite no módulo de origem">⌁</span>' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php if ($showForm): ?>
<div class="modal open">
    <a class="modal-backdrop" href="?page=cash"></a>
    <section class="modal-panel">
        <header>
            <div>
                <p class="eyebrow">CAIXA AVULSO</p>
                <h2><?= $edit ? 'Editar movimento' : 'Novo movimento' ?></h2>
            </div>
            <a href="?page=cash" class="modal-close">×</a>
        </header>
        <div class="form-note">Use este formulário para aportes, retiradas, ajustes e outras movimentações que não sejam pagamentos de clientes nem gastos.</div>
        <form method="post" class="form-grid" data-money-form data-daily-rate="1" data-new-record="<?= $edit ? '0' : '1' ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_cash">
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
            <input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">

            <label>
                Unidade de Negócio
                <select name="business_unit_id">
                    <option value="">Geral / Sem unidade</option>
                    <?php foreach ($allBusinesses as $bu): ?>
                        <option value="<?= (int) $bu['id'] ?>" <?= ((int) ($edit['business_unit_id'] ?? 0)) === (int) $bu['id'] ? 'selected' : '' ?>>
                            <?= h($bu['icon']) ?> <?= h($bu['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Direção
                <select name="direction">
                    <option value="in" <?= ($edit['direction'] ?? 'in') === 'in' ? 'selected' : '' ?>>Entrada</option>
                    <option value="out" <?= ($edit['direction'] ?? '') === 'out' ? 'selected' : '' ?>>Saída</option>
                </select>
            </label>

            <label>
                Categoria
                <select name="category_id">
                    <option value="">Outros / Ajuste</option>
                    <?php foreach ($allCategories as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>" <?= ((int) ($edit['category_id'] ?? 0)) === (int) $cat['id'] ? 'selected' : '' ?>>
                            <?= h($cat['icon']) ?> <?= $cat['parent_name'] ? h($cat['parent_name']) . ' ↳ ' : '' ?><?= h($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Nome / Descrição avulsa da categoria
                <input name="category" value="<?= h($edit['category'] ?? '') ?>" placeholder="Aporte, retirada, ajuste…">
            </label>

            <label class="span-2">
                Descrição
                <input name="description" required value="<?= h($edit['description'] ?? '') ?>">
            </label>

            <label>
                Moeda
                <select name="currency" data-money-currency>
                    <option value="BRL" <?= ($edit['currency'] ?? 'BRL') === 'BRL' ? 'selected' : '' ?>>BRL — Real</option>
                    <option value="USD" <?= ($edit['currency'] ?? '') === 'USD' ? 'selected' : '' ?>>USD — Dólar</option>
                </select>
            </label>

            <label>
                Valor
                <input name="amount" type="number" step="0.01" min="0.01" required data-money-amount value="<?= decimal_input($edit['amount'] ?? 0) ?>">
            </label>

            <input type="hidden" data-money-fee value="0">

            <label>
                Cotação USD → BRL
                <input name="exchange_rate" type="number" step="0.000001" min="0" data-money-rate value="<?= h($edit['exchange_rate'] ?? '') ?>" placeholder="Automática pela data">
                <small data-rate-help><?= $edit ? 'Cotação histórica deste movimento' : 'Aguardando a moeda e a data' ?></small>
            </label>

            <label>
                Data
                <input name="entry_date" type="date" required data-rate-date value="<?= h($edit['entry_date'] ?? date('Y-m-d')) ?>">
            </label>

            <div class="conversion-preview span-2">
                <span>Total convertido</span>
                <strong data-money-preview>R$ 0,00</strong>
            </div>

            <label class="span-2">
                Observações
                <textarea name="notes" rows="3"><?= h($edit['notes'] ?? '') ?></textarea>
            </label>

            <footer class="span-2">
                <a class="button ghost" href="?page=cash">Cancelar</a>
                <button class="button primary">Salvar movimento</button>
            </footer>
        </form>

        <?php if ($edit && $auth->canWrite()): ?>
            <form method="post" class="danger-zone" data-confirm="Excluir esta movimentação?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_cash">
                <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
                <button>Excluir movimento</button>
            </form>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>
