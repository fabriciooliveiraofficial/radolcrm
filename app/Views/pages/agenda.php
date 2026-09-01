<?php
use App\Services\FinanceService;

$finance = new FinanceService($db);
$rate = $rates->current();

$monthParam = (string) ($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
    $monthParam = date('Y-m');
}

$firstDay = $monthParam . '-01';
$lastDay = date('Y-m-t', strtotime($firstDay));
$prevMonth = date('Y-m', strtotime($firstDay . ' -1 month'));
$nextMonth = date('Y-m', strtotime($firstDay . ' +1 month'));

$buFilter = isset($_GET['bu']) && $_GET['bu'] !== '' ? (int) $_GET['bu'] : null;
$viewMode = (string) ($_GET['view'] ?? 'calendar'); // 'calendar' or 'list'
$typeFilter = (string) ($_GET['type'] ?? ''); // 'in', 'out', 'card'

$allBusinesses = $db->fetchAll('SELECT id, name, icon, color, is_personal FROM business_units WHERE active = 1 ORDER BY sort_order ASC, id ASC');

$agenda = $finance->financialAgenda($firstDay, $lastDay, $buFilter, (float) $rate['bid']);

$events = $agenda['events'];
if ($typeFilter === 'in') {
    $events = array_filter($events, static fn($e) => $e['direction'] === 'in');
} elseif ($typeFilter === 'out') {
    $events = array_filter($events, static fn($e) => $e['direction'] === 'out' && $e['type'] !== 'card_invoice');
} elseif ($typeFilter === 'card') {
    $events = array_filter($events, static fn($e) => $e['type'] === 'card_invoice');
}

$eventsByDate = [];
foreach ($events as $e) {
    $d = $e['date'];
    if (!isset($eventsByDate[$d])) {
        $eventsByDate[$d] = [];
    }
    $eventsByDate[$d][] = $e;
}

// Calendar Grid Calculations
$firstDayTimestamp = strtotime($firstDay);
$daysInMonth = (int) date('t', $firstDayTimestamp);
$startDayOfWeek = (int) date('w', $firstDayTimestamp); // 0 (Sunday) to 6 (Saturday)
$monthNamePt = [
    '01' => 'Janeiro', '02' => 'Fevereiro', '03' => 'Março', '04' => 'Abril',
    '05' => 'Maio', '06' => 'Junho', '07' => 'Julho', '08' => 'Agosto',
    '09' => 'Setembro', '10' => 'Outubro', '11' => 'Novembro', '12' => 'Dezembro'
][date('m', $firstDayTimestamp)] . ' de ' . date('Y', $firstDayTimestamp);

$today = date('Y-m-d');
?>
<section class="mini-stats money-stats">
    <div>
        <span class="dot green"></span>
        <span><small>Entradas previstas</small><b><?= money($agenda['expected_in']) ?></b></span>
    </div>
    <div>
        <span class="dot red"></span>
        <span><small>Saídas previstas</small><b><?= money($agenda['expected_out']) ?></b></span>
    </div>
    <div>
        <span class="dot <?= $agenda['expected_net'] < 0 ? 'red' : 'purple' ?>"></span>
        <span><small>Saldo previsto no mês</small><b class="<?= $agenda['expected_net'] < 0 ? 'negative' : 'positive' ?>"><?= money($agenda['expected_net']) ?></b></span>
    </div>
    <div>
        <span class="dot blue"></span>
        <span><small>Total de compromissos</small><b><?= $agenda['total_count'] ?> itens</b></span>
    </div>
</section>

