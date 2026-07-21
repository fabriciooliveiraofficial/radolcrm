<?php
use App\Services\FinanceService;

[$from, $to, $periodLabel] = period_dates();
$rate = $rates->current();
$finance = new FinanceService($db);
$metrics = $finance->dashboard($from, $to, $rate['bid']);
$series = $finance->monthlySeries();
$balance = $finance->cashBalance();
$upcoming = $db->fetchAll("SELECT s.id,s.next_billing_date,s.currency,s.unit_price,s.quantity,s.discount,c.name client,p.name product FROM subscriptions s JOIN clients c ON c.id=s.client_id JOIN products p ON p.id=s.product_id WHERE s.status IN ('active','trial','past_due') AND s.next_billing_date IS NOT NULL ORDER BY s.next_billing_date LIMIT 6");
$recent = $db->fetchAll("SELECT p.id,p.payment_date,p.settlement_date,p.amount,p.currency,p.amount_brl,p.status,c.name client FROM payments p JOIN clients c ON c.id=p.client_id ORDER BY COALESCE(CASE WHEN p.currency='USD' THEN p.settlement_date ELSE p.payment_date END,p.payment_date,p.due_date) DESC,p.id DESC LIMIT 6");
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

<section class="metric-grid">
    <article class="metric-card"><div class="metric-icon green">↗</div><div><span>Faturamento bruto</span><strong><?= money($metrics['gross']) ?></strong><small><?= (int) $metrics['paymentCount'] ?> pagamentos em <?= h(mb_strtolower($periodLabel)) ?></small></div></article>
    <article class="metric-card"><div class="metric-icon gold">◎</div><div><span>Lucro líquido</span><strong class="<?= $metrics['profit'] < 0 ? 'negative' : '' ?>"><?= money($metrics['profit']) ?></strong><small class="<?= $metrics['margin'] < 0 ? 'negative' : 'positive' ?>"><?= number_format($metrics['margin'], 1, ',', '.') ?>% de margem</small></div></article>
    <article class="metric-card"><div class="metric-icon blue">↻</div><div><span>Receita recorrente (MRR)</span><strong><?= money($metrics['mrr']) ?></strong><small><?= $metrics['activeSubscriptions'] ?> assinaturas ativas</small></div></article>
    <article class="metric-card"><div class="metric-icon purple">♙</div><div><span>Clientes recorrentes</span><strong><?= $metrics['activeClients'] ?></strong><small>com assinatura vigente</small></div></article>
</section>

<section class="dashboard-grid">
    <article class="card chart-card">
        <div class="card-header"><div><p class="eyebrow">DESEMPENHO</p><h2>Receitas x saídas</h2></div><div class="chart-legend"><span><i class="revenue"></i> Entradas</span><span><i class="cost"></i> Saídas</span></div></div>
        <div class="bar-chart" data-chart='<?= h(json_encode($series, JSON_UNESCAPED_UNICODE)) ?>'></div>
    </article>
    <article class="card health-card">
        <div class="card-header"><div><p class="eyebrow">SAÚDE FINANCEIRA</p><h2>Resumo do período</h2></div></div>
        <?php $health = $metrics['gross'] > 0 ? max(0, min(100, 50 + $metrics['margin'])) : 0; ?>
        <div class="health-score"><div class="score-ring" style="--score:<?= (int) $health ?>"><span><?= (int) $health ?></span></div><div><b><?= $health >= 70 ? 'Saudável' : ($health >= 45 ? 'Atenção' : 'Crítico') ?></b><small>Índice baseado na margem</small></div></div>
        <dl class="summary-list"><div><dt>Receita líquida</dt><dd><?= money($metrics['net']) ?></dd></div><div><dt>Despesas operacionais</dt><dd>− <?= money($metrics['expenses']) ?></dd></div><div><dt>Investimentos</dt><dd>− <?= money($metrics['investments']) ?></dd></div><div class="total"><dt>Saldo de caixa atual</dt><dd class="<?= $balance < 0 ? 'negative' : 'positive' ?>"><?= money($balance) ?></dd></div></dl>
    </article>
</section>

<section class="dashboard-grid lower-grid">
    <article class="card">
        <div class="card-header"><div><p class="eyebrow">ÚLTIMOS LANÇAMENTOS</p><h2>Pagamentos recentes</h2></div><a href="?page=payments">Ver todos →</a></div>
        <div class="table-wrap"><table><thead><tr><th>Cliente</th><th>Data</th><th>Original</th><th>Convertido</th><th>Status</th></tr></thead><tbody>
        <?php if (!$recent): ?><tr><td colspan="5" class="empty-cell">Nenhum pagamento registrado.</td></tr><?php endif; ?>
        <?php foreach ($recent as $item): ?><tr><td><span class="avatar-sm"><?= h(mb_strtoupper(mb_substr($item['client'],0,1))) ?></span><b><?= h($item['client']) ?></b></td><td><?= date_br($item['currency']==='USD' ? ($item['settlement_date']?:$item['payment_date']) : $item['payment_date']) ?></td><td><?= money($item['amount'],$item['currency']) ?></td><td><b><?= money($item['amount_brl']) ?></b></td><td><span class="badge <?= status_class($item['status']) ?>"><?= status_label($item['status']) ?></span></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </article>
    <article class="card upcoming-card">
        <div class="card-header"><div><p class="eyebrow">PRÓXIMOS 30 DIAS</p><h2>Cobranças</h2></div><a href="?page=subscriptions">Ver todas →</a></div>
        <div class="upcoming-list">
            <?php if (!$upcoming): ?><div class="empty-mini">Nenhuma cobrança agendada.</div><?php endif; ?>
            <?php foreach ($upcoming as $item): $value=max(0,$item['unit_price']*$item['quantity']-$item['discount']); ?><a href="?page=subscriptions&edit=<?= (int)$item['id'] ?>"><span class="date-box"><b><?= date('d',strtotime($item['next_billing_date'])) ?></b><small><?= mb_strtoupper(['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'][(int)date('n',strtotime($item['next_billing_date']))-1]) ?></small></span><span><b><?= h($item['client']) ?></b><small><?= h($item['product']) ?></small></span><strong><?= money($value,$item['currency']) ?></strong></a><?php endforeach; ?>
        </div>
    </article>
</section>
