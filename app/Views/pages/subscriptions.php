<?php
$buFilter = $buFilter ?? (isset($_GET['bu']) && $_GET['bu'] !== '' ? (int) $_GET['bu'] : null);
$search = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$dueFilter = (string) ($_GET['due'] ?? '');
$badgeFilter = max(0, (int) ($_GET['badge'] ?? 0));
$pageSizeOptions = [20,50,100,200];
$perPage = in_array((int) ($_GET['per_page'] ?? 20), $pageSizeOptions, true) ? (int) ($_GET['per_page'] ?? 20) : 20;
$sortOptions = [
    'client_product' => 'c.name',
    'recurring_value' => '((s.unit_price*s.quantity)-s.discount)',
    'cycle' => "CASE p.billing_cycle WHEN 'annual' THEN 'Anual' WHEN 'monthly' THEN 'Mensal' WHEN 'semiannual' THEN 'Semestral' WHEN 'quarterly' THEN 'Trimestral' ELSE p.billing_cycle END",
    'next_billing' => 's.next_billing_date',
    'status' => "CASE s.status WHEN 'active' THEN 'Ativa' WHEN 'canceled' THEN 'Cancelada' WHEN 'past_due' THEN 'Em atraso' WHEN 'trial' THEN 'Em teste' WHEN 'paused' THEN 'Pausada' ELSE s.status END",
];
$sort = isset($sortOptions[(string) ($_GET['sort'] ?? '')]) ? (string) $_GET['sort'] : 'next_billing';
$sortDirection = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$sqlDirection = strtoupper($sortDirection);
$orderBy = match ($sort) {
    'client_product' => "c.name {$sqlDirection},p.name {$sqlDirection},s.id ASC",
    'next_billing' => "s.next_billing_date IS NULL ASC,s.next_billing_date {$sqlDirection},FIELD(s.status,'past_due','trial','active','paused','canceled'),c.name ASC,s.id ASC",
    default => $sortOptions[$sort] . " {$sqlDirection},c.name ASC,s.id ASC",
};
$tableSortHeader = static function (string $key, string $label) use ($sort, $sortDirection): string {
    $query = $_GET;
    unset($query['p'], $query['edit'], $query['new'], $query['renewal'], $query['renewals'], $query['badges']);
    $active = $sort === $key;
    $nextDirection = $active && $sortDirection === 'asc' ? 'desc' : 'asc';
    $query['sort'] = $key;
    $query['dir'] = $nextDirection;
    $ariaSort = $active ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none';
    $indicator = $active ? ($sortDirection === 'asc' ? '↑' : '↓') : '↕';
    $nextLabel = $nextDirection === 'asc' ? 'crescente' : 'decrescente';

    return '<th class="sortable-column' . ($active ? ' is-sorted' : '') . '" aria-sort="' . $ariaSort . '"><a class="table-sort-link" href="?'
        . h(http_build_query($query)) . '" title="Ordenar por ' . h($label) . '" aria-label="Ordenar por ' . h($label) . ', ordem ' . $nextLabel . '"><span>'
        . h($label) . '</span><span class="table-sort-indicator" aria-hidden="true">' . $indicator . '</span></a></th>';
};
$where = ' WHERE 1=1';
$params = [];
if ($buFilter !== null) {
    $where .= ' AND c.business_unit_id=?';
    $params[] = $buFilter;
}
if ($search !== '') {
    $where .= " AND CONCAT_WS(' ',s.id,c.name,c.company,c.email,c.country,CASE c.country WHEN 'BR' THEN 'Brasil' WHEN 'US' THEN 'Estados Unidos' END,p.name,p.sku,p.billing_cycle,CASE p.billing_cycle WHEN 'monthly' THEN 'Mensal' WHEN 'quarterly' THEN 'Trimestral' WHEN 'semiannual' THEN 'Semestral' WHEN 'annual' THEN 'Anual' END,s.quantity,s.currency,s.unit_price,REPLACE(s.unit_price,'.',','),s.discount,REPLACE(s.discount,'.',','),s.status,CASE s.status WHEN 'active' THEN 'Ativa Ativo' WHEN 'trial' THEN 'Teste' WHEN 'past_due' THEN 'Em atraso Atrasada' WHEN 'paused' THEN 'Pausada' WHEN 'canceled' THEN 'Cancelada' END,s.start_date,DATE_FORMAT(s.start_date,'%d/%m/%Y'),s.next_billing_date,DATE_FORMAT(s.next_billing_date,'%d/%m/%Y'),DATEDIFF(s.next_billing_date,CURDATE()),CASE WHEN s.next_billing_date<CURDATE() THEN 'Vencida atrasada' WHEN s.next_billing_date=CURDATE() THEN 'Vence hoje' WHEN s.next_billing_date=DATE_ADD(CURDATE(),INTERVAL 1 DAY) THEN 'Vence amanhã' WHEN s.next_billing_date=DATE_ADD(CURDATE(),INTERVAL 2 DAY) THEN 'Vence em 2 dias' WHEN s.next_billing_date<=DATE_ADD(CURDATE(),INTERVAL 7 DAY) THEN 'Próximos 7 dias' END,s.payment_method,s.payment_link,s.notes) LIKE ?";
    $params[] = '%' . $search . '%';
        $where = ' WHERE 1=1' . ($buFilter !== null ? ' AND c.business_unit_id=' . (int)$buFilter : '') . ' AND (' . substr($where, strlen(' WHERE 1=1' . ($buFilter !== null ? ' AND c.business_unit_id=' . (int)$buFilter : '') . ' AND '))
        . " OR EXISTS (
                SELECT 1 FROM subscription_service_badges search_ssb
                JOIN service_badges search_badge ON search_badge.id=search_ssb.badge_id
                WHERE search_ssb.subscription_id=s.id AND search_badge.name LIKE ?
            ))";
    $params[] = '%' . $search . '%';
}
if (in_array($status, ['trial', 'active', 'past_due', 'paused', 'canceled'], true)) {
    $where .= ' AND s.status=?';
    $params[] = $status;
}
if (in_array($dueFilter, ['overdue', 'today', 'tomorrow', 'two_days', 'next_7'], true)) {
    $where .= " AND s.status IN ('active','trial','past_due')" . match ($dueFilter) {
        'overdue' => ' AND s.next_billing_date<CURDATE()',
        'today' => ' AND s.next_billing_date=CURDATE()',
        'tomorrow' => ' AND s.next_billing_date=DATE_ADD(CURDATE(),INTERVAL 1 DAY)',
        'two_days' => ' AND s.next_billing_date=DATE_ADD(CURDATE(),INTERVAL 2 DAY)',
        'next_7' => ' AND s.next_billing_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)',
    };
}
if ($badgeFilter > 0) {
    $where .= ' AND EXISTS (
        SELECT 1 FROM subscription_service_badges filter_ssb
        WHERE filter_ssb.subscription_id=s.id AND filter_ssb.badge_id=?
    )';
    $params[] = $badgeFilter;
}
$countSql = 'SELECT COUNT(*) FROM subscriptions s JOIN clients c ON c.id=s.client_id JOIN products p ON p.id=s.product_id' . $where;
$dataSql = "SELECT s.*,c.name client,c.country,p.name product,p.billing_cycle,((s.unit_price*s.quantity)-s.discount) recurring_value,DATEDIFF(s.next_billing_date,CURDATE()) due_in_days FROM subscriptions s JOIN clients c ON c.id=s.client_id JOIN products p ON p.id=s.product_id{$where} ORDER BY {$orderBy}";
$pagination = pagination($db, $countSql, $dataSql, $params, $perPage);
$displayedFrom = $pagination['total'] > 0 ? (($pagination['page'] - 1) * $perPage) + 1 : 0;
$displayedTo = $pagination['total'] > 0 ? $displayedFrom + count($pagination['rows']) - 1 : 0;

