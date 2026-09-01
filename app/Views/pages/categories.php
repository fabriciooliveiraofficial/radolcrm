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
if (in_array($typeFilter, ['expense', 'income', 'investment', 'both'], true)) {
    $where .= ' AND (c.type = ? OR c.type = "both")';
    $params[] = $typeFilter;
}

$rawCategories = $db->fetchAll(
    'SELECT c.*, bu.name bu_name, bu.icon bu_icon, bu.color bu_color, pcat.name parent_name,
        (SELECT COUNT(*) FROM expenses e WHERE e.category_id = c.id) expense_count,
        (SELECT COUNT(*) FROM payments p WHERE p.category_id = c.id) payment_count,
        (SELECT COUNT(*) FROM credit_card_transactions ct WHERE ct.category_id = c.id) card_count,
        (SELECT COUNT(*) FROM cash_entries ce WHERE ce.category_id = c.id) cash_count,
        (SELECT COUNT(*) FROM recurring_templates rt WHERE rt.category_id = c.id) recurring_count,
        (SELECT COUNT(*) FROM categories sub WHERE sub.parent_id = c.id) sub_count
     FROM categories c
     LEFT JOIN business_units bu ON bu.id = c.business_unit_id
     LEFT JOIN categories pcat ON pcat.id = c.parent_id' . $where . '
     ORDER BY COALESCE(c.parent_id, c.id) ASC, (c.parent_id IS NOT NULL) ASC, c.sort_order ASC, c.id ASC',
    $params
);

$totalCategories = count($rawCategories);
$mainCategories = count(array_filter($rawCategories, static fn($c) => $c['parent_id'] === null));
$subCategories = count(array_filter($rawCategories, static fn($c) => $c['parent_id'] !== null));
$withLimits = count(array_filter($rawCategories, static fn($c) => !empty($c['budget_limit_percent']) || !empty($c['budget_limit_amount'])));

$edit = isset($_GET['edit']) ? $db->fetch('SELECT * FROM categories WHERE id = ?', [(int) $_GET['edit']]) : null;
$preselectedParentId = isset($_GET['parent']) ? (int) $_GET['parent'] : (int) ($edit['parent_id'] ?? 0);
$preselectedParent = $preselectedParentId > 0 ? $db->fetch('SELECT * FROM categories WHERE id = ?', [$preselectedParentId]) : null;
$showForm = isset($_GET['new']) || isset($_GET['new_sub']) || isset($_GET['parent']) || $edit;

$potentialParents = $db->fetchAll('SELECT id, name, icon, business_unit_id FROM categories WHERE parent_id IS NULL' . ($edit ? ' AND id != ' . (int)$edit['id'] : '') . ' ORDER BY name ASC');

$editLinkedCount = 0;
if ($edit) {
    $editLinkedCount = (int) $db->value('SELECT (SELECT COUNT(*) FROM expenses WHERE category_id=?) + (SELECT COUNT(*) FROM payments WHERE category_id=?) + (SELECT COUNT(*) FROM cash_entries WHERE category_id=?) + (SELECT COUNT(*) FROM credit_card_transactions WHERE category_id=?) + (SELECT COUNT(*) FROM recurring_templates WHERE category_id=?)', [(int) $edit['id'], (int) $edit['id'], (int) $edit['id'], (int) $edit['id'], (int) $edit['id']]);
    $editSubCount = (int) $db->value('SELECT COUNT(*) FROM categories WHERE parent_id=?', [(int) $edit['id']]);
}

$iconOptions = ['📁','🛒','🏠','🚗','🩺','🎓','📱','🏦','🍿','📣','💻','🏛️','👥','🏢','💎','✈️','🎁','🍕','⚡','💧','🌐','⛽','🛠️','📈','💰','👔'];
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
        <span class="dot blue"></span>
        <span><small>Subcategorias</small><b><?= $subCategories ?> ativas</b></span>
    </div>
    <div>
        <span class="dot gold"></span>
        <span><small>Com limitador de orçamento</small><b><?= $withLimits ?> categorias</b></span>
    </div>
