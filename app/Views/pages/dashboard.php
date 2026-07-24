<?php
use App\Services\FinanceService;

[$from, $to, $periodLabel] = period_dates();
$rate = $rates->current();
$finance = new FinanceService($db);
$metrics = $finance->dashboard($from, $to, (float) $rate['bid']);
$intelligence = $finance->businessIntelligence((float) $rate['bid']);
$series = $finance->monthlySeries();
$balance = $finance->cashBalance();

try {
    $fromDate = new DateTimeImmutable($from);
    $toDate = new DateTimeImmutable($to);
} catch (Throwable) {
    $fromDate = new DateTimeImmutable('first day of this month');
    $toDate = new DateTimeImmutable('today');
}
$periodDays = max(1, $fromDate->diff($toDate)->days + 1);
$previousTo = $fromDate->modify('-1 day');
$previousFrom = $previousTo->modify('-' . ($periodDays - 1) . ' days');
$previousMetrics = $finance->dashboard($previousFrom->format('Y-m-d'), $previousTo->format('Y-m-d'), (float) $rate['bid']);
$revenueGrowth = $previousMetrics['gross'] > 0
    ? (($metrics['gross'] - $previousMetrics['gross']) / $previousMetrics['gross']) * 100
    : ($metrics['gross'] > 0 ? 100.0 : 0.0);
$growthLabel = $previousMetrics['gross'] > 0
    ? number_format(abs($revenueGrowth), 1, ',', '.') . '% vs. período anterior'
    : ($metrics['gross'] > 0 ? 'Nova receita no período' : 'Sem variação no período');

$clients = $intelligence['clients'];
$subscriptions = $intelligence['subscriptions'];
$renewals = $intelligence['renewals'];
$collections = $intelligence['collections'];
$financialScore = $metrics['gross'] > 0 ? max(0, min(100, 50 + $metrics['margin'])) : 0;
$managementScore = (int) round(($financialScore * .45) + ($collections['rate'] * .30) + ($intelligence['portfolioHealth'] * .25));
$managementLabel = $managementScore >= 85 ? 'Excelência' : ($managementScore >= 70 ? 'Saudável' : ($managementScore >= 45 ? 'Atenção' : 'Crítico'));
$managementTone = $managementScore >= 70 ? 'good' : ($managementScore >= 45 ? 'warning' : 'danger');
$arr = $metrics['mrr'] * 12;
$arpa = $metrics['activeClients'] > 0 ? $metrics['mrr'] / $metrics['activeClients'] : 0;

$lifecycleTotal = max(1, (int) ($subscriptions['total'] ?? 0));
$activeEnd = ((int) ($subscriptions['active'] ?? 0) / $lifecycleTotal) * 360;
$trialEnd = $activeEnd + ((int) ($subscriptions['trial'] ?? 0) / $lifecycleTotal) * 360;
$pastDueEnd = $trialEnd + ((int) ($subscriptions['past_due'] ?? 0) / $lifecycleTotal) * 360;
$pausedEnd = $pastDueEnd + ((int) ($subscriptions['paused'] ?? 0) / $lifecycleTotal) * 360;
$lifecycleGradient = sprintf(
    'conic-gradient(#2b826b 0deg %.2fdeg,#7aa6d8 %.2fdeg %.2fdeg,#d49c42 %.2fdeg %.2fdeg,#8d79b6 %.2fdeg %.2fdeg,#d9dfdc %.2fdeg 360deg)',
    $activeEnd, $activeEnd, $trialEnd, $trialEnd, $pastDueEnd, $pastDueEnd, $pausedEnd, $pausedEnd
);

$maxProductMrr = 0.0;
foreach ($intelligence['topProducts'] as $productRow) {
    $maxProductMrr = max($maxProductMrr, (float) $productRow['mrr']);
}
$countryMrrTotal = array_sum(array_map(static fn(array $row): float => (float) $row['mrr'], $intelligence['countries']));

$brief = 'Operação estável, sem pendências críticas identificadas agora.';
if ($renewals['overdueCount'] > 0) {
    $brief = $renewals['overdueCount'] . ' assinatura(s) vencida(s) exigem atenção, somando ' . money($renewals['overdueValue']) . '.';
} elseif ($metrics['profit'] < 0) {
    $brief = 'O resultado do período está negativo. Revise custos e investimentos para recuperar margem.';
} elseif ($renewals['next7'] > 0) {
    $brief = $renewals['next7'] . ' renovação(ões) chegam nos próximos 7 dias. Acompanhe o pipeline de cobrança.';
}

