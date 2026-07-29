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

function service_badge_icon_options(): array
{
    return [
        'sparkles' => 'Destaque',
        'diamond' => 'Diamante',
        'crown' => 'Coroa',
        'shield' => 'Proteção',
        'bolt' => 'Agilidade',
        'globe' => 'Global',
        'rocket' => 'Lançamento',
        'headset' => 'Suporte',
        'star' => 'Premium',
        'layers' => 'Camadas',
    ];
}

function service_badge_tone_options(): array
{
    return [
        'emerald' => 'Esmeralda',
        'gold' => 'Dourado',
        'sapphire' => 'Safira',
        'amethyst' => 'Ametista',
        'ruby' => 'Rubi',
        'graphite' => 'Grafite',
    ];
}

function service_badge_icon(string $icon): string
{
    $paths = [
        'sparkles' => '<path d="M12 2l1.45 4.55L18 8l-4.55 1.45L12 14l-1.45-4.55L6 8l4.55-1.45L12 2Z"/><path d="m19 14 .8 2.2L22 17l-2.2.8L19 20l-.8-2.2L16 17l2.2-.8L19 14ZM5 13l1.1 2.9L9 17l-2.9 1.1L5 21l-1.1-2.9L1 17l2.9-1.1L5 13Z"/>',
        'diamond' => '<path d="m4 8 4-5h8l4 5-8 13L4 8Z"/><path d="m8 3 4 18 4-18M4 8h16"/>',
        'crown' => '<path d="m3 6 4 4 5-7 5 7 4-4-2 12H5L3 6Z"/><path d="M5 18h14v3H5z"/>',
        'shield' => '<path d="M12 2 20 5v6c0 5.2-3.4 8.5-8 11-4.6-2.5-8-5.8-8-11V5l8-3Z"/><path d="m8.5 12 2.2 2.2 4.8-5"/>',
        'bolt' => '<path d="M13.5 2 5 13h6l-.5 9L19 10h-6l.5-8Z"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"/>',
        'rocket' => '<path d="M14 4c2.5-2 5.5-2 6-2 .1.5.1 3.5-2 6l-5 5-5-1-1-5 7-3Z"/><path d="m8 12-4 1-2 3 6 1 1 5 3-2 1-7M15 7h.01"/>',
        'headset' => '<path d="M4 14v-2a8 8 0 0 1 16 0v2"/><path d="M4 13h3v7H5a2 2 0 0 1-2-2v-3a2 2 0 0 1 1-2Zm16 0h-3v7h2a2 2 0 0 0 2-2v-3a2 2 0 0 0-1-2ZM17 20c-1 2-3 2-5 2"/>',
        'star' => '<path d="m12 2.5 2.9 5.9 6.5.9-4.7 4.6 1.1 6.5-5.8-3.1-5.8 3.1 1.1-6.5-4.7-4.6 6.5-.9L12 2.5Z"/>',
        'layers' => '<path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5M3 17l9 5 9-5"/>',
    ];
    $selected = $paths[$icon] ?? $paths['sparkles'];

    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . $selected . '</svg>';
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

function renewal_period_label(int|string|null $months, int|string|null $days = 0): string
{
    $months = max(0, (int) $months);
    $days = max(0, (int) $days);
    $parts = [];
    if ($months > 0) {
        $parts[] = $months . ' ' . ($months === 1 ? 'mês' : 'meses');
    }
    if ($days > 0) {
        $parts[] = $days . ' ' . ($days === 1 ? 'dia' : 'dias');
    }
    return $parts ? implode(' e ', $parts) : '—';
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
