<?php
$search = trim((string) ($_GET['q'] ?? ''));
$params = [];
$where = '';
if ($search !== '') {
    $where = " WHERE CONCAT_WS(' ',p.id,p.name,p.sku,p.description,p.price_brl,REPLACE(p.price_brl,'.',','),p.price_usd,REPLACE(p.price_usd,'.',','),p.pricing_mode,CASE p.pricing_mode WHEN 'manual' THEN 'Preços locais independentes' WHEN 'brl' THEN 'Cotado em BRL real' WHEN 'usd' THEN 'Cotado em USD dólar' END,p.billing_cycle,CASE p.billing_cycle WHEN 'monthly' THEN 'Mensal' WHEN 'quarterly' THEN 'Trimestral' WHEN 'semiannual' THEN 'Semestral' WHEN 'annual' THEN 'Anual' END,CASE p.active WHEN 1 THEN 'Ativo' ELSE 'Inativo' END,(SELECT COUNT(*) FROM subscriptions sx WHERE sx.product_id=p.id AND sx.status='active'),'assinaturas') LIKE ?";
    $params = ['%' . $search . '%'];
}
$currentQuote = $rates->current();
$currentRate = (float) $currentQuote['bid'];
$pagination = pagination(
    $db,
    'SELECT COUNT(*) FROM products p' . $where,
    'SELECT p.*, (SELECT COUNT(*) FROM subscriptions s WHERE s.product_id=p.id AND s.status=\'active\') active_subscriptions FROM products p' . $where . ' ORDER BY p.active DESC,p.created_at DESC',
    $params
);
foreach ($pagination['rows'] as $key => $product) {
    $pagination['rows'][$key] = product_with_current_prices($product, $currentRate);
}
$edit = isset($_GET['edit']) ? $db->fetch('SELECT * FROM products WHERE id=?', [(int) $_GET['edit']]) : null;
if ($edit) {
    $edit = product_with_current_prices($edit, $currentRate);
}
$showForm = isset($_GET['new']) || $edit;
$pricingMode = $edit['pricing_mode'] ?? 'manual';
?>

<?php if (!empty($currentQuote['warning'])): ?><p class="inline-warning">⚠ <?= h($currentQuote['warning']) ?></p><?php endif; ?>
<section class="toolbar list-toolbar"><form class="search-filters" method="get" data-live-filter><input type="hidden" name="page" value="products"><label class="search-box">⌕<input name="q" autocomplete="off" placeholder="Buscar qualquer informação" value="<?= h($search) ?>"></label><span class="live-filter-indicator" data-live-filter-indicator aria-live="polite">Busca automática</span></form><?php if ($auth->canWrite()): ?><a class="button primary" href="?page=products&new=1">＋ Novo produto</a><?php endif; ?></section>

<div data-live-results>
<section class="product-grid">
<?php if (!$pagination['rows']): ?><article class="card empty-state span-full"><span>◇</span><h2>Nenhum produto cadastrado</h2><p>Cadastre preços locais ou use BRL/USD como moeda-base com conversão diária.</p><a class="button primary" href="?page=products&new=1">Criar primeiro produto</a></article><?php endif; ?>
<?php foreach ($pagination['rows'] as $item):
    $modeLabel = ['manual'=>'Preços locais','brl'=>'Cotado em BRL','usd'=>'Cotado em USD'][$item['pricing_mode']] ?? 'Preços locais';
?>
<article class="card product-card <?= !$item['active'] ? 'disabled' : '' ?>"><div class="product-top"><span class="product-icon">◇</span><span class="badge <?= $item['active'] ? 'success' : 'muted' ?>"><?= $item['active'] ? 'Ativo' : 'Inativo' ?></span></div><h2><?= h($item['name']) ?></h2><p><?= h($item['description'] ?: 'Sem descrição') ?></p><div class="product-pricing-mode"><b><?= h($modeLabel) ?></b><?php if ($item['pricing_mode'] !== 'manual'): ?><span>US$ 1 = <?= money($currentRate) ?> · <?= date_br($currentQuote['quoted_at']) ?></span><?php else: ?><span>Valores definidos separadamente</span><?php endif; ?></div><div class="local-prices"><div><small>🇧🇷 BRASIL</small><strong><?= money($item['price_brl']) ?></strong></div><div><small>🇺🇸 ESTADOS UNIDOS</small><strong><?= money($item['price_usd'], 'USD') ?></strong></div></div><footer><span><?= cycle_label($item['billing_cycle']) ?> · <?= (int) $item['active_subscriptions'] ?> assinaturas</span><a href="?page=products&edit=<?= (int) $item['id'] ?>">Editar →</a></footer></article>
<?php endforeach; ?>
</section><?= render_pagination($pagination) ?>
</div>