$upcoming = $db->fetchAll(
    "SELECT s.id,s.next_billing_date,s.currency,s.unit_price,s.quantity,s.discount,c.name client,p.name product
     FROM subscriptions s JOIN clients c ON c.id=s.client_id JOIN products p ON p.id=s.product_id
     WHERE s.status IN ('active','trial','past_due')
       AND s.next_billing_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)
     ORDER BY s.next_billing_date LIMIT 6"
);
$dashboardTimezone = new DateTimeZone((string) ($config['app']['timezone'] ?? 'America/Sao_Paulo'));
$tomorrowDate = (new DateTimeImmutable('today', $dashboardTimezone))->modify('+1 day')->format('Y-m-d');
$tomorrowSubscriptions = $db->fetchAll(
    "SELECT s.id,s.next_billing_date,c.name client,c.country
     FROM subscriptions s
     JOIN clients c ON c.id=s.client_id
     WHERE s.status IN ('active','trial','past_due')
       AND s.next_billing_date=?
     ORDER BY c.name",
    [$tomorrowDate]
);
$recent = $db->fetchAll(
    "SELECT p.id,p.payment_date,p.settlement_date,p.amount,p.currency,p.amount_brl,p.status,c.name client
     FROM payments p JOIN clients c ON c.id=p.client_id
     ORDER BY COALESCE(CASE WHEN p.currency='USD' THEN p.settlement_date ELSE p.payment_date END,p.payment_date,p.due_date) DESC,p.id DESC LIMIT 6"
);
?>
<section class="toolbar dashboard-toolbar">
    <form method="get" class="period-filter" data-auto-submit>
        <input type="hidden" name="page" value="dashboard">
        <label>Período<select name="period"><option value="month">Este mês</option><option value="today" <?= ($_GET['period'] ?? '') === 'today' ? 'selected' : '' ?>>Hoje</option><option value="quarter" <?= ($_GET['period'] ?? '') === 'quarter' ? 'selected' : '' ?>>Últimos 3 meses</option><option value="year" <?= ($_GET['period'] ?? '') === 'year' ? 'selected' : '' ?>>Este ano</option><option value="custom" <?= ($_GET['period'] ?? '') === 'custom' ? 'selected' : '' ?>>Personalizado</option></select></label>
        <?php if (($_GET['period'] ?? '') === 'custom'): ?><label>De<input type="date" name="from" value="<?= h($from) ?>"></label><label>Até<input type="date" name="to" value="<?= h($to) ?>"></label><?php endif; ?>
    </form>
    <div class="rate-pill">
        <span class="live-dot"></span><div><small>COTAÇÃO DIÁRIA USD/BRL</small><b>US$ 1 = <?= money($rate['bid']) ?></b></div><span><?= h($rate['source']) ?><br><?= date_br($rate['quoted_at']) ?></span>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="refresh_rate"><input type="hidden" name="_return" value="?page=dashboard"><button class="icon-button" title="Atualizar cotação">↻</button></form>
    </div>
</section>
<?php if (!empty($rate['warning'])): ?><div class="inline-warning">⚠ <?= h($rate['warning']) ?></div><?php endif; ?>

<section class="executive-hero card">
    <div class="executive-copy">
        <p class="eyebrow">CENTRAL DE COMANDO · <?= h(mb_strtoupper($periodLabel)) ?></p>
        <h2>Visão executiva do negócio</h2>
        <p><?= h($brief) ?></p>
        <span class="executive-update"><i></i> Dados atualizados agora · <?= date('d/m/Y H:i') ?></span>
    </div>
    <div class="executive-pulse">
        <div class="executive-score <?= h($managementTone) ?>" style="--score:<?= $managementScore ?>">
            <span><strong><?= $managementScore ?></strong><small>/100</small></span>
        </div>
        <div><small>SCORE DE GESTÃO</small><strong><?= h($managementLabel) ?></strong><span>Finanças, recebimentos e carteira</span></div>
    </div>
    <div class="executive-kpis">
        <div><span>Taxa de recebimento</span><strong><?= number_format($collections['rate'], 1, ',', '.') ?>%</strong><small>últimos 90 dias</small></div>
        <div><span>ARR projetado</span><strong><?= money($arr) ?></strong><small>receita anual recorrente</small></div>
        <div><span>ARPA mensal</span><strong><?= money($arpa) ?></strong><small>por cliente recorrente</small></div>
    </div>
