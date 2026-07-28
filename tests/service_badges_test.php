<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$schema = (string) file_get_contents($root . '/database/schema.sql');
$migration = (string) file_get_contents($root . '/app/Services/MigrationService.php');
$migrationSql = (string) file_get_contents($root . '/database/migrations/006_service_badges.sql');
$actions = (string) file_get_contents($root . '/app/Http/ActionHandler.php');
$subscriptions = (string) file_get_contents($root . '/app/Views/pages/subscriptions.php');
$badgePage = (string) file_get_contents($root . '/app/Views/pages/service-badges.php');
$helpers = (string) file_get_contents($root . '/app/Support/helpers.php');
$index = (string) file_get_contents($root . '/index.php');
$layout = (string) file_get_contents($root . '/app/Views/layout.php');
$css = (string) file_get_contents($root . '/assets/css/app.css');

$contracts = [
    'schema de badges' => str_contains($schema, 'CREATE TABLE IF NOT EXISTS service_badges')
        && str_contains($schema, 'CREATE TABLE IF NOT EXISTS subscription_service_badges')
        && str_contains($schema, "('schema_version', '6')"),
    'migração automática v6' => str_contains($migration, 'private const VERSION = 6')
        && str_contains($migration, 'if ($version < 6)')
        && str_contains($migrationSql, 'fk_subscription_badges_badge'),
    'rota e navegação da biblioteca' => str_contains($index, "'service-badges'")
        && str_contains($layout, '?page=service-badges')
        && str_contains($badgePage, 'Biblioteca de badges'),
    'ícones seguros e estilos premium' => str_contains($helpers, 'function service_badge_icon_options')
        && str_contains($helpers, 'function service_badge_icon')
        && str_contains($helpers, "'diamond'")
        && str_contains($helpers, "'crown'")
        && str_contains($helpers, "'shield'")
        && str_contains($helpers, "'graphite'"),
    'cadastro e exclusão de badges' => str_contains($actions, "'save_service_badge' =>")
        && str_contains($actions, "'delete_service_badge' =>")
        && str_contains($badgePage, 'name="icon"')
        && str_contains($badgePage, 'name="tone"'),
    'associação muitos para muitos' => str_contains($actions, 'postedServiceBadgeIds')
        && str_contains($actions, 'syncSubscriptionBadges')
        && str_contains($subscriptions, 'name="badge_ids[]"'),
    'badges exibidos nas assinaturas' => str_contains($subscriptions, 'serviceBadgesBySubscription')
        && str_contains($subscriptions, 'service-badge compact')
        && str_contains($subscriptions, 'service_badge_icon($serviceBadge'),
    'busca inteligente inclui badges' => str_contains($subscriptions, 'search_badge.name LIKE ?')
        && str_contains($subscriptions, 'subscription_service_badges search_ssb')
        && str_contains($subscriptions, 'filter_ssb.badge_id=?')
        && str_contains($subscriptions, 'name="badge"'),
    'interface responsiva dos badges' => str_contains($css, '.service-badge-grid')
        && str_contains($css, '.subscription-badge-options')
        && str_contains($css, '.badge-icon-options'),
];

$failed = array_keys(array_filter($contracts, static fn(bool $passed): bool => !$passed));
if ($failed) {
    fwrite(STDERR, 'Falharam: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

function h(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function csrf_field(): string { return '<input type="hidden" name="_token" value="test">'; }
function service_badge_icon_options(): array { return ['sparkles'=>'Destaque','diamond'=>'Diamante']; }
function service_badge_tone_options(): array { return ['emerald'=>'Esmeralda','gold'=>'Dourado']; }
function service_badge_icon(string $icon): string { return '<svg data-icon="' . h($icon) . '"></svg>'; }

$badge = ['id'=>3,'name'=>'Suporte premium','icon'=>'diamond','tone'=>'gold','active'=>1,'subscription_count'=>2];
$db = new class($badge) {
    public function __construct(private array $badge) {}
    public function fetchAll(string $sql, array $params = []): array { return [$this->badge]; }
    public function fetch(string $sql, array $params = []): ?array { return null; }
};
$auth = new class { public function canWrite(): bool { return true; } };
$_GET = ['new'=>'1'];
$_SERVER['REQUEST_URI'] = '?page=service-badges&new=1';
ob_start();
require $root . '/app/Views/pages/service-badges.php';
$html = (string) ob_get_clean();
foreach (['Suporte premium','data-icon="diamond"','name="icon"','name="tone"','save_service_badge'] as $check) {
    if (!str_contains($html, $check)) {
        fwrite(STDERR, "Falha ao renderizar biblioteca de badges: {$check}\n");
        exit(1);
    }
}

echo count($contracts) . " contratos e a renderização dos badges passaram.\n";