$edit = isset($_GET['edit']) ? $db->fetch('SELECT * FROM subscriptions WHERE id=?', [(int) $_GET['edit']]) : null;
$showForm = isset($_GET['new']) || $edit;
$serviceBadgeCatalog = $db->fetchAll('SELECT * FROM service_badges ORDER BY active DESC,name');
$badgeAssignmentId = max(0, (int) ($_GET['badges'] ?? 0));
$badgeAssignmentSubscription = null;
$selectedBadgeAssignmentIds = [];
if ($badgeAssignmentId > 0 && $auth->canWrite()) {
    $badgeAssignmentSubscription = $db->fetch(
        'SELECT s.id,c.name client,p.name product
         FROM subscriptions s
         JOIN clients c ON c.id=s.client_id
         JOIN products p ON p.id=s.product_id
         WHERE s.id=?',
        [$badgeAssignmentId]
    );
    if ($badgeAssignmentSubscription) {
        $selectedBadgeAssignmentIds = array_map(
            'intval',
            array_column(
                $db->fetchAll(
                    'SELECT badge_id FROM subscription_service_badges WHERE subscription_id=?',
                    [$badgeAssignmentId]
                ),
                'badge_id'
            )
        );
    }
}
$badgeAssignmentOptions = array_values(array_filter(
    $serviceBadgeCatalog,
    static fn(array $badge): bool => (bool) $badge['active'] || in_array((int) $badge['id'], $selectedBadgeAssignmentIds, true)
));
$selectedServiceBadgeIds = $edit
    ? array_map(
        'intval',
        array_column(
            $db->fetchAll('SELECT badge_id FROM subscription_service_badges WHERE subscription_id=?', [(int) $edit['id']]),
            'badge_id'
        )
    )
    : [];
$assignableServiceBadges = array_values(array_filter(
    $serviceBadgeCatalog,
    static fn(array $badge): bool => (bool) $badge['active'] || in_array((int) $badge['id'], $selectedServiceBadgeIds, true)
));
$serviceBadgesBySubscription = [];
$visibleSubscriptionIds = array_map('intval', array_column($pagination['rows'], 'id'));
if ($visibleSubscriptionIds) {
    $placeholders = implode(',', array_fill(0, count($visibleSubscriptionIds), '?'));
    $assignedBadges = $db->fetchAll(
        "SELECT ssb.subscription_id,b.id,b.name,b.icon,b.tone
         FROM subscription_service_badges ssb
         JOIN service_badges b ON b.id=ssb.badge_id
         WHERE ssb.subscription_id IN ({$placeholders})
         ORDER BY b.name",
        $visibleSubscriptionIds
    );
    foreach ($assignedBadges as $assignedBadge) {
        $serviceBadgesBySubscription[(int) $assignedBadge['subscription_id']][] = $assignedBadge;
    }
}
$individualRenewalId = max(0, (int) ($_GET['renewal'] ?? 0));
$isIndividualRenewal = $individualRenewalId > 0;
$showRenewals = isset($_GET['renewals']) || $isIndividualRenewal;
$productRate = null;
if ($showForm || $showRenewals) {
    $productRate = (float) $rates->current()['bid'];
}
$clientsQuery = "SELECT id,name,country,preferred_currency FROM clients WHERE (status!='inactive' OR id=?)";
$clientsParams = [(int) ($edit['client_id'] ?? 0)];
if ($buFilter !== null) {
    $clientsQuery .= " AND business_unit_id=?";
    $clientsParams[] = $buFilter;
}
$clientsQuery .= " ORDER BY name";
$clients = $showForm ? $db->fetchAll($clientsQuery, $clientsParams) : [];

$productsQuery = "SELECT * FROM products WHERE (active=1 OR id=?)";
$productsParams = [(int) ($edit['product_id'] ?? 0)];
if ($buFilter !== null) {
    $productsQuery .= " AND business_unit_id=?";
    $productsParams[] = $buFilter;
}
$productsQuery .= " ORDER BY name";
$products = $showForm ? $db->fetchAll($productsQuery, $productsParams) : [];
foreach ($products as $key => $product) {
    $products[$key] = product_with_current_prices($product, $productRate ?: 1.0);
}

$statusWhere = "WHERE 1=1";
$statusParams = [];
if ($buFilter !== null) {
    $statusWhere .= " AND c.business_unit_id=?";
    $statusParams[] = $buFilter;
}
$activeCount = (int) $db->value("SELECT COUNT(*) FROM subscriptions s JOIN clients c ON c.id=s.client_id {$statusWhere} AND s.status='active'", $statusParams);
$trialCount = (int) $db->value("SELECT COUNT(*) FROM subscriptions s JOIN clients c ON c.id=s.client_id {$statusWhere} AND s.status='trial'", $statusParams);
$overdueCount = (int) $db->value("SELECT COUNT(*) FROM subscriptions s JOIN clients c ON c.id=s.client_id {$statusWhere} AND s.status='past_due'", $statusParams);

$prodWhere = "WHERE 1=1";
$prodParams = [];
if ($buFilter !== null) {
    $prodWhere .= " AND p.business_unit_id=?";
    $prodParams = [$buFilter, $buFilter];
} else {
    $prodWhere .= " AND (p.active=1 OR s.id IS NOT NULL)";
}

$productUnitSummary = $db->fetchAll(
    "SELECT p.id,p.name,p.active,COUNT(s.id) active_subscriptions,
            COALESCE(SUM(
                CASE
                    WHEN LOWER(TRIM(p.name)) REGEXP '^[0-9]+[[:space:]]*pontos?'
                        THEN CAST(TRIM(p.name) AS UNSIGNED) * s.quantity
                    ELSE s.quantity
                END
            ),0) active_units
     FROM products p
     LEFT JOIN subscriptions s ON s.product_id=p.id AND s.status='active'
     " . ($buFilter !== null ? "LEFT JOIN clients c ON c.id=s.client_id AND c.business_unit_id=?" : "") . "
     {$prodWhere}
     GROUP BY p.id,p.name,p.active
     ORDER BY p.active DESC,active_units DESC,p.name",
    $prodParams
);
$totalProductUnits = array_sum(array_map(static fn(array $item): int => (int) $item['active_units'], $productUnitSummary));
$totalProductSubscriptions = array_sum(array_map(static fn(array $item): int => (int) $item['active_subscriptions'], $productUnitSummary));
$cutoff = (new DateTimeImmutable('today'))->modify('+45 days')->format('Y-m-d');

$dueCountSql = "SELECT COUNT(*) FROM subscriptions s JOIN clients c ON c.id=s.client_id
     WHERE s.status IN ('active','trial','past_due')
       AND (EXISTS (SELECT 1 FROM payments pending WHERE pending.subscription_id=s.id AND pending.status='pending')
            OR (s.next_billing_date IS NOT NULL AND s.next_billing_date<=?
                AND NOT EXISTS (SELECT 1 FROM payments paid WHERE paid.subscription_id=s.id AND paid.due_date=s.next_billing_date AND paid.status='paid')))";
$dueCountParams = [$cutoff];
if ($buFilter !== null) {
    $dueCountSql .= " AND c.business_unit_id=?";
    $dueCountParams[] = $buFilter;
}
$dueCount = (int) $db->value($dueCountSql, $dueCountParams);

