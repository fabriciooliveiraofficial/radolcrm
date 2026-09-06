<?php

declare(strict_types=1);

use App\Services\DailyFinanceService;

$dailyService = new DailyFinanceService($db);

// Parâmetros de Navegação e Filtros
$activeTab = (string) ($_GET['tab'] ?? 'extract');
$currentMonth = (string) ($_GET['month'] ?? date('Y-m'));
$from = (string) ($_GET['from'] ?? date('Y-m-01'));
$to = (string) ($_GET['to'] ?? date('Y-m-t'));
$search = trim((string) ($_GET['q'] ?? ''));
$typeFilter = (string) ($_GET['type'] ?? '');
$methodFilter = (string) ($_GET['method'] ?? '');

// Dados Consolidados do Resumo (Período)
$summary = $dailyService->summary($from, $to);

// Dados das Abas
$allCategoriesGrouped = $dailyService->categoriesWithBudgets($currentMonth);
$allCards = $dailyService->cardsList();
$allCommitments = $dailyService->commitmentsList(false);
$agendaData = $dailyService->agenda($from, date('Y-m-d', strtotime($to . ' +30 days')));

// Query do Extrato Diário
$whereTx = " WHERE t.transaction_date BETWEEN ? AND ?";
$paramsTx = [$from, $to];

if ($search !== '') {
    $whereTx .= " AND (t.payee_name LIKE ? OR t.description LIKE ? OR t.notes LIKE ?)";
    $paramsTx[] = '%' . $search . '%';
    $paramsTx[] = '%' . $search . '%';
    $paramsTx[] = '%' . $search . '%';
}
if (in_array($typeFilter, ['expense', 'income'], true)) {
    $whereTx .= " AND t.type = ?";
    $paramsTx[] = $typeFilter;
}
if (in_array($methodFilter, ['pix', 'credit_card', 'debit_card', 'cash', 'transfer'], true)) {
    $whereTx .= " AND t.payment_method = ?";
    $paramsTx[] = $methodFilter;
}

$transactions = $db->fetchAll(
    "SELECT t.*, 
            cat.name cat_name, cat.icon cat_icon, cat.color cat_color,
            pcat.name parent_cat_name,
            c.name card_name, c.brand card_brand, c.color card_color
     FROM daily_transactions t
     LEFT JOIN daily_categories cat ON cat.id = t.category_id
     LEFT JOIN daily_categories pcat ON pcat.id = cat.parent_id
     LEFT JOIN daily_credit_cards c ON c.id = t.card_id
     {$whereTx}
     ORDER BY t.transaction_date DESC, t.id DESC
     LIMIT 500",
    $paramsTx
);

// Agrupamento do extrato por dia
$transactionsByDate = [];
foreach ($transactions as $tx) {
    $d = $tx['transaction_date'];
    if (!isset($transactionsByDate[$d])) {
        $transactionsByDate[$d] = [
            'income' => 0.0,
            'expense' => 0.0,
            'items' => [],
        ];
    }
    if ($tx['type'] === 'income' && $tx['status'] === 'realized') {
        $transactionsByDate[$d]['income'] += (float) $tx['amount'];
    } elseif ($tx['type'] === 'expense' && $tx['status'] === 'realized') {
        $transactionsByDate[$d]['expense'] += (float) $tx['amount'];
    }
    $transactionsByDate[$d]['items'][] = $tx;
}

// Lista plana de categorias para Selects (Hierárquico)
$rawCategories = $db->fetchAll(
    "SELECT c.* FROM daily_categories c WHERE c.active = 1 ORDER BY c.type DESC, c.sort_order ASC, c.name ASC"
);
$categoriesById = [];
$categoryTree = ['expense' => [], 'income' => []];
foreach ($rawCategories as $rc) {
    $categoriesById[$rc['id']] = $rc;
    if (empty($rc['parent_id'])) {
        $rc['children'] = [];
        $categoryTree[$rc['type']][$rc['id']] = $rc;
    }
}
foreach ($rawCategories as $rc) {
    if (!empty($rc['parent_id']) && isset($categoryTree[$rc['type']][$rc['parent_id']])) {
        $categoryTree[$rc['type']][$rc['parent_id']]['children'][] = $rc;
    }
}

// Favorecidos recentes para autocomplete
$recentPayees = $dailyService->recentPayees('', 30);
?>

