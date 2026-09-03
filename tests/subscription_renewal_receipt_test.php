<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function h(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function money(float|int|string|null $value, string $currency = 'BRL'): string { return ($currency === 'USD' ? 'US$' : 'R$') . ' ' . number_format((float) $value, 2, ',', '.'); }
function decimal_input(mixed $value): string { return number_format((float) $value, 2, '.', ''); }
function date_br(?string $date): string { return $date ? (new DateTimeImmutable($date))->format('d/m/Y') : '—'; }
function cycle_label(string $cycle): string { return ['monthly'=>'Mensal','quarterly'=>'Trimestral','semiannual'=>'Semestral','annual'=>'Anual'][$cycle] ?? $cycle; }
function status_label(string $status): string { return ['active'=>'Ativa','paid'=>'Pago','pending'=>'Pendente'][$status] ?? $status; }
function status_class(string $status): string { return in_array($status, ['active', 'paid'], true) ? 'success' : 'warning'; }
function csrf_field(): string { return '<input type="hidden" name="_token" value="test">'; }
function country_flag_icon(string $country): string { return '<span class="flag-icon flag-' . (strtoupper($country) === 'BR' ? 'br' : 'us') . '"></span>'; }
function service_badge_icon(string $icon): string { return '<svg data-icon="' . h($icon) . '"></svg>'; }
function renewal_period_label(int|string|null $months, int|string|null $days = 0): string { return ((int) $months) . ' meses e ' . ((int) $days) . ' dias'; }
function product_with_current_prices(array $product, float $rate): array { return $product; }
function pagination(object $db, string $countSql, string $dataSql, array $params = [], int $perPage = 15): array
{
    return ['rows'=>[],'total'=>0,'pages'=>1,'page'=>1];
}
function render_pagination(array $pagination): void {}

// 1. Test WhatsApp message format
$sampleReceipt = [
    'subscription_id' => 15,
    'client_name' => 'Carlos Eduardo Silva',
    'client_phone' => '(11) 98765-4321',
    'client_country' => 'BR',
    'product_name' => 'Plano VIP Anual',
    'quantity' => 2,
    'amount' => 70.00,
    'currency' => 'BRL',
    'payment_date' => '2026-09-02',
    'due_date' => '2026-09-02',
    'renewal_end_date' => '2026-10-02',
    'period_label' => '1 mês',
];

require_once dirname(__DIR__) . '/app/Services/WhatsAppReminderService.php';

// Prepare dummy session with receipt
$_SESSION['_renewal_receipts'] = [$sampleReceipt];

$subscription = [
    'id'=>15,'client_id'=>3,'product_id'=>2,'quantity'=>1,'currency'=>'BRL','unit_price'=>'35.00','discount'=>'0.00',
    'status'=>'active','start_date'=>'2026-01-01','next_billing_date'=>'2026-10-02','canceled_at'=>null,
    'payment_method'=>'PIX','notes'=>null,'created_at'=>'2026-01-01 10:00:00','updated_at'=>'2026-09-02 10:00:00',
    'client'=>'Carlos Eduardo Silva','country'=>'BR','product'=>'Plano VIP Anual','billing_cycle'=>'monthly','recurring_value'=>'35.00',
    'pending_payment_id'=>null,'pending_due_date'=>null,'pending_amount'=>null,'pending_fee_amount'=>null,
    'pending_payment_method'=>null,'pending_external_reference'=>null,'pending_notes'=>null,'due_in_days'=>30,
];
$product = ['id'=>2,'name'=>'Plano VIP Anual','price_brl'=>'35.00','price_usd'=>'10.00','pricing_mode'=>'manual','billing_cycle'=>'monthly','active'=>1];
$db = new class($subscription, $product) {
    public function __construct(private array $subscription, private array $product) {}
    public function value(string $sql, array $params = []): int { return 1; }
    public function fetch(string $sql, array $params = []): ?array
    {
        if (str_contains($sql, 'tomorrow_count')) return ['overdue'=>0,'today_count'=>0,'tomorrow_count'=>0,'two_days_count'=>0,'next_7_count'=>0];
        if (str_contains($sql, 'FROM subscriptions s') && str_contains($sql, 'WHERE s.id=?')) {
            return ['id'=>15,'client'=>'Carlos Eduardo Silva','product'=>'Plano VIP Anual'];
        }
        return null;
    }
    public function fetchAll(string $sql, array $params = []): array
    {
        if (str_contains($sql, 'active_units')) return [];
        if (str_contains($sql, 'SELECT * FROM service_badges')) return [];
        if (str_contains($sql, 'SELECT badge_id FROM subscription_service_badges')) return [];
        if (str_contains($sql, 'FROM subscription_service_badges ssb')) return [];
        if (str_contains($sql, 'FROM products ORDER BY active DESC')) return [$this->product];
        return [];
    }
};
$auth = new class { public function canWrite(): bool { return true; } };
$rates = new class { public function current(): array { return ['bid'=>5.5]; } };
$_GET = [];
$_SERVER['REQUEST_URI'] = '?page=subscriptions';

ob_start();
require dirname(__DIR__) . '/app/Views/pages/subscriptions.php';
$html = (string) ob_get_clean();

// Validations
$checks = [
    'modal de comprovante presente' => str_contains($html, 'data-renewal-receipt-modal'),
    'título de confirmação presente' => str_contains($html, 'Cobrança confirmada com sucesso!'),
    'nome do cliente na mensagem' => str_contains($html, 'Carlos'),
    'plano/assinatura na mensagem' => str_contains($html, 'Plano VIP Anual (2 un.)'),
    'valor na mensagem' => str_contains($html, 'R$ 70,00'),
    'data de pagamento na mensagem' => str_contains($html, '02/09/2026'),
    'próximo vencimento na mensagem' => str_contains($html, '02/10/2026'),
    'ícones do WhatsApp presentes' => str_contains($html, '✅') && str_contains($html, '📦') && str_contains($html, '💰') && str_contains($html, '🗓️') && str_contains($html, '📅'),
    'botão de copiar presente' => str_contains($html, 'data-copy-receipt'),
    'link do WhatsApp direto' => str_contains($html, 'https://wa.me/5511987654321'),
    'sessão limpa após exibição' => !isset($_SESSION['_renewal_receipts']),
];

$actionHandlerCode = (string) file_get_contents(dirname(__DIR__) . '/app/Http/ActionHandler.php');
$checks['ActionHandler armazena recibo na sessão'] = str_contains($actionHandlerCode, "\$_SESSION['_renewal_receipts'] = \$receipts;");
$checks['ActionHandler busca cliente e telefone'] = str_contains($actionHandlerCode, 'c.name client, c.phone client_phone, c.country client_country');

$javascriptCode = (string) file_get_contents(dirname(__DIR__) . '/assets/js/app.js');
$checks['JavaScript possui handler de cópia'] = str_contains($javascriptCode, "data-copy-receipt")
    && str_contains($javascriptCode, "clipboard")
    && str_contains($javascriptCode, "Mensagem copiada!");

$cssCode = (string) file_get_contents(dirname(__DIR__) . '/assets/css/app.css');
$checks['CSS possui estilização do modal de comprovante'] = str_contains($cssCode, ".renewal-receipt-modal")
    && str_contains($cssCode, ".whatsapp-bubble-wrap")
    && str_contains($cssCode, ".copy-receipt-btn");

$failed = false;
foreach ($checks as $title => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FALHA: {$title}\n");
        $failed = true;
    }
}

if ($failed) {
    exit(1);
}

echo count($checks) . " verificações do comprovante WhatsApp de renovação passaram com sucesso!\n";
