<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Services/ExchangeRateService.php';

use App\Core\Database;
use App\Services\ExchangeRateService;

echo "Iniciando testes de Cotação Bidirecional (USD ⇄ BRL)...\n";

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec("CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)");
$pdo->exec("CREATE TABLE exchange_rates (id INTEGER PRIMARY KEY AUTOINCREMENT, base_currency TEXT, quote_currency TEXT, bid REAL, ask REAL, source TEXT, quoted_at TEXT, created_at TEXT)");

$pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('exchange_cache_minutes', '720'), ('manual_exchange_rate', '5.20')");
$pdo->exec("INSERT INTO exchange_rates (base_currency, quote_currency, bid, ask, source, quoted_at, created_at) VALUES ('USD', 'BRL', 5.1151, 5.1151, 'Frankfurter · diária', '2026-09-03 12:00:00', '" . date('Y-m-d H:i:s') . "')");

$ref = new ReflectionClass(Database::class);
$db = $ref->newInstanceWithoutConstructor();
$prop = $ref->getProperty('pdo');
$prop->setAccessible(true);
$prop->setValue($db, $pdo);

$service = new ExchangeRateService($db);

// 1. Teste de consulta em USD
$quoteUsd = $service->forDate(date('Y-m-d'), false, 'USD', 'BRL');
assert(isset($quoteUsd['bid']), 'Quote USD deve conter bid');
assert($quoteUsd['bid'] > 2.0, 'Taxa USD deve ser maior que 2 (esperado ~5)');
assert($quoteUsd['base_currency'] === 'USD', 'Base deve ser USD');
assert($quoteUsd['quote_currency'] === 'BRL', 'Quote deve ser BRL');
assert(isset($quoteUsd['usd_to_brl']), 'Quote USD deve conter usd_to_brl');
assert(isset($quoteUsd['brl_to_usd']), 'Quote USD deve conter brl_to_usd');
echo "✓ 1. Consulta de câmbio USD → BRL validada com sucesso: 1 USD = {$quoteUsd['bid']} BRL.\n";

// 2. Teste de consulta em BRL
$quoteBrl = $service->forDate(date('Y-m-d'), false, 'BRL', 'USD');
assert(isset($quoteBrl['bid']), 'Quote BRL deve conter bid');
assert($quoteBrl['bid'] > 0.05 && $quoteBrl['bid'] < 1.0, 'Taxa BRL deve ser menor que 1 (esperado ~0.19)');
assert($quoteBrl['base_currency'] === 'BRL', 'Base deve ser BRL');
assert($quoteBrl['quote_currency'] === 'USD', 'Quote deve ser USD');
assert(abs(($quoteBrl['usd_to_brl'] * $quoteBrl['brl_to_usd']) - 1.0) < 0.001, 'As taxas direta e inversa devem ser matematicamente coerentes');
echo "✓ 2. Consulta de câmbio BRL → USD validada com sucesso: 1 BRL = {$quoteBrl['bid']} USD.\n";

// 3. Validação dos contratos nas Views
$views = [
    'expenses' => file_get_contents(__DIR__ . '/../app/Views/pages/expenses.php'),
    'payments' => file_get_contents(__DIR__ . '/../app/Views/pages/payments.php'),
    'cash' => file_get_contents(__DIR__ . '/../app/Views/pages/cash.php'),
];

foreach ($views as $name => $content) {
    assert(str_contains($content, 'data-rate-title'), "View {$name} deve conter span[data-rate-title] para troca dinâmica de label");
    assert(str_contains($content, 'data-preview-label'), "View {$name} deve conter span[data-preview-label] para troca dinâmica do resumo");
    echo "✓ 3. Contratos de View dinâmicos validados para {$name}.php.\n";
}

// 4. Validação dos contratos do JavaScript
$jsContent = file_get_contents(__DIR__ . '/../assets/js/app.js');
assert(str_contains($jsContent, 'formatUsd'), 'app.js deve implementar formatUsd');
assert(str_contains($jsContent, 'Cotação BRL → USD'), 'app.js deve atualizar label para Cotação BRL → USD');
assert(str_contains($jsContent, 'Total convertido em USD'), 'app.js deve atualizar preview para Total convertido em USD');
assert(!str_contains($jsContent, "rate.value = '1'"), 'app.js não deve mais forçar rate.value = 1 ao selecionar BRL');
assert(str_contains($jsContent, 'currency=${encodeURIComponent(currency.value)}'), 'app.js deve enviar moeda ativa na consulta');
echo "✓ 4. Contratos de automação e conversão em app.js validados com sucesso.\n";

// 5. Validação de persistência matemática em ActionHandler
$actionHandler = file_get_contents(__DIR__ . '/../app/Http/ActionHandler.php');
assert(str_contains($actionHandler, "\$amountBrl = \$currency === 'USD' ? round(\$amount * \$rate, 2) : round(\$amount, 2);"), 'ActionHandler deve calcular amount_brl considerando se moeda é USD ou BRL');
echo "✓ 5. Contratos de cálculo no ActionHandler validados com sucesso.\n";

echo "\nTODOS OS TESTES DE COTAÇÃO BIDIRECIONAL PASSARAM COM SUCESSO!\n";
