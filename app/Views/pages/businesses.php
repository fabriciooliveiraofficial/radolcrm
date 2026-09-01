<?php
$search = trim((string) ($_GET['q'] ?? ''));
$where = ' WHERE 1=1';
$params = [];
if ($search !== '') {
    $where .= ' AND (bu.name LIKE ? OR bu.icon LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$businesses = $db->fetchAll(
    'SELECT bu.*,
        (SELECT COUNT(*) FROM clients c WHERE c.business_unit_id = bu.id) total_clients,
        (SELECT COUNT(*) FROM products p WHERE p.business_unit_id = bu.id) total_products,
        (SELECT COALESCE(SUM(pa.net_brl), 0) FROM payments pa WHERE pa.business_unit_id = bu.id AND pa.status = "paid" AND (CASE WHEN pa.currency="USD" THEN COALESCE(pa.settlement_date, pa.payment_date) ELSE pa.payment_date END) BETWEEN ? AND ?) month_revenue,
        (SELECT COALESCE(SUM(ex.amount_brl), 0) FROM expenses ex WHERE ex.business_unit_id = bu.id AND ex.status = "paid" AND ex.payment_date BETWEEN ? AND ?) month_expenses
     FROM business_units bu' . $where . ' ORDER BY bu.sort_order ASC, bu.id ASC',
    array_merge([date('Y-m-01'), date('Y-m-t'), date('Y-m-01'), date('Y-m-t')], $params)
);

$totalBu = count($businesses);
$activeBu = count(array_filter($businesses, static fn($b) => (int)$b['active'] === 1));
$personalBu = count(array_filter($businesses, static fn($b) => (int)$b['is_personal'] === 1));

$edit = isset($_GET['edit']) ? $db->fetch('SELECT * FROM business_units WHERE id = ?', [(int) $_GET['edit']]) : null;
$showForm = isset($_GET['new']) || $edit;

$iconOptions = ['💼','🚀','🏢','🏪','🛠️','💻','🛒','🛍️','📦','🏠','🚗','🩺','🎓','👤','⭐','💎'];
$colorOptions = ['#2b826b','#6366f1','#3b82f6','#06b6d4','#10b981','#f59e0b','#f97316','#ef4444','#ec4899','#8b5cf6','#64748b'];
?>
<section class="mini-stats money-stats">
    <div>
        <span class="dot green"></span>
        <span><small>Total de unidades</small><b><?= $totalBu ?> cadastradas</b></span>
    </div>
    <div>
        <span class="dot purple"></span>
        <span><small>Unidades ativas</small><b><?= $activeBu ?> em operação</b></span>
    </div>
    <div>
        <span class="dot blue"></span>
        <span><small>Finanças pessoais</small><b><?= $personalBu ?> unidade(s)</b></span>
    </div>
</section>

<section class="toolbar list-toolbar">
    <form class="search-filters" method="get" data-live-filter>
        <input type="hidden" name="page" value="businesses">
        <label class="search-box">⌕<input name="q" autocomplete="off" placeholder="Buscar negócio..." value="<?= h($search) ?>"></label>
        <span class="live-filter-indicator" data-live-filter-indicator aria-live="polite">Busca automática</span>
    </form>
    <div>
        <?php if ($auth->canWrite()): ?>
            <a class="button primary" href="?page=businesses&new=1">＋ Nova unidade de negócio</a>
        <?php endif; ?>
    </div>
</section>