</section>

<section class="metric-grid executive-metrics">
    <article class="metric-card"><div class="metric-icon green">↗</div><div><span>Faturamento bruto</span><strong><?= money($metrics['gross']) ?></strong><small class="metric-trend <?= $revenueGrowth < 0 ? 'down' : 'up' ?>"><?= $revenueGrowth < 0 ? '↓' : '↑' ?> <?= h($growthLabel) ?></small></div></article>
    <article class="metric-card"><div class="metric-icon gold">◎</div><div><span>Lucro líquido</span><strong class="<?= $metrics['profit'] < 0 ? 'negative' : 'positive' ?>"><?= money($metrics['profit']) ?></strong><small class="<?= $metrics['margin'] < 0 ? 'negative' : 'positive' ?>"><?= number_format($metrics['margin'], 1, ',', '.') ?>% de margem líquida</small></div></article>
    <article class="metric-card"><div class="metric-icon blue">↻</div><div><span>Receita recorrente (MRR)</span><strong><?= money($metrics['mrr']) ?></strong><small><?= (int) $metrics['activeSubscriptions'] ?> assinaturas ativas · ARR <?= money($arr) ?></small></div></article>
    <article class="metric-card"><div class="metric-icon purple">▤</div><div><span>Saldo de caixa atual</span><strong class="<?= $balance < 0 ? 'negative' : 'positive' ?>"><?= money($balance) ?></strong><small><?= (int) $metrics['paymentCount'] ?> pagamento(s) em <?= h(mb_strtolower($periodLabel)) ?></small></div></article>
</section>

<section class="customer-command card">
    <div class="card-header">
        <div><p class="eyebrow">CARTEIRA DE CLIENTES</p><h2>Mapa de relacionamento</h2><p class="card-subtitle">Status cadastral, recorrência e risco em uma única visão.</p></div>
        <a href="?page=clients">Gerenciar clientes →</a>
    </div>
    <div class="customer-status-grid">
        <a href="?page=clients"><i class="status-signal total">◎</i><span><small>Total da base</small><strong><?= (int) ($clients['total'] ?? 0) ?></strong><em>clientes cadastrados</em></span></a>
        <a href="?page=clients&status=active"><i class="status-signal active">●</i><span><small>Clientes ativos</small><strong><?= (int) ($clients['active'] ?? 0) ?></strong><em>status ativo no cadastro</em></span></a>
        <a href="?page=clients&status=inactive"><i class="status-signal inactive">○</i><span><small>Clientes inativos</small><strong><?= (int) ($clients['inactive'] ?? 0) ?></strong><em>oportunidade de reativação</em></span></a>
        <a href="?page=clients&status=lead"><i class="status-signal lead">✦</i><span><small>Leads</small><strong><?= (int) ($clients['leads'] ?? 0) ?></strong><em>potencial de conversão</em></span></a>
        <a href="?page=subscriptions&status=active"><i class="status-signal recurring">↻</i><span><small>Clientes recorrentes</small><strong><?= (int) ($subscriptions['recurring_clients'] ?? 0) ?></strong><em>com assinatura vigente</em></span></a>
        <a href="?page=subscriptions&due=overdue" class="<?= (int) ($subscriptions['overdue_clients'] ?? 0) > 0 ? 'needs-attention' : '' ?>"><i class="status-signal overdue">!</i><span><small>Clientes vencidos</small><strong><?= (int) ($subscriptions['overdue_clients'] ?? 0) ?></strong><em>precisam de acompanhamento</em></span><b class="status-chevron">→</b></a>
    </div>
</section>

