<?php
$tab = (string) ($_GET['tab'] ?? 'installments');
$search = trim((string) ($_GET['q'] ?? ''));
$buFilter = isset($_GET['bu']) && $_GET['bu'] !== '' ? (int) $_GET['bu'] : null;
$statusFilter = (string) ($_GET['status'] ?? 'pending');
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');

$allBusinesses = $db->fetchAll('SELECT id, name, icon, color, is_personal FROM business_units WHERE active = 1 ORDER BY sort_order ASC, id ASC');
$allCategories = $db->fetchAll('SELECT c.*, p.name parent_name, bu.name bu_name FROM categories c LEFT JOIN categories p ON p.id = c.parent_id LEFT JOIN business_units bu ON bu.id = c.business_unit_id WHERE c.active = 1 ORDER BY COALESCE(c.parent_id, c.id) ASC, (c.parent_id IS NOT NULL) ASC, c.name ASC');

// Installments query
$whereInst = ' WHERE 1=1';
$paramsInst = [];
if ($search !== '') {
    $whereInst .= " AND (i.description LIKE ? OR i.supplier LIKE ? OR bu.name LIKE ? OR cat.name LIKE ?)";
    $paramsInst[] = '%' . $search . '%';
    $paramsInst[] = '%' . $search . '%';
    $paramsInst[] = '%' . $search . '%';
    $paramsInst[] = '%' . $search . '%';
}
if ($buFilter !== null) {
    $whereInst .= ' AND i.business_unit_id = ?';
    $paramsInst[] = $buFilter;
}
if ($statusFilter === 'pending') {
    $whereInst .= ' AND i.status = "pending"';
} elseif ($statusFilter === 'paid') {
    $whereInst .= ' AND i.status = "paid"';
} elseif ($statusFilter === 'overdue') {
    $whereInst .= ' AND i.status = "pending" AND i.due_date < ?';
    $paramsInst[] = $today;
} elseif ($statusFilter === 'month') {
    $whereInst .= ' AND i.due_date BETWEEN ? AND ?';
    $paramsInst[] = $monthStart;
    $paramsInst[] = $monthEnd;
}

$installments = $db->fetchAll(
    'SELECT i.*, bu.name bu_name, bu.icon bu_icon, bu.color bu_color, cat.name cat_name, cat.icon cat_icon, cat.color cat_color,
            rt.recurrence rt_recurrence, rt.type rt_type
     FROM installments i
     LEFT JOIN business_units bu ON bu.id = i.business_unit_id
     LEFT JOIN categories cat ON cat.id = i.category_id
     LEFT JOIN recurring_templates rt ON rt.id = i.template_id' . $whereInst . '
     ORDER BY i.due_date ASC, i.installment_number ASC
     LIMIT 300',
    $paramsInst
);

// Templates query
$whereTpl = ' WHERE 1=1';
$paramsTpl = [];
if ($search !== '') {
    $whereTpl .= " AND (rt.description LIKE ? OR rt.supplier LIKE ?)";
    $paramsTpl[] = '%' . $search . '%';
    $paramsTpl[] = '%' . $search . '%';
}
if ($buFilter !== null) {
    $whereTpl .= ' AND rt.business_unit_id = ?';
    $paramsTpl[] = $buFilter;
}
$templates = $db->fetchAll(
    'SELECT rt.*, bu.name bu_name, bu.icon bu_icon, bu.color bu_color, cat.name cat_name, cat.icon cat_icon,
        (SELECT COUNT(*) FROM installments inst WHERE inst.template_id = rt.id) generated_count,
        (SELECT COUNT(*) FROM installments inst WHERE inst.template_id = rt.id AND inst.status = "paid") paid_count,
        (SELECT COALESCE(SUM(inst.amount_brl), 0) FROM installments inst WHERE inst.template_id = rt.id AND inst.status = "pending") pending_total_brl
     FROM recurring_templates rt
     LEFT JOIN business_units bu ON bu.id = rt.business_unit_id
     LEFT JOIN categories cat ON cat.id = rt.category_id' . $whereTpl . '
     ORDER BY rt.active DESC, rt.id DESC',
    $paramsTpl
);