<div data-live-results>
    <section class="card table-card">
        <div class="table-meta">
            <span><b><?= count($businesses) ?></b> unidades de negócio</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Unidade de Negócio</th>
                        <th>Tipo</th>
                        <th>Clientes</th>
                        <th>Receitas (mês)</th>
                        <th>Despesas (mês)</th>
                        <th>Resultado (mês)</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$businesses): ?>
                        <tr><td colspan="8" class="empty-cell">Nenhuma unidade de negócio encontrada.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($businesses as $item):
                        $netMonth = (float) $item['month_revenue'] - (float) $item['month_expenses'];
                    ?>
                        <tr>
                            <td>
                                <div class="entity">
                                    <span class="business-unit-icon" style="background: <?= h($item['color']) ?>22; color: <?= h($item['color']) ?>; border: 1px solid <?= h($item['color']) ?>55; font-size: 1.2rem; width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px;">
                                        <?= h($item['icon']) ?>
                                    </span>
                                    <span>
                                        <b><?= h($item['name']) ?></b>
                                        <small><?= (int) $item['total_products'] ?> produto(s) vinculado(s)</small>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?= $item['is_personal'] ? 'info' : 'muted' ?>">
                                    <?= $item['is_personal'] ? '🏠 Finanças Pessoais' : '💼 Negócio / Empresa' ?>
                                </span>
                            </td>
                            <td><b><?= (int) $item['total_clients'] ?></b></td>
                            <td><b class="positive">＋ <?= money($item['month_revenue']) ?></b></td>
                            <td><b class="negative">− <?= money($item['month_expenses']) ?></b></td>
                            <td>
                                <b class="<?= $netMonth < 0 ? 'negative' : ($netMonth > 0 ? 'positive' : '') ?>">
                                    <?= ($netMonth > 0 ? '+ ' : ($netMonth < 0 ? '− ' : '')) . money(abs($netMonth)) ?>
                                </b>
                            </td>
                            <td>
                                <span class="badge <?= $item['active'] ? 'success' : 'muted' ?>">
                                    <?= $item['active'] ? 'Ativo' : 'Inativo' ?>
                                </span>
                            </td>
                            <td>
                                <a class="row-action" href="?page=businesses&edit=<?= (int) $item['id'] ?>">•••</a>
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
    <a class="modal-backdrop" href="?page=businesses"></a>
    <section class="modal-panel">
        <header>
            <div>
                <p class="eyebrow">UNIDADE DE NEGÓCIO</p>
                <h2><?= $edit ? 'Editar unidade' : 'Nova unidade de negócio' ?></h2>
            </div>
            <a href="?page=businesses" class="modal-close">×</a>
        </header>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_business_unit">
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
            <input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">

            <label class="span-2">
                Nome da unidade / negócio
                <input name="name" required value="<?= h($edit['name'] ?? '') ?>" placeholder="Ex.: Gearzone Apps, Barbearia, Finanças Pessoais">
            </label>

            <label>
                Ícone representativo
                <select name="icon">
                    <?php foreach ($iconOptions as $ico): ?>
                        <option value="<?= h($ico) ?>" <?= ($edit['icon'] ?? '💼') === $ico ? 'selected' : '' ?>><?= $ico ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Cor de identificação
                <select name="color">
                    <?php foreach ($colorOptions as $col): ?>
                        <option value="<?= h($col) ?>" <?= ($edit['color'] ?? '#2b826b') === $col ? 'selected' : '' ?> style="background: <?= $col ?>; color: #fff;"><?= $col ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="check-label check-inline span-2">
                <input type="checkbox" name="is_personal" value="1" <?= ($edit['is_personal'] ?? 0) ? 'checked' : '' ?>>
                <span>Esta unidade é de <b>Finanças Pessoais</b> (gastos de casa, família, etc.)</span>
            </label>

            <label class="check-label check-inline">
                <input type="checkbox" name="active" value="1" <?= ($edit['active'] ?? 1) ? 'checked' : '' ?>>
                <span>Unidade ativa</span>
            </label>

            <label>
                Ordem de exibição
                <input type="number" name="sort_order" value="<?= (int) ($edit['sort_order'] ?? 0) ?>">
            </label>

            <footer class="span-2">
                <a class="button ghost" href="?page=businesses">Cancelar</a>
                <button class="button primary">Salvar unidade</button>
            </footer>
        </form>

        <?php if ($edit && $auth->canWrite()): ?>
            <form method="post" class="danger-zone" data-confirm="Excluir esta unidade de negócio? Registros vinculados ficarão sem unidade atribuída.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_business_unit">
                <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
                <button>Excluir unidade</button>
            </form>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>
