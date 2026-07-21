<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Database;

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(float|int|string|null $value, string $currency = 'BRL'): string
{
    $prefix = $currency === 'USD' ? 'US$' : 'R$';
    return $prefix . ' ' . number_format((float) $value, 2, ',', '.');
}

function decimal_input(mixed $value): string
{
    return number_format((float) $value, 2, '.', '');
}

function normalize_decimal(mixed $value): float
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return 0.0;
    }
    if (str_contains($raw, ',') && str_contains($raw, '.')) {
        $raw = str_replace('.', '', $raw);
    }
    $raw = str_replace(',', '.', $raw);

    return round((float) $raw, 6);
}

function product_with_current_prices(array $product, float $usdBrlRate): array
{
    $rate = $usdBrlRate > 0 ? $usdBrlRate : 1.0;
    $mode = in_array($product['pricing_mode'] ?? 'manual', ['manual', 'brl', 'usd'], true)
        ? $product['pricing_mode']
        : 'manual';
    $priceBrl = (float) ($product['price_brl'] ?? 0);
    $priceUsd = (float) ($product['price_usd'] ?? 0);

    if ($mode === 'usd') {
        $priceBrl = round($priceUsd * $rate, 2);
    } elseif ($mode === 'brl') {
        $priceUsd = round($priceBrl / $rate, 2);
    }

    $product['pricing_mode'] = $mode;
    $product['price_brl'] = $priceBrl;
    $product['price_usd'] = $priceUsd;
    $product['current_exchange_rate'] = $rate;

    return $product;
}

function country_flag_icon(string $country): string
{
    $code = strtoupper($country) === 'BR' ? 'br' : 'us';
    $label = $code === 'br' ? 'Bandeira do Brasil' : 'Bandeira dos Estados Unidos';
    return '<span class="flag-icon flag-' . $code . '" role="img" aria-label="' . $label . '"></span>';
}

function csrf_field(): string
{
    return Csrf::field();
}

function app_url(string $path = ''): string
{
    $base = rtrim((string) ($GLOBALS['config']['app']['url'] ?? ''), '/');
    if ($base === '') {
        return $path;
    }

    return $base . '/' . ltrim($path, '/');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function setting(Database $db, string $key, mixed $default = null): mixed
{
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $value = $db->value('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
        $cache[$key] = $value === false ? $default : $value;
    }

    return $cache[$key];
}

function date_br(?string $date): string
{
    if (!$date) {
        return '—';
    }
    try {
        return (new DateTimeImmutable($date))->format('d/m/Y');
    } catch (Throwable) {
        return '—';
    }
}

function datetime_br(?string $date): string
{
    if (!$date) {
        return '—';
    }
    try {
        return (new DateTimeImmutable($date))->format('d/m/Y H:i');
    } catch (Throwable) {
        return '—';
    }
}

function status_label(string $status): string
{
    return [
        'active' => 'Ativo', 'inactive' => 'Inativo', 'lead' => 'Lead',
        'trial' => 'Teste', 'past_due' => 'Atrasada', 'paused' => 'Pausada', 'canceled' => 'Cancelada',
        'pending' => 'Pendente', 'paid' => 'Pago', 'failed' => 'Falhou', 'refunded' => 'Estornado',
    ][$status] ?? ucfirst($status);
}

function status_class(string $status): string
{
    return match ($status) {
        'active', 'paid' => 'success',
        'pending', 'trial', 'past_due' => 'warning',
        'failed', 'canceled', 'refunded' => 'danger',
        default => 'muted',
    };
}

function cycle_label(string $cycle): string
{
    return ['monthly' => 'Mensal', 'quarterly' => 'Trimestral', 'semiannual' => 'Semestral', 'annual' => 'Anual'][$cycle] ?? $cycle;
}

function audit(Database $db, string $action, string $entityType, ?int $entityId = null, array $details = []): void
{
    $db->query(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)',
        [$_SESSION['auth_user_id'] ?? null, $action, $entityType, $entityId, $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null, $_SERVER['REMOTE_ADDR'] ?? null]
    );
}

function period_dates(): array
{
    $today = new DateTimeImmutable('today');
    $period = $_GET['period'] ?? 'month';
    return match ($period) {
        'today' => [$today->format('Y-m-d'), $today->format('Y-m-d'), 'Hoje'],
        'quarter' => [$today->modify('first day of this month')->modify('-2 months')->format('Y-m-d'), $today->format('Y-m-d'), 'Últimos 3 meses'],
        'year' => [$today->format('Y-01-01'), $today->format('Y-m-d'), 'Este ano'],
        'custom' => [$_GET['from'] ?? $today->format('Y-m-01'), $_GET['to'] ?? $today->format('Y-m-d'), 'Período personalizado'],
        default => [$today->format('Y-m-01'), $today->format('Y-m-d'), 'Este mês'],
    };
}

function pagination(Database $db, string $countSql, string $dataSql, array $params = [], int $perPage = 15): array
{
    $page = max(1, (int) ($_GET['p'] ?? 1));
    $total = (int) $db->value($countSql, $params);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $rows = $db->fetchAll($dataSql . ' LIMIT ' . $perPage . ' OFFSET ' . $offset, $params);

    return compact('rows', 'total', 'pages', 'page');
}

function render_pagination(array $pagination): void
{
    if ($pagination['pages'] <= 1) {
        return;
    }
    $query = $_GET;
    echo '<nav class="pagination" aria-label="Paginação">';
    for ($i = 1; $i <= $pagination['pages']; $i++) {
        $query['p'] = $i;
        $class = $i === $pagination['page'] ? 'active' : '';
        echo '<a class="' . $class . '" href="?' . h(http_build_query($query)) . '">' . $i . '</a>';
    }
    echo '</nav>';
}