// Stats
$statsMonthDue = (float) $db->value('SELECT COALESCE(SUM(amount_brl), 0) FROM installments WHERE status = "pending" AND due_date BETWEEN ? AND ?', [$monthStart, $monthEnd]);
$statsMonthPaid = (float) $db->value('SELECT COALESCE(SUM(amount_brl), 0) FROM installments WHERE status = "paid" AND payment_date BETWEEN ? AND ?', [$monthStart, $monthEnd]);
$statsOverdue = (float) $db->value('SELECT COALESCE(SUM(amount_brl), 0) FROM installments WHERE status = "pending" AND due_date < ?', [$today]);
$statsFuturePending = (float) $db->value('SELECT COALESCE(SUM(amount_brl), 0) FROM installments WHERE status = "pending"');

$showNewModal = isset($_GET['new']);
$editInstallment = isset($_GET['edit_inst']) ? $db->fetch('SELECT * FROM installments WHERE id = ?', [(int) $_GET['edit_inst']]) : null;
$payInstallment = isset($_GET['pay_inst']) ? $db->fetch('SELECT * FROM installments WHERE id = ?', [(int) $_GET['pay_inst']]) : null;
?>
<section class="mini-stats money-stats">
    <div>
        <span class="dot gold"></span>
        <span><small>A vencer este mês</small><b><?= money($statsMonthDue) ?></b></span>
    </div>
    <div>
        <span class="dot green"></span>
        <span><small>Pagos este mês</small><b><?= money($statsMonthPaid) ?></b></span>
    </div>
    <div>
        <span class="dot red"></span>
        <span><small>Parcelas em atraso</small><b><?= money($statsOverdue) ?></b></span>
    </div>
    <div>
        <span class="dot purple"></span>
        <span><small>Comprometimento total futuro</small><b><?= money($statsFuturePending) ?></b></span>
    </div>
</section>

<div class="tabs-nav" style="display: flex; gap: 0.5rem; margin-bottom: 1.25rem;">
    <a class="button <?= $tab === 'installments' ? 'primary' : 'ghost' ?>" href="?page=recurring&tab=installments<?= $buFilter ? '&bu=' . $buFilter : '' ?>">
        📅 Cronograma de Parcelas
    </a>
    <a class="button <?= $tab === 'templates' ? 'primary' : 'ghost' ?>" href="?page=recurring&tab=templates<?= $buFilter ? '&bu=' . $buFilter : '' ?>">
        📑 Contratos e Recorrências Ativas
    </a>
</div>