</section>

<section class="toolbar list-toolbar">
    <form class="search-filters" method="get" data-live-filter>
        <input type="hidden" name="page" value="categories">
        <label class="search-box">⌕<input name="q" autocomplete="off" placeholder="Buscar categoria ou subcategoria..." value="<?= h($search) ?>"></label>
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
            <option value="expense" <?= $typeFilter === 'expense' ? 'selected' : '' ?>>Despesas operacionais</option>
            <option value="investment" <?= $typeFilter === 'investment' ? 'selected' : '' ?>>Investimentos</option>
            <option value="income" <?= $typeFilter === 'income' ? 'selected' : '' ?>>Receitas</option>
            <option value="both" <?= $typeFilter === 'both' ? 'selected' : '' ?>>Ambos</option>
        </select>
        <span class="live-filter-indicator" data-live-filter-indicator aria-live="polite">Busca automática</span>
    </form>
    <div>
        <?php if ($auth->canWrite()): ?>
            <a class="button primary" href="?page=categories&new=1<?= $businessUnitFilter ? '&bu=' . (int)$businessUnitFilter : '' ?>">＋ Nova categoria principal</a>
        <?php endif; ?>
    </div>
</section>

<div data-live-results>
    <section class="card table-card">
        <div class="table-meta">
            <span><b><?= $totalCategories ?></b> categorias e subcategorias cadastradas</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Categoria / Subcategoria</th>
                        <th>Negócio</th>
                        <th>Tipo</th>
                        <th>Teto / Limitador</th>
                        <th>Lançamentos</th>
                        <th>Status</th>
                        <th style="text-align: right;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rawCategories): ?>
                        <tr><td colspan="7" class="empty-cell">Nenhuma categoria encontrada. Clique em "Nova categoria principal" para cadastrar.</td></tr>
                    <?php endif; ?>
                    <?php
                    foreach ($rawCategories as $cat):
                        $isSub = $cat['parent_id'] !== null;
                        $totalUsage = (int)$cat['expense_count'] + (int)$cat['payment_count'] + (int)$cat['card_count'] + (int)$cat['cash_count'] + (int)$cat['recurring_count'];
                    ?>
                        <tr class="<?= $isSub ? 'sub-category-row' : 'parent-category-row' ?>" style="<?= $isSub ? 'background: rgba(0,0,0,0.015);' : '' ?>">
                            <td>
                                <div class="entity" style="<?= $isSub ? 'padding-left: 2rem;' : '' ?>">
                                    <span style="font-size: 1.1rem; width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center; background: <?= h($cat['color'] ?: '#2b826b') ?>22; color: <?= h($cat['color'] ?: '#2b826b') ?>; border: 1px solid <?= h($cat['color'] ?: '#2b826b') ?>55; border-radius: 7px; flex-shrink: 0;">
                                        <?= h($cat['icon'] ?: '📁') ?>
                                    </span>
                                    <span>
                                        <b style="<?= !$isSub ? 'font-size: 13.5px;' : 'font-size: 13px; font-weight: 600;' ?>">
                                            <?= $isSub ? '<span style="color: var(--muted); margin-right: 4px;">↳</span>' : '' ?><?= h($cat['name']) ?>
                                        </b>
                                        <?php if (!$isSub && (int) $cat['sub_count'] > 0): ?>
                                            <small class="block" style="color: var(--muted);"><?= (int) $cat['sub_count'] ?> subcategoria(s)</small>
                                        <?php elseif ($isSub && $cat['parent_name']): ?>
                                            <small class="block" style="color: var(--muted);">Grupo: <?= h($cat['parent_name']) ?></small>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <?php if ($cat['bu_name']): ?>
                                    <span class="badge" style="background: <?= h($cat['bu_color'] ?: '#2b826b') ?>18; color: <?= h($cat['bu_color'] ?: '#2b826b') ?>; border: 1px solid <?= h($cat['bu_color'] ?: '#2b826b') ?>44;">
                                        <?= h($cat['bu_icon']) ?> <?= h($cat['bu_name']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge muted">Global (Todos)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                $typeLabel = match($cat['type']) {
                                    'income' => 'Receita',
                                    'investment' => 'Investimento',
                                    'both' => 'Ambos',
                                    default => 'Despesa',
                                };
                                $typeClass = match($cat['type']) {
                                    'income' => 'success',
                                    'investment' => 'info',
                                    'both' => 'gold',
                                    default => 'muted',
                                };
                                ?>
                                <span class="badge <?= $typeClass ?>">
                                    <?= $typeLabel ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($cat['budget_limit_percent'])): ?>
                                    <b class="positive" style="font-size: 12px;">Máx. <?= number_format((float)$cat['budget_limit_percent'], 1, ',', '.') ?>% da receita</b>
                                <?php elseif (!empty($cat['budget_limit_amount'])): ?>
                                    <b class="gold" style="font-size: 12px;">Teto: <?= money($cat['budget_limit_amount']) ?></b>
                                <?php else: ?>
                                    <span class="muted" style="font-size: 12px;">Sem limitador</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="font-size: 12px; <?= $totalUsage > 0 ? 'font-weight: 600; color: var(--ink);' : 'color: var(--muted);' ?>">
                                    <?= $totalUsage ?> vínculo(s)
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $cat['active'] ? 'success' : 'muted' ?>">
                                    <?= $cat['active'] ? 'Ativa' : 'Inativa' ?>
                                </span>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <?php if (!$isSub && $auth->canWrite()): ?>
                                    <a class="button ghost" style="min-height: 28px; padding: 0 8px; font-size: 11.5px; margin-right: 4px;" href="?page=categories&parent=<?= (int) $cat['id'] ?><?= $businessUnitFilter ? '&bu=' . (int)$businessUnitFilter : '' ?>" title="Adicionar subcategoria neste grupo">
                                        ＋ Subcategoria
                                    </a>
                                <?php endif; ?>
                                <a class="row-action" href="?page=categories&edit=<?= (int) $cat['id'] ?><?= $businessUnitFilter ? '&bu=' . (int)$businessUnitFilter : '' ?>" title="Editar categoria">•••</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php if ($showForm): 
    $isSubModal = $preselectedParentId > 0;
    $modalTitle = $edit 
        ? ($edit['parent_id'] ? 'Editar subcategoria' : 'Editar categoria principal') 
        : ($isSubModal ? 'Nova subcategoria' . ($preselectedParent ? ' de ' . $preselectedParent['name'] : '') : 'Nova categoria principal');