<section class="card tomorrow-subscriptions">
    <div class="card-header">
        <div><p class="eyebrow">VENCEM AMANHÃ</p><h2>Assinaturas a vencer no dia seguinte</h2></div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Nome</th><th>País</th><th>Data de vencimento</th></tr></thead>
            <tbody>
                <?php if (!$tomorrowSubscriptions): ?><tr><td colspan="3" class="empty-cell">Nenhuma assinatura vence amanhã.</td></tr><?php endif; ?>
                <?php foreach ($tomorrowSubscriptions as $item): ?>
                    <tr>
                        <td><b><?= h($item['client']) ?></b></td>
                        <td><?= country_flag_icon($item['country']) ?></td>
                        <td><b><?= date_br($item['next_billing_date']) ?></b></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="intelligence-grid">
    <article class="card chart-card performance-card">
        <div class="card-header"><div><p class="eyebrow">DESEMPENHO FINANCEIRO</p><h2>Receitas x saídas</h2><p class="card-subtitle">Evolução dos últimos 6 meses em reais.</p></div><div class="chart-legend"><span><i class="revenue"></i> Entradas</span><span><i class="cost"></i> Saídas</span></div></div>
        <div class="bar-chart" data-chart='<?= h(json_encode($series, JSON_UNESCAPED_UNICODE)) ?>'></div>
    </article>
    <article class="card operational-health">
        <div class="card-header"><div><p class="eyebrow">SAÚDE OPERACIONAL</p><h2>Diagnóstico 360°</h2></div><span class="health-state <?= h($managementTone) ?>"><?= h($managementLabel) ?></span></div>
        <div class="health-dimensions">
            <div><span><b>Saúde financeira</b><strong><?= (int) round($financialScore) ?>%</strong></span><i><em style="width:<?= (int) round($financialScore) ?>%"></em></i></div>
            <div><span><b>Recebimentos 90d</b><strong><?= (int) round($collections['rate']) ?>%</strong></span><i><em style="width:<?= (int) round($collections['rate']) ?>%"></em></i></div>
            <div><span><b>Saúde da carteira</b><strong><?= (int) round($intelligence['portfolioHealth']) ?>%</strong></span><i><em style="width:<?= (int) round($intelligence['portfolioHealth']) ?>%"></em></i></div>
        </div>
        <dl class="summary-list executive-summary">
            <div><dt>Receita líquida</dt><dd><?= money($metrics['net']) ?></dd></div>
            <div><dt>Ticket médio recebido</dt><dd><?= money($collections['averageTicket']) ?></dd></div>
            <div><dt>Despesas + investimentos</dt><dd>− <?= money($metrics['expenses'] + $metrics['investments']) ?></dd></div>
            <div class="total"><dt>Resultado do período</dt><dd class="<?= $metrics['profit'] < 0 ? 'negative' : 'positive' ?>"><?= money($metrics['profit']) ?></dd></div>
        </dl>
    </article>
</section>

