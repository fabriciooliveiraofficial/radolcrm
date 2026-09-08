<?php

declare(strict_types=1);

$root = dirname(__DIR__);

echo "Iniciando verificação de contratos do Extrato Detalhado estilo QuickBooks e Relatórios...\n";

// 1. FinanceService detailedStatement contract check
$financeFile = (string) file_get_contents($root . '/app/Services/FinanceService.php');
assert(str_contains($financeFile, 'function detailedStatement'), 'FinanceService deve conter método detailedStatement');
assert(str_contains($financeFile, 'running_balance'), 'detailedStatement deve calcular running_balance');
assert(str_contains($financeFile, 'opening_balance'), 'detailedStatement deve calcular opening_balance');
assert(str_contains($financeFile, 'closing_balance'), 'detailedStatement deve calcular closing_balance');
echo "✓ 1. Métodos e contratos de FinanceService validados.\n";

// 2. Reports view contract check
$reportsView = (string) file_get_contents($root . '/app/Views/pages/reports.php');
assert(str_contains($reportsView, 'detailedStatement'), 'reports.php deve chamar detailedStatement');
assert(str_contains($reportsView, 'Extrato Geral de Lançamentos'), 'reports.php deve conter aba de Extrato Geral de Lançamentos');
assert(str_contains($reportsView, 'LIVRO RAZÃO & EXTRATO BANCÁRIO DETALHADO'), 'reports.php deve conter título do extrato bancário');
assert(str_contains($reportsView, 'Saldo Acumulado'), 'reports.php deve conter coluna de Saldo Acumulado');
assert(!str_contains($reportsView, 'p.payment_date) ELSE payment_date'), 'reports.php não pode conter p.payment_date sem alias na consulta de moedas');
echo "✓ 2. View reports.php e ausência de bugs SQL validados.\n";

// 3. Exporter contract check
$exporterFile = (string) file_get_contents($root . '/app/Http/Exporter.php');
assert(str_contains($exporterFile, "'statement' =>"), 'Exporter.php deve implementar exportação de extrato tipo statement');
assert(str_contains($exporterFile, 'extrato-financeiro'), 'Exporter.php deve gerar arquivo com prefixo extrato-financeiro');
echo "✓ 3. Contratos de exportação CSV no Exporter.php validados.\n";

// 4. Test detailedStatement logic with SQLite in-memory PDO
require_once $root . '/app/Core/Database.php';
require_once $root . '/app/Services/FinanceService.php';

$dbRef = (new ReflectionClass(\App\Core\Database::class))->newInstanceWithoutConstructor();
$pdoProp = (new ReflectionClass(\App\Core\Database::class))->getProperty('pdo');
$pdoProp->setAccessible(true);
$sqlite = new PDO('sqlite::memory:');
$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$sqlite->exec("CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)");
$sqlite->exec("INSERT INTO settings VALUES ('initial_balance_brl', '1000.00')");

$sqlite->exec("CREATE TABLE categories (id INTEGER PRIMARY KEY, business_unit_id INTEGER, parent_id INTEGER, name TEXT, icon TEXT, color TEXT, active INTEGER, type TEXT)");
$sqlite->exec("CREATE TABLE business_units (id INTEGER PRIMARY KEY, name TEXT, icon TEXT, color TEXT)");
$sqlite->exec("CREATE TABLE clients (id INTEGER PRIMARY KEY, name TEXT)");
$sqlite->exec("CREATE TABLE payments (id INTEGER PRIMARY KEY, client_id INTEGER, category_id INTEGER, business_unit_id INTEGER, description TEXT, amount REAL, amount_brl REAL, net_brl REAL, currency TEXT, payment_method TEXT, status TEXT, payment_date TEXT, settlement_date TEXT)");
$sqlite->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY, category_id INTEGER, business_unit_id INTEGER, type TEXT, description TEXT, supplier TEXT, amount_brl REAL, currency TEXT, status TEXT, payment_date TEXT, notes TEXT)");
$sqlite->exec("CREATE TABLE cash_entries (id INTEGER PRIMARY KEY, category_id INTEGER, business_unit_id INTEGER, direction TEXT, category TEXT, description TEXT, amount_brl REAL, currency TEXT, entry_date TEXT, notes TEXT)");
$sqlite->exec("CREATE TABLE daily_transactions (id INTEGER PRIMARY KEY, category_id INTEGER, invoice_id INTEGER, type TEXT, payee_name TEXT, description TEXT, amount REAL, payment_method TEXT, transaction_date TEXT, status TEXT, notes TEXT)");
$sqlite->exec("CREATE TABLE daily_categories (id INTEGER PRIMARY KEY, parent_id INTEGER, business_unit_id INTEGER, name TEXT, icon TEXT, color TEXT)");
$sqlite->exec("CREATE TABLE daily_card_invoices (id INTEGER PRIMARY KEY, payment_date TEXT, due_date TEXT)");

// Insert test entries
$sqlite->exec("INSERT INTO clients VALUES (1, 'Cliente Teste SP')");
$sqlite->exec("INSERT INTO payments VALUES (1, 1, NULL, NULL, 'Fatura Setembro', 2500.00, 2500.00, 2400.00, 'BRL', 'pix', 'paid', '2026-09-02', NULL)");
$sqlite->exec("INSERT INTO expenses VALUES (1, NULL, NULL, 'expense', 'Hospedagem Server', 'AWS', 400.00, 'BRL', 'paid', '2026-09-03', NULL)");
$sqlite->exec("INSERT INTO cash_entries VALUES (1, NULL, NULL, 'in', 'Avulso', 'Aporte Capital', 1000.00, 'BRL', '2026-09-05', NULL)");
$sqlite->exec("INSERT INTO daily_transactions VALUES (1, NULL, NULL, 'expense', 'Supermercado', 'Compras do mês', 300.00, 'pix', '2026-09-06', 'realized', NULL)");

$pdoProp->setValue($dbRef, $sqlite);
$finance = new \App\Services\FinanceService($dbRef);
$stmt = $finance->detailedStatement('2026-09-01', '2026-09-30');

assert($stmt['opening_balance'] === 1000.00, 'Saldo inicial antes de 01/09 deve ser 1000.00');
assert($stmt['total_in'] === 3400.00, 'Total de entradas deve ser 2400 (payments) + 1000 (cash) = 3400');
assert($stmt['total_out'] === 700.00, 'Total de saídas deve ser 400 (expenses) + 300 (daily) = 700');
assert($stmt['net_period'] === 2700.00, 'Resultado do período deve ser 3400 - 700 = 2700');
assert($stmt['closing_balance'] === 3700.00, 'Saldo final acumulado deve ser 1000 + 2700 = 3700');
assert(count($stmt['items']) === 4, 'Deve conter 4 movimentações listadas');

// Verify running balance chronologically:
// 02/09: +2400 -> 3400
// 03/09: -400  -> 3000
// 05/09: +1000 -> 4000
// 06/09: -300  -> 3700
// Items presentation order is DESC (most recent first):
assert($stmt['items'][0]['running_balance'] === 3700.00, 'Item mais recente (06/09) deve ter saldo acumulado 3700');
assert($stmt['items'][3]['running_balance'] === 3400.00, 'Item mais antigo (02/09) deve ter saldo acumulado 3400');

echo "✓ 4. Cálculo matemático e saldo acumulado linha a linha validados com sucesso!\n";
echo "\nTODOS OS TESTES DO EXTRATO DETALHADO ESTILO QUICKBOOKS PASSARAM COM SUCESSO!\n";