?>
<div class="modal open">
    <a class="modal-backdrop" href="?page=categories<?= $businessUnitFilter ? '&bu=' . (int)$businessUnitFilter : '' ?>"></a>
    <section class="modal-panel">
        <header>
            <div>
                <p class="eyebrow"><?= $isSubModal ? 'SUBCATEGORIA' : 'CATEGORIA PRINCIPAL' ?></p>
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
                Nome da categoria / subcategoria
                <input name="name" required value="<?= h($edit['name'] ?? '') ?>" placeholder="Ex.: Gasolina, Alimentação, Moradia, SaaS, Equipe">
            </label>

            <label>
                Hierarquia / Categoria Pai
                <select name="parent_id">
                    <option value="">Nenhuma (Esta é uma categoria principal / grupo)</option>
                    <?php foreach ($potentialParents as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= $preselectedParentId === (int) $p['id'] ? 'selected' : '' ?>>
                            <?= h($p['icon']) ?> <?= h($p['name']) ?> (Grupo Principal)
                        </option>
                    <?php endforeach; ?>
                </select>
                <small>Selecione um grupo acima para torná-la uma subcategoria.</small>
            </label>

            <label>
                Unidade de negócio
                <select name="business_unit_id">
                    <option value="">Global / Qualquer negócio</option>
                    <?php 
                    $selectedBuModal = $edit ? (int) ($edit['business_unit_id'] ?? 0) : ($preselectedParent ? (int)$preselectedParent['business_unit_id'] : $businessUnitFilter);
                    foreach ($allBusinesses as $bu): ?>
                        <option value="<?= (int) $bu['id'] ?>" <?= $selectedBuModal === (int) $bu['id'] ? 'selected' : '' ?>>
                            <?= h($bu['icon']) ?> <?= h($bu['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small>Vincule a um negócio específico ou deixe global.</small>
            </label>

            <label>
                Tipo de movimentação
                <select name="type">
                    <option value="expense" <?= ($edit['type'] ?? 'expense') === 'expense' ? 'selected' : '' ?>>Despesa operacional / consumo</option>
                    <option value="investment" <?= ($edit['type'] ?? '') === 'investment' ? 'selected' : '' ?>>Investimento em crescimento</option>
                    <option value="income" <?= ($edit['type'] ?? '') === 'income' ? 'selected' : '' ?>>Receita / Entrada</option>
                    <option value="both" <?= ($edit['type'] ?? '') === 'both' ? 'selected' : '' ?>>Ambos (Receita e Despesa)</option>
                </select>
            </label>

            <label>
                Ícone representativo
                <select name="icon">
                    <?php foreach ($iconOptions as $ico): ?>
                        <option value="<?= h($ico) ?>" <?= ($edit['icon'] ?? ($preselectedParent ? $preselectedParent['icon'] : '📁')) === $ico ? 'selected' : '' ?>><?= $ico ?> <?= $ico ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Cor visual
                <select name="color">
                    <?php foreach ($colorOptions as $col): ?>
                        <option value="<?= h($col) ?>" <?= ($edit['color'] ?? ($preselectedParent ? $preselectedParent['color'] : '#2b826b')) === $col ? 'selected' : '' ?> style="color: <?= h($col) ?>;">■ <?= h($col) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Limitador % sobre o faturamento
                <input name="budget_limit_percent" type="number" step="0.1" min="0" max="100" value="<?= decimal_input($edit['budget_limit_percent'] ?? '') ?>" placeholder="Ex: 15.0">
                <small>Alerta quando o gasto ultrapassar esta % da receita.</small>
            </label>

            <label>
                Ou Teto fixo em R$ (Orçamento mensal)
                <input name="budget_limit_amount" type="number" step="0.01" min="0" value="<?= decimal_input($edit['budget_limit_amount'] ?? '') ?>" placeholder="Ex: 1500.00">
                <small>Alerta quando o gasto ultrapassar este valor em reais.</small>
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
            if ($editLinkedCount > 0 || $editSubCount > 0) {
                $delConfirmMsg = "ATENÇÃO: Esta categoria possui " . ($editLinkedCount > 0 ? "{$editLinkedCount} lançamento(s) vinculados" : "") . ($editLinkedCount > 0 && $editSubCount > 0 ? " e " : "") . ($editSubCount > 0 ? "{$editSubCount} subcategoria(s)" : "") . ". Os registros serão desvinculados com segurança para não corromper o histórico. Deseja continuar?";
            }
            ?>
            <form method="post" class="danger-zone" data-confirm="<?= h($delConfirmMsg) ?>" style="margin-top: 15px; border-top: 1px solid var(--line); padding-top: 15px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_category">
                <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
                <input type="hidden" name="force" value="1">
                <?php if ($editLinkedCount > 0 || $editSubCount > 0): ?>
                    <p style="color: var(--red); font-size: 12px; margin-bottom: 8px;">
                        ⚠ Possui <?= $editLinkedCount ?> lançamento(s) financeiro(s) e <?= $editSubCount ?> subcategoria(s) associadas.
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