<div class="daily-finance-wrapper">
    <!-- BARRA MACRO DE LIQUIDEZ E COMPILAÇÃO (MÉTRICAS RÁPIDAS) -->
    <section class="mini-stats daily-kpis">
        <div class="kpi-card <?= $summary['net_balance'] >= 0 ? 'good' : 'danger' ?>">
            <span class="dot <?= $summary['net_balance'] >= 0 ? 'green' : 'red' ?>"></span>
            <div class="kpi-info">
                <small>Saldo Líquido Realizado</small>
                <b class="kpi-val <?= $summary['net_balance'] >= 0 ? 'positive' : 'negative' ?>">
                    R$ <?= number_format($summary['net_balance'], 2, ',', '.') ?>
                </b>
            </div>
        </div>

        <div class="kpi-card">
            <span class="dot green"></span>
            <div class="kpi-info">
                <small>Entradas Realizadas</small>
                <b class="kpi-val positive">R$ <?= number_format($summary['total_income'], 2, ',', '.') ?></b>
            </div>
        </div>

        <div class="kpi-card">
            <span class="dot red"></span>
            <div class="kpi-info">
                <small>Saídas Realizadas</small>
                <b class="kpi-val negative">R$ <?= number_format($summary['total_expense'], 2, ',', '.') ?></b>
            </div>
        </div>

        <div class="kpi-card">
            <span class="dot gold"></span>
            <div class="kpi-info">
                <small>Faturas Cartão em Aberto</small>
                <b class="kpi-val" style="color: #d97706;">R$ <?= number_format($summary['cards_open_total'], 2, ',', '.') ?></b>
            </div>
        </div>

        <div class="kpi-card">
            <span class="dot purple"></span>
            <div class="kpi-info">
                <small>Agenda Próx. 15 Dias</small>
                <b class="kpi-val" style="color: #7c3aed;">R$ <?= number_format($summary['upcoming_obligations_total'], 2, ',', '.') ?></b>
            </div>
        </div>
    </section>

    <!-- CABEÇALHO COM ABAS E BOTÃO DE LANÇAMENTO RÁPIDO -->
    <div class="daily-header-actions">
        <nav class="daily-tabs-nav">
            <a class="daily-tab-btn <?= $activeTab === 'extract' ? 'active' : '' ?>" href="?page=financeiro&tab=extract">
                📋 Extrato Diário
            </a>
            <a class="daily-tab-btn <?= $activeTab === 'agenda' ? 'active' : '' ?>" href="?page=financeiro&tab=agenda">
                📅 Agenda Preditiva (<?= $agendaData['total_count'] ?>)
            </a>
            <a class="daily-tab-btn <?= $activeTab === 'cards' ? 'active' : '' ?>" href="?page=financeiro&tab=cards">
                💳 Cartões de Crédito (<?= count($allCards) ?>)
            </a>
            <a class="daily-tab-btn <?= $activeTab === 'commitments' ? 'active' : '' ?>" href="?page=financeiro&tab=commitments">
                🎓 Despesas Fixas & Filhos (<?= count($allCommitments) ?>)
            </a>
            <a class="daily-tab-btn <?= $activeTab === 'budgets' ? 'active' : '' ?>" href="?page=financeiro&tab=budgets">
                🎯 Tetos & Orçamentos
            </a>
            <a class="daily-tab-btn <?= $activeTab === 'categories' ? 'active' : '' ?>" href="?page=financeiro&tab=categories">
                🗂️ Categorias Oficiais
            </a>
        </nav>

        <div class="daily-quick-cta">
            <button type="button" class="button primary quick-launch-btn" onclick="openQuickTxModal()">
                ⚡ Lançamento Rápido
            </button>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- ABA 1: EXTRATO DIÁRIO (LANÇAMENTOS DO DIA A DIA)                           -->
    <!-- ========================================================================= -->
    <?php if ($activeTab === 'extract'): ?>
    <section class="toolbar list-toolbar" style="margin-top: 14px;">
        <form class="search-filters" method="get" data-live-filter>
            <input type="hidden" name="page" value="financeiro">
            <input type="hidden" name="tab" value="extract">
            <label class="search-box">
                ⌕
                <input name="q" autocomplete="off" placeholder="Buscar mercado, posto, favorecido..." value="<?= h($search) ?>">
            </label>
            <select name="type">
                <option value="">Todas as naturezas</option>
                <option value="expense" <?= $typeFilter === 'expense' ? 'selected' : '' ?>>Saídas / Gastos</option>
                <option value="income" <?= $typeFilter === 'income' ? 'selected' : '' ?>>Entradas / Receitas</option>
            </select>
            <select name="method">
                <option value="">Todos os métodos</option>
                <option value="pix" <?= $methodFilter === 'pix' ? 'selected' : '' ?>>PIX</option>
                <option value="credit_card" <?= $methodFilter === 'credit_card' ? 'selected' : '' ?>>Cartão de Crédito</option>
                <option value="debit_card" <?= $methodFilter === 'debit_card' ? 'selected' : '' ?>>Cartão de Débito</option>
                <option value="cash" <?= $methodFilter === 'cash' ? 'selected' : '' ?>>Dinheiro em Espécie</option>
                <option value="transfer" <?= $methodFilter === 'transfer' ? 'selected' : '' ?>>Transferência / TED</option>
            </select>
            <label>De <input type="date" name="from" value="<?= h($from) ?>"></label>
            <label>Até <input type="date" name="to" value="<?= h($to) ?>"></label>
            <button type="submit" class="button ghost">Filtrar</button>
        </form>
    </section>

    <div class="daily-timeline-container" style="margin-top: 16px;">
        <?php if (empty($transactionsByDate)): ?>
            <div class="card" style="padding: 40px; text-align: center; color: var(--muted);">
                <span style="font-size: 40px; display: block; margin-bottom: 12px;">☕</span>
                <b style="font-size: 16px; color: var(--ink);">Nenhuma movimentação registrada no período selecionado.</b>
                <p style="margin-top: 6px;">Clique no botão <strong>⚡ Lançamento Rápido</strong> acima para registrar despesas de mercado, combustível ou receitas.</p>
            </div>
        <?php else: ?>
            <?php foreach ($transactionsByDate as $dateStr => $dayGroup): 
                $dateObj = new DateTimeImmutable($dateStr);
                $isToday = $dateStr === date('Y-m-d');
                $isYesterday = $dateStr === date('Y-m-d', strtotime('-1 day'));
                $dayLabel = $isToday ? 'Hoje' : ($isYesterday ? 'Ontem' : $dateObj->format('d/m/Y'));
                $weekDay = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'][(int)$dateObj->format('w')];
            ?>
                <div class="day-group-card">
                    <div class="day-header">
                        <div class="day-title">
                            <span class="day-badge <?= $isToday ? 'today' : '' ?>"><?= $weekDay ?></span>
                            <b><?= $dayLabel ?> · <?= $dateObj->format('d/m/Y') ?></b>
                        </div>
                        <div class="day-summary">
                            <?php if ($dayGroup['income'] > 0): ?>
                                <span class="income-tag">+ R$ <?= number_format($dayGroup['income'], 2, ',', '.') ?></span>
                            <?php endif; ?>
                            <?php if ($dayGroup['expense'] > 0): ?>
                                <span class="expense-tag">- R$ <?= number_format($dayGroup['expense'], 2, ',', '.') ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="day-items-list">
                        <?php foreach ($dayGroup['items'] as $tx): 
                            $isExp = $tx['type'] === 'expense';
                            $methodBadge = match($tx['payment_method']) {
                                'pix' => '⚡ PIX',
                                'credit_card' => '💳 Cartão (' . ($tx['card_name'] ?? 'Crédito') . ')',
                                'debit_card' => '💳 Débito',
                                'cash' => '💵 Dinheiro',
                                'transfer' => '🏦 Transferência',
                                default => $tx['payment_method']
                            };
                        ?>
                            <div class="tx-item-row">
                                <div class="tx-icon" style="background: <?= h($tx['cat_color'] ?? ($isExp ? '#fee2e2' : '#dcfce7')) ?>25; color: <?= h($tx['cat_color'] ?? ($isExp ? '#ef4444' : '#10b981')) ?>;">
                                    <?= h($tx['cat_icon'] ?? ($isExp ? '💸' : '💰')) ?>
                                </div>
                                <div class="tx-details">
                                    <div class="tx-main">
                                        <b class="tx-payee"><?= h($tx['payee_name']) ?></b>
                                        <?php if (!empty($tx['description']) && $tx['description'] !== $tx['payee_name']): ?>
                                            <span class="tx-desc">· <?= h($tx['description']) ?></span>
                                        <?php endif; ?>
                                        <?php if ($tx['total_installments'] > 1): ?>
                                            <span class="badge" style="background: #fef3c7; color: #b45309; font-size: 11px;">
                                                <?= (int)$tx['installment_number'] ?>/<?= (int)$tx['total_installments'] ?>x
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="tx-meta">
                                        <span class="category-pill" style="border-left: 3px solid <?= h($tx['cat_color'] ?? '#94a3b8') ?>;">
                                            <?= h($tx['parent_cat_name'] ? $tx['parent_cat_name'] . ' › ' : '') ?><?= h($tx['cat_name'] ?? 'Geral') ?>
                                        </span>
                                        <span class="method-tag"><?= $methodBadge ?></span>
                                        <?php if ($tx['status'] === 'pending'): ?>
                                            <span class="badge warning">Agendado</span>
                                        <?php endif; ?>
                                        <?php if (!empty($tx['notes'])): ?>
                                            <small class="tx-notes">💬 <?= h($tx['notes']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="tx-amount-col">
                                    <b class="tx-amount <?= $isExp ? 'negative' : 'positive' ?>">
                                        <?= $isExp ? '-' : '+' ?> R$ <?= number_format((float)$tx['amount'], 2, ',', '.') ?>
                                    </b>
                                    <div class="tx-actions" style="display: flex; gap: 4px; align-items: center;">
                                        <button type="button" class="button-icon-edit" title="Editar lançamento" onclick='openEditTxModal(<?= json_encode($tx, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' style="background: none; border: 1px solid #cbd5e1; border-radius: 4px; cursor: pointer; padding: 3px 6px; font-size: 13px; color: #475569;">✎</button>
                                        <form method="post" data-confirm="Excluir este lançamento de R$ <?= number_format((float)$tx['amount'], 2, ',', '.') ?>?" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_daily_transaction">
                                            <input type="hidden" name="id" value="<?= (int)$tx['id'] ?>">
                                            <input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">
                                            <button type="submit" class="button-icon-danger" title="Excluir lançamento">🗑️</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ========================================================================= -->
    <!-- ABA 2: AGENDA FINANCEIRA PREDITIVA                                         -->
    <!-- ========================================================================= -->
    <?php if ($activeTab === 'agenda'): ?>
    <div class="agenda-view-container" style="margin-top: 16px;">
        <div class="agenda-summary-banner">
            <div class="asb-item">
                <small>Entradas Previstas no Período</small>
                <b class="positive">+ R$ <?= number_format($agendaData['expected_in'], 2, ',', '.') ?></b>
            </div>
            <div class="asb-item">
                <small>Saídas e Obrigações Futuras Previstas</small>
                <b class="negative">- R$ <?= number_format($agendaData['expected_out'], 2, ',', '.') ?></b>
            </div>
            <div class="asb-item">
                <small>Resultado Projetado</small>
                <b class="<?= $agendaData['expected_net'] >= 0 ? 'positive' : 'negative' ?>">
                    R$ <?= number_format($agendaData['expected_net'], 2, ',', '.') ?>
                </b>
            </div>
        </div>

        <section class="card table-card" style="margin-top: 14px;">
            <div class="table-meta">
                <span><b><?= count($agendaData['events']) ?></b> compromissos e vencimentos futuros cronológicos</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Data Prevista</th>
                            <th>Natureza / Compromisso</th>
                            <th>Categoria / Detalhes</th>
                            <th>Tipo</th>
                            <th>Valor</th>
                            <th>Ação Rápida</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($agendaData['events'])): ?>
                            <tr><td colspan="6" class="empty-cell">Nenhum vencimento ou compromisso programado para o período.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($agendaData['events'] as $ev): 
                            $isPast = $ev['date'] < date('Y-m-d');
                            $isToday = $ev['date'] === date('Y-m-d');
                        ?>
                            <tr class="<?= $isPast ? 'agenda-overdue' : ($isToday ? 'agenda-today' : '') ?>">
                                <td>
                                    <b style="color: <?= $isPast ? '#ef4444' : ($isToday ? '#d97706' : 'inherit') ?>;">
                                        <?= date_br($ev['date']) ?>
                                    </b>
                                    <?php if ($isToday): ?>
                                        <span class="badge warning" style="margin-left: 4px;">Vence Hoje</span>
                                    <?php elseif ($isPast): ?>
                                        <span class="badge danger" style="margin-left: 4px;">Atrasado</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="entity">
                                        <span class="avatar-sm" style="background: <?= h($ev['color']) ?>25; color: <?= h($ev['color']) ?>;">
                                            <?= h($ev['icon']) ?>
                                        </span>
                                        <div>
                                            <b><?= h($ev['title']) ?></b>
                                            <small class="block"><?= h($ev['subtitle']) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($ev['type'] === 'card_invoice'): ?>
                                        <span class="badge" style="background: #e0e7ff; color: #3730a3;">Fatura Cartão de Crédito</span>
                                    <?php elseif ($ev['type'] === 'recurring_commitment'): ?>
                                        <span class="badge" style="background: #fef3c7; color: #92400e;">Despesa Fixa / Escola / Mensalidade</span>
                                    <?php else: ?>
                                        <span class="badge" style="background: #f1f5f9; color: #475569;">Transação Programada</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= $ev['direction'] === 'in' ? 'good' : 'warning' ?>">
                                        <?= $ev['direction'] === 'in' ? 'Recebimento' : 'Pagamento' ?>
                                    </span>
                                </td>
                                <td>
                                    <b class="<?= $ev['direction'] === 'in' ? 'positive' : 'negative' ?>">
                                        <?= $ev['direction'] === 'in' ? '+' : '-' ?> R$ <?= number_format($ev['amount'], 2, ',', '.') ?>
                                    </b>
                                </td>
                                <td>
                                    <?php if ($ev['type'] === 'card_invoice'): ?>
                                        <button type="button" class="button primary sm" onclick="openPayInvoiceModal(<?= (int)$ev['invoice_id'] ?>, '<?= h(addslashes($ev['title'])) ?>', '<?= $ev['amount'] ?>')">
                                            ✓ Pagar Fatura
                                        </button>
                                    <?php elseif ($ev['type'] === 'recurring_commitment'): ?>
                                        <form method="post" data-confirm="Confirmar quitação de '<?= h($ev['title']) ?>' no valor de R$ <?= number_format($ev['amount'], 2, ',', '.') ?>?" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="pay_daily_commitment">
                                            <input type="hidden" name="commitment_id" value="<?= (int)$ev['commitment_id'] ?>">
                                            <input type="hidden" name="payment_date" value="<?= date('Y-m-d') ?>">
                                            <input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">
                                            <button type="submit" class="button ghost sm">✓ Liquidar</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <?php endif; ?>

    <!-- ========================================================================= -->
    <!-- ABA 3: CARTÕES DE CRÉDITO & FATURAS                                       -->
    <!-- ========================================================================= -->
    <?php if ($activeTab === 'cards'): ?>
    <div class="cards-view-container" style="margin-top: 16px;">
        <div style="display: flex; justify-content: flex-end; margin-bottom: 14px;">
            <button type="button" class="button ghost" onclick="openNewCardModal()">＋ Adicionar Novo Cartão</button>
        </div>

        <div class="cards-grid">
            <?php if (empty($allCards)): ?>
                <div class="card" style="padding: 30px; text-align: center; grid-column: 1 / -1;">
                    <p>Nenhum cartão de crédito cadastrado ainda.</p>
                </div>
            <?php endif; ?>

            <?php foreach ($allCards as $card): 
                $limit = (float) $card['credit_limit'];
                $used = (float) $card['open_invoices_sum'];
                $avail = (float) $card['available_limit'];
                $usedPct = $limit > 0 ? min(100, round(($used / $limit) * 100, 1)) : 0;
            ?>
                <div class="credit-card-item" style="border-top: 4px solid <?= h($card['color'] ?: '#6366f1') ?>;">
                    <div class="cci-header">
                        <div>
                            <b class="cci-name"><?= h($card['name']) ?></b>
                            <span class="cci-brand"><?= h($card['brand']) ?> <?= $card['last_four_digits'] ? '•••• ' . h($card['last_four_digits']) : '' ?></span>
                        </div>
                        <span class="badge <?= $card['active'] ? 'good' : 'warning' ?>"><?= $card['active'] ? 'Ativo' : 'Inativo' ?></span>
                    </div>

                    <div class="cci-invoice-box">
                        <small>Fatura Aberta Atual (<?= h($card['current_open_invoice_month'] ?: 'Atual') ?>)</small>
                        <b class="cci-inv-val">R$ <?= number_format($used, 2, ',', '.') ?></b>
                        <?php if ($card['current_open_invoice_due']): ?>
                            <span class="cci-due">Vencimento: <?= date_br($card['current_open_invoice_due']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="cci-limit-bar">
                        <div class="limit-labels">
                            <small>Limite Usado: <?= $usedPct ?>%</small>
                            <small>Disponível: R$ <?= number_format($avail, 2, ',', '.') ?></small>
                        </div>
                        <div class="progress-track">
                            <div class="progress-fill" style="width: <?= $usedPct ?>%; background: <?= $usedPct > 80 ? '#ef4444' : ($usedPct > 50 ? '#f59e0b' : '#10b981') ?>;"></div>
                        </div>
                        <small class="block" style="margin-top: 4px;">Limite Total: R$ <?= number_format($limit, 2, ',', '.') ?></small>
                    </div>

                    <div class="cci-footer">
                        <small>Fechamento dia <?= (int)$card['closing_day'] ?> · Vencimento dia <?= (int)$card['due_day'] ?></small>
                        <div class="cci-actions">
                            <?php if ($card['current_open_invoice_id'] && $used > 0): ?>
                                <button type="button" class="button primary sm" onclick="openPayInvoiceModal(<?= (int)$card['current_open_invoice_id'] ?>, '<?= h(addslashes($card['name'])) ?>', '<?= $used ?>')">
                                    Pagar Fatura
                                </button>
                            <?php endif; ?>
                            <form method="post" data-confirm="Excluir o cartão <?= h($card['name']) ?>?" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_daily_card">
                                <input type="hidden" name="id" value="<?= (int)$card['id'] ?>">
                                <input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">
                                <button type="submit" class="button ghost sm" title="Excluir">🗑️</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ========================================================================= -->
    <!-- ABA 4: DESPESAS FIXAS & FILHOS (MENSALIDADES, CURSOS, ESCOLA)              -->
    <!-- ========================================================================= -->
    <?php if ($activeTab === 'commitments'): ?>
    <div class="commitments-view-container" style="margin-top: 16px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
            <p style="margin: 0; color: var(--muted);">Mensalidades escolares, cursinhos dos filhos, condomínio, internet e assinaturas fixas da família.</p>
            <button type="button" class="button primary" onclick="openNewCommitmentModal()">＋ Novo Compromisso Fixo</button>
        </div>

        <section class="card table-card">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Obrigação / Favorecido</th>
                            <th>Categoria</th>
                            <th>Dia de Vencimento</th>
                            <th>Valor Recorrente</th>
                            <th>Forma de Pgto</th>
                            <th>Parcelas / Status</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allCommitments)): ?>
                            <tr><td colspan="7" class="empty-cell">Nenhum compromisso fixo cadastrado ainda.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($allCommitments as $com): ?>
                            <tr>
                                <td>
                                    <div class="entity">
                                        <span class="avatar-sm" style="background: <?= h($com['cat_color'] ?: '#3b82f6') ?>22; color: <?= h($com['cat_color'] ?: '#3b82f6') ?>;">
                                            <?= h($com['cat_icon'] ?: '🎓') ?>
                                        </span>
                                        <div>
                                            <b><?= h($com['payee_name']) ?></b>
                                            <small class="block"><?= h($com['description']) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge" style="background: <?= h($com['cat_color'] ?: '#10b981') ?>15; color: <?= h($com['cat_color'] ?: '#10b981') ?>;">
                                        <?= h($com['cat_name'] ?: 'Geral') ?>
                                    </span>
                                </td>
                                <td>
                                    <b>Dia <?= (int)$com['due_day'] ?></b> de cada mês
                                </td>
                                <td>
                                    <b class="negative">R$ <?= number_format((float)$com['amount'], 2, ',', '.') ?></b>
                                </td>
                                <td>
                                    <span class="badge"><?= strtoupper(h($com['payment_method'])) ?></span>
                                </td>
                                <td>
                                    <?php if ($com['total_installments']): ?>
                                        <span class="badge warning"><?= (int)$com['current_installment'] ?>/<?= (int)$com['total_installments'] ?> parcelas</span>
                                    <?php else: ?>
                                        <span class="badge good">Mensalidade Contínua</span>
                                    <?php endif; ?>
                                    <?php if (!$com['active']): ?>
                                        <span class="badge danger">Encerrado</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" data-confirm="Remover o compromisso <?= h($com['payee_name']) ?>?" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_daily_commitment">
                                        <input type="hidden" name="id" value="<?= (int)$com['id'] ?>">
                                        <input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">
                                        <button type="submit" class="button ghost sm">🗑️</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <?php endif; ?>

    <!-- ========================================================================= -->
    <!-- ABA 5: TETOS & ORÇAMENTOS POR MACRO-CATEGORIA (REGRA 4)                    -->
    <!-- ========================================================================= -->
    <?php if ($activeTab === 'budgets'): ?>
    <div class="budgets-view-container" style="margin-top: 16px;">
        <div class="budgets-header-meta">
            <div>
                <h3>Tetos Orçamentários de <?= date('m/Y', strtotime($currentMonth . '-01')) ?></h3>
                <p class="muted">Planeje o limite máximo que a família pode despender em Alimentação, Moradia, etc.</p>
            </div>
            <div class="budgets-totals-box">
                <div>
                    <small>Teto Total Planejado</small>
                    <b>R$ <?= number_format($allCategoriesGrouped['total_budget_planned'], 2, ',', '.') ?></b>
                </div>
                <div>
                    <small>Gasto Realizado no Mês</small>
                    <b class="negative">R$ <?= number_format($allCategoriesGrouped['total_spent'], 2, ',', '.') ?></b>
                </div>
            </div>
        </div>

        <div class="budgets-grid" style="margin-top: 14px;">
            <?php foreach ($allCategoriesGrouped['categories'] as $macro): 
                $spent = (float)$macro['spent'];
                $limit = $macro['monthly_budget_limit'] ? (float)$macro['monthly_budget_limit'] : null;
                $pct = $macro['consumption_percent'];
            ?>
                <div class="budget-card">
                    <div class="bc-top">
                        <div class="bc-icon-title">
                            <span class="bc-icon" style="background: <?= h($macro['color']) ?>20; color: <?= h($macro['color']) ?>;">
                                <?= h($macro['icon']) ?>
                            </span>
                            <div>
                                <b><?= h($macro['name']) ?></b>
                                <small class="block"><?= count($macro['subcategories']) ?> subcategorias estruturadas</small>
                            </div>
                        </div>
                        <div class="bc-spent-status">
                            <span class="badge <?= $macro['status'] === 'danger' ? 'danger' : ($macro['status'] === 'warning' ? 'warning' : 'good') ?>">
                                <?= $pct !== null ? $pct . '%' : 'Sem teto' ?>
                            </span>
                        </div>
                    </div>

                    <div class="bc-figures">
                        <div>
                            <small>Gasto Realizado</small>
                            <b class="negative">R$ <?= number_format($spent, 2, ',', '.') ?></b>
                        </div>
                        <div style="text-align: right;">
                            <small>Teto Definido</small>
                            <b><?= $limit ? 'R$ ' . number_format($limit, 2, ',', '.') : '—' ?></b>
                        </div>
                    </div>

                    <?php if ($limit && $limit > 0): ?>
                        <div class="progress-track" style="margin-top: 10px;">
                            <div class="progress-fill" style="width: <?= min(100, $pct) ?>%; background: <?= $macro['status'] === 'danger' ? '#ef4444' : ($macro['status'] === 'warning' ? '#f59e0b' : '#10b981') ?>;"></div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ========================================================================= -->
    <!-- ABA 6: CATEGORIAS ESTRUTURADAS (ÁRVORE 2 NÍVEIS)                          -->
    <!-- ========================================================================= -->
    <?php if ($activeTab === 'categories'): ?>
    <div class="categories-view-container" style="margin-top: 16px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
            <div>
                <h3>Estrutura de Categorias Oficiais (2 Níveis Rígidos)</h3>
                <p class="muted">Macro-categorias padronizadas com subcategorias específicas para evitar proliferação desordenada.</p>
            </div>
            <button type="button" class="button ghost" onclick="openNewCategoryModal()">＋ Nova Categoria / Subcategoria</button>
        </div>

        <div class="categories-tree-grid">
            <?php foreach (['expense' => 'Despesas & Saídas', 'income' => 'Receitas & Entradas'] as $typeKey => $typeTitle): ?>
                <div class="category-type-column">
                    <h4 class="cat-col-title"><?= $typeTitle ?></h4>
                    <div class="cat-cards-list">
                        <?php foreach ($categoryTree[$typeKey] as $macro): ?>
                            <div class="cat-macro-card">
                                <div class="cm-head">
                                    <div class="cm-title">
                                        <span class="cm-icon" style="background: <?= h($macro['color']) ?>20; color: <?= h($macro['color']) ?>;">
                                            <?= h($macro['icon']) ?>
                                        </span>
                                        <b><?= h($macro['name']) ?></b>
                                    </div>
                                    <div class="cm-actions">
                                        <?php if (!empty($macro['monthly_budget_limit'])): ?>
                                            <span class="badge">Teto: R$ <?= number_format((float)$macro['monthly_budget_limit'], 0, ',', '.') ?></span>
                                        <?php endif; ?>
                                        <button type="button" class="button-icon" onclick="openAddSubcategoryModal(<?= (int)$macro['id'] ?>, '<?= h(addslashes($macro['name'])) ?>', '<?= $typeKey ?>')">＋ Sub</button>
                                    </div>
                                </div>

                                <?php if (!empty($macro['children'])): ?>
                                    <ul class="cm-sub-list">
                                        <?php foreach ($macro['children'] as $sub): ?>
                                            <li>
                                                <span><?= h($sub['icon']) ?> <?= h($sub['name']) ?></span>
                                                <form method="post" data-confirm="Excluir subcategoria <?= h($sub['name']) ?>?" style="display:inline;">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete_daily_category">
                                                    <input type="hidden" name="id" value="<?= (int)$sub['id'] ?>">
                                                    <input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">
                                                    <button type="submit" class="sub-del-btn">×</button>
                                                </form>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ========================================================================= -->
