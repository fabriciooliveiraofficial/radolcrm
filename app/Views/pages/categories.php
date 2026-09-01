<?php
$search = trim((string) ($_GET['q'] ?? ''));
$businessUnitFilter = isset($_GET['bu']) && $_GET['bu'] !== '' ? (int) $_GET['bu'] : null;
$typeFilter = (string) ($_GET['type'] ?? '');

$allBusinesses = $db->fetchAll('SELECT id, name, icon, color, is_personal FROM business_units WHERE active = 1 ORDER BY sort_order ASC, id ASC');

$where = ' WHERE 1=1';
$params = [];
if ($search !== '') {
    $where .= ' AND (c.name LIKE ? OR c.icon LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($businessUnitFilter !== null) {
    $where .= ' AND (c.business_unit_id = ? OR c.business_unit_id IS NULL)';
    $params[] = $businessUnitFilter;
}
if (in_array($typeFilter, ['expense', 'income', 'both'], true)) {
    $where .= ' AND (c.type = ? OR c.type = "both")';
    $params[] = $typeFilter;
}

$rawCategories = $db->fetchAll(
    'SELECT c.*, bu.name bu_name, bu.icon bu_icon, bu.color bu_color,
        (SELECT COUNT(*) FROM expenses e WHERE e.category_id = c.id) expense_count,
        (SELECT COUNT(*) FROM payments p WHERE p.category_id = c.id) payment_count,
        (SELECT COUNT(*) FROM categories sub WHERE sub.parent_id = c.id) sub_count
     FROM categories c
     LEFT JOIN business_units bu ON bu.id = c.business_unit_id' . $where . '
     ORDER BY COALESCE(c.parent_id, c.id) ASC, (c.parent_id IS NOT NULL) ASC, c.sort_order ASC, c.id ASC',
    $params
);

// Build hierarchy
$parents = [];
$children = [];
foreach ($rawCategories as $cat) {
    if ($cat['parent_id'] === null) {
        $parents[$cat['id']] = $cat;
        $children[$cat['id']] = [];
    } else {
        $children[$cat['parent_id']][] = $cat;
    }
}

$totalCategories = count($rawCategories);
$withLimits = count(array_filter($rawCategories, static fn($c) => !empty($c['budget_limit_percent']) || !empty($c['budget_limit_amount'])));
$mainCategories = count(array_filter($rawCategories, static fn($c) => $c['parent_id'] === null));

$edit = isset($_GET['edit']) ? $db->fetch('SELECT * FROM categories WHERE id = ?', [(int) $_GET['edit']]) : null;
$showForm = isset($_GET['new']) || $edit;

$potentialParents = $db->fetchAll('SELECT id, name, icon, business_unit_id FROM categories WHERE parent_id IS NULL' . ($edit ? ' AND id != ' . (int)$edit['id'] : '') . ' ORDER BY name ASC');

$iconOptions = ['📁','🛒','🏠','🚗','🩺','🎓','📱','🏦','🍿','📣','💻','🏛️','👥','🏢','💎','✈️','🎁','🍕','⚡','💧','🌐','⛽','🛠️'];
$colorOptions = ['#2b826b','#6366f1','#3b82f6','#06b6d4','#10b981','#f59e0b','#f97316','#ef4444','#ec4899','#8b5cf6','#64748b'];
?>
<section class="mini-stats money-stats">
    <div>
        <span class="dot green"></span>
        <span><small>Total de categorias</small><b><?= $totalCategories ?> cadastradas</b></span>
    </div>
    <div>
        <span class="dot purple"></span>
        <span><small>Categorias principais</small><b><?= $mainCategories ?> grupos</b></span>
    </div>
    <div>
        <span class="dot gold"></span>
        <span><small>Com limitador de orçamento</small><b><?= $withLimits ?> categorias</b></span>
    </div>
</section>

