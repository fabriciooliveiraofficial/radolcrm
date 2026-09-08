<?php

declare(strict_types=1);

use App\Services\FinanceService;

[$from, $to, $periodLabel] = period_dates();
$rate = $rates->current();
$finance = new FinanceService($db);

// Navegação de Abas Internas e Filtros
$activeTab = (string) ($_GET['tab'] ?? 'statement');
$search = trim((string) ($_GET['q'] ?? ''));
$typeFilter = (string) ($_GET['type'] ?? '');
$catFilter = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int) $_GET['category_id'] : null;

// Extrato Detalhado estilo QuickBooks
$statement = $finance->detailedStatement($from, $to, $buFilter ?? null, $typeFilter, $search, $catFilter);

// Dados dos Relatórios e Indicadores
$metrics = $finance->dashboard($from, $to, $rate['bid'], $buFilter ?? null);
$series = $finance->monthlySeries(12, $buFilter ?? null);

$participation = $finance->revenueParticipation($from, $to);
$revenueIndices = $finance->categoryRevenueIndices($from, $to, $buFilter ?? null);
$categoryIndices = $finance->categoryExpenseIndices($from, $to, $buFilter ?? null);

$allCategories = $db->fetchAll(
    "SELECT id, name, icon, color, type FROM categories WHERE active = 1 ORDER BY type DESC, name ASC"
);

$byCountry = $db->fetchAll(
    "SELECT c.country, COUNT(DISTINCT p.client_id) clients, COALESCE(SUM(p.amount_brl), 0) revenue
     FROM payments p
     JOIN clients c ON c.id=p.client_id
     WHERE p.status='paid' AND (CASE WHEN p.currency='USD' THEN COALESCE(p.settlement_date, p.payment_date) ELSE p.payment_date END) BETWEEN ? AND ?
     GROUP BY c.country ORDER BY revenue DESC",
    [$from, $to]
);

$byCurrency = $db->fetchAll(
    "SELECT currency, COUNT(*) payments, COALESCE(SUM(amount), 0) original, COALESCE(SUM(amount_brl), 0) brl
     FROM payments
     WHERE status='paid' AND (CASE WHEN currency='USD' THEN COALESCE(settlement_date, payment_date) ELSE payment_date END) BETWEEN ? AND ?
     GROUP BY currency ORDER BY brl DESC",
    [$from, $to]
);

$byProduct = $db->fetchAll(
    "SELECT COALESCE(pr.name, 'Pagamentos avulsos') product, COUNT(pa.id) payments, COALESCE(SUM(pa.net_brl), 0) revenue
     FROM payments pa
     LEFT JOIN subscriptions s ON s.id=pa.subscription_id
     LEFT JOIN products pr ON pr.id=s.product_id
     WHERE pa.status='paid' AND (CASE WHEN pa.currency='USD' THEN COALESCE(pa.settlement_date, pa.payment_date) ELSE pa.payment_date END) BETWEEN ? AND ?
     GROUP BY COALESCE(pr.name, 'Pagamentos avulsos')
     ORDER BY revenue DESC LIMIT 8",
    [$from, $to]
);
$maxProduct = max(array_column($byProduct, 'revenue') ?: [1]);
?>