<section class="management-grid">
    <article class="card action-center">
        <div class="card-header"><div><p class="eyebrow">COPILOTO DE GESTÃO</p><h2>Radar de ações prioritárias</h2><p class="card-subtitle">O que merece sua atenção agora.</p></div><span class="action-count"><?= ($renewals['overdueCount'] > 0 ? 1 : 0) + ($metrics['profit'] < 0 ? 1 : 0) + ((int) ($clients['inactive'] ?? 0) > 0 ? 1 : 0) + ($renewals['next7'] > 0 ? 1 : 0) ?></span></div>
        <div class="action-list">
            <?php if ($renewals['overdueCount'] > 0): ?><a href="?page=subscriptions&due=overdue" class="danger"><i>!</i><span><b>Recupere receitas vencidas</b><small><?= $renewals['overdueCount'] ?> assinatura(s), no valor estimado de <?= money($renewals['overdueValue']) ?>.</small></span><em>Revisar →</em></a><?php endif; ?>
            <?php if ($metrics['profit'] < 0): ?><a href="?page=reports&period=<?= h($_GET['period'] ?? 'month') ?>" class="warning"><i>↘</i><span><b>Margem do período está negativa</b><small>O resultado atual é <?= money($metrics['profit']) ?>. Analise custos e investimentos.</small></span><em>Analisar →</em></a><?php endif; ?>
            <?php if ((int) ($clients['inactive'] ?? 0) > 0): ?><a href="?page=clients&status=inactive" class="neutral"><i>↻</i><span><b>Ative sua base adormecida</b><small><?= (int) $clients['inactive'] ?> cliente(s) inativo(s) podem entrar em uma campanha de recuperação.</small></span><em>Ver base →</em></a><?php endif; ?>
            <?php if ($renewals['next7'] > 0): ?><a href="?page=subscriptions&due=next_7" class="info"><i>⌁</i><span><b>Prepare as próximas renovações</b><small><?= $renewals['next7'] ?> cobrança(s) vencem nos próximos 7 dias.</small></span><em>Acompanhar →</em></a><?php endif; ?>
            <?php if ($renewals['overdueCount'] === 0 && $metrics['profit'] >= 0 && (int) ($clients['inactive'] ?? 0) === 0 && $renewals['next7'] === 0): ?><div class="all-clear"><i>✓</i><span><b>Nenhuma ação crítica agora</b><small>O radar continuará monitorando sua operação automaticamente.</small></span></div><?php endif; ?>
        </div>
    </article>
    <article class="card lifecycle-card">
        <div class="card-header"><div><p class="eyebrow">CICLO DE VIDA</p><h2>Assinaturas por status</h2></div><a href="?page=subscriptions">Ver todas →</a></div>
        <div class="lifecycle-visual">
            <div class="lifecycle-donut" style="background:<?= h($lifecycleGradient) ?>"><span><strong><?= (int) ($subscriptions['total'] ?? 0) ?></strong><small>assinaturas</small></span></div>
            <div class="lifecycle-legend">
                <a href="?page=subscriptions&status=active"><i class="active"></i><span>Ativas</span><strong><?= (int) ($subscriptions['active'] ?? 0) ?></strong></a>
                <a href="?page=subscriptions&status=trial"><i class="trial"></i><span>Em teste</span><strong><?= (int) ($subscriptions['trial'] ?? 0) ?></strong></a>
                <a href="?page=subscriptions&status=past_due"><i class="past-due"></i><span>Em atraso</span><strong><?= (int) ($subscriptions['past_due'] ?? 0) ?></strong></a>
                <a href="?page=subscriptions&status=paused"><i class="paused"></i><span>Pausadas</span><strong><?= (int) ($subscriptions['paused'] ?? 0) ?></strong></a>
                <a href="?page=subscriptions&status=canceled"><i class="canceled"></i><span>Canceladas</span><strong><?= (int) ($subscriptions['canceled'] ?? 0) ?></strong></a>
            </div>
        </div>
    </article>
</section>