$dueStatsSql = "SELECT
        COALESCE(SUM(s.next_billing_date<CURDATE()),0) overdue,
        COALESCE(SUM(s.next_billing_date=CURDATE()),0) today_count,
        COALESCE(SUM(s.next_billing_date=DATE_ADD(CURDATE(),INTERVAL 1 DAY)),0) tomorrow_count,
        COALESCE(SUM(s.next_billing_date=DATE_ADD(CURDATE(),INTERVAL 2 DAY)),0) two_days_count,
        COALESCE(SUM(s.next_billing_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)),0) next_7_count
     FROM subscriptions s JOIN clients c ON c.id=s.client_id WHERE s.status IN ('active','trial','past_due')";
$dueStatsParams = [];
if ($buFilter !== null) {
    $dueStatsSql .= " AND c.business_unit_id=?";
    $dueStatsParams[] = $buFilter;
}
$dueStats = $db->fetch($dueStatsSql, $dueStatsParams) ?: ['overdue'=>0,'today_count'=>0,'tomorrow_count'=>0,'two_days_count'=>0,'next_7_count'=>0];

$tomorrowSql = "SELECT s.id,s.currency,s.unit_price,s.quantity,s.discount,c.name client,p.name product
     FROM subscriptions s JOIN clients c ON c.id=s.client_id JOIN products p ON p.id=s.product_id
     WHERE s.status IN ('active','trial','past_due') AND s.next_billing_date=DATE_ADD(CURDATE(),INTERVAL 1 DAY)";
$tomorrowParams = [];
if ($buFilter !== null) {
    $tomorrowSql .= " AND c.business_unit_id=?";
    $tomorrowParams[] = $buFilter;
}
$tomorrowSql .= " ORDER BY c.name LIMIT 20";
$tomorrowSubscriptions = $db->fetchAll($tomorrowSql, $tomorrowParams);

$renewalRows = [];
$renewalProducts = [];
if ($showRenewals) {
    $renewalWhere = $isIndividualRenewal
        ? "s.id=?"
        : "s.status IN ('active','trial','past_due')
           AND (pending.id IS NOT NULL OR (s.next_billing_date IS NOT NULL AND s.next_billing_date<=?
                AND NOT EXISTS (SELECT 1 FROM payments paid WHERE paid.subscription_id=s.id AND paid.due_date=s.next_billing_date AND paid.status='paid')))";
    $renewalParams = [$isIndividualRenewal ? $individualRenewalId : $cutoff];
    if (!$isIndividualRenewal && $buFilter !== null) {
        $renewalWhere .= " AND c.business_unit_id=?";
        $renewalParams[] = $buFilter;
    }
    $renewalRows = $db->fetchAll(
        "SELECT s.*,c.name client,c.country,p.name product,p.billing_cycle,
                p.price_brl product_price_brl,p.price_usd product_price_usd,p.pricing_mode product_pricing_mode,
                pending.id pending_payment_id,pending.due_date pending_due_date,
                pending.amount pending_amount,pending.fee_amount pending_fee_amount,
                pending.base_amount pending_base_amount,pending.discount_amount pending_discount_amount,
                pending.surcharge_amount pending_surcharge_amount,pending.manual_adjustment_amount pending_manual_adjustment_amount,
                pending.renewal_mode pending_renewal_mode,pending.renewal_months pending_renewal_months,
                pending.renewal_days pending_renewal_days,pending.renewal_end_date pending_renewal_end_date,
                pending.payment_method pending_payment_method,pending.external_reference pending_external_reference,
                pending.notes pending_notes
         FROM subscriptions s
         JOIN clients c ON c.id=s.client_id
         JOIN products p ON p.id=s.product_id
         LEFT JOIN payments pending ON pending.id=(
             SELECT MIN(p2.id) FROM payments p2 WHERE p2.subscription_id=s.id AND p2.status='pending'
         )
         WHERE {$renewalWhere}
         ORDER BY COALESCE(pending.due_date,s.next_billing_date),c.name
         LIMIT " . ($isIndividualRenewal ? '1' : '100'),
        $renewalParams
    );
    $renProdQuery = "SELECT * FROM products WHERE (active=1 OR id IN (SELECT product_id FROM subscriptions s JOIN clients c ON c.id=s.client_id WHERE s.status='active'))";
    $renProdParams = [];
    if ($buFilter !== null) {
        $renProdQuery .= " AND business_unit_id=?";
        $renProdParams[] = $buFilter;
    }
    $renProdQuery .= " ORDER BY active DESC,name";
    $renewalProducts = $db->fetchAll($renProdQuery, $renProdParams);
    foreach ($renewalProducts as $key => $product) {
        $renewalProducts[$key] = product_with_current_prices($product, $productRate ?: 1.0);
    }
    foreach ($renewalRows as $key => $row) {
        $pricedProduct = product_with_current_prices([
            'pricing_mode' => $row['product_pricing_mode'] ?? 'manual',
            'price_brl' => $row['product_price_brl'] ?? 0,
            'price_usd' => $row['product_price_usd'] ?? 0,
        ], $productRate ?: 1.0);
        $renewalRows[$key]['renewal_unit_price'] = ($pricedProduct['pricing_mode'] ?? 'manual') === 'manual'
            ? (float) $row['unit_price']
            : (float) $pricedProduct[$row['currency'] === 'USD' ? 'price_usd' : 'price_brl'];
    }
}

$historyId = max(0, (int) ($_GET['history'] ?? 0));
$historySubscription = null;
$historyEvents = [];
$historyPayments = [];
if ($historyId > 0) {
    $historySubscription = $db->fetch('SELECT s.id,c.name client,p.name product FROM subscriptions s JOIN clients c ON c.id=s.client_id JOIN products p ON p.id=s.product_id WHERE s.id=?', [$historyId]);
    if ($historySubscription) {
        $historyEvents = $db->fetchAll(
            'SELECT e.*,u.name user_name,p.amount,p.currency,p.due_date,p.payment_date,p.renewal_months,p.renewal_days,p.renewal_end_date FROM subscription_events e LEFT JOIN users u ON u.id=e.user_id LEFT JOIN payments p ON p.id=e.payment_id WHERE e.subscription_id=? ORDER BY e.created_at DESC,e.id DESC LIMIT 100',
            [$historyId]
        );
        $historyPayments = $db->fetchAll('SELECT id,description,amount,currency,status,due_date,payment_date,settlement_date,renewal_months,renewal_days,renewal_end_date,base_amount,discount_amount,surcharge_amount,manual_adjustment_amount FROM payments WHERE subscription_id=? ORDER BY COALESCE(payment_date,due_date) DESC,id DESC LIMIT 30', [$historyId]);
    }
}
?>

<section class="mini-stats"><div><span class="dot green"></span><b><?= $activeCount ?></b><small>Ativas</small></div><div><span class="dot gold"></span><b><?= $trialCount ?></b><small>Em teste</small></div><div><span class="dot red"></span><b><?= $overdueCount ?></b><small>Em atraso</small></div></section>