<!-- MODAL: LANÇAMENTO RÁPIDO (3 TOQUES - PIX, CARTÃO, DÉBITO, DINHEIRO)       -->
<!-- ========================================================================= -->
<!-- ========================================================================= -->
<!-- MODAL: LANÇAMENTO RÁPIDO COM PARCELAMENTO AVANÇADO E AJUSTE FINO          -->
<!-- ========================================================================= -->
<div id="quickTxModal" class="modal">
    <div class="modal-backdrop" onclick="closeQuickTxModal()"></div>
    <section class="modal-panel" style="max-width: 760px; width: 95%;">
        <header>
            <div>
                <p class="eyebrow">LANÇAMENTO INTELIGENTE</p>
                <h2 id="quickTxModalTitle">⚡ Novo Lançamento Diário</h2>
            </div>
            <button type="button" class="modal-close" onclick="closeQuickTxModal()">×</button>
        </header>

        <form method="post" id="quickTxForm" class="form-grid" style="gap: 14px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_daily_transaction">
            <input type="hidden" name="id" id="txIdInput" value="">
            <input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">

            <!-- TIPO: SAÍDA OU ENTRADA -->
            <div class="full-field type-toggle-buttons">
                <label class="type-radio-btn active-expense" id="radioLabelExpense">
                    <input type="radio" name="type" value="expense" checked onchange="handleTypeChange('expense')">
                    <span>💸 Saída / Despesa</span>
                </label>
                <label class="type-radio-btn" id="radioLabelIncome">
                    <input type="radio" name="type" value="income" onchange="handleTypeChange('income')">
                    <span>💰 Entrada / Receita</span>
                </label>
            </div>

            <!-- VALOR E DATA -->
            <div>
                <label>Valor Total (R$) *</label>
                <input type="text" name="amount" id="txAmountInput" required placeholder="0,00" class="input-lg" autocomplete="off" autofocus oninput="handleAmountOrInstallmentsChange()">
            </div>
            <div>
                <label>Data *</label>
                <input type="date" name="transaction_date" id="txDateInput" required value="<?= date('Y-m-d') ?>" onchange="handleAmountOrInstallmentsChange()">
            </div>

            <!-- FAVORECIDO / ESTABELECIMENTO COM AUTOCOMPLETE INTELIGENTE -->
            <div class="full-field">
                <label>Favorecido / Estabelecimento *</label>
                <input list="payeesList" name="payee_name" id="payeeInput" required placeholder="Ex: Pão de Açúcar, Posto Ipiranga, Drogaria São Paulo, Financiamento Veicular..." autocomplete="off" oninput="handlePayeeSelect(this.value)">
                <datalist id="payeesList">
                    <?php foreach ($recentPayees as $rp): ?>
                        <option value="<?= h($rp['name']) ?>" data-cat="<?= (int)$rp['default_category_id'] ?>" data-method="<?= h($rp['default_payment_method']) ?>">
                            <?= h($rp['name']) ?> (<?= h($rp['default_category_name'] ?: 'Frequente') ?>)
                        </option>
                    <?php endforeach; ?>
                </datalist>
                <small class="muted">Separação cirúrgica: o Favorecido identifica 'Quem', sem poluir as categorias.</small>
            </div>

            <!-- FORMA DE PAGAMENTO -->
            <div class="full-field">
                <label>Forma de Pagamento *</label>
                <select name="payment_method" id="paymentMethodSelect" onchange="handlePaymentMethodChange(this.value)">
                    <option value="pix">⚡ PIX</option>
                    <option value="credit_card">💳 Cartão de Crédito</option>
                    <option value="debit_card">💳 Cartão de Débito</option>
                    <option value="cash">💵 Dinheiro em Espécie</option>
                    <option value="transfer">🏦 Transferência Bancária</option>
                    <option value="boleto">📄 Boleto Bancário / Carnê</option>
                </select>
            </div>

            <!-- BLOCO CONDICIONAL: CARTÃO DE CRÉDITO -->
            <div id="cardDetailsBlock" class="full-field card-details-row" style="display: none; background: #f8fafc; padding: 14px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label style="margin: 0; font-weight: 600;">Cartão de Crédito Utilizado *</label>
                    <button type="button" class="btn-link-action" onclick="openInlineNewCardModal()" style="background: none; border: none; color: var(--primary); font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: underline;">
                        ＋ Novo Cartão
                    </button>
                </div>
                <select name="card_id" id="cardSelectInput" onchange="handleCardSelectChange(this.value)">
                    <option value="">Selecione o cartão...</option>
                    <?php foreach ($allCards as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" data-closing="<?= (int)$c['closing_day'] ?>" data-due="<?= (int)$c['due_day'] ?>">
                            <?= h($c['name']) ?> (<?= h($c['brand']) ?> · Fecha dia <?= (int)$c['closing_day'] ?> / Vence dia <?= (int)$c['due_day'] ?>)
                        </option>
                    <?php endforeach; ?>
                    <option value="__new__">＋ Cadastrar novo cartão...</option>
                </select>
            </div>

            <!-- SEÇÃO DE PARCELAMENTO & FINANCIAMENTOS -->
            <div id="installmentOptionBlock" class="full-field" style="background: #f8fafc; padding: 12px 16px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                    <label style="display: inline-flex; flex-direction: row; align-items: center; gap: 10px; font-weight: 600; cursor: pointer; margin: 0; font-size: 13px; color: var(--ink);">
                        <input type="checkbox" id="enableInstallmentsCheckbox" onchange="toggleInstallmentsSection(this.checked)">
                        <span id="installmentCheckboxLabel">Dividir em Parcelas / Financiamento</span>
                    </label>
                    <div id="installmentSelectWrapper" style="display: none; align-items: center; gap: 8px;">
                        <span style="font-size: 12px; color: var(--muted); font-weight: 600;">Quantidade:</span>
                        <select name="total_installments" id="totalInstallmentsSelect" style="width: 100px; min-height: 36px; padding: 4px 8px; font-weight: 700;" onchange="handleInstallmentCountChange()">
                            <?php for ($i = 2; $i <= 72; $i++): ?>
                                <option value="<?= $i ?>"><?= $i ?>x</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- CRONOGRAMA AVANÇADO DE PARCELAS COM AJUSTE FINO DE DATAS E VALORES -->
            <div id="installmentsScheduleBlock" class="full-field" style="display: none; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 16px; box-shadow: 0 2px 4px rgba(0,0,0,0.03);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <div>
                        <b style="color: var(--ink); font-size: 14px; display: flex; align-items: center; gap: 6px;">📅 Cronograma de Parcelas (Ajuste Fino)</b>
                        <small class="muted" style="font-size: 11px;">Datas e valores gerados automaticamente. Você pode ajustar individualmente cada parcela.</small>
                    </div>
                    <button type="button" class="button ghost small" onclick="recalculateEqualInstallments()" title="Restaurar distribuição padrão de valores" style="font-size: 12px; padding: 6px 12px;">
                        ↺ Redistribuir
                    </button>
                </div>

                <div class="installments-scroll-wrap">
                    <table class="installments-table">
                        <thead>
                            <tr>
                                <th style="text-align: center; width: 70px;">Parcela</th>
                                <th style="text-align: left; min-width: 180px;">Data de Vencimento</th>
                                <th style="text-align: right; width: 170px;">Valor (R$)</th>
                                <th style="text-align: center; width: 110px;">Status</th>
                            </tr>
                        </thead>
                        <tbody id="installmentsTableBody">
                            <!-- Gerado dinamicamente via Javascript -->
                        </tbody>
                    </table>
                </div>

                <!-- Barra de Resumo e Balanço em Tempo Real -->
                <div id="installmentsBalanceBar" style="margin-top: 12px; padding: 10px 14px; border-radius: 6px; background: #f8fafc; border: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; font-size: 13px;">
                    <div>
                        <span>Soma das Parcelas: <strong id="installmentsSumText" style="color: var(--primary);">R$ 0,00</strong></span>
                        <span style="margin: 0 8px; color: #cbd5e1;">|</span>
                        <span>Total Informado: <strong id="originalTotalText">R$ 0,00</strong></span>
                    </div>
                    <div id="installmentsDiffNotice" style="display: none; align-items: center; gap: 8px;">
                        <span id="diffBadge" class="badge warning" style="font-weight: 600;">Diferença: R$ 0,00</span>
                        <button type="button" class="button ghost small" onclick="applyDifferenceToLastInstallment()" style="font-size: 11px; padding: 4px 8px;">
                            ⚡ Ajustar centavos na última
                        </button>
                        <button type="button" class="button ghost small" onclick="syncTotalFromInstallments()" style="font-size: 11px; padding: 4px 8px;">
                            Atualizar Total Geral
                        </button>
                    </div>
                </div>
            </div>

            <!-- CATEGORIA (ESTRUTURADA COM GRUPOS OPTGROUP) -->
            <div class="full-field">
                <label>Categoria Oficial *</label>
                <select name="category_id" id="categorySelect" required>
                    <option value="">Selecione uma categoria...</option>
                </select>
            </div>

            <!-- STATUS DO LANÇAMENTO -->
            <div class="full-field" id="statusFieldContainer">
                <label style="margin-bottom: 6px;">Status do Lançamento *</label>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <label class="status-radio-card active-realized" id="statusCardRealized">
                        <input type="radio" name="status" value="realized" checked onchange="handleStatusChange('realized')">
                        <span>✓ Já Pago / Realizado</span>
                    </label>
                    <label class="status-radio-card" id="statusCardPending">
                        <input type="radio" name="status" value="pending" onchange="handleStatusChange('pending')">
                        <span>⏳ Pendente / A Pagar</span>
                    </label>
                </div>
            </div>

            <!-- DETALHE ADICIONAL -->
            <div class="full-field">
                <label>Descrição ou Detalhes (Opcional)</label>
                <input type="text" name="description" id="txDescriptionInput" placeholder="Ex: Compras da semana, Jantar em família...">
            </div>

            <div class="full-field">
                <label>Observações / Notas (Opcional)</label>
                <input type="text" name="notes" id="txNotesInput" placeholder="Ex: Informações adicionais, garantia, etc...">
            </div>

            <footer class="form-actions full-field" style="margin-top: 10px; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="button ghost" onclick="closeQuickTxModal()">Cancelar</button>
                <button type="submit" class="button primary" id="quickTxSubmitBtn">✓ Salvar Lançamento</button>
            </footer>
        </form>
    </section>
</div>

<!-- ========================================================================= -->
<!-- MINI-MODAL: CADASTRO RÁPIDO DE CARTÃO (AJAX)                              -->
<!-- ========================================================================= -->
<div id="inlineCardModal" class="modal" style="z-index: 10002;">
    <div class="modal-backdrop" onclick="closeInlineCardModal()"></div>
    <section class="modal-panel" style="max-width: 440px;">
        <header>
            <div>
                <p class="eyebrow">CADASTRO INSTANTÂNEO</p>
                <h2>💳 Novo Cartão de Crédito</h2>
            </div>
            <button type="button" class="modal-close" onclick="closeInlineCardModal()">×</button>
        </header>
        <form id="inlineCardForm" onsubmit="handleInlineCardSubmit(event)" class="form-grid" style="gap: 12px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_daily_card_ajax">

            <div class="full-field">
                <label>Nome do Cartão *</label>
                <input type="text" name="name" id="inlineCardName" required placeholder="Ex: Nubank Black, XP Visa...">
            </div>

            <div>
                <label>Bandeira</label>
                <input type="text" name="brand" id="inlineCardBrand" value="Mastercard" placeholder="Mastercard, Visa, Elo...">
            </div>

            <div>
                <label>Últimos 4 Dígitos</label>
                <input type="text" name="last_four_digits" id="inlineCardDigits" maxlength="4" placeholder="Ex: 1234">
            </div>

            <div class="full-field">
                <label>Limite Total de Crédito (R$)</label>
                <input type="text" name="credit_limit" id="inlineCardLimit" placeholder="0,00" value="5.000,00">
            </div>

            <div>
                <label>Dia de Fechamento (1-31) *</label>
                <input type="number" name="closing_day" id="inlineCardClosing" min="1" max="31" value="1" required>
            </div>

            <div>
                <label>Dia de Vencimento (1-31) *</label>
                <input type="number" name="due_day" id="inlineCardDue" min="1" max="31" value="10" required>
            </div>

            <div class="full-field">
                <label>Cor do Cartão</label>
                <input type="color" name="color" id="inlineCardColor" value="#6366f1" style="height: 38px; padding: 2px;">
            </div>

            <footer class="form-actions full-field" style="margin-top: 10px; display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="button ghost" onclick="closeInlineCardModal()">Cancelar</button>
                <button type="submit" class="button primary" id="inlineCardSaveBtn">✓ Salvar Cartão</button>
            </footer>
        </form>
    </section>
</div>

<!-- ========================================================================= -->
<!-- MODAL: PAGAR FATURA DE CARTÃO                                             -->
<!-- ========================================================================= -->
<div id="payInvoiceModal" class="modal">
    <div class="modal-backdrop" onclick="closePayInvoiceModal()"></div>
    <section class="modal-panel">
        <header>
            <div>
                <p class="eyebrow">QUITAÇÃO DE CARTÃO</p>
                <h2 id="payInvTitle">Pagar Fatura de Cartão</h2>
            </div>
            <button type="button" class="modal-close" onclick="closePayInvoiceModal()">×</button>
        </header>
        <form method="post" class="form-grid" style="gap: 14px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="pay_daily_card_invoice">
            <input type="hidden" name="invoice_id" id="payInvId" value="">
            <input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">

            <div class="full-field">
                <label>Valor da Fatura</label>
                <input type="text" id="payInvAmount" readonly class="input-lg" style="background: #f1f5f9;">
            </div>

            <div class="full-field">
                <label>Data de Pagamento *</label>
                <input type="date" name="payment_date" required value="<?= date('Y-m-d') ?>">
            </div>

            <footer class="form-actions full-field" style="margin-top: 10px; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="button ghost" onclick="closePayInvoiceModal()">Cancelar</button>
                <button type="submit" class="button primary">✓ Confirmar Pagamento</button>
            </footer>
        </form>
    </section>
</div>

<!-- ========================================================================= -->
<!-- MODAL: NOVO CARTÃO DE CRÉDITO                                              -->
<!-- ========================================================================= -->
<div id="newCardModal" class="modal">
    <div class="modal-backdrop" onclick="closeNewCardModal()"></div>
    <section class="modal-panel">
        <header>
            <div>
                <p class="eyebrow">MEIOS DE PAGAMENTO</p>
                <h2>💳 Novo Cartão de Crédito</h2>
            </div>
            <button type="button" class="modal-close" onclick="closeNewCardModal()">×</button>
        </header>
        <form method="post" class="form-grid" style="gap: 14px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_daily_card">
            <input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">

            <div class="full-field">
                <label>Nome do Cartão *</label>
                <input type="text" name="name" required placeholder="Ex: Nubank Ultravioleta, XP Visa Infinite...">
            </div>

            <div>
                <label>Bandeira</label>
                <input type="text" name="brand" value="Mastercard" placeholder="Mastercard, Visa, Elo...">
            </div>

            <div>
                <label>Últimos 4 Dígitos</label>
                <input type="text" name="last_four_digits" maxlength="4" placeholder="Ex: 8821">
            </div>

            <div class="full-field">
                <label>Limite Total de Crédito (R$)</label>
                <input type="text" name="credit_limit" placeholder="0,00" value="5000,00">
            </div>

            <div>
                <label>Dia de Fechamento (1 a 31) *</label>
                <input type="number" name="closing_day" min="1" max="31" value="1" required>
            </div>

            <div>
                <label>Dia de Vencimento (1 a 31) *</label>
                <input type="number" name="due_day" min="1" max="31" value="10" required>
            </div>

            <div>
                <label>Cor de Identificação</label>
                <input type="color" name="color" value="#6366f1" style="height: 40px; padding: 2px;">
            </div>

            <div style="display: flex; align-items: center; margin-top: 20px;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" name="active" value="1" checked>
                    Cartão Ativo
                </label>
            </div>

            <footer class="form-actions full-field" style="margin-top: 10px; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="button ghost" onclick="closeNewCardModal()">Cancelar</button>
                <button type="submit" class="button primary">Cadastrar Cartão</button>
            </footer>
        </form>
    </section>
</div>

<!-- ========================================================================= -->
<!-- MODAL: NOVO COMPROMISSO FIXO / ESCOLA FILHOS                              -->
<!-- ========================================================================= -->
<div id="newCommitmentModal" class="modal">
    <div class="modal-backdrop" onclick="closeNewCommitmentModal()"></div>
    <section class="modal-panel">
        <header>
            <div>
                <p class="eyebrow">PLANEJAMENTO FAMILIAR</p>
                <h2>🎓 Novo Compromisso Fixo / Filhos</h2>
            </div>
            <button type="button" class="modal-close" onclick="closeNewCommitmentModal()">×</button>
        </header>
        <form method="post" class="form-grid" style="gap: 14px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_daily_commitment">
            <input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">

            <div class="full-field">
                <label>Beneficiário / Obrigação *</label>
                <input type="text" name="payee_name" required placeholder="Ex: Colégio Bernoulli, Cursinho de Inglês, Condomínio...">
            </div>

            <div class="full-field">
                <label>Descrição do Compromisso *</label>
                <input type="text" name="description" required placeholder="Ex: Mensalidade escolar Filhos, Natação, Aluguel...">
            </div>

            <div>
                <label>Valor Recorrente (R$) *</label>
                <input type="text" name="amount" required placeholder="0,00">
            </div>

            <div>
                <label>Dia de Vencimento *</label>
                <input type="number" name="due_day" min="1" max="31" value="10" required>
            </div>

            <div>
                <label>Data de Início *</label>
                <input type="date" name="start_date" required value="<?= date('Y-m-01') ?>">
            </div>

            <div>
                <label>Total de Parcelas (Opcional)</label>
                <input type="number" name="total_installments" placeholder="Ex: 12 (ou vazio se for contínuo)">
            </div>

            <div class="full-field">
                <label>Categoria Oficial</label>
                <select name="category_id">
                    <option value="">Selecione...</option>
                    <?php foreach ($categoryTree['expense'] as $macro): ?>
                        <optgroup label="<?= h($macro['icon'] . ' ' . $macro['name']) ?>">
                            <option value="<?= (int)$macro['id'] ?>"><?= h($macro['name']) ?> (Geral)</option>
                            <?php foreach ($macro['children'] as $sub): ?>
                                <option value="<?= (int)$sub['id'] ?>"><?= h($macro['name']) ?> › <?= h($sub['name']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="full-field">
                <label>Forma de Pagamento Habitual</label>
                <select name="payment_method">
                    <option value="pix">⚡ PIX</option>
                    <option value="boleto">📄 Boleto Bancário</option>
                    <option value="transfer">🏦 Débito em Conta / Transferência</option>
                    <option value="credit_card">💳 Cartão de Crédito</option>
                </select>
            </div>

            <input type="hidden" name="type" value="expense">
            <input type="hidden" name="active" value="1">

            <footer class="form-actions full-field" style="margin-top: 10px; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="button ghost" onclick="closeNewCommitmentModal()">Cancelar</button>
                <button type="submit" class="button primary">Salvar Compromisso</button>
            </footer>
        </form>
    </section>
</div>

<!-- ========================================================================= -->
<!-- MODAL: NOVA CATEGORIA / SUBCATEGORIA                                      -->
<!-- ========================================================================= -->
<div id="newCategoryModal" class="modal">
    <div class="modal-backdrop" onclick="closeNewCategoryModal()"></div>
    <section class="modal-panel">
        <header>
            <div>
                <p class="eyebrow">ESTRUTURAÇÃO RÍGIDA</p>
                <h2 id="catModalTitle">📁 Nova Categoria</h2>
            </div>
            <button type="button" class="modal-close" onclick="closeNewCategoryModal()">×</button>
        </header>
        <form method="post" class="form-grid" style="gap: 14px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_daily_category">
            <input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">
            <input type="hidden" name="parent_id" id="catParentId" value="">

            <div class="full-field" id="catParentDisplay" style="display: none; background: #f8fafc; padding: 8px 12px; border-radius: 6px;">
                <small class="muted">Subcategoria vinculada à Macro:</small>
                <b id="catParentName"></b>
            </div>

            <div class="full-field">
                <label>Nome da Categoria *</label>
                <input type="text" name="name" required placeholder="Ex: Farmácia, Açougue, Cursinho...">
            </div>

            <div>
                <label>Natureza *</label>
                <select name="type" id="catTypeSelect">
                    <option value="expense">Saída / Despesa</option>
                    <option value="income">Entrada / Receita</option>
                </select>
            </div>

            <div>
                <label>Ícone (Emoji)</label>
                <input type="text" name="icon" value="📁" maxlength="4">
            </div>

            <div>
                <label>Cor de Destaque</label>
                <input type="color" name="color" value="#2b826b" style="height: 40px; padding: 2px;">
            </div>

            <div>
                <label>Teto Mensal Orçamentário (R$)</label>
                <input type="text" name="monthly_budget_limit" placeholder="Ex: 2500,00">
            </div>

            <input type="hidden" name="active" value="1">

            <footer class="form-actions full-field" style="margin-top: 10px; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="button ghost" onclick="closeNewCategoryModal()">Cancelar</button>
                <button type="submit" class="button primary">Salvar Categoria</button>
            </footer>
        </form>
    </section>
</div>

<!-- ========================================================================= -->
<!-- JAVASCRIPT & ESTILOS DEDICADOS DA CENTRAL FINANCEIRA                       -->
<!-- ========================================================================= -->
<script>
const categoryData = <?= json_encode($categoryTree, JSON_UNESCAPED_UNICODE) ?>;
const payeesMap = <?= json_encode($recentPayees, JSON_UNESCAPED_UNICODE) ?>;
let cardsMap = <?= json_encode(array_values($allCards), JSON_UNESCAPED_UNICODE) ?>;

function parseMonetary(val) {
    if (typeof val === 'number') return val;
    if (!val) return 0;
    const clean = String(val).replace(/[^\d,\.-]/g, '').replace(/\./g, '').replace(',', '.');
    const num = parseFloat(clean);
    return isNaN(num) ? 0 : num;
}

function formatMonetary(num) {
    return Number(num).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function renderCategoryOptions(type, selectedId = null) {
    const select = document.getElementById('categorySelect');
    if (!select) return;
    select.innerHTML = '<option value="">Selecione uma categoria...</option>';

    const list = categoryData[type] || {};
    for (const macroId in list) {
        const macro = list[macroId];
        const group = document.createElement('optgroup');
        group.label = (macro.icon || '📁') + ' ' + macro.name;

        // Opção geral da macro
        const optGeral = document.createElement('option');
        optGeral.value = macro.id;
        optGeral.textContent = macro.name + ' (Geral)';
        if (selectedId && String(macro.id) === String(selectedId)) {
            optGeral.selected = true;
        }
        group.appendChild(optGeral);

        // Subcategorias
        if (macro.children && macro.children.length > 0) {
            macro.children.forEach(sub => {
                const opt = document.createElement('option');
                opt.value = sub.id;
                opt.textContent = macro.name + ' › ' + sub.name;
                if (selectedId && String(sub.id) === String(selectedId)) {
                    opt.selected = true;
                }
                group.appendChild(opt);
            });
        }
        select.appendChild(group);
    }
}

function handleTypeChange(type) {
    renderCategoryOptions(type);
    const expLabel = document.getElementById('radioLabelExpense');
    const incLabel = document.getElementById('radioLabelIncome');
    if (type === 'expense') {
        if (expLabel) expLabel.className = 'type-radio-btn active-expense';
        if (incLabel) incLabel.className = 'type-radio-btn';
    } else {
        if (expLabel) expLabel.className = 'type-radio-btn';
        if (incLabel) incLabel.className = 'type-radio-btn active-income';
    }
}

function handlePaymentMethodChange(method) {
    const cardBlock = document.getElementById('cardDetailsBlock');
    const instOption = document.getElementById('installmentOptionBlock');
    const instLabel = document.getElementById('installmentCheckboxLabel');
    const enableCheck = document.getElementById('enableInstallmentsCheckbox');

    if (method === 'credit_card') {
        if (cardBlock) cardBlock.style.display = 'block';
        if (instLabel) instLabel.textContent = 'Compra Parcelada no Cartão';
    } else {
        if (cardBlock) cardBlock.style.display = 'none';
        if (instLabel) instLabel.textContent = 'Dividir em Parcelas / Financiamento (Boleto, Carnê, etc.)';
    }

    if (enableCheck && enableCheck.checked) {
        renderInstallmentsSchedule();
    }
}

function handleCardSelectChange(val) {
    if (val === '__new__') {
        openInlineNewCardModal();
        const select = document.getElementById('cardSelectInput');
        if (select) select.value = '';
        return;
    }
    const enableCheck = document.getElementById('enableInstallmentsCheckbox');
    if (enableCheck && enableCheck.checked) {
        renderInstallmentsSchedule();
    }
}

function toggleInstallmentsSection(enabled) {
    const wrapper = document.getElementById('installmentSelectWrapper');
    const schedule = document.getElementById('installmentsScheduleBlock');
    if (wrapper) wrapper.style.display = enabled ? 'flex' : 'none';
    if (schedule) schedule.style.display = enabled ? 'block' : 'none';
    if (enabled) {
        renderInstallmentsSchedule();
    }
}

function handleInstallmentCountChange() {
    renderInstallmentsSchedule();
}

function handleAmountOrInstallmentsChange() {
    const enableCheck = document.getElementById('enableInstallmentsCheckbox');
    if (enableCheck && enableCheck.checked) {
        renderInstallmentsSchedule();
    }
}

function calculateInstallmentDates(baseDateStr, count, isCreditCard, cardId) {
    const dates = [];
    const baseDate = new Date(baseDateStr + 'T12:00:00');
    
    if (isCreditCard && cardId) {
        const card = cardsMap.find(c => String(c.id) === String(cardId));
        if (card) {
            const closingDay = parseInt(card.closing_day, 10) || 1;
            const dueDay = parseInt(card.due_day, 10) || 10;
            const txDay = baseDate.getDate();
            let curYear = baseDate.getFullYear();
            let curMonth = baseDate.getMonth(); // 0-indexed

            if (txDay >= closingDay) {
                curMonth++;
            }

            for (let i = 0; i < count; i++) {
                let instMonth = curMonth + i;
                let instYear = curYear;
                while (instMonth > 11) {
                    instMonth -= 12;
                    instYear++;
                }

                let dueM = instMonth;
                let dueY = instYear;
                if (dueDay < closingDay) {
                    dueM++;
                    if (dueM > 11) {
                        dueM = 0;
                        dueY++;
                    }
                }

                const daysInDueM = new Date(dueY, dueM + 1, 0).getDate();
                const actualDay = Math.min(dueDay, daysInDueM);
                const dStr = `${dueY}-${String(dueM + 1).padStart(2, '0')}-${String(actualDay).padStart(2, '0')}`;
                dates.push(dStr);
            }
            return dates;
        }
    }

    // Para outros métodos (Boleto, Financiamento, etc.):
    const day = baseDate.getDate();
    for (let i = 0; i < count; i++) {
        let targetMonth = baseDate.getMonth() + i;
        let targetYear = baseDate.getFullYear();
        while (targetMonth > 11) {
            targetMonth -= 12;
            targetYear++;
        }
        const maxDays = new Date(targetYear, targetMonth + 1, 0).getDate();
        const actualDay = Math.min(day, maxDays);
        const dStr = `${targetYear}-${String(targetMonth + 1).padStart(2, '0')}-${String(actualDay).padStart(2, '0')}`;
        dates.push(dStr);
    }
    return dates;
}

function renderInstallmentsSchedule() {
    const tbody = document.getElementById('installmentsTableBody');
    if (!tbody) return;

    const total = parseMonetary(document.getElementById('txAmountInput')?.value || 0);
    const dateStr = document.getElementById('txDateInput')?.value || new Date().toISOString().split('T')[0];
    const count = parseInt(document.getElementById('totalInstallmentsSelect')?.value || '2', 10);
    const method = document.getElementById('paymentMethodSelect')?.value || 'pix';
    const isCreditCard = (method === 'credit_card');
    const cardId = document.getElementById('cardSelectInput')?.value;

    const dates = calculateInstallmentDates(dateStr, count, isCreditCard, cardId);

    // Calcular valores das parcelas
    const baseVal = Math.floor((total / count) * 100) / 100;
    const diff = Math.round((total - (baseVal * count)) * 100) / 100;

    let html = '';
    for (let i = 1; i <= count; i++) {
        const dVal = dates[i - 1] || dateStr;
        const val = (i === 1) ? Number((baseVal + diff).toFixed(2)) : baseVal;
        const valFormatted = formatMonetary(val);
        const isPending = (i > 1) || (!isCreditCard && dVal > new Date().toISOString().split('T')[0]);
        const statusBadge = isCreditCard
            ? '<span class="badge" style="background:#e0e7ff; color:#4338ca; font-size:11px;">Fatura</span>'
            : (isPending ? '<span class="badge warning" style="font-size:11px;">Pendente</span>' : '<span class="badge success" style="font-size:11px;">Pago</span>');

        html += `
        <tr style="border-bottom: 1px solid #f1f5f9;">
            <td style="padding: 6px 10px; text-align: center; font-weight: 600; color: #64748b;">${i}/${count}</td>
            <td style="padding: 6px 10px;">
                <input type="date" name="installments[${i}][date]" value="${dVal}" class="inst-date-input" style="width: 100%; padding: 4px 8px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 4px;" onchange="handleInstallmentFieldChange()">
            </td>
            <td style="padding: 6px 10px;">
                <input type="text" name="installments[${i}][amount]" value="${valFormatted}" class="inst-amount-input" style="width: 100%; text-align: right; padding: 4px 8px; font-size: 13px; font-weight: 600; border: 1px solid #cbd5e1; border-radius: 4px;" oninput="handleInstallmentFieldChange()">
            </td>
            <td style="padding: 6px 10px; text-align: center;">
                ${statusBadge}
            </td>
        </tr>`;
    }

    tbody.innerHTML = html;
    handleInstallmentFieldChange();
}

function handleInstallmentFieldChange() {
    const amountInputs = document.querySelectorAll('.inst-amount-input');
    let sum = 0;
    amountInputs.forEach(inp => {
        sum += parseMonetary(inp.value);
    });

    const originalTotal = parseMonetary(document.getElementById('txAmountInput')?.value || 0);

    const sumEl = document.getElementById('installmentsSumText');
    const origEl = document.getElementById('originalTotalText');
    const diffNotice = document.getElementById('installmentsDiffNotice');
    const diffBadge = document.getElementById('diffBadge');

    if (sumEl) sumEl.textContent = 'R$ ' + formatMonetary(sum);
    if (origEl) origEl.textContent = 'R$ ' + formatMonetary(originalTotal);

    const diff = Math.round((originalTotal - sum) * 100) / 100;
    if (Math.abs(diff) >= 0.01) {
        if (diffNotice) diffNotice.style.display = 'inline-flex';
        if (diffBadge) {
            diffBadge.textContent = (diff > 0 ? 'Falta: R$ ' : 'Excedente: R$ ') + formatMonetary(Math.abs(diff));
            diffBadge.className = diff > 0 ? 'badge warning' : 'badge danger';
        }
    } else {
        if (diffNotice) diffNotice.style.display = 'none';
    }
}

function recalculateEqualInstallments() {
    renderInstallmentsSchedule();
}

function applyDifferenceToLastInstallment() {
    const amountInputs = document.querySelectorAll('.inst-amount-input');
    if (amountInputs.length === 0) return;

    const originalTotal = parseMonetary(document.getElementById('txAmountInput')?.value || 0);
    let sumOthers = 0;
    for (let i = 0; i < amountInputs.length - 1; i++) {
        sumOthers += parseMonetary(amountInputs[i].value);
    }
    const lastVal = Math.max(0, Number((originalTotal - sumOthers).toFixed(2)));
    amountInputs[amountInputs.length - 1].value = formatMonetary(lastVal);
    handleInstallmentFieldChange();
}

function syncTotalFromInstallments() {
    const amountInputs = document.querySelectorAll('.inst-amount-input');
    let sum = 0;
    amountInputs.forEach(inp => {
        sum += parseMonetary(inp.value);
    });
    const totalInp = document.getElementById('txAmountInput');
    if (totalInp) {
        totalInp.value = formatMonetary(sum);
    }
    handleInstallmentFieldChange();
}

function handleStatusChange(status) {
    const cardReal = document.getElementById('statusCardRealized');
    const cardPend = document.getElementById('statusCardPending');
    if (status === 'realized') {
        if (cardReal) cardReal.className = 'status-radio-card active-realized';
        if (cardPend) cardPend.className = 'status-radio-card';
    } else {
        if (cardReal) cardReal.className = 'status-radio-card';
        if (cardPend) cardPend.className = 'status-radio-card active-pending';
    }
}

function handlePayeeSelect(val) {
    const p = payeesMap.find(item => item.name.toLowerCase() === val.toLowerCase());
    if (p) {
        if (p.default_category_id) {
            const catSelect = document.getElementById('categorySelect');
            if (catSelect) catSelect.value = p.default_category_id;
        }
        if (p.default_payment_method) {
            const methodSelect = document.getElementById('paymentMethodSelect');
            if (methodSelect) {
                methodSelect.value = p.default_payment_method;
                handlePaymentMethodChange(p.default_payment_method);
            }
        }
    }
}

// Modal Quick Launch
function openQuickTxModal() {
    const form = document.getElementById('quickTxForm');
    if (form) form.reset();
    document.getElementById('txIdInput').value = '';
    document.getElementById('quickTxModalTitle').textContent = '⚡ Novo Lançamento Diário';
    document.getElementById('txDateInput').value = new Date().toISOString().split('T')[0];
    handleTypeChange('expense');
    handlePaymentMethodChange('pix');
    handleStatusChange('realized');
    toggleInstallmentsSection(false);
    document.getElementById('enableInstallmentsCheckbox').checked = false;
    document.getElementById('installmentOptionBlock').style.display = 'block';
    document.getElementById('quickTxModal').classList.add('open');
}

function closeQuickTxModal() {
    document.getElementById('quickTxModal').classList.remove('open');
}

// Modal Editar Lançamento
function openEditTxModal(tx) {
    document.getElementById('txIdInput').value = tx.id;
    document.getElementById('quickTxModalTitle').textContent = '✎ Editar Lançamento';
    document.getElementById('txAmountInput').value = formatMonetary(tx.amount);
    document.getElementById('txDateInput').value = tx.transaction_date;
    document.getElementById('payeeInput').value = tx.payee_name;
    document.getElementById('txDescriptionInput').value = tx.description || '';
    document.getElementById('txNotesInput').value = tx.notes || '';

    handleTypeChange(tx.type);
    renderCategoryOptions(tx.type, tx.category_id);

    const methodSelect = document.getElementById('paymentMethodSelect');
    if (methodSelect) {
        methodSelect.value = tx.payment_method;
        handlePaymentMethodChange(tx.payment_method);
    }

    if (tx.card_id) {
        const cardSelect = document.getElementById('cardSelectInput');
        if (cardSelect) cardSelect.value = tx.card_id;
    }

    handleStatusChange(tx.status || 'realized');
    const radio = document.querySelector(`input[name="status"][value="${tx.status || 'realized'}"]`);
    if (radio) radio.checked = true;

    // Em edição individual, esconde o bloco de novos parcelamentos
    toggleInstallmentsSection(false);
    document.getElementById('enableInstallmentsCheckbox').checked = false;
    document.getElementById('installmentOptionBlock').style.display = 'none';

    document.getElementById('quickTxModal').classList.add('open');
}

// Mini-Modal Cartão Inline (AJAX)
function openInlineNewCardModal() {
    const f = document.getElementById('inlineCardForm');
    if (f) f.reset();
    document.getElementById('inlineCardModal').classList.add('open');
}
function closeInlineCardModal() {
    document.getElementById('inlineCardModal').classList.remove('open');
}

async function handleInlineCardSubmit(e) {
    e.preventDefault();
    const form = e.target;
    const btn = document.getElementById('inlineCardSaveBtn');
    btn.disabled = true;
    btn.textContent = 'Salvando...';

    try {
        const formData = new FormData(form);
        const resp = await fetch('index.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await resp.json().catch(() => null);
        if (!resp.ok || !data || !data.ok) {
            alert('Erro ao salvar cartão: ' + ((data && data.message) ? data.message : ('Erro no processamento (HTTP ' + resp.status + ')')));
            return;
        }

        // Adicionar novo cartão no cardsMap
        cardsMap.push(data.card);

        // Adicionar no select de cartões
        const select = document.getElementById('cardSelectInput');
        if (select) {
            const newOpt = document.createElement('option');
            newOpt.value = data.card.id;
            newOpt.textContent = `${data.card.name} (${data.card.brand} · Fecha dia ${data.card.closing_day} / Vence dia ${data.card.due_day})`;
            newOpt.selected = true;
            select.insertBefore(newOpt, select.lastElementChild);
        }

        closeInlineCardModal();

        // Se o parcelamento estiver ativo, recalcular cronograma com base nas datas do novo cartão
        const enableCheck = document.getElementById('enableInstallmentsCheckbox');
        if (enableCheck && enableCheck.checked) {
            renderInstallmentsSchedule();
        }
    } catch (err) {
        alert('Não foi possível conectar para salvar o cartão.');
    } finally {
        btn.disabled = false;
        btn.textContent = '✓ Salvar Cartão';
    }
}

function openPayInvoiceModal(id, cardName, amount) {
    document.getElementById('payInvId').value = id;
    document.getElementById('payInvTitle').textContent = 'Pagar Fatura: ' + cardName;
    document.getElementById('payInvAmount').value = 'R$ ' + parseFloat(amount).toLocaleString('pt-BR', {minimumFractionDigits: 2});
    document.getElementById('payInvoiceModal').classList.add('open');
}
function closePayInvoiceModal() {
    document.getElementById('payInvoiceModal').classList.remove('open');
}

function openNewCardModal() {
    document.getElementById('newCardModal').classList.add('open');
}
function closeNewCardModal() {
    document.getElementById('newCardModal').classList.remove('open');
}

function openNewCommitmentModal() {
    document.getElementById('newCommitmentModal').classList.add('open');
}
function closeNewCommitmentModal() {
    document.getElementById('newCommitmentModal').classList.remove('open');
}

function openNewCategoryModal() {
    document.getElementById('catParentId').value = '';
    document.getElementById('catParentDisplay').style.display = 'none';
    document.getElementById('catModalTitle').textContent = '📁 Nova Categoria Macro';
    document.getElementById('newCategoryModal').classList.add('open');
}
function openAddSubcategoryModal(parentId, parentName, type) {
    document.getElementById('catParentId').value = parentId;
    document.getElementById('catParentName').textContent = parentName;
    document.getElementById('catParentDisplay').style.display = 'block';
    document.getElementById('catTypeSelect').value = type;
    document.getElementById('catModalTitle').textContent = '📁 Nova Subcategoria em ' + parentName;
    document.getElementById('newCategoryModal').classList.add('open');
}
function closeNewCategoryModal() {
    document.getElementById('newCategoryModal').classList.remove('open');
}

// Inicializar categorias padrão no carregamento
document.addEventListener('DOMContentLoaded', () => {
    renderCategoryOptions('expense');
});
</script>

<style>
/* Estilos Específicos da Central Financeira Diária */
.daily-finance-wrapper {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.daily-kpis {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
}
.kpi-card {
    background: var(--card);
    border-radius: var(--radius);
    padding: 16px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: var(--shadow);
    border: 1px solid var(--line);
}
.kpi-card .dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    flex-shrink: 0;
}
.kpi-card .dot.green { background: #10b981; }
.kpi-card .dot.red { background: #ef4444; }
.kpi-card .dot.gold { background: #f59e0b; }
.kpi-card .dot.purple { background: #8b5cf6; }
.kpi-info small {
    display: block;
    font-size: 11px;
    color: var(--muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.kpi-val {
    display: block;
    font-size: 18px;
    font-weight: 800;
    letter-spacing: -0.02em;
    margin-top: 2px;
}

.daily-header-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    background: var(--card);
    padding: 10px 16px;
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    border: 1px solid var(--line);
}
.daily-tabs-nav {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}
.daily-tab-btn {
    padding: 8px 14px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    color: var(--muted);
    text-decoration: none;
    transition: all 0.2s;
}
.daily-tab-btn:hover {
    color: var(--ink);
    background: var(--canvas);
}
.daily-tab-btn.active {
    background: #e2f2eb;
    color: #123333;
    font-weight: 700;
}
.quick-launch-btn {
    background: linear-gradient(135deg, #10b981, #059669) !important;
    font-weight: 700 !important;
    padding: 10px 20px !important;
    box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35);
}

/* Extrato Diário em Linha do Tempo */
.daily-timeline-container {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.day-group-card {
    background: var(--card);
    border-radius: var(--radius);
    border: 1px solid var(--line);
    box-shadow: var(--shadow);
    overflow: hidden;
}
.day-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f8fafc;
    padding: 10px 18px;
    border-bottom: 1px solid var(--line);
}
.day-title {
    display: flex;
    align-items: center;
    gap: 10px;
}
.day-badge {
    background: #e2e8f0;
    color: #475569;
    font-size: 11px;
    font-weight: 800;
    padding: 2px 8px;
    border-radius: 6px;
    text-transform: uppercase;
}
.day-badge.today {
    background: #10b981;
    color: #fff;
}
.income-tag {
    color: #10b981;
    font-weight: 700;
    font-size: 13px;
    margin-left: 8px;
}
.expense-tag {
    color: #ef4444;
    font-weight: 700;
    font-size: 13px;
    margin-left: 8px;
}

.day-items-list {
    display: flex;
    flex-direction: column;
}
.tx-item-row {
    display: flex;
    align-items: center;
    padding: 12px 18px;
    border-bottom: 1px solid #f1f5f9;
    gap: 14px;
    transition: background 0.15s;
}
.tx-item-row:last-child {
    border-bottom: none;
}
.tx-item-row:hover {
    background: #fafafa;
}
.tx-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: grid;
    place-items: center;
    font-size: 18px;
    flex-shrink: 0;
}
.tx-details {
    flex: 1;
}
.tx-main {
    display: flex;
    align-items: center;
    gap: 8px;
}
.tx-payee {
    font-size: 14px;
    color: var(--ink);
}
.tx-desc {
    color: var(--muted);
    font-size: 13px;
}
.tx-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 3px;
    font-size: 12px;
}
.category-pill {
    padding-left: 6px;
    color: var(--muted);
    font-weight: 500;
}
.method-tag {
    background: #f1f5f9;
    padding: 1px 6px;
    border-radius: 4px;
    font-size: 11px;
    color: #64748b;
}
.tx-amount-col {
    display: flex;
    align-items: center;
    gap: 14px;
}
.tx-amount {
    font-size: 15px;
}
.button-icon-danger {
    background: none;
    border: none;
    cursor: pointer;
    opacity: 0.4;
    transition: opacity 0.2s;
    font-size: 15px;
}
.button-icon-danger:hover {
    opacity: 1;
}

/* Agenda Preditiva */
.agenda-summary-banner {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 12px;
    background: var(--card);
    padding: 16px;
    border-radius: var(--radius);
    border: 1px solid var(--line);
}
.asb-item small {
    display: block;
    color: var(--muted);
    font-size: 11px;
    text-transform: uppercase;
}
.asb-item b {
    font-size: 18px;
    margin-top: 4px;
    display: block;
}
.agenda-today {
    background: #fffbeb !important;
}
.agenda-overdue {
    background: #fef2f2 !important;
}

/* Grid de Cartões */
.cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 16px;
}
.credit-card-item {
    background: var(--card);
    border-radius: var(--radius);
    padding: 18px;
    border: 1px solid var(--line);
    box-shadow: var(--shadow);
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.cci-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}
.cci-name {
    display: block;
    font-size: 16px;
    color: var(--ink);
}
.cci-brand {
    font-size: 12px;
    color: var(--muted);
}
.cci-invoice-box {
    background: #f8fafc;
    padding: 12px;
    border-radius: 8px;
}
.cci-inv-val {
    display: block;
    font-size: 20px;
    color: #d97706;
    margin-top: 2px;
}
.cci-due {
    display: block;
    font-size: 11px;
    color: var(--muted);
    margin-top: 4px;
}
.limit-labels {
    display: flex;
    justify-content: space-between;
    margin-bottom: 4px;
    font-size: 11px;
    color: var(--muted);
}
.progress-track {
    width: 100%;
    height: 7px;
    background: #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
}
.progress-fill {
    height: 100%;
    border-radius: 10px;
    transition: width 0.3s ease;
}
.cci-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 10px;
    border-top: 1px solid #f1f5f9;
}

/* Tetos & Orçamentos */
.budgets-header-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--card);
    padding: 16px;
    border-radius: var(--radius);
    border: 1px solid var(--line);
}
.budgets-totals-box {
    display: flex;
    gap: 24px;
}
.budgets-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 16px;
}
.budget-card {
    background: var(--card);
    border-radius: var(--radius);
    padding: 16px;
    border: 1px solid var(--line);
    box-shadow: var(--shadow);
}
.bc-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.bc-icon-title {
    display: flex;
    align-items: center;
    gap: 10px;
}
.bc-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: grid;
    place-items: center;
    font-size: 18px;
}
.bc-figures {
    display: flex;
    justify-content: space-between;
    margin-top: 14px;
}

/* Categorias Árvore */
.categories-tree-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}
.cat-col-title {
    margin-bottom: 12px;
    color: var(--muted);
    text-transform: uppercase;
    font-size: 12px;
    letter-spacing: 0.05em;
}
.cat-cards-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.cat-macro-card {
    background: var(--card);
    border-radius: var(--radius);
    border: 1px solid var(--line);
    padding: 14px;
}
.cm-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.cm-title {
    display: flex;
    align-items: center;
    gap: 10px;
}
.cm-icon {
    width: 30px;
    height: 30px;
    border-radius: 6px;
    display: grid;
    place-items: center;
    font-size: 16px;
}
.cm-sub-list {
    list-style: none;
    padding: 0;
    margin: 12px 0 0 0;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    border-top: 1px dashed var(--line);
    padding-top: 10px;
}
.cm-sub-list li {
    background: var(--canvas);
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 12px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.sub-del-btn {
    background: none;
    border: none;
    cursor: pointer;
    color: #94a3b8;
    font-size: 14px;
    line-height: 1;
    padding: 0;
}
.sub-del-btn:hover {
    color: #ef4444;
}

/* Botões do Modal Tipo de Lançamento */
.type-toggle-buttons {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
.type-radio-btn {
    border: 2px solid var(--line);
    border-radius: 8px;
    padding: 12px;
    text-align: center;
    cursor: pointer;
    font-weight: 700;
    transition: all 0.2s;
}
.type-radio-btn input {
    display: none;
}
.type-radio-btn.active-expense {
    border-color: #ef4444;
    background: #fef2f2;
    color: #b91c1c;
}
.type-radio-btn.active-income {
    border-color: #10b981;
    background: #ecfdf5;
    color: #047857;
}
.input-lg {
    font-size: 18px !important;
    font-weight: 700 !important;
    padding: 10px !important;
}

/* Forçar campos de largura total a ocuparem 100% das 2 colunas do grid */
.form-grid .full-field {
    grid-column: 1 / -1 !important;
}

/* Reset obrigatório de tamanho para checkbox e radio (evita que peguem min-height: 42px) */
.form-grid input[type="checkbox"],
.form-grid input[type="radio"] {
    width: 18px !important;
    min-height: 18px !important;
    height: 18px !important;
    padding: 0 !important;
    margin: 0 !important;
    border: none !important;
    cursor: pointer;
    box-shadow: none !important;
    display: inline-block !important;
    flex-shrink: 0 !important;
}

/* Inputs da Tabela de Parcelamento */
.installments-table input[type="date"],
.installments-table input[type="text"] {
    min-height: 34px !important;
    height: 34px !important;
    padding: 4px 8px !important;
    font-size: 13px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 6px !important;
    background: #fff !important;
}
.installments-table input[type="date"]:focus,
.installments-table input[type="text"]:focus {
    border-color: var(--primary) !important;
    box-shadow: 0 0 0 2px rgba(43, 130, 107, 0.15) !important;
}

/* Status Radio Cards */
.status-radio-card {
    border: 1.5px solid var(--line);
    border-radius: 8px;
    padding: 12px 16px;
    display: flex;
    flex-direction: row !important;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    background: #fff;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.2s ease;
}
.status-radio-card input[type="radio"] {
    accent-color: #10b981;
}
.status-radio-card.active-realized {
    border-color: #10b981 !important;
    background: #ecfdf5 !important;
    color: #065f46 !important;
}
.status-radio-card.active-pending {
    border-color: #f59e0b !important;
    background: #fffbeb !important;
    color: #92400e !important;
}
.status-radio-card.active-pending input[type="radio"] {
    accent-color: #f59e0b;
}

/* Cronograma de Parcelas */
.installments-scroll-wrap {
    max-height: 240px;
    overflow-y: auto;
    overflow-x: hidden;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    background: #fff;
}
.installments-table {
    width: 100%;
    border-collapse: collapse;
}
.installments-table th {
    background: #f8fafc;
    position: sticky;
    top: 0;
    z-index: 2;
    padding: 8px 12px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #64748b;
    border-bottom: 1px solid #e2e8f0;
}
.installments-table td {
    padding: 6px 10px;
    vertical-align: middle;
}
.button-icon-edit {
    transition: all 0.15s ease;
}
.button-icon-edit:hover {
    background: #f1f5f9 !important;
    border-color: #94a3b8 !important;
    color: #0f172a !important;
}
</style>
