<?php

declare(strict_types=1);

use App\Services\DailyFinanceService;

$dailyService = new DailyFinanceService($db);

$currentMonth = (string) ($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $currentMonth)) {
    $currentMonth = date('Y-m');
}

$monthStart = new DateTimeImmutable($currentMonth . '-01');
$from = $monthStart->format('Y-m-01');
$to = $monthStart->format('Y-m-t');
$isCurrentCalendarMonth = $currentMonth === date('Y-m');

$monthNamesFull = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
$monthLabel = $monthNamesFull[(int) $monthStart->format('n') - 1] . ' de ' . $monthStart->format('Y');
$prevMonthKey = $monthStart->modify('-1 month')->format('Y-m');
$nextMonthKey = $monthStart->modify('+1 month')->format('Y-m');

// Saúde financeira do mês selecionado e do mês anterior (para comparação de tendência)
$health = $dailyService->financialHealth($from, $to);
$prevFrom = $monthStart->modify('-1 month')->format('Y-m-01');
$prevTo = $monthStart->modify('-1 month')->format('Y-m-t');
$prevHealth = $dailyService->financialHealth($prevFrom, $prevTo);

$incomeDelta = $health['income'] - $prevHealth['income'];
$expenseDelta = $health['expense'] - $prevHealth['expense'];
$netDelta = $health['net'] - $prevHealth['net'];

// Série de 12 meses (Entradas x Saídas) para o gráfico e para o radar de meses bons/ruins
$series = $dailyService->monthlySeries(12);

// Projeção do próximo mês com base em compromissos recorrentes e lançamentos já agendados
$nextMonthStart = $monthStart->modify('+1 month')->format('Y-m-01');
$nextMonthEnd = $monthStart->modify('+1 month')->format('Y-m-t');
$projection = $dailyService->agenda($nextMonthStart, $nextMonthEnd);

// Para onde vai o dinheiro / de onde vem a renda no mês selecionado
$expenseBreakdown = $dailyService->categoryBreakdown($from, $to, 'expense', 6);
$incomeBreakdown = $dailyService->categoryBreakdown($from, $to, 'income', 6);

$scoreColors = ['good' => '#10b981', 'warning' => '#f59e0b', 'danger' => '#ef4444', 'empty' => '#94a3b8'];
$scoreColor = $scoreColors[$health['status']];

$healthMessage = match ($health['status']) {
    'good' => 'Você guardou ' . number_format($health['savings_rate'], 1, ',', '.') . '% do que ganhou este mês. Mês saudável.',
    'warning' => $health['savings_rate'] >= 0
        ? 'Sobrou pouco: só ' . number_format($health['savings_rate'], 1, ',', '.') . '% do que entrou. Vale olhar onde apertar.'
        : 'Gastos praticamente empataram com a renda. Fique de olho no próximo mês.',
    'danger' => 'Você gastou ' . number_format(abs($health['savings_rate']), 1, ',', '.') . '% a mais do que ganhou. Mês no vermelho.',
    default => 'Nenhum lançamento realizado neste mês ainda.',
};

$projectionTone = $projection['expected_net'] >= 0 ? 'good' : 'danger';
?>