<section class="toolbar list-toolbar">
    <form class="search-filters" method="get" data-live-filter>
        <input type="hidden" name="page" value="categories">
        <label class="search-box">⌕<input name="q" autocomplete="off" placeholder="Buscar categoria..." value="<?= h($search) ?>"></label>
        <select name="bu">
            <option value="">Todos os negócios</option>
            <?php foreach ($allBusinesses as $bu): ?>
                <option value="<?= (int) $bu['id'] ?>" <?= $businessUnitFilter === (int) $bu['id'] ? 'selected' : '' ?>>
                    <?= h($bu['icon']) ?> <?= h($bu['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="type">
            <option value="">Todos os tipos</option>
            <option value="expense" <?= $typeFilter === 'expense' ? 'selected' : '' ?>>Despesas</option>
            <option value="income" <?= $typeFilter === 'income' ? 'selected' : '' ?>>Receitas</option>
            <option value="both" <?= $typeFilter === 'both' ? 'selected' : '' ?>>Ambos</option>
        </select>
        <span class="live-filter-indicator" data-live-filter-indicator aria-live="polite">Busca automática</span>
    </form>
    <div>
        <?php if ($auth->canWrite()): ?>
            <a class="button primary" href="?page=categories&new=1">＋ Nova categoria</a>
        <?php endif; ?>
    </div>
</section>

<div data-live-results>
    <section class="card table-card">
        <div class="table-meta">
            <span><b><?= $totalCategories ?></b> categorias cadastradas</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Categoria / Subcategoria</th>
                        <th>Negócio</th>
                        <th>Tipo</th>
                        <th>Teto / Limitador</th>
                        <th>Uso</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rawCategories): ?>
                        <tr><td colspan="7" class="empty-cell">Nenhuma categoria encontrada.</td></tr>
                    <?php endif; ?>
                    <?php
                    // Render hierarchical or flat if searched
                    foreach ($rawCategories as $cat):
                        $isSub = $cat['parent_id'] !== null;
                    ?>
                        <tr class="<?= $isSub ? 'sub-row' : 'parent-row' ?>">
                            <td>
                                <div class="entity" style="<?= $isSub ? 'padding-left: 2rem;' : '' ?>">
                                    <span style="font-size: 1.1rem; width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; background: <?= h($cat['color'] ?: '#2b826b') ?>22; color: <?= h($cat['color'] ?: '#2b826b') ?>; border-radius: 6px;">
                                        <?= h($cat['icon']) ?>
                                    </span>
                                    <span>
                                        <b><?= $isSub ? '↳ ' : '' ?><?= h($cat['name']) ?></b>
                                        <?php if (!$isSub && (int) $cat['sub_count'] > 0): ?>
                                            <small><?= (int) $cat['sub_count'] ?> subcategoria(s)</small>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <?php if ($cat['bu_name']): ?>
                                    <span class="badge" style="background: <?= h($cat['bu_color'] ?: '#2b826b') ?>15; color: <?= h($cat['bu_color'] ?: '#2b826b') ?>; border: 1px solid <?= h($cat['bu_color'] ?: '#2b826b') ?>44;">
                                        <?= h($cat['bu_icon']) ?> <?= h($cat['bu_name']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge muted">Global (Todos)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $cat['type'] === 'income' ? 'success' : ($cat['type'] === 'expense' ? 'muted' : 'info') ?>">
                                    <?= ['expense' => 'Despesa', 'income' => 'Receita', 'both' => 'Ambos'][$cat['type']] ?? 'Despesa' ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($cat['budget_limit_percent'])): ?>
                                    <b class="positive">Máx. <?= number_format((float)$cat['budget_limit_percent'], 1, ',', '.') ?>% da receita</b>
                                <?php elseif (!empty($cat['budget_limit_amount'])): ?>
                                    <b class="gold">Teto: <?= money($cat['budget_limit_amount']) ?></b>
                                <?php else: ?>
                                    <span class="muted">Sem limite</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small><?= (int) $cat['expense_count'] + (int) $cat['payment_count'] ?> lançamento(s)</small>
                            </td>
                            <td>
                                <span class="badge <?= $cat['active'] ? 'success' : 'muted' ?>">
                                    <?= $cat['active'] ? 'Ativa' : 'Inativa' ?>
                                </span>
                            </td>
                            <td>
                                <a class="row-action" href="?page=categories&edit=<?= (int) $cat['id'] ?>">•••</a>
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
    <a class="modal-backdrop" href="?page=categories"></a>
    <section class="modal-panel">
        <header>
            <div>
                <p class="eyebrow">CATEGORIA</p>
                <h2><?= $edit ? 'Editar categoria' : 'Nova categoria' ?></h2>
            </div>
            <a href="?page=categories" class="modal-close">×</a>
        </header>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_category">
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
            <input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">

            <label class="span-2">
                Nome da categoria
                <input name="name" required value="<?= h($edit['name'] ?? '') ?>" placeholder="Ex.: Gasolina, Alimentação, Moradia, SaaS">
            </label>

            <label>
                Unidade de negócio
                <select name="business_unit_id">
                    <option value="">Global / Qualquer negócio</option>
                    <?php foreach ($allBusinesses as $bu): ?>
                        <option value="<?= (int) $bu['id'] ?>" <?= ((int) ($edit['business_unit_id'] ?? 0)) === (int) $bu['id'] ? 'selected' : '' ?>>
                            <?= h($bu['icon']) ?> <?= h($bu['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Categoria pai (opcional)
                <select name="parent_id">
                    <option value="">Nenhuma (Esta é uma categoria principal)</option>
                    <?php foreach ($potentialParents as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= ((int) ($edit['parent_id'] ?? 0)) === (int) $p['id'] ? 'selected' : '' ?>>
                            <?= h($p['icon']) ?> <?= h($p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Tipo de movimentação
                <select name="type">
                    <option value="expense" <?= ($edit['type'] ?? 'expense') === 'expense' ? 'selected' : '' ?>>Despesa / Saída</option>
                    <option value="income" <?= ($edit['type'] ?? '') === 'income' ? 'selected' : '' ?>>Receita / Entrada</option>
                    <option value="both" <?= ($edit['type'] ?? '') === 'both' ? 'selected' : '' ?>>Ambos</option>
                </select>
            </label>

            <label>
                Ícone
                <select name="icon">
                    <?php foreach ($iconOptions as $ico): ?>
                        <option value="<?= h($ico) ?>" <?= ($edit['icon'] ?? '📁') === $ico ? 'selected' : '' ?>><?= $ico ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Cor
                <select name="color">
                    <?php foreach ($colorOptions as $col): ?>
                        <option value="<?= h($col) ?>" <?= ($edit['color'] ?? '#2b826b') === $col ? 'selected' : '' ?> style="background: <?= $col ?>; color: #fff;"><?= $col ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Limitador automático (% do faturamento)
                <input name="budget_limit_percent" type="number" step="0.1" min="0" max="100" value="<?= h($edit['budget_limit_percent'] ?? '') ?>" placeholder="Ex.: 15.0">
                <small>Alerta se o gasto ultrapassar esta % do faturamento mensal.</small>
            </label>

            <label>
                Ou teto fixo em R$
                <input name="budget_limit_amount" type="number" step="0.01" min="0" value="<?= h($edit['budget_limit_amount'] ?? '') ?>" placeholder="Ex.: 2000.00">
                <small>Alerta se o gasto mensal ultrapassar este valor.</small>
            </label>

            <label class="check-label check-inline">
                <input type="checkbox" name="active" value="1" <?= ($edit['active'] ?? 1) ? 'checked' : '' ?>>
                <span>Categoria ativa</span>
            </label>

            <label>
                Ordem
                <input type="number" name="sort_order" value="<?= (int) ($edit['sort_order'] ?? 0) ?>">
            </label>

            <footer class="span-2">
                <a class="button ghost" href="?page=categories">Cancelar</a>
                <button class="button primary">Salvar categoria</button>
            </footer>
        </form>

        <?php if ($edit && $auth->canWrite()): ?>
            <form method="post" class="danger-zone" data-confirm="Excluir esta categoria?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_category">
                <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
                <button>Excluir categoria</button>
            </form>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>