<section class="portfolio-grid">
    <article class="card product-intelligence">
        <div class="card-header"><div><p class="eyebrow">CONCENTRAÇÃO DE RECEITA</p><h2>MRR por produto</h2><p class="card-subtitle">Produtos que sustentam a receita recorrente atual.</p></div><a href="?page=products">Catálogo →</a></div>
        <div class="executive-rank">
            <?php if (!$intelligence['topProducts']): ?><div class="empty-mini">Nenhuma assinatura ativa para analisar.</div><?php endif; ?>
            <?php foreach ($intelligence['topProducts'] as $index => $productRow): $productWidth = $maxProductMrr > 0 ? ((float) $productRow['mrr'] / $maxProductMrr) * 100 : 0; ?>
                <a href="?page=subscriptions&q=<?= h(urlencode($productRow['name'])) ?>"><span class="rank-number"><?= $index + 1 ?></span><span class="rank-product"><b><?= h($productRow['name']) ?></b><small><?= (int) $productRow['clients'] ?> cliente(s) · <?= (int) $productRow['subscriptions'] ?> assinatura(s)</small><i><em style="width:<?= round($productWidth, 1) ?>%"></em></i></span><strong><?= money($productRow['mrr']) ?><small>/mês</small></strong></a>
            <?php endforeach; ?>
        </div>
    </article>
    <article class="card renewal-pipeline">
        <div class="card-header"><div><p class="eyebrow">PIPELINE DE RENOVAÇÕES</p><h2>Próximos movimentos</h2></div><a href="?page=subscriptions">Abrir radar →</a></div>
        <div class="pipeline-value"><span>Previsão para 30 dias</span><strong><?= money($renewals['next30Value']) ?></strong><small><?= $renewals['next30'] ?> renovação(ões) futuras</small></div>
        <div class="pipeline-stages">
            <a href="?page=subscriptions&due=overdue" class="overdue"><span><i>!</i> Vencidas</span><strong><?= $renewals['overdueCount'] ?></strong><small><?= money($renewals['overdueValue']) ?> em risco</small></a>
            <a href="?page=subscriptions&due=today" class="today"><span><i>•</i> Hoje</span><strong><?= $renewals['dueToday'] ?></strong><small>exigem conferência</small></a>
            <a href="?page=subscriptions&due=next_7" class="future"><span><i>↗</i> Próximos 7 dias</span><strong><?= $renewals['next7'] ?></strong><small>renovações previstas</small></a>
        </div>
    </article>
    <article class="card geography-card">
        <div class="card-header"><div><p class="eyebrow">PRESENÇA INTERNACIONAL</p><h2>Carteira por país</h2></div></div>
        <div class="geography-list">
            <?php if (!$intelligence['countries']): ?><div class="empty-mini">Sem receita recorrente por país.</div><?php endif; ?>
            <?php foreach ($intelligence['countries'] as $countryRow): $countryShare = $countryMrrTotal > 0 ? ((float) $countryRow['mrr'] / $countryMrrTotal) * 100 : 0; ?>
                <div><span class="country-identity"><?= country_flag_icon($countryRow['country']) ?><span><b><?= $countryRow['country'] === 'BR' ? 'Brasil' : 'Estados Unidos' ?></b><small><?= (int) $countryRow['clients'] ?> cliente(s) recorrente(s)</small></span></span><strong><?= money($countryRow['mrr']) ?><small><?= number_format($countryShare, 1, ',', '.') ?>% do MRR</small></strong><i><em style="width:<?= round($countryShare, 1) ?>%"></em></i></div>
            <?php endforeach; ?>
        </div>
        <div class="currency-foot"><span>Base BR</span><b><?= (int) ($clients['brazil'] ?? 0) ?></b><span>Base EUA</span><b><?= (int) ($clients['usa'] ?? 0) ?></b></div>
    </article>
</section>

<section class="dashboard-grid lower-grid">
    <article class="card">
        <div class="card-header"><div><p class="eyebrow">ÚLTIMOS LANÇAMENTOS</p><h2>Pagamentos recentes</h2></div><a href="?page=payments">Ver todos →</a></div>
        <div class="table-wrap"><table><thead><tr><th>Cliente</th><th>Data</th><th>Original</th><th>Convertido</th><th>Status</th></tr></thead><tbody>
        <?php if (!$recent): ?><tr><td colspan="5" class="empty-cell">Nenhum pagamento registrado.</td></tr><?php endif; ?>
        <?php foreach ($recent as $item): ?><tr><td><span class="avatar-sm"><?= h(mb_strtoupper(mb_substr($item['client'], 0, 1))) ?></span> <b><?= h($item['client']) ?></b></td><td><?= date_br($item['currency'] === 'USD' ? ($item['settlement_date'] ?: $item['payment_date']) : $item['payment_date']) ?></td><td><?= money($item['amount'], $item['currency']) ?></td><td><b><?= money($item['amount_brl']) ?></b></td><td><span class="badge <?= status_class($item['status']) ?>"><?= status_label($item['status']) ?></span></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </article>
    <article class="card upcoming-card">
        <div class="card-header"><div><p class="eyebrow">PRÓXIMOS 30 DIAS</p><h2>Cobranças agendadas</h2></div><a href="?page=subscriptions&due=next_7">Ver radar →</a></div>
        <div class="upcoming-list">
            <?php if (!$upcoming): ?><div class="empty-mini">Nenhuma cobrança agendada para os próximos 30 dias.</div><?php endif; ?>
            <?php foreach ($upcoming as $item): $value = max(0, $item['unit_price'] * $item['quantity'] - $item['discount']); ?><a href="?page=subscriptions&edit=<?= (int) $item['id'] ?>"><span class="date-box"><b><?= date('d', strtotime($item['next_billing_date'])) ?></b><small><?= mb_strtoupper(['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'][(int) date('n', strtotime($item['next_billing_date'])) - 1]) ?></small></span><span><b><?= h($item['client']) ?></b><small><?= h($item['product']) ?></small></span><strong><?= money($value, $item['currency']) ?></strong></a><?php endforeach; ?>
        </div>
    </article>
</section>