<section class="toolbar list-toolbar">
    <form class="search-filters" method="get" data-live-filter>
        <input type="hidden" name="page" value="recurring">
        <input type="hidden" name="tab" value="<?= h($tab) ?>">
        <label class="search-box">⌕<input name="q" autocomplete="off" placeholder="Buscar parcela ou contrato..." value="<?= h($search) ?>"></label>
        
        <select name="bu">
            <option value="">Todos os negócios</option>
            <?php foreach ($allBusinesses as $bu): ?>
                <option value="<?= (int) $bu['id'] ?>" <?= $buFilter === (int) $bu['id'] ? 'selected' : '' ?>>
                    <?= h($bu['icon']) ?> <?= h($bu['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <?php if ($tab === 'installments'): ?>
        <select name="status">
            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pendentes</option>
            <option value="month" <?= $statusFilter === 'month' ? 'selected' : '' ?>>Vencendo no mês</option>
            <option value="overdue" <?= $statusFilter === 'overdue' ? 'selected' : '' ?>>Em atraso</option>
            <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Pagas</option>
            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Todas as parcelas</option>
        </select>
        <?php endif; ?>

        <span class="live-filter-indicator" data-live-filter-indicator aria-live="polite">Busca automática</span>
    </form>
    <div>
        <?php if ($auth->canWrite()): ?>
            <a class="button primary" href="?page=recurring&new=1">＋ Novo lançamento parcelado / recorrente</a>
        <?php endif; ?>
    </div>
</section>

<div data-live-results>
<?php if ($tab === 'installments'): ?>
    <section class="card table-card">
        <div class="table-meta">
            <span><b><?= count($installments) ?></b> parcelas listadas</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Parcela / Descrição</th>
                        <th>Negócio</th>
                        <th>Categoria</th>
                        <th>Vencimento</th>
                        <th>Valor</th>
                        <th>Status</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$installments): ?>
                        <tr><td colspan="7" class="empty-cell">Nenhuma parcela encontrada para os filtros selecionados.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($installments as $item):
                        $isOverdue = $item['status'] === 'pending' && $item['due_date'] < $today;
                    ?>
                        <tr>
                            <td>
                                <div class="entity">
                                    <span class="flow-icon <?= $item['status'] === 'paid' ? 'in' : ($isOverdue ? 'out' : '') ?>">
                                        <?= $item['status'] === 'paid' ? '✓' : ($isOverdue ? '!' : '◷') ?>
                                    </span>
                                    <span>
                                        <b><?= h($item['description']) ?></b>
                                        <small><?= h($item['supplier'] ?: 'Sem fornecedor') ?><?= $item['notes'] ? ' · ' . h($item['notes']) : '' ?></small>
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
                                <?php if (!empty($item['cat_name'])): ?>
                                    <span><?= h($item['cat_icon']) ?> <?= h($item['cat_name']) ?></span>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="<?= $isOverdue ? 'negative font-bold' : '' ?>">
                                    <?= date_br($item['due_date']) ?>
                                    <?= $isOverdue ? ' (Atrasado)' : '' ?>
                                </span>
                            </td>
                            <td>
                                <b><?= money($item['amount_brl']) ?></b>
                                <?php if ($item['currency'] === 'USD'): ?>
                                    <small class="block">USD <?= money($item['amount'], 'USD') ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $item['status'] === 'paid' ? 'success' : ($isOverdue ? 'danger' : 'warning') ?>">
                                    <?= $item['status'] === 'paid' ? 'Pago em ' . date_br($item['payment_date']) : ($isOverdue ? 'Vencida' : 'Pendente') ?>
                                </span>
                            </td>
                            <td>
                                <div style="display: flex; gap: 0.35rem; align-items: center;">
                                    <?php if ($item['status'] !== 'paid' && $auth->canWrite()): ?>
                                        <a class="button primary small" style="padding: 0.25rem 0.6rem; font-size: 0.75rem;" href="?page=recurring&pay_inst=<?= (int) $item['id'] ?>">✓ Pagar</a>
                                        <a class="row-action" href="?page=recurring&edit_inst=<?= (int) $item['id'] ?>" title="Editar parcela">✎</a>
                                    <?php elseif ($item['status'] === 'paid'): ?>
                                        <span class="badge success" title="Lançado no financeiro">Lançado</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php else: ?>
    <section class="card table-card">
        <div class="table-meta">
            <span><b><?= count($templates) ?></b> contratos / modelos cadastrados</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Contrato / Descrição</th>
                        <th>Negócio</th>
                        <th>Categoria</th>
                        <th>Recorrência</th>
                        <th>Valor Parcela</th>
                        <th>Parcelas</th>
                        <th>Saldo a Pagar</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$templates): ?>
                        <tr><td colspan="9" class="empty-cell">Nenhum contrato recorrente cadastrado.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($templates as $tpl):
                        $recLabels = ['monthly' => 'Mensal', 'weekly' => 'Semanal', 'biweekly' => 'Quinzenal', 'quarterly' => 'Trimestral', 'annual' => 'Anual'];
                    ?>
                        <tr>
                            <td>
                                <div class="entity">
                                    <span class="flow-icon in">🔁</span>
                                    <span>
                                        <b><?= h($tpl['description']) ?></b>
                                        <small><?= h($tpl['supplier'] ?: 'Sem fornecedor') ?></small>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($tpl['bu_name'])): ?>
                                    <span class="badge" style="background: <?= h($tpl['bu_color'] ?: '#2b826b') ?>15; color: <?= h($tpl['bu_color'] ?: '#2b826b') ?>; border: 1px solid <?= h($tpl['bu_color'] ?: '#2b826b') ?>44;">
                                        <?= h($tpl['bu_icon'] ?: '💼') ?> <?= h($tpl['bu_name']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge muted">Geral</span>
                                <?php endif; ?>
                            </td>
                            <td><?= h($tpl['cat_icon'] ?? '📁') ?> <?= h($tpl['cat_name'] ?? 'Geral') ?></td>
                            <td><span class="badge info"><?= $recLabels[$tpl['recurrence']] ?? 'Mensal' ?></span></td>
                            <td><b><?= money($tpl['amount'], $tpl['currency']) ?></b></td>
                            <td>
                                <b><?= (int) $tpl['paid_count'] ?> / <?= $tpl['total_installments'] ? (int) $tpl['total_installments'] : '∞' ?></b>
                                <small class="block"><?= (int) $tpl['generated_count'] ?> geradas</small>
                            </td>
                            <td><b class="negative"><?= money($tpl['pending_total_brl']) ?></b></td>
                            <td>
                                <span class="badge <?= $tpl['active'] ? 'success' : 'muted' ?>">
                                    <?= $tpl['active'] ? 'Ativo' : 'Encerrado' ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($auth->canWrite()): ?>
                                    <form method="post" data-confirm="Excluir este contrato e todas as parcelas pendentes?" style="display: inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_recurring_template">
                                        <input type="hidden" name="id" value="<?= (int) $tpl['id'] ?>">
                                        <button class="row-action" type="submit" style="background:none;border:none;color:#ef4444;cursor:pointer;">✕</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
</div>

<?php if ($showNewModal): ?>
<div class="modal open">
    <a class="modal-backdrop" href="?page=recurring"></a>
    <section class="modal-panel wide" style="max-width: 900px;">
        <header>
            <div>
                <p class="eyebrow">RECORRÊNCIA E PARCELAMENTO</p>
                <h2>Novo lançamento programado</h2>
            </div>
            <a href="?page=recurring" class="modal-close">×</a>
        </header>
        <form method="post" class="form-grid" id="recurringForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_recurring_template">
            <input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">

            <label>
                Unidade de Negócio
                <select name="business_unit_id" required>
                    <option value="">Selecione o negócio ou finança…</option>
                    <?php foreach ($allBusinesses as $bu): ?>
                        <option value="<?= (int) $bu['id'] ?>">
                            <?= h($bu['icon']) ?> <?= h($bu['name']) ?><?= $bu['is_personal'] ? ' (Pessoal)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Categoria
                <select name="category_id" required>
                    <option value="">Selecione a categoria…</option>
                    <?php foreach ($allCategories as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>">
                            <?= h($cat['icon']) ?> <?= $cat['parent_name'] ? h($cat['parent_name']) . ' ↳ ' : '' ?><?= h($cat['name']) ?><?= $cat['bu_name'] ? ' (' . h($cat['bu_name']) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="span-2">
                Descrição do Contrato / Financiamento
                <input name="description" id="fieldDesc" required placeholder="Ex.: Financiamento Carro Corolla, Aluguel Imóvel, Seguro, Internet">
            </label>

            <label class="span-2">
                Fornecedor / Credor / Favorecido
                <input name="supplier" id="fieldSupplier" placeholder="Ex.: Banco Santander, Imobiliária Nova, Claro, Porto Seguro">
            </label>

            <label>
                Tipo
                <select name="type">
                    <option value="expense">Despesa / Saída</option>
                    <option value="income">Receita / Entrada</option>
                </select>
            </label>

            <label>
                Recorrência
                <select name="recurrence" id="fieldRecurrence">
                    <option value="monthly">Mensal</option>
                    <option value="weekly">Semanal</option>
                    <option value="biweekly">Quinzenal</option>
                    <option value="quarterly">Trimestral</option>
                    <option value="annual">Anual</option>
                </select>
            </label>

            <label>
                Valor por parcela (R$)
                <input name="amount" id="fieldAmount" type="number" step="0.01" min="0.01" required placeholder="0,00">
            </label>

            <label>
                Nº de parcelas (deixe vazio para conta contínua)
                <input name="total_installments" id="fieldInstallments" type="number" min="1" max="360" placeholder="Ex.: 12, 24, 36, 48...">
            </label>

            <label>
                Data do 1º vencimento
                <input name="start_date" id="fieldStartDate" type="date" required value="<?= date('Y-m-d') ?>">
            </label>

            <label class="check-label check-inline span-2">
                <input type="checkbox" name="active" value="1" checked>
                <span>Contrato / Recorrência ativa</span>
            </label>

            <!-- Interactive Installment Preview Table -->
            <div class="span-2" style="background: var(--bg-card, #131b26); border: 1px solid var(--border-color, #233247); border-radius: 8px; padding: 1rem; margin-top: 0.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                    <div>
                        <h4 style="margin: 0; font-size: 0.95rem; font-weight: 600;">✨ Prévia e Ajuste Flexível das Parcelas</h4>
                        <small style="color: var(--text-muted, #8b9bb4);">Você pode editar individualmente as datas de vencimento e os valores (ex.: balão, reajuste) antes de salvar.</small>
                    </div>
                    <button type="button" class="button ghost small" id="btnRefreshPreview">🔄 Recalcular Prévia</button>
                </div>
                
                <div style="max-height: 260px; overflow-y: auto; border: 1px solid var(--border-color, #233247); border-radius: 6px;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                        <thead style="position: sticky; top: 0; background: var(--bg-card, #131b26); z-index: 1;">
                            <tr>
                                <th style="padding: 0.5rem; text-align: left; width: 60px;">Nº</th>
                                <th style="padding: 0.5rem; text-align: left;">Descrição da Parcela</th>
                                <th style="padding: 0.5rem; text-align: left; width: 160px;">Vencimento</th>
                                <th style="padding: 0.5rem; text-align: right; width: 140px;">Valor (R$)</th>
                            </tr>
                        </thead>
                        <tbody id="previewTableBody">
                            <tr><td colspan="4" style="text-align: center; padding: 1rem; color: #8b9bb4;">Preencha o valor e a data acima para gerar a prévia.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div style="display: flex; justify-content: flex-end; margin-top: 0.75rem;">
                    <strong style="font-size: 0.9rem;">Total Programado: <span id="previewTotalSum" class="positive">R$ 0,00</span></strong>
                </div>
            </div>

            <label class="span-2">
                Observações
                <textarea name="notes" rows="2" placeholder="Informações adicionais do contrato ou financiamento..."></textarea>
            </label>

            <footer class="span-2">
                <a class="button ghost" href="?page=recurring">Cancelar</a>
                <button class="button primary">Confirmar e Programar Parcelas</button>
            </footer>
        </form>
    </section>
</div>

<script>
(function() {
    const descInput = document.getElementById('fieldDesc');
    const amountInput = document.getElementById('fieldAmount');
    const countInput = document.getElementById('fieldInstallments');
    const dateInput = document.getElementById('fieldStartDate');
    const recInput = document.getElementById('fieldRecurrence');
    const tableBody = document.getElementById('previewTableBody');
    const totalSumEl = document.getElementById('previewTotalSum');
    const btnRefresh = document.getElementById('btnRefreshPreview');

    function formatMoneyBr(val) {
        return 'R$ ' + Number(val || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function generatePreview() {
        const desc = (descInput.value || 'Parcela').trim();
        const amount = parseFloat(amountInput.value) || 0;
        const count = parseInt(countInput.value, 10) || 12;
        const startDateStr = dateInput.value;
        const rec = recInput.value;

        if (!startDateStr || amount <= 0) {
            tableBody.innerHTML = '<tr><td colspan="4" style="text-align: center; padding: 1rem; color: #8b9bb4;">Preencha o valor e a data inicial para visualizar as parcelas.</td></tr>';
            totalSumEl.textContent = 'R$ 0,00';
            return;
        }

        let html = '';
        let total = 0;
        let [y, m, d] = startDateStr.split('-').map(Number);
        let currDate = new Date(y, m - 1, d);

        for (let i = 1; i <= count; i++) {
            const instDateStr = currDate.toISOString().split('T')[0];
            const isLast = count > 0 && i === count;
            const instDesc = countInput.value ? `${desc} (${i}/${count})` : `${desc} (${i})`;
            total += amount;

            html += `
                <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                    <td style="padding: 0.4rem 0.5rem;"><b>#${i}</b><input type="hidden" name="installments[${i}][installment_number]" value="${i}"></td>
                    <td style="padding: 0.4rem 0.5rem;"><input style="padding: 0.25rem 0.4rem; font-size: 0.8rem; width: 100%;" name="installments[${i}][description]" value="${instDesc}"></td>
                    <td style="padding: 0.4rem 0.5rem;"><input style="padding: 0.25rem 0.4rem; font-size: 0.8rem; width: 100%;" type="date" name="installments[${i}][due_date]" value="${instDateStr}"></td>
                    <td style="padding: 0.4rem 0.5rem;"><input style="padding: 0.25rem 0.4rem; font-size: 0.8rem; width: 100%; text-align: right;" class="preview-amount-input" type="number" step="0.01" name="installments[${i}][amount]" value="${amount.toFixed(2)}"></td>
                </tr>
            `;

            if (rec === 'weekly') {
                currDate.setDate(currDate.getDate() + 7);
            } else if (rec === 'biweekly') {
                currDate.setDate(currDate.getDate() + 14);
            } else if (rec === 'quarterly') {
                currDate.setMonth(currDate.getMonth() + 3);
            } else if (rec === 'annual') {
                currDate.setFullYear(currDate.getFullYear() + 1);
            } else {
                currDate.setMonth(currDate.getMonth() + 1);
            }
        }

        tableBody.innerHTML = html;
        totalSumEl.textContent = formatMoneyBr(total);

        // Bind dynamic amount recalculation
        tableBody.querySelectorAll('.preview-amount-input').forEach(inp => {
            inp.addEventListener('input', () => {
                let dynamicTotal = 0;
                tableBody.querySelectorAll('.preview-amount-input').forEach(ai => {
                    dynamicTotal += parseFloat(ai.value) || 0;
                });
                totalSumEl.textContent = formatMoneyBr(dynamicTotal);
            });
        });
    }

    [descInput, amountInput, countInput, dateInput, recInput].forEach(el => {
        if (el) el.addEventListener('change', generatePreview);
    });
    if (btnRefresh) btnRefresh.addEventListener('click', generatePreview);
    if (amountInput && amountInput.value) generatePreview();
})();
</script>
<?php endif; ?>

<?php if ($payInstallment && $auth->canWrite()): ?>
<div class="modal open">
    <a class="modal-backdrop" href="?page=recurring"></a>
    <section class="modal-panel">
        <header>
            <div>
                <p class="eyebrow">CONFIRMAR PAGAMENTO</p>
                <h2>Pagar parcela #<?= (int) $payInstallment['installment_number'] ?></h2>
            </div>
            <a href="?page=recurring" class="modal-close">×</a>
        </header>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="pay_installment">
            <input type="hidden" name="id" value="<?= (int) $payInstallment['id'] ?>">
            <input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">

            <div class="span-2" style="background: var(--bg-card, #131b26); border-radius: 8px; padding: 1rem;">
                <p style="margin: 0 0 0.25rem 0;"><b><?= h($payInstallment['description']) ?></b></p>
                <p style="margin: 0; color: var(--text-muted, #8b9bb4);">Vencimento: <?= date_br($payInstallment['due_date']) ?> · Fornecedor: <?= h($payInstallment['supplier'] ?: '—') ?></p>
                <h3 class="negative" style="margin: 0.5rem 0 0 0;"><?= money($payInstallment['amount_brl']) ?></h3>
            </div>

            <label class="span-2">
                Data do pagamento realizado
                <input name="payment_date" type="date" required value="<?= date('Y-m-d') ?>">
            </label>

            <footer class="span-2">
                <a class="button ghost" href="?page=recurring">Cancelar</a>
                <button class="button primary">Confirmar Pagamento</button>
            </footer>
        </form>
    </section>
</div>
<?php endif; ?>

<?php if ($editInstallment && $auth->canWrite()): ?>
<div class="modal open">
    <a class="modal-backdrop" href="?page=recurring"></a>
    <section class="modal-panel">
        <header>
            <div>
                <p class="eyebrow">EDITAR PARCELA</p>
                <h2>Parcela #<?= (int) $editInstallment['installment_number'] ?></h2>
            </div>
            <a href="?page=recurring" class="modal-close">×</a>
        </header>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_installment">
            <input type="hidden" name="id" value="<?= (int) $editInstallment['id'] ?>">
            <input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">

            <label class="span-2">
                Descrição da Parcela
                <input name="description" required value="<?= h($editInstallment['description']) ?>">
            </label>

            <label>
                Data de Vencimento
                <input name="due_date" type="date" required value="<?= h($editInstallment['due_date']) ?>">
            </label>

            <label>
                Valor (R$)
                <input name="amount" type="number" step="0.01" min="0.01" required value="<?= decimal_input($editInstallment['amount']) ?>">
            </label>

            <label class="span-2">
                Observações
                <textarea name="notes" rows="2"><?= h($editInstallment['notes'] ?? '') ?></textarea>
            </label>

            <footer class="span-2">
                <a class="button ghost" href="?page=recurring">Cancelar</a>
                <button class="button primary">Salvar Parcela</button>
            </footer>
        </form>

        <form method="post" class="danger-zone" data-confirm="Excluir esta parcela avulsa?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_installment">
            <input type="hidden" name="id" value="<?= (int) $editInstallment['id'] ?>">
            <button>Excluir parcela</button>
        </form>
    </section>
</div>
<?php endif; ?>