<?php if ($showForm): ?>
<div class="modal open"><a class="modal-backdrop" href="?page=products"></a><section class="modal-panel"><header><div><p class="eyebrow">CATÁLOGO</p><h2><?= $edit ? 'Editar produto' : 'Novo produto' ?></h2></div><a href="?page=products" class="modal-close">×</a></header>
<form method="post" class="form-grid" data-product-pricing data-current-rate="<?= h($currentRate) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_product"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>"><input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">
    <label class="span-2">Nome do produto<input name="name" required value="<?= h($edit['name'] ?? '') ?>"></label>
    <label>SKU<input name="sku" value="<?= h($edit['sku'] ?? '') ?>" placeholder="PLANO-PRO"></label>
    <label>Ciclo de cobrança<select name="billing_cycle"><option value="monthly" <?= ($edit['billing_cycle'] ?? 'monthly') === 'monthly' ? 'selected' : '' ?>>Mensal</option><option value="quarterly" <?= ($edit['billing_cycle'] ?? '') === 'quarterly' ? 'selected' : '' ?>>Trimestral</option><option value="semiannual" <?= ($edit['billing_cycle'] ?? '') === 'semiannual' ? 'selected' : '' ?>>Semestral</option><option value="annual" <?= ($edit['billing_cycle'] ?? '') === 'annual' ? 'selected' : '' ?>>Anual</option></select></label>
    <label class="span-2">Formação do preço<select name="pricing_mode" data-pricing-mode><option value="manual" <?= $pricingMode === 'manual' ? 'selected' : '' ?>>Preços locais independentes — informar BRL e USD</option><option value="brl" <?= $pricingMode === 'brl' ? 'selected' : '' ?>>Cotado em real — converter automaticamente para USD</option><option value="usd" <?= $pricingMode === 'usd' ? 'selected' : '' ?>>Cotado em dólar — converter automaticamente para BRL</option></select><small>Escolha qual preço é fixo. O campo convertido acompanha a cotação diária.</small></label>
    <div class="pricing-quote span-2"><span class="live-dot"></span><div><small>COTAÇÃO DIÁRIA APLICADA</small><b>US$ 1 = <?= money($currentRate) ?></b></div><em><?= h($currentQuote['source']) ?> · <?= date_br($currentQuote['quoted_at']) ?></em></div>
    <label data-price-brl-label>Preço no Brasil (R$)<input name="price_brl" type="number" min="0.01" step="0.01" value="<?= decimal_input($edit['price_brl'] ?? 0) ?>" data-price-brl><small data-price-brl-help></small></label>
    <label data-price-usd-label>Preço nos EUA (US$)<input name="price_usd" type="number" min="0.01" step="0.01" value="<?= decimal_input($edit['price_usd'] ?? 0) ?>" data-price-usd><small data-price-usd-help></small></label>
    <label class="span-2">Descrição<textarea name="description" rows="3"><?= h($edit['description'] ?? '') ?></textarea></label>
    <label class="check-label span-2"><input type="checkbox" name="active" value="1" <?= !isset($edit['active']) || $edit['active'] ? 'checked' : '' ?>><span>Produto disponível para novas assinaturas</span></label>
    <footer class="span-2"><a class="button ghost" href="?page=products">Cancelar</a><button class="button primary">Salvar produto</button></footer>
</form>
<?php if ($edit && $auth->canWrite()): ?><form method="post" class="danger-zone" data-confirm="Excluir este produto?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_product"><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><button>Excluir produto</button></form><?php endif; ?>
</section></div>
<?php endif; ?>
