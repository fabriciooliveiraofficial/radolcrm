<?php
$search = trim((string) ($_GET['q'] ?? ''));
$type = (string) ($_GET['type'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$buFilter = isset($_GET['bu']) && $_GET['bu'] !== '' ? (int) $_GET['bu'] : null;
$categoryFilter = isset($_GET['cat']) && $_GET['cat'] !== '' ? (int) $_GET['cat'] : null;

$where = ' WHERE 1=1';
$params = [];
if ($search !== '') {
    $where .= " AND CONCAT_WS(' ', e.id, e.type, CASE e.type WHEN 'expense' THEN 'Despesa operacional' WHEN 'investment' THEN 'Investimento' END, COALESCE(cat.name, e.category), e.description, e.supplier, e.amount, REPLACE(e.amount,'.',','), e.currency, e.exchange_rate, e.amount_brl, REPLACE(e.amount_brl,'.',','), e.status, CASE e.status WHEN 'paid' THEN 'Pago' WHEN 'pending' THEN 'Pendente' END, e.payment_date, DATE_FORMAT(e.payment_date,'%d/%m/%Y'), bu.name, e.notes) LIKE ?";
    $params[] = '%' . $search . '%';
}
if (in_array($type, ['expense', 'investment'], true)) {
    $where .= ' AND e.type = ?';
    $params[] = $type;
}
if (in_array($status, ['paid', 'pending'], true)) {
    $where .= ' AND e.status = ?';
    $params[] = $status;
}
if ($buFilter !== null) {
    $where .= ' AND e.business_unit_id = ?';
    $params[] = $buFilter;
}
if ($categoryFilter !== null) {
    $where .= ' AND (e.category_id = ? OR cat.parent_id = ?)';
    $params[] = $categoryFilter;
    $params[] = $categoryFilter;
}

$pagination = pagination(
    $db,
    'SELECT COUNT(*) FROM expenses e LEFT JOIN categories cat ON cat.id = e.category_id LEFT JOIN business_units bu ON bu.id = e.business_unit_id' . $where,
    'SELECT e.*, bu.name bu_name, bu.icon bu_icon, bu.color bu_color, cat.name category_name, cat.icon category_icon, cat.color category_color, pcat.name parent_category_name
     FROM expenses e
     LEFT JOIN business_units bu ON bu.id = e.business_unit_id
     LEFT JOIN categories cat ON cat.id = e.category_id
     LEFT JOIN categories pcat ON pcat.id = cat.parent_id' . $where . '
     ORDER BY e.payment_date DESC, e.id DESC',
    $params
);

$edit = isset($_GET['edit']) ? $db->fetch('SELECT * FROM expenses WHERE id = ?', [(int) $_GET['edit']]) : null;
$showForm = isset($_GET['new']) || $edit;

$monthWhere = 'payment_date BETWEEN ? AND ?';
$monthParams = [date('Y-m-01'), date('Y-m-t')];
if ($buFilter !== null) {
    $monthWhere .= ' AND business_unit_id = ?';
    $monthParams[] = $buFilter;
}
$month = $db->fetch(
    "SELECT COALESCE(SUM(CASE WHEN type='expense' AND status='paid' THEN amount_brl ELSE 0 END), 0) expenses,
            COALESCE(SUM(CASE WHEN type='investment' AND status='paid' THEN amount_brl ELSE 0 END), 0) investments,
            COALESCE(SUM(CASE WHEN status='pending' THEN amount_brl ELSE 0 END), 0) pending
     FROM expenses WHERE " . $monthWhere,
    $monthParams
);

$allBusinesses = $db->fetchAll('SELECT id, name, icon, color, is_personal FROM business_units WHERE active = 1 ORDER BY sort_order ASC, id ASC');

$allCategories = $db->fetchAll(
    'SELECT c.*, bu.name bu_name
     FROM categories c
     LEFT JOIN business_units bu ON bu.id = c.business_unit_id
     WHERE c.active = 1 AND (c.parent_id IS NULL OR c.parent_id = 0) AND c.type IN ("expense", "investment", "both")' .
     ($buFilter !== null ? ' AND (c.business_unit_id = ' . (int)$buFilter . ' OR c.business_unit_id IS NULL)' : '') . '
     ORDER BY c.sort_order ASC, c.name ASC'
);

$frequentSuppliers = $db->fetchAll("SELECT DISTINCT supplier FROM expenses WHERE supplier IS NOT NULL AND supplier != '' ORDER BY id DESC LIMIT 50");
?>
<section class="mini-stats money-stats">
    <div>
        <span class="dot red"></span>
        <span><small>Despesas no mês</small><b><?= money($month['expenses']) ?></b></span>
    </div>
    <div>
        <span class="dot purple"></span>
        <span><small>Investimentos no mês</small><b><?= money($month['investments']) ?></b></span>
    </div>
    <div>
        <span class="dot gold"></span>
        <span><small>A pagar</small><b><?= money($month['pending']) ?></b></span>
    </div>
</section>

<section class="toolbar list-toolbar">
    <form class="search-filters" method="get" data-live-filter>
        <input type="hidden" name="page" value="expenses">
        <label class="search-box">⌕<input name="q" autocomplete="off" placeholder="Buscar qualquer informação" value="<?= h($search) ?>"></label>
        <select name="bu">
            <option value="">Todos os negócios</option>
            <?php foreach ($allBusinesses as $bu): ?>
                <option value="<?= (int) $bu['id'] ?>" <?= $buFilter === (int) $bu['id'] ? 'selected' : '' ?>>
                    <?= h($bu['icon']) ?> <?= h($bu['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="type">
            <option value="">Despesas + investimentos</option>
            <option value="expense" <?= $type === 'expense' ? 'selected' : '' ?>>Despesas</option>
            <option value="investment" <?= $type === 'investment' ? 'selected' : '' ?>>Investimentos</option>
        </select>

        <select name="status">
            <option value="">Todos os status</option>
            <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Pagos</option>
            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pendentes</option>
        </select>

        <span class="live-filter-indicator" data-live-filter-indicator aria-live="polite">Busca automática</span>
    </form>
    <div>
        <a class="button ghost" href="?page=export&type=expenses">⇩ Exportar</a>
        <?php if ($auth->canWrite()): ?>
            <a class="button primary" href="?page=expenses&new=1<?= $buFilter ? '&bu=' . (int)$buFilter : '' ?>">＋ Novo lançamento</a>
        <?php endif; ?>
    </div>
</section>

<div data-live-results>
    <section class="card table-card">
        <div class="table-meta">
            <span><b><?= $pagination['total'] ?></b> lançamentos</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Descrição</th>
                        <th>Negócio</th>
                        <th>Tipo / Categoria</th>
                        <th>Data</th>
                        <th>Valor original</th>
                        <th>Valor BRL</th>
                        <th>Status</th>
                        <th class="actions-column"><span class="sr-only">Ações</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$pagination['rows']): ?>
                        <tr><td colspan="8" class="empty-cell">Nenhum lançamento encontrado.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($pagination['rows'] as $item): ?>
                        <tr>
                            <td>
                                <div class="entity">
                                    <span class="expense-icon <?= $item['type'] === 'investment' ? 'purple' : 'red' ?>">
                                        <?= $item['type'] === 'investment' ? '↗' : '↑' ?>
                                    </span>
                                    <span>
                                        <b><?= h($item['description']) ?></b>
                                        <small><?= h($item['supplier'] ?: 'Sem fornecedor') ?><?= $item['is_recurring'] ? ' · Recorrente' : '' ?></small>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <?php if ($item['bu_name']): ?>
                                    <span class="badge" style="background: <?= h($item['bu_color'] ?: '#2b826b') ?>15; color: <?= h($item['bu_color'] ?: '#2b826b') ?>; border: 1px solid <?= h($item['bu_color'] ?: '#2b826b') ?>44;">
                                        <?= h($item['bu_icon']) ?> <?= h($item['bu_name']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge muted">Geral</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $item['type'] === 'investment' ? 'info' : 'muted' ?>">
                                    <?= $item['type'] === 'investment' ? 'Investimento' : 'Despesa' ?>
                                </span>
                                <small class="block">
                                    <?php if ($item['parent_category_name']): ?>
                                        <?= h($item['parent_category_name']) ?> ↳
                                    <?php endif; ?>
                                    <?= h($item['category_name'] ?: $item['category']) ?>
                                </small>
                            </td>
                            <td><?= date_br($item['payment_date']) ?></td>
                            <td>
                                <b><?= money($item['amount'], $item['currency']) ?></b>
                                <?php if ($item['currency'] === 'USD'): ?>
                                    <small class="block">USD × <?= number_format($item['exchange_rate'], 4, ',', '.') ?></small>
                                <?php endif; ?>
                            </td>
                            <td><b><?= money($item['amount_brl']) ?></b></td>
                            <td><span class="badge <?= status_class($item['status']) ?>"><?= status_label($item['status']) ?></span></td>
                            <td><a class="row-action" href="?page=expenses&edit=<?= (int) $item['id'] ?><?= $buFilter ? '&bu=' . (int)$buFilter : '' ?>">•••</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= render_pagination($pagination) ?>
    </section>
</div>

<?php if ($showForm): ?>
<div class="modal open">
    <a class="modal-backdrop" href="?page=expenses<?= $buFilter ? '&bu=' . (int)$buFilter : '' ?>"></a>
    <section class="modal-panel wide">
        <header>
            <div>
                <p class="eyebrow">SAÍDAS</p>
                <h2><?= $edit ? 'Editar lançamento' : 'Novo gasto ou investimento' ?></h2>
            </div>
            <a href="?page=expenses<?= $buFilter ? '&bu=' . (int)$buFilter : '' ?>" class="modal-close">×</a>
        </header>
        <form method="post" class="form-grid" data-money-form data-daily-rate="1" data-new-record="<?= $edit ? '0' : '1' ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_expense">
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
            <input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">

            <?php 
            $activeBuObj = null;
            if ($buFilter) {
                foreach ($allBusinesses as $b) {
                    if ((int)$b['id'] === $buFilter) { $activeBuObj = $b; break; }
                }
            }
            if ($activeBuObj && !$edit): 
            ?>
                <label>
                    <span style="display: flex; align-items: center; justify-content: space-between;">
                        <span>Unidade de Negócio</span>
                        <span class="badge success" style="font-size: 10px; font-weight: 600;">🔒 Automático & Travado</span>
                    </span>
                    <input type="hidden" name="business_unit_id" value="<?= (int) $activeBuObj['id'] ?>">
                    <div style="display: flex; align-items: center; gap: 8px; background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 6px; padding: 0.6rem 0.8rem; font-size: 13.5px; font-weight: 600; color: var(--ink);">
                        <span style="font-size: 1.2rem;"><?= h($activeBuObj['icon'] ?: '🏢') ?></span>
                        <span><?= h($activeBuObj['name']) ?></span>
                        <small style="margin-left: auto; color: var(--muted); font-size: 11px; font-weight: normal;">Isolado nesta página</small>
                    </div>
                </label>
            <?php else: ?>
                <label>
                    Unidade de Negócio
                    <select name="business_unit_id">
                        <option value="">Geral / Sem unidade</option>
                        <?php 
                        $selectedBuForm = $edit ? (int) ($edit['business_unit_id'] ?? 0) : $buFilter;
                        foreach ($allBusinesses as $bu): ?>
                            <option value="<?= (int) $bu['id'] ?>" <?= $selectedBuForm === (int) $bu['id'] ? 'selected' : '' ?>>
                                <?= h($bu['icon']) ?> <?= h($bu['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>

            <label>
                Tipo
                <select name="type">
                    <option value="expense" <?= ($edit['type'] ?? 'expense') === 'expense' ? 'selected' : '' ?>>Despesa operacional / consumo</option>
                    <option value="investment" <?= ($edit['type'] ?? '') === 'investment' ? 'selected' : '' ?>>Investimento em crescimento</option>
                </select>
            </label>

            <label class="span-2">
                Categoria
                <select name="category_id" required>
                    <option value="">Selecione uma categoria…</option>
                    <?php foreach ($allCategories as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>" <?= ((int) ($edit['category_id'] ?? 0)) === (int) $cat['id'] ? 'selected' : '' ?>>
                            <?= h($cat['icon']) ?> <?= h($cat['name']) ?><?= !empty($cat['bu_name']) ? ' (' . h($cat['bu_name']) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="span-2">
                Descrição
                <input name="description" required value="<?= h($edit['description'] ?? '') ?>" placeholder="O que foi pago? (ex.: Gasolina, Supermercado, Aluguel, Servidor)">
            </label>

            <label class="span-2">
                Fornecedor ou favorecido
                <input name="supplier" list="frequent-suppliers" value="<?= h($edit['supplier'] ?? '') ?>" placeholder="Ex.: Posto Shell, Carrefour, AWS, Proprietário">
                <datalist id="frequent-suppliers">
                    <?php foreach ($frequentSuppliers as $sup): ?>
                        <option value="<?= h($sup['supplier']) ?>">
                    <?php endforeach; ?>
                </datalist>
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
                <input name="amount" type="number" min="0.01" step="0.01" required data-money-amount value="<?= decimal_input($edit['amount'] ?? 0) ?>">
            </label>

            <input type="hidden" data-money-fee value="0">

            <label>
                <span data-rate-title><?= ($edit['currency'] ?? 'BRL') === 'USD' ? 'Cotação USD → BRL' : 'Cotação BRL → USD' ?></span>
                <input name="exchange_rate" type="number" step="0.000001" min="0" data-money-rate value="<?= h($edit['exchange_rate'] ?? '') ?>" placeholder="Automática pela data">
                <small data-rate-help><?= $edit ? 'Cotação histórica deste lançamento' : 'Aguardando a moeda e a data' ?></small>
            </label>

            <label>
                Data
                <input name="payment_date" type="date" required data-rate-date value="<?= h($edit['payment_date'] ?? date('Y-m-d')) ?>">
            </label>

            <div class="conversion-preview span-2">
                <span data-preview-label><?= ($edit['currency'] ?? 'BRL') === 'USD' ? 'Total convertido em BRL' : 'Total convertido em USD' ?></span>
                <strong data-money-preview><?= ($edit['currency'] ?? 'BRL') === 'USD' ? 'R$ 0,00' : 'US$ 0,00' ?></strong>
                <small>A conversão diária é salva para manter o histórico correto.</small>
            </div>

            <label>
                Status
                <select name="status">
                    <option value="paid" <?= ($edit['status'] ?? 'paid') === 'paid' ? 'selected' : '' ?>>Pago</option>
                    <option value="pending" <?= ($edit['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pendente</option>
                </select>
            </label>

            <label class="check-label check-inline">
                <input type="checkbox" name="is_recurring" value="1" <?= ($edit['is_recurring'] ?? 0) ? 'checked' : '' ?>>
                <span>Este gasto é recorrente</span>
            </label>

            <label class="span-2">
                Observações
                <textarea name="notes" rows="3"><?= h($edit['notes'] ?? '') ?></textarea>
            </label>

            <footer class="span-2">
                <a class="button ghost" href="?page=expenses<?= $buFilter ? '&bu=' . (int)$buFilter : '' ?>">Cancelar</a>
                <button class="button primary">Salvar lançamento</button>
            </footer>
        </form>

        <?php if ($edit && $auth->canWrite()): ?>
            <form method="post" class="danger-zone" data-confirm="Excluir este lançamento?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_expense">
                <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
                <button>Excluir lançamento</button>
            </form>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>