<section class="subscription-unit-overview">
    <header><div><p class="eyebrow">CAPACIDADE ATIVA</p><h2>Unidades por produto</h2><p>A soma considera a quantidade real de pontos, multipontos e aplicativos de cada assinatura ativa.</p></div></header>
    <div class="subscription-unit-grid">
        <article class="subscription-unit-card total" data-total-units="<?= $totalProductUnits ?>">
            <span class="subscription-unit-icon">Σ</span>
            <div><small>TOTAL GERAL</small><strong><?= $totalProductUnits ?></strong><b>unidades ativas</b><p><?= $totalProductSubscriptions ?> assinatura(s) ativa(s)</p></div>
        </article>
        <?php foreach ($productUnitSummary as $productSummary): $productSymbol=mb_strtoupper(mb_substr(ltrim((string) $productSummary['name'], '+'),0,1)); ?>
            <article class="subscription-unit-card <?= !$productSummary['active'] ? 'inactive' : '' ?>" data-product-id="<?= (int) $productSummary['id'] ?>" data-product-units="<?= (int) $productSummary['active_units'] ?>">
                <span class="subscription-unit-icon"><?= h($productSymbol) ?></span>
                <div><small>PRODUTO</small><strong><?= (int) $productSummary['active_units'] ?></strong><b><?= h($productSummary['name']) ?></b><p><?= (int) $productSummary['active_subscriptions'] ?> assinatura(s) · unidades ativas</p></div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="subscription-radar card"><header><div><p class="eyebrow">RADAR DE RENOVAÇÕES</p><h2>Agenda inteligente de vencimentos</h2><p>Antecipe cobranças críticas e priorize o que precisa de atenção agora.</p></div><?php if ((int) $dueStats['tomorrow_count'] > 0): ?><button type="button" class="radar-notification" data-due-alert-open><span>♢</span><b><?= (int) $dueStats['tomorrow_count'] ?></b> alerta(s) para amanhã</button><?php endif; ?></header><div class="radar-grid"><a href="?page=subscriptions&due=overdue<?= $buFilter ? '&bu=' . (int)$buFilter : '' ?>" class="radar-item overdue <?= $dueFilter === 'overdue' ? 'active' : '' ?>" data-radar-filter="overdue"><span>!</span><div><small>ATRASADAS</small><b><?= (int) $dueStats['overdue'] ?></b><p>Exigem ação imediata</p></div></a><a href="?page=subscriptions&due=today<?= $buFilter ? '&bu=' . (int)$buFilter : '' ?>" class="radar-item today <?= $dueFilter === 'today' ? 'active' : '' ?>" data-radar-filter="today"><span>●</span><div><small>VENCEM HOJE</small><b><?= (int) $dueStats['today_count'] ?></b><p>Confirmar recebimentos</p></div></a><a href="?page=subscriptions&due=tomorrow<?= $buFilter ? '&bu=' . (int)$buFilter : '' ?>" class="radar-item tomorrow <?= $dueFilter === 'tomorrow' ? 'active' : '' ?>" data-radar-filter="tomorrow"><span>→</span><div><small>VENCEM AMANHÃ</small><b><?= (int) $dueStats['tomorrow_count'] ?></b><p>Preparar cobranças</p></div></a><a href="?page=subscriptions&due=two_days<?= $buFilter ? '&bu=' . (int)$buFilter : '' ?>" class="radar-item two-days <?= $dueFilter === 'two_days' ? 'active' : '' ?>" data-radar-filter="two_days"><span>2</span><div><small>EM 2 DIAS</small><b><?= (int) $dueStats['two_days_count'] ?></b><p>Próxima janela</p></div></a><a href="?page=subscriptions&due=next_7<?= $buFilter ? '&bu=' . (int)$buFilter : '' ?>" class="radar-item week <?= $dueFilter === 'next_7' ? 'active' : '' ?>" data-radar-filter="next_7"><span>7</span><div><small>PRÓXIMOS 7 DIAS</small><b><?= (int) $dueStats['next_7_count'] ?></b><p>Visão semanal</p></div></a></div></section>

<section class="toolbar list-toolbar">
    <form class="search-filters" method="get" data-live-filter id="subscription-filters">
        <input type="hidden" name="page" value="subscriptions">
        <?php if ($buFilter !== null): ?><input type="hidden" name="bu" value="<?= (int)$buFilter ?>"><?php endif; ?>
        <input type="hidden" name="per_page" value="<?= $perPage ?>">
        <input type="hidden" name="sort" value="<?= h($sort) ?>">
        <input type="hidden" name="dir" value="<?= h($sortDirection) ?>">
        <label class="search-box">⌕<input name="q" autocomplete="off" placeholder="Cliente, produto, badge, valor, data…" value="<?= h($search) ?>"></label>
        <select name="status"><option value="">Todos os status</option><?php foreach (['active'=>'Ativas','trial'=>'Em teste','past_due'=>'Em atraso','paused'=>'Pausadas','canceled'=>'Canceladas'] as $value => $label): ?><option value="<?= $value ?>" <?= $status === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select>
        <select name="due" data-due-filter><option value="">Todos os vencimentos</option><option value="overdue" <?= $dueFilter === 'overdue' ? 'selected' : '' ?>>Atrasadas</option><option value="today" <?= $dueFilter === 'today' ? 'selected' : '' ?>>Vencem hoje</option><option value="tomorrow" <?= $dueFilter === 'tomorrow' ? 'selected' : '' ?>>Vencem amanhã</option><option value="two_days" <?= $dueFilter === 'two_days' ? 'selected' : '' ?>>Vencem em 2 dias</option><option value="next_7" <?= $dueFilter === 'next_7' ? 'selected' : '' ?>>Próximos 7 dias</option></select>
        <select name="badge"><option value="">Todos os badges</option><?php foreach ($serviceBadgeCatalog as $serviceBadge): ?><option value="<?= (int) $serviceBadge['id'] ?>" <?= $badgeFilter === (int) $serviceBadge['id'] ? 'selected' : '' ?>><?= h($serviceBadge['name']) ?><?= $serviceBadge['active'] ? '' : ' (inativo)' ?></option><?php endforeach; ?></select>
        <span class="live-filter-indicator" data-live-filter-indicator aria-live="polite">Busca automática</span>
    </form>
    <div>
        <a class="button ghost" href="?page=export&type=subscriptions<?= $buFilter ? '&bu=' . (int)$buFilter : '' ?>">⇩ Exportar</a>
        <?php if ($auth->canWrite() && $dueCount > 0): ?><a class="button secondary" href="?page=subscriptions&renewals=1<?= $buFilter ? '&bu=' . (int)$buFilter : '' ?>">⚡ Gerar próximas cobranças (<?= $dueCount ?>)</a><?php endif; ?>
        <?php if ($auth->canWrite()): ?><a class="button primary" href="?page=subscriptions&new=1<?= $buFilter ? '&bu=' . (int)$buFilter : '' ?>">＋ Nova assinatura</a><?php endif; ?>
    </div>
</section>

