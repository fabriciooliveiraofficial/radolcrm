<?php
$search = trim((string) ($_GET['q'] ?? ''));
$businessUnitFilter = isset($_GET['bu']) && $_GET['bu'] !== '' ? (int) $_GET['bu'] : null;
$typeFilter = (string) ($_GET['type'] ?? '');

$allBusinesses = $db->fetchAll('SELECT id, name, icon, color, is_personal FROM business_units WHERE active = 1 ORDER BY sort_order ASC, id ASC');

$where = ' WHERE c.active = 1';
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
if (in_array($typeFilter, ['expense', 'income', 'investment', 'both'], true)) {
    $where .= ' AND (c.type = ? OR c.type = "both")';
    $params[] = $typeFilter;
}

$rawCategories = $db->fetchAll(
    'SELECT c.*, bu.name bu_name, bu.icon bu_icon, bu.color bu_color,
        (SELECT COUNT(*) FROM expenses e WHERE e.category_id = c.id) expense_count,
        (SELECT COUNT(*) FROM payments p WHERE p.category_id = c.id) payment_count,
        (SELECT COUNT(*) FROM credit_card_transactions ct WHERE ct.category_id = c.id) card_count,
        (SELECT COUNT(*) FROM cash_entries ce WHERE ce.category_id = c.id) cash_count,
        (SELECT COUNT(*) FROM recurring_templates rt WHERE rt.category_id = c.id) recurring_count,
        (SELECT COUNT(*) FROM products pr WHERE pr.category_id = c.id) product_count
     FROM categories c
     LEFT JOIN business_units bu ON bu.id = c.business_unit_id' . $where . '
     ORDER BY FIELD(c.type, "income", "expense", "investment", "both"), c.sort_order ASC, c.name ASC',
    $params
);

$allActiveCategories = $db->fetchAll('SELECT type, budget_limit_percent, budget_limit_amount FROM categories WHERE active = 1');
$totalCategories = count($allActiveCategories);
$incomeCategoriesCount = count(array_filter($allActiveCategories, static fn($c) => in_array($c['type'], ['income', 'both'], true)));
$expenseCategoriesCount = count(array_filter($allActiveCategories, static fn($c) => in_array($c['type'], ['expense', 'both'], true)));
$withLimits = count(array_filter($allActiveCategories, static fn($c) => !empty($c['budget_limit_percent']) || !empty($c['budget_limit_amount'])));

$edit = isset($_GET['edit']) ? $db->fetch('SELECT * FROM categories WHERE id = ?', [(int) $_GET['edit']]) : null;
$showForm = isset($_GET['new']) || $edit;

$editLinkedCount = 0;
if ($edit) {
    $editLinkedCount = (int) $db->value(
        'SELECT (SELECT COUNT(*) FROM expenses WHERE category_id=?) +
                (SELECT COUNT(*) FROM payments WHERE category_id=?) +
                (SELECT COUNT(*) FROM cash_entries WHERE category_id=?) +
                (SELECT COUNT(*) FROM credit_card_transactions WHERE category_id=?) +
                (SELECT COUNT(*) FROM recurring_templates WHERE category_id=?) +
                (SELECT COUNT(*) FROM products WHERE category_id=?)',
        [(int) $edit['id'], (int) $edit['id'], (int) $edit['id'], (int) $edit['id'], (int) $edit['id'], (int) $edit['id']]
    );
}

$iconOptions = ['📁','💎','📦','🛠️','💰','🏢','👥','📣','🚗','🏛️','☕','🏠','🛒','🩺','🍿','💻','✈️','🎁','⚡','💧','🌐','⛽','📈','👔','📱','🏦'];
$colorOptions = ['#10b981','#3b82f6','#8b5cf6','#06b6d4','#64748b','#f59e0b','#d97706','#ef4444','#ec4899','#0284c7','#2b826b'];
?>
<section class="mini-stats money-stats">
    <div>
        <span class="dot green"></span>
        <span><small>Total no plano de contas</small><b><?= $totalCategories ?> categorias oficiais</b></span>
    </div>
    <div>
        <span class="dot blue"></span>
        <span><small>Categorias de receita</small><b><?= $incomeCategoriesCount ?> ativas</b></span>
    </div>
    <div>
        <span class="dot red"></span>
        <span><small>Categorias de despesa</small><b><?= $expenseCategoriesCount ?> ativas</b></span>
    </div>
    <div>
        <span class="dot gold"></span>
        <span><small>Com limitador / teto</small><b><?= $withLimits ?> com alerta</b></span>
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
            <option value="income" <?= $typeFilter === 'income' ? 'selected' : '' ?>>💎 Apenas Receitas</option>
            <option value="expense" <?= $typeFilter === 'expense' ? 'selected' : '' ?>>💼 Apenas Despesas Operacionais</option>
            <option value="investment" <?= $typeFilter === 'investment' ? 'selected' : '' ?>>💰 Apenas Investimentos</option>
        </select>
        <span class="live-filter-indicator" data-live-filter-indicator aria-live="polite">Busca automática</span>
    </form>
    <div>
        <?php if ($auth->canWrite()): ?>
            <a class="button primary" href="?page=categories&new=1<?= $businessUnitFilter ? '&bu=' . (int)$businessUnitFilter : '' ?>">＋ Nova categoria</a>
        <?php endif; ?>
    </div>