<div class="fh-wrapper">
    <!-- NAVEGADOR DE MÊS -->
    <section class="fh-month-nav">
        <a class="button ghost small" href="?page=saude-financeira&month=<?= h($prevMonthKey) ?>">← Mês anterior</a>
        <div class="fh-month-current">
            <b><?= h($monthLabel) ?></b>
            <?php if ($isCurrentCalendarMonth): ?><span class="badge success">Mês atual</span><?php endif; ?>
        </div>
        <?php if (!$isCurrentCalendarMonth): ?>
            <a class="button ghost small" href="?page=saude-financeira&month=<?= h($nextMonthKey) ?>">Próximo mês →</a>
        <?php else: ?>
            <span class="button ghost small" style="opacity:.35;pointer-events:none;">Próximo mês →</span>
        <?php endif; ?>
    </section>

    <!-- KPIs DO MÊS -->
    <section class="mini-stats daily-kpis">
        <div class="kpi-card">
            <span class="dot green"></span>
            <div class="kpi-info">
                <small>Entradas do mês</small>
                <b class="kpi-val positive">R$ <?= number_format($health['income'], 2, ',', '.') ?></b>
                <small class="fh-delta" style="margin-top:3px;display:block;">
                    <?= $incomeDelta >= 0 ? '▲' : '▼' ?> R$ <?= number_format(abs($incomeDelta), 2, ',', '.') ?> vs. mês anterior
                </small>
            </div>
        </div>

        <div class="kpi-card">
            <span class="dot red"></span>
            <div class="kpi-info">
                <small>Saídas do mês</small>
                <b class="kpi-val negative">R$ <?= number_format($health['expense'], 2, ',', '.') ?></b>
                <small class="fh-delta" style="margin-top:3px;display:block;">
                    <?= $expenseDelta <= 0 ? '▼' : '▲' ?> R$ <?= number_format(abs($expenseDelta), 2, ',', '.') ?> vs. mês anterior
                </small>
            </div>
        </div>

        <div class="kpi-card">
            <span class="dot <?= $health['net'] >= 0 ? 'green' : 'red' ?>"></span>
            <div class="kpi-info">
                <small>Resultado do mês</small>
                <b class="kpi-val <?= $health['net'] >= 0 ? 'positive' : 'negative' ?>">R$ <?= number_format($health['net'], 2, ',', '.') ?></b>
                <small class="fh-delta" style="margin-top:3px;display:block;">
                    <?= $netDelta >= 0 ? '▲' : '▼' ?> R$ <?= number_format(abs($netDelta), 2, ',', '.') ?> vs. mês anterior
                </small>
            </div>
        </div>

        <div class="kpi-card">
            <span class="dot purple"></span>
            <div class="kpi-info">
                <small>Taxa de poupança</small>
                <b class="kpi-val" style="color:<?= h($scoreColor) ?>;"><?= number_format($health['savings_rate'], 1, ',', '.') ?>%</b>
                <small style="margin-top:3px;display:block;color:var(--muted);">do que ganhou, sobrou no mês</small>
            </div>
        </div>
    </section>

    <!-- SAÚDE FINANCEIRA + PROJEÇÃO -->
    <section class="fh-grid-2">
        <article class="card fh-health-card">
            <div class="card-header">
                <div><p class="eyebrow">SAÚDE FINANCEIRA DO MÊS</p><h2><?= h($monthLabel) ?></h2></div>
                <span class="health-state <?= $health['status'] === 'empty' ? 'warning' : $health['status'] ?>"><?= h($health['label']) ?></span>
            </div>
            <div class="health-score" style="border-bottom:none;">
                <div class="score-ring" style="--score:<?= $health['score'] ?>;background:conic-gradient(<?= h($scoreColor) ?> calc(var(--score) * 1%), #e7ebe8 0);">
                    <span><?= $health['score'] ?></span>
                </div>
                <div>
                    <b>Score de saúde financeira</b>
                    <small><?= h($healthMessage) ?></small>
                </div>
            </div>
        </article>

        <article class="card fh-projection-card">
            <div class="card-header">
                <div><p class="eyebrow">PROJEÇÃO</p><h2>Como deve fechar o próximo mês</h2></div>
                <span class="health-state <?= $projectionTone ?>"><?= $projectionTone === 'good' ? 'Tendência positiva' : 'Tendência de rombo' ?></span>
            </div>
            <dl class="summary-list">
                <div><dt>Entradas previstas</dt><dd class="positive">+ R$ <?= number_format($projection['expected_in'], 2, ',', '.') ?></dd></div>
                <div><dt>Saídas e compromissos previstos</dt><dd class="negative">− R$ <?= number_format($projection['expected_out'], 2, ',', '.') ?></dd></div>
                <div class="total"><dt>Resultado projetado</dt><dd class="<?= $projectionTone === 'good' ? 'positive' : 'negative' ?>">R$ <?= number_format($projection['expected_net'], 2, ',', '.') ?></dd></div>
            </dl>
            <p class="fh-projection-note">Baseado nas faturas de cartão, compromissos recorrentes e lançamentos já agendados para <?= h($monthNamesFull[(int) $monthStart->modify('+1 month')->format('n') - 1]) ?>.</p>
        </article>
    </section>

    <!-- GRÁFICO ENTRADAS X SAÍDAS -->
    <section class="card chart-card">
        <div class="card-header">
            <div><p class="eyebrow">EVOLUÇÃO EM 12 MESES</p><h2>Entradas x Saídas</h2></div>
            <div class="chart-legend">
                <span><i class="revenue"></i> Entradas</span>
                <span><i class="cost"></i> Saídas</span>
            </div>
        </div>
        <div class="bar-chart" data-chart='<?= h(json_encode($series, JSON_UNESCAPED_UNICODE)) ?>'></div>

        <div class="fh-month-radar">
            <?php foreach ($series as $item): ?>
                <a class="fh-month-chip <?= $item['year_month'] === $currentMonth ? 'active' : '' ?>" href="?page=saude-financeira&month=<?= h($item['year_month']) ?>">
                    <span class="fh-month-chip-dot <?= $item['net'] >= 0 ? 'green' : 'red' ?>"></span>
                    <span class="fh-month-chip-label"><?= h($item['label']) ?></span>
                    <span class="fh-month-chip-val <?= $item['net'] >= 0 ? 'positive' : 'negative' ?>">
                        <?= $item['net'] >= 0 ? '+' : '−' ?>R$ <?= number_format(abs($item['net']), 0, ',', '.') ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- PARA ONDE VAI O DINHEIRO -->
    <section class="fh-grid-2">
        <article class="card">
            <div class="card-header">
                <div><p class="eyebrow">PARA ONDE FOI SEU DINHEIRO</p><h2>Maiores gastos do mês</h2></div>
                <span class="muted">Total: <b><?= money($expenseBreakdown['total']) ?></b></span>
            </div>
            <div class="rank-bars costs">
                <?php if (!$expenseBreakdown['items']): ?>
                    <div class="empty-mini">Nenhum gasto realizado neste mês.</div>
                <?php endif; ?>
                <?php foreach ($expenseBreakdown['items'] as $cat): ?>
                    <div>
                        <span><b><?= h($cat['icon']) ?> <?= h($cat['name']) ?></b><small><?= number_format($cat['pct'], 1, ',', '.') ?>% dos gastos</small></span>
                        <strong><?= money($cat['total']) ?></strong>
                        <i style="--width:<?= $cat['pct'] ?>%; background:<?= h($cat['color']) ?>;"></i>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="card">
            <div class="card-header">
                <div><p class="eyebrow">DE ONDE VEIO SUA RENDA</p><h2>Principais entradas do mês</h2></div>
                <span class="muted">Total: <b><?= money($incomeBreakdown['total']) ?></b></span>
            </div>
            <div class="rank-bars">
                <?php if (!$incomeBreakdown['items']): ?>
                    <div class="empty-mini">Nenhuma entrada realizada neste mês.</div>
                <?php endif; ?>
                <?php foreach ($incomeBreakdown['items'] as $cat): ?>
                    <div>
                        <span><b><?= h($cat['icon']) ?> <?= h($cat['name']) ?></b><small><?= number_format($cat['pct'], 1, ',', '.') ?>% da renda</small></span>
                        <strong><?= money($cat['total']) ?></strong>
                        <i style="--width:<?= $cat['pct'] ?>%; background:<?= h($cat['color']) ?>;"></i>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    </section>