<div data-live-results>
<section class="card table-card subscription-table"><div class="table-meta with-page-size"><span class="table-range-summary"><b><?= $pagination['total'] ?></b> assinaturas<small>Exibindo <?= $displayedFrom ?>–<?= $displayedTo ?> de <?= $pagination['total'] ?></small></span><div class="table-meta-actions"><div class="urgency-legend"><span class="tomorrow">Amanhã</span><span class="two-days">Em 2 dias</span><span class="overdue">Atrasada</span></div><form class="page-size-form" method="get"><input type="hidden" name="page" value="subscriptions"><?php if($buFilter!==null): ?><input type="hidden" name="bu" value="<?= (int)$buFilter ?>"><?php endif; ?><?php if($search!==''): ?><input type="hidden" name="q" value="<?= h($search) ?>"><?php endif; ?><?php if($status!==''): ?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif; ?><?php if($dueFilter!==''): ?><input type="hidden" name="due" value="<?= h($dueFilter) ?>"><?php endif; ?><?php if($badgeFilter>0): ?><input type="hidden" name="badge" value="<?= $badgeFilter ?>"><?php endif; ?><input type="hidden" name="sort" value="<?= h($sort) ?>"><input type="hidden" name="dir" value="<?= h($sortDirection) ?>"><label>Linhas por página<select name="per_page" data-page-size-select><?php foreach($pageSizeOptions as $pageSize): ?><option value="<?= $pageSize ?>" <?= $perPage===$pageSize?'selected':'' ?>><?= $pageSize ?></option><?php endforeach; ?></select></label></form></div></div><div class="table-wrap"><table><thead><tr><?= $tableSortHeader('client_product', 'Cliente / Produto') ?><?= $tableSortHeader('recurring_value', 'Valor recorrente') ?><?= $tableSortHeader('cycle', 'Ciclo') ?><?= $tableSortHeader('next_billing', 'Próxima cobrança') ?><?= $tableSortHeader('status', 'Status') ?><th class="actions-column"><span class="sr-only">Ações</span></th></tr></thead><tbody>
<?php if (!$pagination['rows']): ?><tr><td colspan="6" class="empty-cell">Nenhuma assinatura encontrada.</td></tr><?php endif; ?>
<?php foreach ($pagination['rows'] as $item):
    $dueDays = $item['due_in_days'] === null ? null : (int) $item['due_in_days'];
    $urgency = $dueDays === null ? 'none' : ($dueDays < 0 ? 'overdue' : ($dueDays === 0 ? 'today' : ($dueDays === 1 ? 'tomorrow' : ($dueDays === 2 ? 'two-days' : ($dueDays <= 7 ? 'week' : 'none')))));
    $dueLabel = match ($urgency) { 'overdue'=>'Vencida há ' . abs($dueDays) . ' dia(s)', 'today'=>'Vence hoje', 'tomorrow'=>'Vence amanhã', 'two-days'=>'Vence em 2 dias', 'week'=>'Vence em ' . $dueDays . ' dias', default=>'Próxima renovação' };
    $dueIcon = match ($urgency) { 'overdue'=>'!', 'today'=>'●', 'tomorrow'=>'→', 'two-days'=>'2', 'week'=>'◷', default=>'◇' };
    $canRenewIndividual = $auth->canWrite();
    $itemServiceBadges = $serviceBadgesBySubscription[(int) $item['id']] ?? [];
?>
<tr class="subscription-row urgency-<?= h($urgency) ?>">
    <td>
        <?php if ($canRenewIndividual): ?>
            <a class="entity subscription-renew-link" href="?page=subscriptions&renewal=<?= (int) $item['id'] ?>" title="Renovar e receber cobrança" aria-label="Renovar assinatura de <?= h($item['client']) ?>">
        <?php else: ?><div class="entity"><?php endif; ?>
            <span class="avatar-sm"><?= h(mb_strtoupper(mb_substr($item['client'], 0, 1))) ?></span>
            <span>
                <b><?= h($item['client']) ?></b>
                <small class="entity-country"><?= h($item['product']) ?> · <?= country_flag_icon($item['country']) ?></small>
                <?php if ($itemServiceBadges): ?>
                    <span class="service-badge-list">
                        <?php foreach ($itemServiceBadges as $serviceBadge): ?>
                            <span class="service-badge compact tone-<?= h($serviceBadge['tone']) ?>" title="<?= h($serviceBadge['name']) ?>"><?= service_badge_icon($serviceBadge['icon']) ?><b><?= h($serviceBadge['name']) ?></b></span>
                        <?php endforeach; ?>
                    </span>
                <?php endif; ?>
            </span>
        <?php if ($canRenewIndividual): ?></a><?php else: ?></div><?php endif; ?>
    </td>
    <td><b><?= money($item['recurring_value'], $item['currency']) ?></b><small class="block"><?= (int) $item['quantity'] ?> unidade(s)</small></td>
    <td><?= cycle_label($item['billing_cycle']) ?></td>
    <td><div class="due-date-cell <?= h($urgency) ?>"><span><?= $dueIcon ?></span><div><b><?= date_br($item['next_billing_date']) ?></b><small><?= h($dueLabel) ?></small></div></div></td>
    <td><span class="badge <?= status_class($item['status']) ?>"><?= status_label($item['status']) ?></span></td>
    <td><div class="row-actions"><a href="?page=subscriptions&history=<?= (int) $item['id'] ?>" title="Histórico">Histórico</a><?php if ($auth->canWrite()): ?><a class="row-badge-action" href="?page=subscriptions&badges=<?= (int) $item['id'] ?>" title="Vincular badges"><?= service_badge_icon('sparkles') ?><span>Badges</span></a><a class="row-action" href="?page=subscriptions&edit=<?= (int) $item['id'] ?>" title="Editar assinatura">•••</a><?php endif; ?></div></td>
</tr>
<?php endforeach; ?>
</tbody></table></div><?= render_pagination($pagination) ?></section>
</div>

<?php if ($tomorrowSubscriptions): ?><div class="due-alert-overlay" data-due-alert data-alert-key="<?= date('Y-m-d') ?>"><section class="due-alert-popup" role="dialog" aria-modal="true" aria-labelledby="due-alert-title"><button type="button" class="due-alert-close" data-due-alert-close aria-label="Fechar">×</button><header><span class="due-alert-symbol">→</span><div><p class="eyebrow">ALERTA DE RENOVAÇÕES</p><h2 id="due-alert-title"><?= count($tomorrowSubscriptions) ?> assinatura(s) vencem amanhã</h2><p>Revise os valores e prepare os recebimentos antes do vencimento.</p></div></header><div class="due-alert-list"><?php foreach (array_slice($tomorrowSubscriptions, 0, 8) as $dueItem): $dueValue=max(0,((float)$dueItem['unit_price']*(int)$dueItem['quantity'])-(float)$dueItem['discount']); ?><a href="?page=subscriptions&renewal=<?= (int) $dueItem['id'] ?>"><span class="avatar-sm"><?= h(mb_strtoupper(mb_substr($dueItem['client'],0,1))) ?></span><span><b><?= h($dueItem['client']) ?></b><small><?= h($dueItem['product']) ?></small></span><strong><?= money($dueValue,$dueItem['currency']) ?></strong></a><?php endforeach; ?></div><?php if (count($tomorrowSubscriptions)>8): ?><p class="due-alert-more">+ <?= count($tomorrowSubscriptions)-8 ?> assinatura(s) na lista completa</p><?php endif; ?><footer><button type="button" class="button ghost" data-due-alert-close>Dispensar hoje</button><a class="button primary" href="?page=subscriptions&due=tomorrow">Ver vencimentos de amanhã</a></footer></section></div><?php endif; ?>