<div class="reports-container">
    <!-- BARRA SUPERIOR DE NAVEGAÇÃO DE ABAS -->
    <div class="daily-header-actions" style="margin-bottom: 16px;">
        <nav class="daily-tabs-nav">
            <a class="daily-tab-btn <?= $activeTab === 'statement' ? 'active' : '' ?>" href="?page=reports&tab=statement<?= $buFilter ? '&bu='.$buFilter : '' ?>">
                📋 Extrato Geral de Lançamentos (<?= $statement['count'] ?>)
            </a>
            <a class="daily-tab-btn <?= $activeTab === 'dre_categories' ? 'active' : '' ?>" href="?page=reports&tab=dre_categories<?= $buFilter ? '&bu='.$buFilter : '' ?>">
                📊 DRE & Orçamentos por Categoria
            </a>
            <a class="daily-tab-btn <?= $activeTab === 'business_units' ? 'active' : '' ?>" href="?page=reports&tab=business_units">
                🏢 Desempenho por Unidade de Negócio
            </a>
            <a class="daily-tab-btn <?= $activeTab === 'currencies_markets' ? 'active' : '' ?>" href="?page=reports&tab=currencies_markets<?= $buFilter ? '&bu='.$buFilter : '' ?>">
                🌍 Moedas, Produtos & Mercados
            </a>
        </nav>
    </div>

    <!-- ========================================================================= -->
    <!-- ABA 1: EXTRATO DE LANÇAMENTOS (ESTILO QUICKBOOKS / LIVRO RAZÃO)           -->
    <!-- ========================================================================= -->
    <?php if ($activeTab === 'statement'): ?>
    <section class="toolbar dashboard-toolbar" style="margin-bottom: 16px;">
        <form method="get" class="search-filters" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; flex: 1;">
            <input type="hidden" name="page" value="reports">
            <input type="hidden" name="tab" value="statement">
            <?php if ($buFilter): ?>
                <input type="hidden" name="bu" value="<?= (int) $buFilter ?>">
            <?php endif; ?>

            <label class="search-box" style="min-width: 220px;">
                ⌕
                <input name="q" autocomplete="off" placeholder="Buscar cliente, fornecedor, descrição..." value="<?= h($search) ?>">
            </label>

            <select name="type">
                <option value="">Todas as movimentações</option>
                <option value="in" <?= $typeFilter === 'in' || $typeFilter === 'income' ? 'selected' : '' ?>>Somente Entradas (+)</option>
                <option value="out" <?= $typeFilter === 'out' || $typeFilter === 'expense' || $typeFilter === 'investment' ? 'selected' : '' ?>>Somente Saídas (-)</option>
            </select>

            <select name="category_id">
                <option value="">Todas as categorias</option>
                <?php foreach ($allCategories as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $catFilter === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= h($c['icon']) ?> <?= h($c['name']) ?> (<?= $c['type'] === 'income' ? 'Entrada' : 'Saída' ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="period" onchange="this.form.submit()">
                <option value="month">Este mês</option>
                <option value="quarter" <?= ($_GET['period'] ?? '') === 'quarter' ? 'selected' : '' ?>>Últimos 3 meses</option>
                <option value="year" <?= ($_GET['period'] ?? '') === 'year' ? 'selected' : '' ?>>Este ano</option>
                <option value="custom" <?= ($_GET['period'] ?? '') === 'custom' ? 'selected' : '' ?>>Personalizado</option>
            </select>

            <label>De <input name="from" type="date" value="<?= h($from) ?>"></label>
            <label>Até <input name="to" type="date" value="<?= h($to) ?>"></label>

            <button type="submit" class="button primary">Filtrar</button>
        </form>

        <div class="export-group" style="display: flex; gap: 8px;">
            <a class="button ghost" href="?page=export&type=statement&from=<?= h($from) ?>&to=<?= h($to) ?><?= $buFilter ? '&bu='.$buFilter : '' ?><?= $typeFilter ? '&type='.$typeFilter : '' ?><?= $search ? '&q='.urlencode($search) : '' ?>">
                ⇩ Extrato CSV (QuickBooks)
            </a>
            <a class="button ghost" href="javascript:window.print()">🖨️ Imprimir</a>
        </div>
    </section>

    <!-- BANNER DE COMPOSIÇÃO DE SALDO & RESULTADO (ESTILO GENERAL LEDGER) -->
    <section class="mini-stats daily-kpis" style="margin-bottom: 16px;">
        <div class="kpi-card">
            <span class="dot gold"></span>
            <div class="kpi-info">
                <small>Saldo Anterior ao Período</small>
                <b class="kpi-val" style="color: var(--ink);">
                    R$ <?= number_format($statement['opening_balance'], 2, ',', '.') ?>
                </b>
                <small style="margin-top: 3px; font-size: 11px; color: var(--muted); display: block;">
                    Em <?= date_br($from) ?>
                </small>
            </div>
        </div>

        <div class="kpi-card">
            <span class="dot green"></span>
            <div class="kpi-info">
                <small>Total de Entradas (+)</small>
                <b class="kpi-val positive">
                    + R$ <?= number_format($statement['total_in'], 2, ',', '.') ?>
                </b>
                <small style="margin-top: 3px; font-size: 11px; color: var(--muted); display: block;">
                    Recebimentos e créditos
                </small>
            </div>
        </div>

        <div class="kpi-card">
            <span class="dot red"></span>
            <div class="kpi-info">
                <small>Total de Saídas (-)</small>
                <b class="kpi-val negative">
                    - R$ <?= number_format($statement['total_out'], 2, ',', '.') ?>
                </b>
                <small style="margin-top: 3px; font-size: 11px; color: var(--muted); display: block;">
                    Gastos e investimentos
                </small>
            </div>
        </div>

        <div class="kpi-card <?= $statement['net_period'] >= 0 ? 'good' : 'danger' ?>">
            <span class="dot <?= $statement['net_period'] >= 0 ? 'green' : 'red' ?>"></span>
            <div class="kpi-info">
                <small>Resultado do Período</small>
                <b class="kpi-val <?= $statement['net_period'] >= 0 ? 'positive' : 'negative' ?>">
                    R$ <?= number_format($statement['net_period'], 2, ',', '.') ?>
                </b>
                <small style="margin-top: 3px; font-size: 11px; color: var(--muted); display: block;">
                    Flutuação líquida no período
                </small>
            </div>
        </div>

        <div class="kpi-card">
            <span class="dot purple"></span>
            <div class="kpi-info">
                <small>Saldo Final Acumulado</small>
                <b class="kpi-val" style="color: <?= $statement['closing_balance'] >= 0 ? '#10b981' : '#ef4444' ?>;">
                    R$ <?= number_format($statement['closing_balance'], 2, ',', '.') ?>
                </b>
                <small style="margin-top: 3px; font-size: 11px; color: var(--muted); display: block;">
                    Em <?= date_br($to) ?>
                </small>
            </div>
        </div>
    </section>

    <!-- TABELA COMPLETA DE EXTRATO DE LANÇAMENTOS (QUICKBOOKS STATEMENT REGISTER) -->
    <section class="card table-card" style="margin-bottom: 24px;">
        <div class="card-header padded" style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <p class="eyebrow">LIVRO RAZÃO & EXTRATO BANCÁRIO DETALHADO</p>
                <h2>Lançamentos de extratos financeiros e movimentações</h2>
            </div>
            <span class="muted">Exibindo <b><?= count($statement['items']) ?></b> lançamento(s) no período</span>
        </div>

        <div class="table-wrap">
            <table class="statement-table">
                <thead>
                    <tr>
                        <th style="width: 105px;">Data</th>
                        <th style="width: 150px;">Módulo / Origem</th>
                        <th>Entidade / Favorecido & Descrição</th>
                        <th>Categoria</th>
                        <th>Negócio (BU)</th>
                        <th>Método / Moeda</th>
                        <th style="text-align: right;">Entrada (+)</th>
                        <th style="text-align: right;">Saída (-)</th>
                        <th style="text-align: right; background: rgba(0,0,0,0.02);">Saldo Acumulado</th>
                        <th style="text-align: center; width: 110px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($statement['items'])): ?>
                        <tr>
                            <td colspan="10" class="empty-cell" style="padding: 40px; text-align: center;">
                                <span style="font-size: 32px; display: block; margin-bottom: 8px;">📑</span>
                                <b>Nenhum lançamento encontrado para os filtros selecionados.</b>
                                <p style="margin-top: 4px; color: var(--muted);">Tente alterar o período ou os parâmetros de busca acima.</p>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($statement['items'] as $tx): 
                        $isIn = $tx['direction'] === 'in';
                        $isToday = $tx['date'] === date('Y-m-d');
                    ?>
                        <tr class="<?= $isToday ? 'highlight-row' : '' ?>">
                            <td>
                                <b><?= date_br($tx['date']) ?></b>
                                <?php if ($isToday): ?>
                                    <small class="badge warning block" style="font-size: 9px; margin-top: 2px;">Hoje</small>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="badge" style="background: rgba(0,0,0,0.05); color: var(--ink); border: 1px solid var(--line);">
                                    <?= h($tx['source_module']) ?>
                                </span>
                            </td>

                            <td>
                                <div class="entity">
                                    <div>
                                        <b style="font-size: 13.5px; color: var(--ink);"><?= h($tx['entity']) ?></b>
                                        <?php if (!empty($tx['description']) && $tx['description'] !== $tx['entity']): ?>
                                            <small class="block" style="color: var(--muted); font-size: 11.5px;"><?= h($tx['description']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <span class="badge" style="background: <?= h($tx['category_color']) ?>18; color: <?= h($tx['category_color']) ?>; border: 1px solid <?= h($tx['category_color']) ?>44;">
                                    <?= h($tx['category_icon']) ?> <?= h($tx['category_name']) ?>
                                </span>
                            </td>

                            <td>
                                <span class="badge muted">
                                    <?= h($tx['bu_icon']) ?> <?= h($tx['bu_name']) ?>
                                </span>
                            </td>

                            <td>
                                <small style="font-weight: 600; color: var(--muted);"><?= h($tx['payment_method']) ?></small>
                            </td>

                            <td style="text-align: right;">
                                <?php if ($isIn): ?>
                                    <b class="positive" style="font-size: 13.5px;">+ R$ <?= number_format($tx['amount_brl'], 2, ',', '.') ?></b>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>

                            <td style="text-align: right;">
                                <?php if (!$isIn): ?>
                                    <b class="negative" style="font-size: 13.5px;">- R$ <?= number_format($tx['amount_brl'], 2, ',', '.') ?></b>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>

                            <td style="text-align: right; background: rgba(0,0,0,0.015); font-weight: 700;">
                                <span style="color: <?= $tx['running_balance'] >= 0 ? '#10b981' : '#ef4444' ?>;">
                                    R$ <?= number_format($tx['running_balance'], 2, ',', '.') ?>
                                </span>
                            </td>

                            <td style="text-align: center;">
                                <span class="badge <?= $tx['status'] === 'paid' ? 'success' : 'warning' ?>">
                                    <?= $tx['status'] === 'paid' ? 'Pago' : 'Pendente' ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <!-- ========================================================================= -->
    <!-- ABA 2: DRE & CATEGORIAS (DEMONSTRATIVO & LIMITADORES DE GASTOS)           -->
    <!-- ========================================================================= -->
    <?php if ($activeTab === 'dre_categories'): ?>
    <section class="report-summary card" style="margin-bottom: 1.5rem;">
        <div>
            <span>FATURAMENTO LÍQUIDO</span>
            <strong><?= money($metrics['net']) ?></strong>
            <small><?= h($periodLabel) ?></small>
        </div>
        <i>−</i>
        <div>
            <span>TOTAL DE GASTOS</span>
            <strong><?= money($metrics['expenses'] + $metrics['investments']) ?></strong>
            <small>Despesas e investimentos</small>
        </div>
        <i>=</i>
        <div class="highlight">
            <span>RESULTADO LÍQUIDO</span>
            <strong class="<?= $metrics['profit'] < 0 ? 'negative' : 'positive' ?>"><?= money($metrics['profit']) ?></strong>
            <small><?= number_format($metrics['margin'], 1, ',', '.') ?>% de margem</small>
        </div>
    </section>

    <!-- Alertas de Limitadores -->
    <?php if (!empty($categoryIndices['alerts'])): ?>
        <section class="card" style="border-left: 4px solid #ef4444; background: rgba(239, 68, 68, 0.08); margin-bottom: 1.5rem; padding: 1.25rem;">
            <div style="display: flex; gap: 0.75rem; align-items: flex-start;">
                <span style="font-size: 1.5rem;">⚠️</span>
                <div>
                    <h3 style="margin: 0 0 0.35rem 0; font-size: 1.05rem; color: #ef4444;">Limitador de Gastos Ultrapassado!</h3>
                    <p style="margin: 0 0 0.5rem 0; font-size: 0.9rem; color: var(--ink, #fff);">
                        As seguintes categorias ultrapassaram a meta de orçamento planejada no período:
                    </p>
                    <ul style="margin: 0; padding-left: 1.25rem; font-size: 0.85rem; color: #b91c1c;">
                        <?php foreach ($categoryIndices['alerts'] as $alert): ?>
                            <li>
                                <b><?= h($alert['icon'] ?: '📁') ?> <?= h($alert['category']) ?>:</b>
                                Consumiu <b><?= money($alert['spent']) ?></b> (<?= number_format($alert['consumption'], 1, ',', '.') ?>% do limite planejado).
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- Receitas por Categoria -->
    <section class="card" style="margin-bottom: 1.5rem;">
        <div class="card-header padded">
            <div>
                <p class="eyebrow">RECEITAS POR CATEGORIA</p>
                <h2>Origem detalhada do faturamento</h2>
            </div>
            <span class="muted">Total Faturado: <b><?= money($revenueIndices['total_revenue']) ?></b></span>
        </div>
        <div style="padding: 1rem 1.25rem;">
            <?php if (!$revenueIndices['categories']): ?>
                <div class="empty-mini">Nenhuma receita registrada no período.</div>
            <?php else: ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1rem;">
                    <?php foreach ($revenueIndices['categories'] as $cat): ?>
                        <div style="background: rgba(0,0,0,0.02); border: 1px solid var(--line); border-radius: 8px; padding: 1rem;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                <span class="badge" style="background: <?= h($cat['color']) ?>22; color: <?= h($cat['color']) ?>; border: 1px solid <?= h($cat['color']) ?>55;">
                                    <?= h($cat['icon']) ?> <?= h($cat['name']) ?>
                                </span>
                                <small class="muted"><?= (int) $cat['payment_count'] ?> recebimento(s)</small>
                            </div>
                            <div style="font-size: 1.35rem; font-weight: 700; color: var(--ink); margin-bottom: 0.5rem;">
                                <?= money($cat['revenue_brl']) ?>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <div style="flex: 1; height: 6px; background: rgba(0,0,0,0.08); border-radius: 3px; overflow: hidden;">
                                    <div style="background: <?= h($cat['color'] ?: '#10b981') ?>; width: <?= min(100, $cat['pct_of_revenue']) ?>%; height: 100%;"></div>
                                </div>
                                <span style="font-size: 0.82rem; font-weight: 600; color: <?= h($cat['color'] ?: '#10b981') ?>;">
                                    <?= number_format($cat['pct_of_revenue'], 1, ',', '.') ?>%
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Tabela de Índices de Despesa e Limitadores -->
    <section class="card table-card" style="margin-bottom: 1.5rem;">
        <div class="card-header padded">
            <div>
                <p class="eyebrow">ÍNDICES DE DESPESA E LIMITADORES</p>
                <h2>Controle de teto de gastos por categoria</h2>
            </div>
            <span class="muted">
                Despesas / Faturamento: <b><?= number_format($categoryIndices['expense_to_revenue_ratio'], 1, ',', '.') ?>%</b>
            </span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Categoria</th>
                        <th>Negócio</th>
                        <th>Gasto Realizado</th>
                        <th>% do Faturamento</th>
                        <th>Teto / Limitador</th>
                        <th>Consumo do Orçamento</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$categoryIndices['categories']): ?>
                        <tr><td colspan="7" class="empty-cell">Nenhuma categoria com lançamentos no período.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($categoryIndices['categories'] as $cat):
                        $isExceeded = $cat['status'] === 'danger';
                        $isWarning = $cat['status'] === 'warning';
                    ?>
                        <tr>
                            <td>
                                <div class="entity">
                                    <span style="font-size: 1.1rem; width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; background: <?= h($cat['color'] ?: '#2b826b') ?>22; color: <?= h($cat['color'] ?: '#2b826b') ?>; border-radius: 6px;">
                                        <?= h($cat['icon'] ?: '📁') ?>
                                    </span>
                                    <span>
                                        <b><?= h($cat['name']) ?></b>
                                        <?php if ($cat['parent_name']): ?>
                                            <small><?= h($cat['parent_name']) ?></small>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <?php if ($cat['bu_name']): ?>
                                    <span class="badge muted"><?= h($cat['bu_icon'] ?: '💼') ?> <?= h($cat['bu_name']) ?></span>
                                <?php else: ?>
                                    <span class="badge muted">Global</span>
                                <?php endif; ?>
                            </td>
                            <td><b><?= money($cat['spent_brl']) ?></b></td>
                            <td>
                                <b><?= number_format($cat['pct_of_revenue'], 1, ',', '.') ?>%</b>
                                <small class="muted block">(<?= number_format($cat['pct_of_expenses'], 1, ',', '.') ?>% dos gastos)</small>
                            </td>
                            <td>
                                <?php if ($cat['budget_limit_percent']): ?>
                                    <b>Máx. <?= number_format((float)$cat['budget_limit_percent'], 1, ',', '.') ?>% da receita</b>
                                <?php elseif ($cat['budget_limit_amount']): ?>
                                    <b>Teto: <?= money($cat['budget_limit_amount']) ?></b>
                                <?php else: ?>
                                    <span class="muted">Sem limite</span>
                                <?php endif; ?>
                            </td>
                            <td style="min-width: 140px;">
                                <?php if ($cat['budget_limit_percent'] || $cat['budget_limit_amount']): ?>
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <div style="flex: 1; height: 6px; background: rgba(0,0,0,0.1); border-radius: 3px; overflow: hidden;">
                                            <div style="background: <?= $isExceeded ? '#ef4444' : ($isWarning ? '#f59e0b' : '#10b981') ?>; width: <?= min(100, $cat['consumption_ratio']) ?>%; height: 100%;"></div>
                                        </div>
                                        <small style="font-weight: 700; color: <?= $isExceeded ? '#ef4444' : ($isWarning ? '#f59e0b' : '#10b981') ?>;">
                                            <?= number_format($cat['consumption_ratio'], 0, ',', '.') ?>%
                                        </small>
                                    </div>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $isExceeded ? 'danger' : ($isWarning ? 'warning' : 'success') ?>">
                                    <?= $isExceeded ? 'Estourou teto' : ($isWarning ? 'Atenção 80%+' : 'Saudável') ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <!-- ========================================================================= -->
    <!-- ABA 3: DESEMPENHO POR UNIDADE DE NEGÓCIO                                  -->
    <!-- ========================================================================= -->
    <?php if ($activeTab === 'business_units'): ?>
    <section class="card" style="margin-bottom: 1.5rem;">
        <div class="card-header padded">
            <div>
                <p class="eyebrow">PARTICIPAÇÃO NO FATURAMENTO</p>
                <h2>Origem da receita por unidade de negócio</h2>
            </div>
            <span class="muted">Total Faturado: <b><?= money($participation['total_revenue']) ?></b></span>
        </div>
        <div style="padding: 1rem 1.25rem;">
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1rem;">
                <?php if (!$participation['units']): ?>
                    <div class="empty-mini">Nenhuma receita registrada no período.</div>
                <?php endif; ?>
                <?php foreach ($participation['units'] as $unit): ?>
                    <div style="background: rgba(0,0,0,0.02); border: 1px solid var(--line); border-radius: 8px; padding: 1rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                            <span class="badge" style="background: <?= h($unit['color']) ?>22; color: <?= h($unit['color']) ?>; border: 1px solid <?= h($unit['color']) ?>55;">
                                <?= h($unit['icon']) ?> <?= h($unit['name']) ?>
                            </span>
                            <strong class="positive" style="font-size: 1.1rem;"><?= number_format($unit['share_percent'], 1, ',', '.') ?>%</strong>
                        </div>
                        <div style="height: 6px; background: rgba(0,0,0,0.08); border-radius: 3px; overflow: hidden; margin-bottom: 0.5rem;">
                            <div style="background: <?= h($unit['color']) ?>; width: <?= $unit['share_percent'] ?>%; height: 100%;"></div>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 0.85rem;">
                            <span><?= money($unit['revenue_brl']) ?></span>
                            <small class="muted"><?= (int) $unit['payments_count'] ?> recebimento(s)</small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Tabela Comparativa Completa entre BUs -->
    <section class="card table-card" style="margin-bottom: 1.5rem;">
        <div class="card-header padded">
            <div>
                <p class="eyebrow">DEMONSTRATIVO POR NEGÓCIO</p>
                <h2>Resultado e margem por Unidade de Negócio</h2>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Unidade de Negócio</th>
                        <th>Natureza</th>
                        <th style="text-align: right;">Faturamento Bruto</th>
                        <th style="text-align: right;">Faturamento Líquido</th>
                        <th style="text-align: right;">Participação (%)</th>
                        <th style="text-align: center;">Recebimentos</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($participation['units'] as $unit): ?>
                        <tr>
                            <td>
                                <div class="entity">
                                    <span class="avatar-sm" style="background: <?= h($unit['color']) ?>22; color: <?= h($unit['color']) ?>;">
                                        <?= h($unit['icon']) ?>
                                    </span>
                                    <b><?= h($unit['name']) ?></b>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?= $unit['is_personal'] ? 'warning' : 'good' ?>">
                                    <?= $unit['is_personal'] ? 'Pessoal / Familiar' : 'Empresarial' ?>
                                </span>
                            </td>
                            <td style="text-align: right;"><b><?= money($unit['revenue_brl']) ?></b></td>
                            <td style="text-align: right;"><b class="positive"><?= money($unit['revenue_brl']) ?></b></td>
                            <td style="text-align: right;"><b><?= number_format($unit['share_percent'], 1, ',', '.') ?>%</b></td>
                            <td style="text-align: center;"><span class="badge muted"><?= (int)$unit['payments_count'] ?> pgts</span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <!-- ========================================================================= -->
    <!-- ABA 4: MOEDAS, PRODUTOS & MERCADOS                                        -->
    <!-- ========================================================================= -->
    <?php if ($activeTab === 'currencies_markets'): ?>
    <section class="dashboard-grid reports-grid" style="margin-bottom: 1.5rem;">
        <article class="card chart-card">
            <div class="card-header">
                <div>
                    <p class="eyebrow">EVOLUÇÃO EM 12 MESES</p>
                    <h2>Entradas e saídas</h2>
                </div>
                <div class="chart-legend">
                    <span><i class="revenue"></i> Entradas</span>
                    <span><i class="cost"></i> Saídas</span>
                </div>
            </div>
            <div class="bar-chart" data-chart='<?= h(json_encode($series, JSON_UNESCAPED_UNICODE)) ?>'></div>
        </article>
        <article class="card">
            <div class="card-header">
                <div>
                    <p class="eyebrow">POR MOEDA</p>
                    <h2>Origem do faturamento</h2>
                </div>
            </div>
            <div class="currency-report">
                <?php if (!$byCurrency): ?>
                    <div class="empty-mini">Sem dados no período.</div>
                <?php endif; ?>
                <?php foreach ($byCurrency as $row): ?>
                    <div>
                        <span class="currency-mark <?= strtolower($row['currency']) ?>"><?= h($row['currency']) ?></span>
                        <span>
                            <b><?= money($row['original'], $row['currency']) ?></b>
                            <small><?= (int) $row['payments'] ?> pagamentos</small>
                        </span>
                        <strong><?= money($row['brl']) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="rate-foot">Cotação atual de referência: US$ 1 = <?= money($rate['bid']) ?></div>
        </article>
    </section>

    <section class="dashboard-grid reports-grid">
        <article class="card">
            <div class="card-header">
                <div>
                    <p class="eyebrow">RECEITA POR PRODUTO</p>
                    <h2>O que mais fatura</h2>
                </div>
            </div>
            <div class="rank-bars">
                <?php if (!$byProduct): ?>
                    <div class="empty-mini">Sem dados no período.</div>
                <?php endif; ?>
                <?php foreach ($byProduct as $row): ?>
                    <div>
                        <span><b><?= h($row['product']) ?></b><small><?= (int) $row['payments'] ?> pagamentos</small></span>
                        <strong><?= money($row['revenue']) ?></strong>
                        <i style="--width:<?= $maxProduct > 0 ? round($row['revenue'] / $maxProduct * 100) : 0 ?>%"></i>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
        <article class="card country-report">
            <div class="card-header">
                <div>
                    <p class="eyebrow">MERCADOS</p>
                    <h2>Brasil x Estados Unidos</h2>
                </div>
            </div>
            <div>
                <?php if (!$byCountry): ?>
                    <div class="empty-mini">Sem pagamentos no período.</div>
                <?php endif; ?>
                <?php foreach ($byCountry as $row): ?>
                    <article>
                        <?= country_flag_icon($row['country']) ?>
                        <span>
                            <b><?= $row['country'] === 'BR' ? 'Brasil' : 'Estados Unidos' ?></b>
                            <small><?= (int) $row['clients'] ?> clientes pagantes</small>
                        </span>
                        <strong><?= money($row['revenue']) ?></strong>
                    </article>
                <?php endforeach; ?>
            </div>
        </article>
    </section>
    <?php endif; ?>
</div>