</div>

<style>
.fh-wrapper { display:flex; flex-direction:column; gap:16px; }
.fh-wrapper .card { padding:20px 22px; }
.fh-month-nav { display:flex; align-items:center; justify-content:space-between; gap:12px; background:var(--card); padding:12px 16px; border-radius:var(--radius); box-shadow:var(--shadow); border:1px solid var(--line); }
.fh-month-current { display:flex; align-items:center; gap:10px; font-size:15px; }

.daily-kpis { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; }
.kpi-card { background:var(--card); border-radius:var(--radius); padding:16px; display:flex; align-items:center; gap:14px; box-shadow:var(--shadow); border:1px solid var(--line); }
.kpi-card .dot { width:12px; height:12px; flex-shrink:0; }
.kpi-info small { display:block; font-size:11px; color:var(--muted); font-weight:600; text-transform:uppercase; letter-spacing:.05em; }
.kpi-val { display:block; font-size:18px; font-weight:800; letter-spacing:-.02em; margin-top:2px; }
.fh-delta { color:var(--muted); font-size:11px; font-weight:600; }

.fh-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.fh-health-card .health-score { padding-top:6px; }
.fh-projection-note { margin:14px 0 0; color:var(--muted); font-size:12px; line-height:1.5; }
.fh-month-radar { display:grid; grid-template-columns:repeat(auto-fill, minmax(84px, 1fr)); gap:8px; padding-top:14px; border-top:1px solid var(--line); margin-top:16px; }
.fh-month-chip { display:flex; flex-direction:column; align-items:center; gap:3px; padding:8px 4px; border-radius:8px; border:1px solid var(--line); text-decoration:none; color:inherit; transition:background .15s; }
.fh-month-chip:hover { background:var(--canvas); }
.fh-month-chip.active { border-color:#10b981; background:#ecfdf5; }
.fh-month-chip-dot { width:8px; height:8px; border-radius:50%; }
.fh-month-chip-dot.green { background:#10b981; }
.fh-month-chip-dot.red { background:#ef4444; }
.fh-month-chip-label { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; }
.fh-month-chip-val { font-size:11px; font-weight:700; }
@media (max-width: 860px) {
    .fh-grid-2 { grid-template-columns:1fr; }
}
</style>