<?php if ($showRenewals): ?>
<div class="modal open"><a class="modal-backdrop" href="?page=subscriptions"></a><section class="modal-panel renewal-panel <?= $isIndividualRenewal ? 'individual-renewal-panel' : '' ?>"><header><div><p class="eyebrow">RENOVAÇÃO ASSISTIDA</p><h2><?= $isIndividualRenewal ? 'Renovar e receber cobrança' : 'Conferir e receber cobranças' ?></h2><p>Revise os dados. Ao confirmar, <?= $isIndividualRenewal ? 'a cobrança será lançada como paga e a assinatura será renovada' : 'cada cobrança será lançada como paga e as assinaturas serão renovadas' ?> com registro completo no histórico.</p></div><a href="?page=subscriptions" class="modal-close">×</a></header>
<?php if (!$renewalRows): ?><div class="empty-renewals"><b><?= $isIndividualRenewal ? 'Cobrança indisponível' : 'Tudo em dia' ?></b><p><?= $isIndividualRenewal ? 'Esta assinatura não está disponível para renovação.' : 'Não há cobranças pendentes ou previstas nos próximos 45 dias.' ?></p><a class="button secondary" href="?page=subscriptions">Voltar</a></div><?php else: ?>
<form method="post" data-renewal-form data-single-renewal="<?= $isIndividualRenewal ? '1' : '0' ?>" data-confirm="<?= $isIndividualRenewal ? 'Confirmar esta cobrança como paga e recebida? O pagamento e a nova data serão registrados juntos.' : 'Confirmar as renovações selecionadas como pagas e recebidas? Pagamentos, novas datas e alterações de plano serão registrados juntos.' ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="process_subscription_renewals"><input type="hidden" name="_return" value="<?= $isIndividualRenewal ? '?page=subscriptions&renewal=' . $individualRenewalId : '?page=subscriptions&renewals=1' ?>">
    <?php if (!$isIndividualRenewal): ?><div class="renewal-toolbar"><label><input type="checkbox" data-renewal-check-all checked> Selecionar todas</label><span><b data-renewal-selected><?= count($renewalRows) ?></b> de <?= count($renewalRows) ?> selecionadas</span></div><?php endif; ?>
    <div class="renewal-list">
    <?php foreach ($renewalRows as $row):
        $subscriptionId = (int) $row['id'];
        $dueDate = $row['pending_due_date'] ?: ($row['next_billing_date'] ?: date('Y-m-d'));
        $renewalUnitPrice = (float) ($row['renewal_unit_price'] ?? $row['unit_price']);
        $contractAmount = round(max(0, ($renewalUnitPrice * (int) $row['quantity']) - (float) $row['discount']), 2);
        $cycleMonthCount = ['monthly'=>1,'quarterly'=>3,'semiannual'=>6,'annual'=>12][$row['billing_cycle']] ?? 1;
        $initialRenewalMode = in_array(($row['pending_renewal_mode'] ?? ''), ['months','date'], true) ? $row['pending_renewal_mode'] : 'months';
        $storedRenewalMonths = ($row['pending_renewal_months'] ?? null) !== null ? (int) $row['pending_renewal_months'] : null;
        $initialRenewalMonths = $storedRenewalMonths !== null
            ? max(1, min(24, $storedRenewalMonths))
            : $cycleMonthCount;
        $initialRenewalDays = max(0, (int) ($row['pending_renewal_days'] ?? 0));
        $calculationRenewalMonths = $initialRenewalMode === 'date' ? max(0, $storedRenewalMonths ?? 0) : $initialRenewalMonths;
        $suggestedBaseAmount = round(($contractAmount / $cycleMonthCount) * ($calculationRenewalMonths + ($initialRenewalDays / 30)), 2);
        $renewalBaseAmount = ($row['pending_base_amount'] ?? null) !== null ? (float) $row['pending_base_amount'] : $suggestedBaseAmount;
        $renewalDiscountAmount = (float) ($row['pending_discount_amount'] ?? 0);
        $renewalSurchargeAmount = (float) ($row['pending_surcharge_amount'] ?? 0);
        $automaticFinalAmount = round($renewalBaseAmount - $renewalDiscountAmount + $renewalSurchargeAmount, 2);
        $receivedAmount = $row['pending_payment_id'] ? (float) $row['pending_amount'] : $automaticFinalAmount;
        $hasBaseOverride = abs($renewalBaseAmount - $suggestedBaseAmount) > 0.009;
        $hasFinalOverride = abs($receivedAmount - $automaticFinalAmount) > 0.009;
    ?>
    <article class="renewal-card" data-renewal-row data-current-product="<?= (int) $row['product_id'] ?>" data-base-manual="<?= $hasBaseOverride ? '1' : '0' ?>" data-amount-manual="<?= $hasFinalOverride ? '1' : '0' ?>">
        <div class="renewal-card-head"><label class="renewal-selector"><input type="checkbox" name="renewals[<?= $subscriptionId ?>][selected]" value="1" data-renewal-check checked><span></span></label><div class="entity"><span class="avatar-sm"><?= h(mb_strtoupper(mb_substr($row['client'], 0, 1))) ?></span><span><b><?= h($row['client']) ?></b><small class="entity-country"><?= h($row['product']) ?> · <?= country_flag_icon($row['country']) ?> <?= $row['country'] === 'BR' ? 'Brasil' : 'Estados Unidos' ?></small></span></div><div class="renewal-due"><small>Vencimento</small><b><?= date_br($dueDate) ?></b><span data-payment-timing>Conferir data</span></div></div>
        <input type="hidden" name="renewals[<?= $subscriptionId ?>][subscription_updated_at]" value="<?= h($row['updated_at']) ?>"><input type="hidden" name="renewals[<?= $subscriptionId ?>][pending_payment_id]" value="<?= (int) ($row['pending_payment_id'] ?? 0) ?>"><input type="hidden" name="renewals[<?= $subscriptionId ?>][due_date]" value="<?= h($dueDate) ?>" data-renewal-due>
        <div class="renewal-grid">
            <label class="span-2">Plano<select name="renewals[<?= $subscriptionId ?>][product_id]" data-renewal-product><?php foreach ($renewalProducts as $product): ?><option value="<?= (int) $product['id'] ?>" data-brl="<?= h($product['price_brl']) ?>" data-usd="<?= h($product['price_usd']) ?>" data-cycle="<?= h($product['billing_cycle']) ?>" <?= (int) $product['id'] === (int) $row['product_id'] ? 'selected' : '' ?>><?= h($product['name']) ?> · <?= cycle_label($product['billing_cycle']) ?><?= $product['active'] ? '' : ' (inativo)' ?></option><?php endforeach; ?></select><small>Trocar o plano aqui altera a assinatura somente após a confirmação.</small></label>
            <label>Moeda<select name="renewals[<?= $subscriptionId ?>][currency]" data-renewal-currency><option value="BRL" <?= $row['currency'] === 'BRL' ? 'selected' : '' ?>>BRL</option><option value="USD" <?= $row['currency'] === 'USD' ? 'selected' : '' ?>>USD</option></select></label>
            <label>Quantidade<input name="renewals[<?= $subscriptionId ?>][quantity]" type="number" min="1" value="<?= (int) $row['quantity'] ?>" data-renewal-quantity></label>
            <label>Valor unitário<input name="renewals[<?= $subscriptionId ?>][unit_price]" type="number" min="0.01" step="0.01" value="<?= decimal_input($renewalUnitPrice) ?>" data-renewal-price><?php if (($row['product_pricing_mode'] ?? 'manual') !== 'manual'): ?><small>Atualizado pela cotação diária do produto</small><?php endif; ?></label>
            <label>Desconto recorrente do plano<input name="renewals[<?= $subscriptionId ?>][discount]" type="number" min="0" step="0.01" value="<?= decimal_input($row['discount']) ?>" data-renewal-discount><small>Altera o valor mensal futuro da assinatura.</small></label>
            <label>Pagamento / resgate<input name="renewals[<?= $subscriptionId ?>][receipt_date]" type="date" value="<?= date('Y-m-d') ?>" data-renewal-receipt required><small>Para USD, esta data define a cotação diária.</small></label>
            <section class="renewal-period-config">
                <header><div><b>Período desta renovação</b><small>Escolha meses completos ou uma próxima cobrança personalizada.</small></div><span data-renewal-period-label>Calculando…</span></header>
                <div class="renewal-mode-switch">
                    <label><input type="radio" name="renewals[<?= $subscriptionId ?>][renewal_mode]" value="months" data-renewal-mode <?= $initialRenewalMode === 'months' ? 'checked' : '' ?>><span>Por meses</span></label>
                    <label><input type="radio" name="renewals[<?= $subscriptionId ?>][renewal_mode]" value="date" data-renewal-mode <?= $initialRenewalMode === 'date' ? 'checked' : '' ?>><span>Por próxima data</span></label>
                </div>
                <div class="renewal-period-fields">
                    <label data-renewal-months-field>Meses renovados<input name="renewals[<?= $subscriptionId ?>][renewal_months]" type="number" min="1" max="24" value="<?= $initialRenewalMonths ?>" data-renewal-months><small>De 1 a 24 meses.</small></label>
                    <label data-renewal-date-field>Próxima cobrança<input name="renewals[<?= $subscriptionId ?>][renewal_end_date]" type="date" value="<?= h($row['pending_renewal_end_date'] ?? '') ?>" data-renewal-custom-date><small>O período será calculado em meses e dias.</small></label>
                    <div class="renewal-period-result"><small>Regra proporcional</small><b>Valor mensal ÷ 30 dias</b><span data-renewal-next-inline>Próxima cobrança: calculando…</span></div>
                </div>
            </section>
            <section class="renewal-financial-breakdown">
                <header><div><b>Composição do pagamento</b><small>Todos os valores podem ser ajustados antes da confirmação.</small></div><button type="button" data-renewal-reset>Recalcular automaticamente</button></header>
                <div>
                    <label>Valor-base do período<input name="renewals[<?= $subscriptionId ?>][base_amount]" type="number" min="0.01" step="0.01" value="<?= decimal_input($renewalBaseAmount) ?>" data-renewal-base><small data-renewal-monthly-value></small></label>
                    <label>Desconto desta renovação<input name="renewals[<?= $subscriptionId ?>][payment_discount]" type="number" min="0" step="0.01" value="<?= decimal_input($renewalDiscountAmount) ?>" data-renewal-payment-discount></label>
                    <label>Acréscimo<input name="renewals[<?= $subscriptionId ?>][surcharge_amount]" type="number" min="0" step="0.01" value="<?= decimal_input($renewalSurchargeAmount) ?>" data-renewal-surcharge></label>
                    <label>Valor final recebido<input name="renewals[<?= $subscriptionId ?>][amount]" type="number" min="0.01" step="0.01" value="<?= decimal_input($receivedAmount) ?>" data-renewal-amount><small data-renewal-balance></small><button type="button" class="renewal-use-total" data-renewal-use-total>Usar total calculado</button></label>
                    <label>Taxa da plataforma<input name="renewals[<?= $subscriptionId ?>][fee_amount]" type="number" min="0" step="0.01" value="<?= decimal_input($row['pending_fee_amount'] ?? 0) ?>" data-renewal-fee></label>
                </div>
            </section>
            <label>Forma de pagamento<input name="renewals[<?= $subscriptionId ?>][payment_method]" value="<?= h($row['pending_payment_method'] ?: $row['payment_method']) ?>" placeholder="PIX, Stripe, cartão…"></label>
            <label class="span-2">Referência externa<input name="renewals[<?= $subscriptionId ?>][external_reference]" value="<?= h($row['pending_external_reference'] ?? '') ?>" placeholder="ID bancário, Stripe ou nota fiscal"></label>
            <label class="span-2">Observações<textarea name="renewals[<?= $subscriptionId ?>][notes]" rows="2" placeholder="Observação opcional desta renovação"><?= h($row['pending_notes'] ?? '') ?></textarea></label>
        </div>
        <div class="renewal-summary"><span>Período renovado <b data-renewal-period-summary>Calculando…</b></span><span>Valor-base <b data-renewal-total><?= money($renewalBaseAmount, $row['currency']) ?></b></span><span>Valor final <b data-renewal-final><?= money($receivedAmount, $row['currency']) ?></b></span><span>Próxima cobrança <b data-renewal-next>Calculando…</b></span><strong class="renewal-match" data-renewal-match>✓ Total automático</strong></div>
    </article>
    <?php endforeach; ?>
    </div>
    <footer class="renewal-footer"><p><b>Operação rastreável:</b> pagamento, cotação, renovação e mudanças comerciais serão salvos no histórico.</p><div><a class="button ghost" href="?page=subscriptions">Cancelar</a><button class="button primary" data-renewal-submit><?= $isIndividualRenewal ? 'Confirmar e receber' : 'Confirmar e receber ' . count($renewalRows) . ' renovação(ões)' ?></button></div></footer>
