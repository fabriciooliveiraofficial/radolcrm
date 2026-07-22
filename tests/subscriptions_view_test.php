<?php

declare(strict_types=1);

session_start();

function h(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function money(float|int|string|null $value, string $currency = 'BRL'): string { return ($currency === 'USD' ? 'US$' : 'R$') . ' ' . number_format((float) $value, 2, ',', '.'); }
function decimal_input(mixed $value): string { return number_format((float) $value, 2, '.', ''); }
function date_br(?string $date): string { return $date ? (new DateTimeImmutable($date))->format('d/m/Y') : '—'; }
function cycle_label(string $cycle): string { return ['monthly'=>'Mensal','quarterly'=>'Trimestral','semiannual'=>'Semestral','annual'=>'Anual'][$cycle] ?? $cycle; }
function status_label(string $status): string { return ['active'=>'Ativo','paid'=>'Pago','pending'=>'Pendente'][$status] ?? $status; }
function status_class(string $status): string { return in_array($status, ['active', 'paid'], true) ? 'success' : 'warning'; }
function csrf_field(): string { return '<input type="hidden" name="_token" value="test">'; }
function country_flag_icon(string $country): string { return '<span class="flag-icon flag-' . (strtoupper($country) === 'BR' ? 'br' : 'us') . '"></span>'; }
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
    return ['rows'=>$db->fetchAll($dataSql . ' LIMIT 15 OFFSET 0', $params),'total'=>1,'pages'=>1,'page'=>1];
}
function render_pagination(array $pagination): void {}

$subscription = [
    'id'=>7,'client_id'=>3,'product_id'=>2,'quantity'=>1,'currency'=>'BRL','unit_price'=>'54.00','discount'=>'0.00',
    'status'=>'active','start_date'=>'2026-01-01','next_billing_date'=>(new DateTimeImmutable('tomorrow'))->format('Y-m-d'),'canceled_at'=>null,
    'payment_method'=>'PIX','notes'=>null,'created_at'=>'2026-01-01 10:00:00','updated_at'=>'2026-07-20 10:00:00',
    'client'=>'Cliente Teste','country'=>'BR','product'=>'Plano Mensal','billing_cycle'=>'monthly','recurring_value'=>'54.00',
    'pending_payment_id'=>null,'pending_due_date'=>null,'pending_amount'=>null,'pending_fee_amount'=>null,
    'pending_payment_method'=>null,'pending_external_reference'=>null,'pending_notes'=>null,'due_in_days'=>1,
];
$product = ['id'=>2,'name'=>'Plano Mensal','price_brl'=>'54.00','price_usd'=>'18.00','pricing_mode'=>'manual','billing_cycle'=>'monthly','active'=>1];
$db = new class($subscription, $product) {
    public function __construct(private array $subscription, private array $product) {}
    public function value(string $sql, array $params = []): int { return 1; }
    public function fetch(string $sql, array $params = []): ?array
    {
        if (str_contains($sql, 'tomorrow_count')) return ['overdue'=>0,'today_count'=>0,'tomorrow_count'=>1,'two_days_count'=>0,'next_7_count'=>1];
        return null;
    }
    public function fetchAll(string $sql, array $params = []): array
    {
        if (str_contains($sql, 'FROM products ORDER BY active DESC')) return [$this->product];
        if (str_contains($sql, 'pending.id pending_payment_id')) return [$this->subscription];
        if (str_contains($sql, 'ORDER BY c.name LIMIT 20')) return [$this->subscription];
        if (str_contains($sql, 'recurring_value')) return [$this->subscription];
        return [];
    }
};
$auth = new class { public function canWrite(): bool { return true; } };
$rates = new class { public function current(): array { return ['bid'=>5.5]; } };
$_GET = ['renewals' => '1'];
$_SERVER['REQUEST_URI'] = '?page=subscriptions&renewals=1';

ob_start();
require dirname(__DIR__) . '/app/Views/pages/subscriptions.php';
$html = (string) ob_get_clean();

$checks = [
    'data-renewal-form',
    'renewals[7][selected]',
    'renewals[7][product_id]',
    'renewals[7][amount]',
    'Confirmar e receber 1 renovação(ões)',
    'Pagamento / resgate',
    'data-due-filter',
    'RADAR DE RENOVAÇÕES',
    'data-due-alert',
    'urgency-tomorrow',
];
foreach ($checks as $check) {
    if (!str_contains($html, $check)) {
        fwrite(STDERR, "Falha ao renderizar: {$check}\n");
        exit(1);
    }
}

$_GET = ['renewal' => '7'];
$_SERVER['REQUEST_URI'] = '?page=subscriptions&renewal=7';
ob_start();
require dirname(__DIR__) . '/app/Views/pages/subscriptions.php';
$individualHtml = (string) ob_get_clean();

$individualChecks = [
    'href="?page=subscriptions&renewal=7"',
    'data-single-renewal="1"',
    'Renovar e receber cobrança',
    'renewals[7][selected]',
    'Confirmar e receber',
];
foreach ($individualChecks as $check) {
    if (!str_contains($individualHtml, $check)) {
        fwrite(STDERR, "Falha ao renderizar renovação individual: {$check}\n");
        exit(1);
    }
}
if (str_contains($individualHtml, 'Selecionar todas')) {
    fwrite(STDERR, "A renovação individual não deve exibir seleção em lote.\n");
    exit(1);
}

echo "Tela de renovação renderizada com sucesso.\n";
