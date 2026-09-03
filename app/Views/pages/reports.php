<?php
use App\Services\FinanceService;

[$from, $to, $periodLabel] = period_dates();
$rate = $rates->current();
$finance = new FinanceService($db);
$metrics = $finance->dashboard($from, $to, $rate['bid']);
$series = $finance->monthlySeries(12);

$participation = $finance->revenueParticipation($from, $to);
$revenueIndices = $finance->categoryRevenueIndices($from, $to, $buFilter ?? null);
$categoryIndices = $finance->categoryExpenseIndices($from, $to);

$byCountry = $db->fetchAll("SELECT c.country, COUNT(DISTINCT p.client_id) clients, COALESCE(SUM(p.amount_brl), 0) revenue FROM payments p JOIN clients c ON c.id=p.client_id WHERE p.status='paid' AND (CASE WHEN p.currency='USD' THEN COALESCE(p.settlement_date, p.payment_date) ELSE p.payment_date END) BETWEEN ? AND ? GROUP BY c.country ORDER BY revenue DESC", [$from, $to]);
$byCurrency = $db->fetchAll("SELECT currency, COUNT(*) payments, COALESCE(SUM(amount), 0) original, COALESCE(SUM(amount_brl), 0) brl FROM payments WHERE status='paid' AND (CASE WHEN currency='USD' THEN COALESCE(settlement_date, p.payment_date) ELSE payment_date END) BETWEEN ? AND ? GROUP BY currency ORDER BY brl DESC", [$from, $to]);
$byProduct = $db->fetchAll("SELECT COALESCE(pr.name, 'Pagamentos avulsos') product, COUNT(pa.id) payments, COALESCE(SUM(pa.net_brl), 0) revenue FROM payments pa LEFT JOIN subscriptions s ON s.id=pa.subscription_id LEFT JOIN products pr ON pr.id=s.product_id WHERE pa.status='paid' AND (CASE WHEN pa.currency='USD' THEN COALESCE(pa.settlement_date, pa.payment_date) ELSE pa.payment_date END) BETWEEN ? AND ? GROUP BY COALESCE(pr.name, 'Pagamentos avulsos') ORDER BY revenue DESC LIMIT 8", [$from, $to]);
$maxProduct = max(array_column($byProduct, 'revenue') ?: [1]);
?>
<section class="toolbar dashboard-toolbar">
    <form method="get" class="period-filter" data-auto-submit>
        <input type="hidden" name="page" value="reports">
        <label>
            Período
            <select name="period">
                <option value="month">Este mês</option>
                <option value="quarter" <?= ($_GET['period'] ?? '') === 'quarter' ? 'selected' : '' ?>>Últimos 3 meses</option>
                <option value="year" <?= ($_GET['period'] ?? '') === 'year' ? 'selected' : '' ?>>Este ano</option>
                <option value="custom" <?= ($_GET['period'] ?? '') === 'custom' ? 'selected' : '' ?>>Personalizado</option>
            </select>
        </label>
        <label>De<input name="from" type="date" value="<?= h($from) ?>"></label>
        <label>Até<input name="to" type="date" value="<?= h($to) ?>"></label>
    </form>
    <div class="export-group">
        <a class="button ghost" href="?page=export&type=payments">⇩ Pagamentos CSV</a>
        <a class="button ghost" href="?page=export&type=expenses">⇩ Gastos CSV</a>
    </div>
</section>

<section class="report-summary card">
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

<!-- Alerts on Budget Thresholds -->
<?php if (!empty($categoryIndices['alerts'])): ?>
    <section class="card" style="border-left: 4px solid #ef4444; background: rgba(239, 68, 68, 0.08); margin-bottom: 1.5rem; padding: 1.25rem;">
        <div style="display: flex; gap: 0.75rem; align-items: flex-start;">
            <span style="font-size: 1.5rem;">⚠️</span>
            <div>
                <h3 style="margin: 0 0 0.35rem 0; font-size: 1.05rem; color: #ef4444;">Limitador de Gastos Ultrapassado!</h3>
                <p style="margin: 0 0 0.5rem 0; font-size: 0.9rem; color: var(--text, #fff);">
                    As seguintes categorias ultrapassaram a meta de orçamento planejada no período:
                </p>
                <ul style="margin: 0; padding-left: 1.25rem; font-size: 0.85rem; color: #fca5a5;">
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

<!-- 1. Revenue Participation by Business Unit -->
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
                <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.07); border-radius: 8px; padding: 1rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                        <span class="badge" style="background: <?= h($unit['color']) ?>22; color: <?= h($unit['color']) ?>; border: 1px solid <?= h($unit['color']) ?>55;">
                            <?= h($unit['icon']) ?> <?= h($unit['name']) ?>
                        </span>
                        <strong class="positive" style="font-size: 1.1rem;"><?= number_format($unit['share_percent'], 1, ',', '.') ?>%</strong>
                    </div>
                    <div style="height: 6px; background: rgba(255,255,255,0.1); border-radius: 3px; overflow: hidden; margin-bottom: 0.5rem;">
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

<!-- 2. Revenue by Category -->
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
                    <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.07); border-radius: 8px; padding: 1rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                            <span class="badge" style="background: <?= h($cat['color']) ?>22; color: <?= h($cat['color']) ?>; border: 1px solid <?= h($cat['color']) ?>55;">
                                <?= h($cat['icon']) ?> <?= h($cat['name']) ?>
                            </span>
                            <small class="muted"><?= (int) $cat['payment_count'] ?> recebimento(s)</small>
                        </div>
                        <div style="font-size: 1.35rem; font-weight: 700; color: var(--text, #fff); margin-bottom: 0.5rem;">
                            <?= money($cat['revenue_brl']) ?>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <div style="flex: 1; height: 6px; background: rgba(255,255,255,0.08); border-radius: 3px; overflow: hidden;">
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

<!-- 3. Category Expense Indices & Limiters Table -->
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
                                    <div style="flex: 1; height: 6px; background: rgba(255,255,255,0.1); border-radius: 3px; overflow: hidden;">
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

<!-- 3. Existing Charts & Visual Breakdown -->
<section class="dashboard-grid reports-grid">
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