</form>
<?php endif; ?></section></div>
<?php endif; ?>

<?php if ($historySubscription): ?>
<div class="modal open"><a class="modal-backdrop" href="?page=subscriptions"></a><section class="modal-panel wide history-panel"><header><div><p class="eyebrow">TRILHA RASTREÁVEL</p><h2><?= h($historySubscription['client']) ?></h2><p><?= h($historySubscription['product']) ?> · pagamentos, renovações e mudanças de plano</p></div><a href="?page=subscriptions" class="modal-close">×</a></header>
<section class="history-section"><h3>Renovações e alterações</h3><?php if (!$historyEvents): ?><p class="history-empty">Nenhuma renovação processada pelo novo fluxo ainda.</p><?php else: ?><div class="history-timeline"><?php foreach ($historyEvents as $event): ?><article><span class="history-dot <?= $event['event_type'] === 'plan_change' ? 'gold' : '' ?>">↻</span><div><b><?= h($event['summary']) ?></b><p><?= date_br($event['event_date']) ?><?php if ($event['amount'] !== null): ?> · <?= money($event['amount'], $event['currency']) ?><?php endif; ?><?php if ($event['renewal_months'] !== null): ?> · até <?= date_br($event['renewal_end_date']) ?><?php endif; ?><?php if ($event['payment_id']): ?> · Pagamento #<?= (int) $event['payment_id'] ?><?php endif; ?></p><small><?= h($event['user_name'] ?: 'Sistema') ?> · <?= date('d/m/Y H:i', strtotime($event['created_at'])) ?></small></div></article><?php endforeach; ?></div><?php endif; ?></section>
<section class="history-section"><h3>Transações da assinatura</h3><?php if (!$historyPayments): ?><p class="history-empty">Nenhum pagamento vinculado.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>ID</th><th>Pagamento</th><th>Período renovado</th><th>Valor final</th><th>Status</th></tr></thead><tbody><?php foreach ($historyPayments as $payment): ?><tr><td>#<?= (int) $payment['id'] ?></td><td><?= date_br($payment['payment_date']) ?><small class="block">Vencimento: <?= date_br($payment['due_date']) ?></small></td><td><?= renewal_period_label($payment['renewal_months'], $payment['renewal_days']) ?><?php if ($payment['renewal_end_date']): ?><small class="block">Próxima: <?= date_br($payment['renewal_end_date']) ?></small><?php endif; ?></td><td><b><?= money($payment['amount'], $payment['currency']) ?></b><?php if ($payment['base_amount'] !== null): ?><small class="block">Base <?= money($payment['base_amount'], $payment['currency']) ?> · desc. <?= money($payment['discount_amount'], $payment['currency']) ?> · acrésc. <?= money($payment['surcharge_amount'], $payment['currency']) ?></small><?php endif; ?><?php if (abs((float) $payment['manual_adjustment_amount']) > 0.009): ?><small class="block">Ajuste manual: <?= money($payment['manual_adjustment_amount'], $payment['currency']) ?></small><?php endif; ?></td><td><span class="badge <?= status_class($payment['status']) ?>"><?= status_label($payment['status']) ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
</section></div>
<?php endif; ?>