<!-- Agenda Toolbar -->
<section class="toolbar list-toolbar" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
    <div style="display: flex; gap: 0.5rem; align-items: center;">
        <a class="button ghost" href="?page=agenda&month=<?= $prevMonth ?><?= $buFilter ? '&bu=' . $buFilter : '' ?>&view=<?= $viewMode ?>" title="Mês anterior">◀ Anterior</a>
        <h2 style="margin: 0 0.5rem; font-size: 1.15rem;"><?= h($monthNamePt) ?></h2>
        <a class="button ghost" href="?page=agenda&month=<?= $nextMonth ?><?= $buFilter ? '&bu=' . $buFilter : '' ?>&view=<?= $viewMode ?>" title="Próximo mês">Próximo ▶</a>
        <a class="button ghost small" href="?page=agenda&month=<?= date('Y-m') ?><?= $buFilter ? '&bu=' . $buFilter : '' ?>&view=<?= $viewMode ?>">Hoje</a>
    </div>

    <div style="display: flex; gap: 0.5rem; align-items: center;">
        <form method="get" class="search-filters" style="margin: 0;">
            <input type="hidden" name="page" value="agenda">
            <input type="hidden" name="month" value="<?= h($monthParam) ?>">
            <input type="hidden" name="view" value="<?= h($viewMode) ?>">

            <select name="bu" onchange="this.form.submit()">
                <option value="">Todos os negócios</option>
                <?php foreach ($allBusinesses as $bu): ?>
                    <option value="<?= (int) $bu['id'] ?>" <?= $buFilter === (int) $bu['id'] ? 'selected' : '' ?>>
                        <?= h($bu['icon']) ?> <?= h($bu['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="type" onchange="this.form.submit()">
                <option value="">Todos os tipos</option>
                <option value="in" <?= $typeFilter === 'in' ? 'selected' : '' ?>>Entradas / Recebimentos</option>
                <option value="out" <?= $typeFilter === 'out' ? 'selected' : '' ?>>Saídas / Parcelas</option>
                <option value="card" <?= $typeFilter === 'card' ? 'selected' : '' ?>>Faturas de Cartão</option>
            </select>
        </form>

        <div style="display: flex; background: rgba(255,255,255,0.06); border-radius: 6px; padding: 2px;">
            <a class="button small <?= $viewMode === 'calendar' ? 'primary' : 'ghost' ?>" style="border:none;" href="?page=agenda&month=<?= h($monthParam) ?>&view=calendar<?= $buFilter ? '&bu=' . $buFilter : '' ?>">📅 Calendário</a>
            <a class="button small <?= $viewMode === 'list' ? 'primary' : 'ghost' ?>" style="border:none;" href="?page=agenda&month=<?= h($monthParam) ?>&view=list<?= $buFilter ? '&bu=' . $buFilter : '' ?>">📋 Linha do Tempo</a>
        </div>
    </div>
</section>

<?php if ($viewMode === 'calendar'): ?>
<!-- Calendar Grid View -->
<div class="card" style="padding: 1rem; overflow-x: auto;">
    <div style="display: grid; grid-template-columns: repeat(7, minmax(130px, 1fr)); gap: 6px; min-width: 900px;">
        <!-- Day Headers -->
        <?php foreach (['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'] as $wDay): ?>
            <div style="text-align: center; font-weight: 700; font-size: 0.8rem; padding: 0.5rem; color: var(--text-muted, #8b9bb4); text-transform: uppercase;">
                <?= $wDay ?>
            </div>
        <?php endforeach; ?>

        <!-- Empty Leading Cells -->
        <?php for ($i = 0; $i < $startDayOfWeek; $i++): ?>
            <div style="min-height: 105px; background: rgba(255,255,255,0.01); border-radius: 6px; border: 1px dashed rgba(255,255,255,0.04);"></div>
        <?php endfor; ?>

        <!-- Month Days -->
        <?php for ($day = 1; $day <= $daysInMonth; $day++):
            $dateStr = sprintf('%s-%02d', $monthParam, $day);
            $isToday = $dateStr === $today;
            $dayEvents = $eventsByDate[$dateStr] ?? [];
            $dayIn = array_sum(array_map(static fn($e) => $e['direction'] === 'in' ? $e['amount_brl'] : 0, $dayEvents));
            $dayOut = array_sum(array_map(static fn($e) => $e['direction'] === 'out' ? $e['amount_brl'] : 0, $dayEvents));
        ?>
            <div style="min-height: 110px; background: <?= $isToday ? 'rgba(43, 130, 107, 0.12)' : 'rgba(255,255,255,0.02)' ?>; border: 1px solid <?= $isToday ? 'var(--primary, #2b826b)' : 'rgba(255,255,255,0.06)' ?>; border-radius: 6px; padding: 0.4rem; display: flex; flex-direction: column; gap: 0.25rem;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-weight: 700; font-size: 0.85rem; <?= $isToday ? 'background: var(--primary, #2b826b); color: #fff; padding: 1px 6px; border-radius: 4px;' : 'color: var(--text, #fff);' ?>">
                        <?= $day ?>
                    </span>
                    <?php if ($dayIn > 0 || $dayOut > 0): ?>
                        <small style="font-size: 0.7rem; font-weight: 600;">
                            <?php if ($dayIn > 0): ?><span class="positive">+<?= number_format($dayIn, 0, ',', '.') ?></span><?php endif; ?>
                            <?php if ($dayOut > 0): ?><span class="negative" style="margin-left: 2px;">-<?= number_format($dayOut, 0, ',', '.') ?></span><?php endif; ?>
                        </small>
                    <?php endif; ?>
                </div>

                <!-- Event Pills inside Cell -->
                <div style="display: flex; flex-direction: column; gap: 3px; overflow-y: auto; max-height: 90px;">
                    <?php foreach ($dayEvents as $ev):
                        $bg = $ev['direction'] === 'in' ? 'rgba(16, 185, 129, 0.15)' : ($ev['type'] === 'card_invoice' ? 'rgba(139, 92, 246, 0.15)' : 'rgba(239, 68, 68, 0.15)');
                        $color = $ev['direction'] === 'in' ? '#10b981' : ($ev['type'] === 'card_invoice' ? '#a78bfa' : '#ef4444');
                        $border = $ev['direction'] === 'in' ? '#10b98144' : ($ev['type'] === 'card_invoice' ? '#a78bfa44' : '#ef444444');
                    ?>
                        <a href="<?= h($ev['url']) ?>" style="background: <?= $bg ?>; color: <?= $color ?>; border: 1px solid <?= $border ?>; text-decoration: none; border-radius: 4px; padding: 2px 4px; font-size: 0.72rem; display: flex; justify-content: space-between; align-items: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= h($ev['title']) ?> (<?= money($ev['amount_brl']) ?>)">
                            <span style="overflow: hidden; text-overflow: ellipsis; max-width: 70px;">
                                <?= $ev['bu_icon'] ?> <?= h($ev['title']) ?>
                            </span>
                            <b style="margin-left: 4px;"><?= money($ev['amount_brl']) ?></b>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endfor; ?>
    </div>
</div>

<?php else: ?>
<!-- Timeline / List View -->
<div class="card table-card">
    <div class="card-header padded">
        <div>
            <p class="eyebrow">CRONOGRAMA DE COMPROMISSOS</p>
            <h2>Obrigações e recebimentos de <?= h($monthNamePt) ?></h2>
        </div>
        <span class="muted"><?= count($events) ?> lançamentos no período</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Compromisso / Descrição</th>
                    <th>Tipo</th>
                    <th>Negócio</th>
                    <th>Valor</th>
                    <th>Ação</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$events): ?>
                    <tr><td colspan="6" class="empty-cell">Nenhum compromisso financeiro previsto para este mês.</td></tr>
                <?php endif; ?>
                <?php foreach ($events as $ev):
                    $isPast = $ev['date'] < $today;
                ?>
                    <tr>
                        <td>
                            <span class="<?= $isPast ? 'negative font-bold' : ($ev['date'] === $today ? 'positive font-bold' : '') ?>">
                                <?= date_br($ev['date']) ?>
                                <?= $ev['date'] === $today ? ' (Hoje)' : ($isPast ? ' (Atrasado)' : '') ?>
                            </span>
                        </td>
                        <td>
                            <div class="entity">
                                <span class="flow-icon <?= $ev['direction'] === 'in' ? 'in' : 'out' ?>">
                                    <?= $ev['direction'] === 'in' ? '↓' : ($ev['type'] === 'card_invoice' ? '💳' : '↑') ?>
                                </span>
                                <span>
                                    <b><?= h($ev['title']) ?></b>
                                    <small><?= h($ev['subtitle']) ?></small>
                                </span>
                            </div>
                        </td>
                        <td>
                            <span class="badge <?= $ev['direction'] === 'in' ? 'success' : ($ev['type'] === 'card_invoice' ? 'info' : 'muted') ?>">
                                <?= ['subscription' => 'Assinatura', 'payment' => 'Recebimento', 'expense' => 'Despesa', 'installment' => 'Parcela / Financiamento', 'card_invoice' => 'Fatura de Cartão'][$ev['type']] ?? 'Financeiro' ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge" style="background: <?= h($ev['bu_color']) ?>15; color: <?= h($ev['bu_color']) ?>; border: 1px solid <?= h($ev['bu_color']) ?>44;">
                                <?= h($ev['bu_icon']) ?> <?= h($ev['bu_name']) ?>
                            </span>
                        </td>
                        <td>
                            <b class="<?= $ev['direction'] === 'in' ? 'positive' : 'negative' ?>">
                                <?= $ev['direction'] === 'in' ? '+ ' : '− ' ?><?= money($ev['amount_brl']) ?>
                            </b>
                        </td>
                        <td>
                            <a class="button small ghost" href="<?= h($ev['url']) ?>">Abrir ➔</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
