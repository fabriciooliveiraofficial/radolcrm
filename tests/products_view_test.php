<?php

declare(strict_types=1);

session_start();
function h(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function money(float|int|string|null $value, string $currency = 'BRL'): string { return ($currency === 'USD' ? 'US$' : 'R$') . ' ' . number_format((float) $value, 2, ',', '.'); }
function decimal_input(mixed $value): string { return number_format((float) $value, 2, '.', ''); }
function date_br(?string $date): string { return $date ? (new DateTimeImmutable($date))->format('d/m/Y') : '—'; }
function cycle_label(string $cycle): string { return ['monthly'=>'Mensal'][$cycle] ?? $cycle; }
function csrf_field(): string { return '<input type="hidden" name="_token" value="test">'; }
function product_with_current_prices(array $product, float $rate): array
{
    $mode = $product['pricing_mode'] ?? 'manual';
    if ($mode === 'usd') $product['price_brl'] = round((float) $product['price_usd'] * $rate, 2);
    if ($mode === 'brl') $product['price_usd'] = round((float) $product['price_brl'] / $rate, 2);
    $product['pricing_mode'] = $mode;
    return $product;
}
function pagination(object $db, string $countSql, string $dataSql, array $params = [], int $perPage = 15): array
{
    return ['rows'=>$db->fetchAll($dataSql, $params),'total'=>1,'pages'=>1,'page'=>1];
}
function render_pagination(array $pagination): void {}

$product = ['id'=>4,'name'=>'Produto USD','sku'=>'USD-15','description'=>'Teste','price_brl'=>'75.00','price_usd'=>'15.00','pricing_mode'=>'usd','billing_cycle'=>'monthly','active'=>1,'active_subscriptions'=>0,'created_at'=>'2026-01-01'];
$db = new class($product) {
    public function __construct(private array $product) {}
    public function fetchAll(string $sql, array $params = []): array { return [$this->product]; }
    public function fetch(string $sql, array $params = []): ?array { return null; }
};
$rates = new class { public function current(): array { return ['bid'=>5.5,'source'=>'Frankfurter · diária','quoted_at'=>'2026-07-21 12:00:00']; } };
$auth = new class { public function canWrite(): bool { return true; } };
$_GET = ['new'=>'1'];
$_SERVER['REQUEST_URI'] = '?page=products&new=1';

ob_start();
require dirname(__DIR__) . '/app/Views/pages/products.php';
$html = (string) ob_get_clean();
foreach (['data-product-pricing','name="pricing_mode"','Cotado em dólar','US$ 1 = R$ 5,50','data-price-brl','data-price-usd'] as $check) {
    if (!str_contains($html, $check)) {
        fwrite(STDERR, "Falha ao renderizar: {$check}\n");
        exit(1);
    }
}
echo "Modal de preços com câmbio renderizado com sucesso.\n";