<?php if ($badgeAssignmentSubscription): ?>
<div class="modal open">
    <a class="modal-backdrop" href="?page=subscriptions"></a>
    <section class="modal-panel badge-assignment-panel">
        <header>
            <div>
                <p class="eyebrow">IDENTIFICAÇÃO VISUAL</p>
                <h2>Vincular badges</h2>
                <p><?= h($badgeAssignmentSubscription['client']) ?> · <?= h($badgeAssignmentSubscription['product']) ?></p>
            </div>
            <a href="?page=subscriptions" class="modal-close">×</a>
        </header>
        <form method="post" class="badge-assignment-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_subscription_badges">
            <input type="hidden" name="id" value="<?= (int) $badgeAssignmentSubscription['id'] ?>">
            <input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">
            <p class="badge-assignment-lead">Selecione um ou mais badges para identificar rapidamente os serviços desta assinatura.</p>
            <div class="subscription-badge-field">
                <div><span>Badges de serviços</span><a href="?page=service-badges">Gerenciar biblioteca →</a></div>
                <?php if (!$badgeAssignmentOptions): ?>
                    <p>Nenhum badge disponível. <a href="?page=service-badges&new=1">Crie o primeiro badge</a> para começar.</p>
                <?php else: ?>
                    <div class="subscription-badge-options">
                        <?php foreach ($badgeAssignmentOptions as $serviceBadge): ?>
                            <label class="<?= !$serviceBadge['active'] ? 'inactive' : '' ?>">
                                <input type="checkbox" name="badge_ids[]" value="<?= (int) $serviceBadge['id'] ?>" <?= in_array((int) $serviceBadge['id'], $selectedBadgeAssignmentIds, true) ? 'checked' : '' ?>>
                                <span class="service-badge tone-<?= h($serviceBadge['tone']) ?>"><?= service_badge_icon($serviceBadge['icon']) ?><b><?= h($serviceBadge['name']) ?></b></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <footer class="badge-assignment-footer"><a class="button ghost" href="?page=subscriptions">Cancelar</a><button class="button primary">Salvar badges</button></footer>
        </form>
    </section>
</div>
<?php endif; ?>

<?php if ($showForm): ?>
<div class="modal open">
    <a class="modal-backdrop" href="?page=subscriptions"></a>
    <section class="modal-panel wide">
        <header><div><p class="eyebrow">RECEITA RECORRENTE</p><h2><?= $edit ? 'Editar assinatura' : 'Nova assinatura' ?></h2></div><a href="?page=subscriptions" class="modal-close">×</a></header>
        <form method="post" class="form-grid" data-subscription-form>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_subscription">
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
            <input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">
            <label>Cliente<select name="client_id" required data-sub-client><option value="">Selecione…</option><?php foreach ($clients as $client): ?><option value="<?= (int) $client['id'] ?>" data-currency="<?= h($client['preferred_currency']) ?>" <?= (int) ($edit['client_id'] ?? 0) === (int) $client['id'] ? 'selected' : '' ?>><?= h($client['name']) ?> · <?= h($client['country']) ?></option><?php endforeach; ?></select></label>
            <label>Produto<select name="product_id" required data-sub-product><option value="">Selecione…</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['id'] ?>" data-brl="<?= h($product['price_brl']) ?>" data-usd="<?= h($product['price_usd']) ?>" <?= (int) ($edit['product_id'] ?? 0) === (int) $product['id'] ? 'selected' : '' ?>><?= h($product['name']) ?> · <?= cycle_label($product['billing_cycle']) ?></option><?php endforeach; ?></select></label>
            <label>Moeda<select name="currency" data-sub-currency><option value="BRL" <?= ($edit['currency'] ?? 'BRL') === 'BRL' ? 'selected' : '' ?>>BRL — Real</option><option value="USD" <?= ($edit['currency'] ?? '') === 'USD' ? 'selected' : '' ?>>USD — Dólar</option></select></label>
            <label>Valor unitário<input name="unit_price" type="number" step="0.01" min="0" required value="<?= decimal_input($edit['unit_price'] ?? 0) ?>" data-sub-price></label>
            <label>Quantidade<input name="quantity" type="number" min="1" required value="<?= (int) ($edit['quantity'] ?? 1) ?>"></label>
            <label>Desconto total<input name="discount" type="number" step="0.01" min="0" value="<?= decimal_input($edit['discount'] ?? 0) ?>"></label>
            <label>Data de início<input name="start_date" type="date" required value="<?= h($edit['start_date'] ?? date('Y-m-d')) ?>"></label>
            <label>Próxima cobrança<input name="next_billing_date" type="date" value="<?= h($edit['next_billing_date'] ?? date('Y-m-d', strtotime('+1 month'))) ?>"></label>
            <label>Status<select name="status"><option value="active" <?= ($edit['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Ativa</option><option value="trial" <?= ($edit['status'] ?? '') === 'trial' ? 'selected' : '' ?>>Teste</option><option value="past_due" <?= ($edit['status'] ?? '') === 'past_due' ? 'selected' : '' ?>>Em atraso</option><option value="paused" <?= ($edit['status'] ?? '') === 'paused' ? 'selected' : '' ?>>Pausada</option><option value="canceled" <?= ($edit['status'] ?? '') === 'canceled' ? 'selected' : '' ?>>Cancelada</option></select></label>
            <label>Data de cancelamento<input name="canceled_at" type="date" value="<?= h($edit['canceled_at'] ?? '') ?>"></label>
            <label class="span-2">Forma de pagamento<input name="payment_method" value="<?= h($edit['payment_method'] ?? '') ?>" placeholder="Cartão, PIX, Stripe, boleto…"></label>
            <label class="span-2">Link de pagamento da assinatura<input name="payment_link" type="url" maxlength="1000" value="<?= h($edit['payment_link'] ?? '') ?>" placeholder="https://pagamento.exemplo.com/cliente"><small>Opcional. Este link individual tem prioridade sobre o link padrão configurado na etapa do WhatsApp.</small></label>
            <div class="subscription-badge-field span-2">
                <div><span>Badges de serviços</span><a href="?page=service-badges">Gerenciar biblioteca →</a></div>
                <?php if (!$assignableServiceBadges): ?>
                    <p>Nenhum badge disponível. <a href="?page=service-badges&new=1">Crie o primeiro badge</a> para identificar os serviços desta assinatura.</p>
                <?php else: ?>
                    <div class="subscription-badge-options">
                        <?php foreach ($assignableServiceBadges as $serviceBadge): ?>
                            <label class="<?= !$serviceBadge['active'] ? 'inactive' : '' ?>">
                                <input type="checkbox" name="badge_ids[]" value="<?= (int) $serviceBadge['id'] ?>" <?= in_array((int) $serviceBadge['id'], $selectedServiceBadgeIds, true) ? 'checked' : '' ?>>
                                <span class="service-badge tone-<?= h($serviceBadge['tone']) ?>"><?= service_badge_icon($serviceBadge['icon']) ?><b><?= h($serviceBadge['name']) ?></b></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <label class="span-2">Observações<textarea name="notes" rows="3"><?= h($edit['notes'] ?? '') ?></textarea></label>
            <footer class="span-2"><a class="button ghost" href="?page=subscriptions">Cancelar</a><button class="button primary">Salvar assinatura</button></footer>
        </form>
        <?php if ($edit && $auth->canWrite()): ?>
            <form method="post" class="danger-zone" data-confirm="Excluir esta assinatura?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_subscription"><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><button>Excluir assinatura</button></form>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>