</section>

<div class="card" style="background: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: 8px; padding: 0.85rem 1.25rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.85rem;">
    <span style="font-size: 1.4rem;">🎯</span>
    <div style="font-size: 0.85rem; line-height: 1.4;">
        <strong style="color: #10b981;">Plano de Contas Estratégico & Enxuto (Consolidado)</strong><br>
        <span style="color: var(--text-muted, #94a3b8);">
            Todas as movimentações estão concentradas nas macro-categorias estratégicas. O detalhamento cirúrgico de cada compra fica registrado no campo <strong>Fornecedor / Favorecido</strong> (ex.: <em>OpenAI, Google, Posto Shell</em>), mantendo os relatórios 100% limpos e objetivos.
        </span>
    </div>
</div>

<div data-live-results>
    <section class="card table-card">
        <div class="table-meta">
            <span><b><?= count($rawCategories) ?></b> categorias ativas encontradas</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Categoria</th>
                        <th>Tipo</th>
                        <th>Unidade de Negócio</th>
                        <th>Teto / Limitador</th>
                        <th>Movimentações</th>
                        <th>Status</th>
                        <th style="text-align: right;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rawCategories): ?>
                        <tr><td colspan="7" class="empty-cell">Nenhuma categoria encontrada para os filtros selecionados.</td></tr>
                    <?php endif; ?>
                    <?php
                    foreach ($rawCategories as $cat):
                        $totalUsage = (int)$cat['expense_count'] + (int)$cat['payment_count'] + (int)$cat['card_count'] + (int)$cat['cash_count'] + (int)$cat['recurring_count'] + (int)$cat['product_count'];
                    ?>
                        <tr>
                            <td>
                                <div class="entity">
                                    <span style="font-size: 1.2rem; width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center; background: <?= h($cat['color'] ?: '#2b826b') ?>22; color: <?= h($cat['color'] ?: '#2b826b') ?>; border: 1px solid <?= h($cat['color'] ?: '#2b826b') ?>55; border-radius: 8px; flex-shrink: 0;">
                                        <?= h($cat['icon'] ?: '📁') ?>
                                    </span>
                                    <span>
                                        <b style="font-size: 13.5px;"><?= h($cat['name']) ?></b>
                                        <small class="block" style="color: var(--muted);">Ordem: <?= (int)$cat['sort_order'] ?></small>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <?php 
                                $typeLabel = match($cat['type']) {
                                    'income' => 'Receita',
                                    'investment' => 'Investimento',
                                    'both' => 'Ambos',
                                    default => 'Despesa Operacional',
                                };
                                $typeStyle = match($cat['type']) {
                                    'income' => 'background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3);',
                                    'investment' => 'background: rgba(6, 182, 212, 0.15); color: #06b6d4; border: 1px solid rgba(6, 182, 212, 0.3);',
                                    'both' => 'background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3);',
                                    default => 'background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3);',
                                };
                                ?>
                                <span class="badge" style="<?= $typeStyle ?>">
                                    <?= $typeLabel ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($cat['bu_name']): ?>
                                    <span class="badge" style="background: <?= h($cat['bu_color'] ?: '#2b826b') ?>18; color: <?= h($cat['bu_color'] ?: '#2b826b') ?>; border: 1px solid <?= h($cat['bu_color'] ?: '#2b826b') ?>44;">
                                        <?= h($cat['bu_icon']) ?> <?= h($cat['bu_name']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge muted">Global (Todos os negócios)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($cat['budget_limit_percent'])): ?>
                                    <b class="positive" style="font-size: 12px;">Máx. <?= number_format((float)$cat['budget_limit_percent'], 1, ',', '.') ?>% da receita</b>
                                <?php elseif (!empty($cat['budget_limit_amount'])): ?>
                                    <b class="gold" style="font-size: 12px;">Teto: <?= money($cat['budget_limit_amount']) ?></b>
                                <?php else: ?>
                                    <span class="muted" style="font-size: 12px;">Sem restrição</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge muted" style="font-size: 12px;">
                                    <?= $totalUsage ?> vínculo(s)
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $cat['active'] ? 'success' : 'muted' ?>">
                                    <?= $cat['active'] ? 'Ativa' : 'Inativa' ?>
                                </span>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <a class="button ghost small" href="?page=categories&edit=<?= (int) $cat['id'] ?><?= $businessUnitFilter ? '&bu=' . (int)$businessUnitFilter : '' ?>" title="Editar categoria">✎ Editar</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php if ($showForm): 
    $modalTitle = $edit ? 'Editar categoria' : 'Nova categoria';
?>
<div class="modal open">
    <a class="modal-backdrop" href="?page=categories<?= $businessUnitFilter ? '&bu=' . (int)$businessUnitFilter : '' ?>"></a>
    <section class="modal-panel">
        <header>
            <div>
                <p class="eyebrow">CATEGORIA</p>
                <h2><?= h($modalTitle) ?></h2>
            </div>
            <a href="?page=categories<?= $businessUnitFilter ? '&bu=' . (int)$businessUnitFilter : '' ?>" class="modal-close">×</a>
        </header>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_category">
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
            <input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">

            <label class="span-2">
                Nome da categoria
                <input name="name" required value="<?= h($edit['name'] ?? '') ?>" placeholder="Ex.: Softwares & Ferramentas, Equipe & Terceiros, Marketing...">
            </label>

            <label>
                Tipo de movimentação
                <select name="type" required>
                    <option value="expense" <?= ($edit['type'] ?? 'expense') === 'expense' ? 'selected' : '' ?>>Despesa operacional / consumo</option>
                    <option value="investment" <?= ($edit['type'] ?? '') === 'investment' ? 'selected' : '' ?>>Investimento em crescimento</option>
                    <option value="income" <?= ($edit['type'] ?? '') === 'income' ? 'selected' : '' ?>>Receita / Entrada</option>
                    <option value="both" <?= ($edit['type'] ?? '') === 'both' ? 'selected' : '' ?>>Ambos (Receita e Despesa)</option>
                </select>
            </label>

            <label>
                Unidade de negócio
                <select name="business_unit_id">
                    <option value="">Global / Qualquer negócio</option>
                    <?php 
                    $selectedBuModal = $edit ? (int) ($edit['business_unit_id'] ?? 0) : $businessUnitFilter;
                    foreach ($allBusinesses as $bu): ?>
                        <option value="<?= (int) $bu['id'] ?>" <?= $selectedBuModal === (int) $bu['id'] ? 'selected' : '' ?>>
                            <?= h($bu['icon']) ?> <?= h($bu['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Ícone representativo
                <select name="icon">
                    <?php foreach ($iconOptions as $ico): ?>
                        <option value="<?= h($ico) ?>" <?= ($edit['icon'] ?? '📁') === $ico ? 'selected' : '' ?>><?= $ico ?> <?= $ico ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Cor visual
                <select name="color">
                    <?php foreach ($colorOptions as $col): ?>
                        <option value="<?= h($col) ?>" <?= ($edit['color'] ?? '#2b826b') === $col ? 'selected' : '' ?> style="color: <?= h($col) ?>;">■ <?= h($col) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Limitador % sobre faturamento
                <input name="budget_limit_percent" type="number" step="0.1" min="0" max="100" value="<?= decimal_input($edit['budget_limit_percent'] ?? '') ?>" placeholder="Ex: 15.0">
                <small>Alerta no relatório se ultrapassar esta %.</small>
            </label>

            <label>
                Ou Teto fixo em R$ (Mensal)
                <input name="budget_limit_amount" type="number" step="0.01" min="0" value="<?= decimal_input($edit['budget_limit_amount'] ?? '') ?>" placeholder="Ex: 3000.00">
                <small>Alerta no relatório se ultrapassar este valor.</small>
            </label>

            <label class="check-inline span-2">
                <input type="checkbox" name="active" value="1" <?= !isset($edit['active']) || $edit['active'] ? 'checked' : '' ?>>
                Categoria ativa para novos lançamentos
            </label>

            <footer class="span-2">
                <a class="button ghost" href="?page=categories<?= $businessUnitFilter ? '&bu=' . (int)$businessUnitFilter : '' ?>">Cancelar</a>
                <button class="button primary">Salvar categoria</button>
            </footer>
        </form>

        <?php if ($edit && $auth->canWrite()): ?>
            <?php 
            $delConfirmMsg = "Excluir esta categoria definitivamente?";
            if ($editLinkedCount > 0) {
                $delConfirmMsg = "ATENÇÃO: Esta categoria possui {$editLinkedCount} lançamento(s) vinculados. Deseja realmente excluir e desvincular os registros?";
            }
            ?>
            <form method="post" class="danger-zone" data-confirm="<?= h($delConfirmMsg) ?>" style="margin-top: 15px; border-top: 1px solid var(--line); padding-top: 15px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_category">
                <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
                <input type="hidden" name="force" value="1">
                <?php if ($editLinkedCount > 0): ?>
                    <p style="color: var(--red); font-size: 12px; margin-bottom: 8px;">
                        ⚠ Possui <?= $editLinkedCount ?> lançamento(s) financeiro(s) associado(s).
                    </p>
                <?php endif; ?>
                <button type="submit" class="button" style="color: var(--red); border-color: var(--red-100); background: transparent;">
                    Excluir categoria
                </button>
            </form>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>
