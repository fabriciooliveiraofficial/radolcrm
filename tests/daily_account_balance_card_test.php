<?php

declare(strict_types=1);

echo "Iniciando verificação do Card de Saldo Atual em Conta (Imunidade a Filtros)...\n";

$root = dirname(__DIR__);

require_once $root . '/app/Core/Database.php';
require_once $root . '/app/Services/DailyFinanceService.php';

// 1. Verificar contratos na View financeiro.php
$view = file_get_contents($root . '/app/Views/pages/financeiro.php');
assert(str_contains($view, "Saldo Atual da Conta"), "Card deve ter o título 'Saldo Atual da Conta'");
assert(str_contains($view, "\$currentAccountBalance"), "View deve utilizar \$currentAccountBalance para o valor principal");
assert(str_contains($view, "\$projectedAccountBalance"), "View deve utilizar \$projectedAccountBalance para o valor projetado");
echo "✓ 1. View financeiro.php com os bindings corretos para Saldo Atual da Conta.\n";

// 2. Verificar métodos no DailyFinanceService
$serviceRef = new ReflectionClass(\App\Services\DailyFinanceService::class);
assert($serviceRef->hasMethod('currentAccountBalance'), "DailyFinanceService deve ter o método currentAccountBalance");
echo "✓ 2. DailyFinanceService contém método currentAccountBalance().\n";

// 3. Teste em SQLite Em Memória
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("
    CREATE TABLE daily_categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id INTEGER NULL,
        name VARCHAR(100) NOT NULL,
        type VARCHAR(20) NOT NULL DEFAULT 'expense',
        icon VARCHAR(30) NOT NULL DEFAULT '🏷️',
        color VARCHAR(20) NOT NULL DEFAULT '#2b826b',
        monthly_budget_limit DECIMAL(15,2) NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        active INTEGER NOT NULL DEFAULT 1
    );

    CREATE TABLE daily_credit_cards (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(120) NOT NULL,
        brand VARCHAR(60) NOT NULL DEFAULT 'Mastercard',
        credit_limit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        closing_day INTEGER NOT NULL DEFAULT 1,
        due_day INTEGER NOT NULL DEFAULT 10,
        color VARCHAR(30) NOT NULL DEFAULT '#6366f1',
        active INTEGER NOT NULL DEFAULT 1
    );

    CREATE TABLE daily_card_invoices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        card_id INTEGER NOT NULL,
        reference_month VARCHAR(7) NOT NULL,
        closing_date DATE NOT NULL,
        due_date DATE NOT NULL,
        total_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        payment_date DATE NULL
    );

    CREATE TABLE daily_transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type VARCHAR(20) NOT NULL,
        category_id INTEGER NULL,
        payee_id INTEGER NULL,
        payee_name VARCHAR(160) NOT NULL,
        description VARCHAR(255) NOT NULL,
        amount DECIMAL(15,2) NOT NULL,
        currency VARCHAR(3) NOT NULL DEFAULT 'BRL',
        original_amount DECIMAL(15,2) NULL,
        exchange_rate DECIMAL(10,4) NULL,
        payment_method VARCHAR(30) NOT NULL DEFAULT 'pix',
        card_id INTEGER NULL,
        invoice_id INTEGER NULL,
        installment_number INTEGER NOT NULL DEFAULT 1,
        total_installments INTEGER NOT NULL DEFAULT 1,
        transaction_date DATE NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'realized',
        notes TEXT NULL
    );

    CREATE TABLE daily_recurring_commitments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type VARCHAR(20) NOT NULL DEFAULT 'expense',
        category_id INTEGER NULL,
        payee_name VARCHAR(160) NOT NULL,
        description VARCHAR(255) NOT NULL,
        amount DECIMAL(15,2) NOT NULL,
        currency VARCHAR(3) NOT NULL DEFAULT 'BRL',
        original_amount DECIMAL(15,2) NULL,
        exchange_rate DECIMAL(10,4) NULL,
        recurrence VARCHAR(20) NOT NULL DEFAULT 'monthly',
        due_day INTEGER NOT NULL DEFAULT 10,
        start_date DATE NOT NULL,
        end_date DATE NULL,
        current_installment INTEGER NULL,
        total_installments INTEGER NULL,
        active INTEGER NOT NULL DEFAULT 1
    );
");

$dbRef = new ReflectionClass(\App\Core\Database::class);
$db = $dbRef->newInstanceWithoutConstructor();
$pdoProp = $dbRef->getProperty('pdo');
$pdoProp->setAccessible(true);
$pdoProp->setValue($db, $pdo);

$service = new \App\Services\DailyFinanceService($db);

$today = date('Y-m-d');
$pastDate = date('Y-m-d', strtotime('-10 days'));
$futureDate = date('Y-m-d', strtotime('+10 days'));

// Inserir movimentações:
// 1. Salário no passado: R$ 5.000,00 realizado
$pdo->exec("INSERT INTO daily_transactions (type, payee_name, description, amount, payment_method, transaction_date, status)
            VALUES ('income', 'Empresa XPTO', 'Salário', 5000.00, 'transfer', '{$pastDate}', 'realized')");

// 2. Aluguel no passado: R$ 1.500,00 realizado
$pdo->exec("INSERT INTO daily_transactions (type, payee_name, description, amount, payment_method, transaction_date, status)
            VALUES ('expense', 'Imobiliária', 'Aluguel', 1500.00, 'pix', '{$pastDate}', 'realized')");

// 3. Fatura de cartão passada paga: R$ 800,00
$pdo->exec("INSERT INTO daily_card_invoices (id, card_id, reference_month, closing_date, due_date, total_amount, status, payment_date)
            VALUES (1, 1, '2026-09', '{$pastDate}', '{$pastDate}', 800.00, 'paid', '{$pastDate}')");
$pdo->exec("INSERT INTO daily_transactions (type, payee_name, description, amount, payment_method, invoice_id, transaction_date, status)
            VALUES ('expense', 'Supermercado', 'Compras', 800.00, 'credit_card', 1, '{$pastDate}', 'realized')");

// 4. Compra em cartão com fatura futura em aberto (vencimento em +10 dias): R$ 600,00
// Não deve impactar o saldo atual bancário hoje!
$pdo->exec("INSERT INTO daily_card_invoices (id, card_id, reference_month, closing_date, due_date, total_amount, status)
            VALUES (2, 1, '2026-11', '{$today}', '{$futureDate}', 600.00, 'open')");
$pdo->exec("INSERT INTO daily_transactions (type, payee_name, description, amount, payment_method, invoice_id, transaction_date, status)
            VALUES ('expense', 'Loja Eletrônicos', 'Teclado', 600.00, 'credit_card', 2, '{$today}', 'realized')");

// 5. Receita pendente futura (a receber): R$ 1.000,00
$pdo->exec("INSERT INTO daily_transactions (type, payee_name, description, amount, payment_method, transaction_date, status)
            VALUES ('income', 'Cliente Freelance', 'Projeto Web', 1000.00, 'pix', '{$futureDate}', 'pending')");

// Saldo esperado da conta bancária HOJE:
// Receitas realizadas até hoje: R$ 5.000,00
// Despesas realizadas até hoje: R$ 1.500,00 (pix) + R$ 800,00 (fatura paga) = R$ 2.300,00
// Saldo atual em conta = 5000 - 2300 = 2.700,00!
$accountBal = $service->currentAccountBalance();
assert(abs($accountBal['current_balance'] - 2700.00) < 0.001, "Saldo atual da conta bancária deve ser exatamente R$ 2.700,00. Obtido: {$accountBal['current_balance']}");
echo "✓ 3. Saldo atual em conta bancária hoje calculado perfeitamente (R$ 2.700,00).\n";

// Saldo projetado esperado (total geral sem filtros):
// Receitas totais (5000 + 1000 = 6000)
// Despesas totais (1500 + 800 + 600 = 2900)
// Projetado = 6000 - 2900 = 3100.00!
assert(abs($accountBal['projected_balance'] - 3100.00) < 0.001, "Saldo projetado deve ser R$ 3.100,00. Obtido: {$accountBal['projected_balance']}");
echo "✓ 4. Saldo projetado calculado perfeitamente (R$ 3.100,00).\n";

// 5. TESTE DE IMUNIDADE A FILTROS:
// A) Filtro de data: apenas hoje (não pega o passado)
$filterToday = $service->summary($today, $today);
assert(abs($filterToday['current_balance'] - 2700.00) < 0.001, "current_balance deve ser imune ao filtro de data!");
assert($filterToday['net_balance'] != 2700.00, "net_balance do período deve refletir apenas o período filtrado.");

// B) Filtro por tipo: apenas despesas
$filterExpense = $service->summary('2026-01-01', '2026-12-31', '', 'expense');
assert(abs($filterExpense['current_balance'] - 2700.00) < 0.001, "current_balance deve ser imune ao filtro de tipo!");

// C) Filtro por método: apenas PIX
$filterPix = $service->summary('2026-01-01', '2026-12-31', '', '', 'pix');
assert(abs($filterPix['current_balance'] - 2700.00) < 0.001, "current_balance deve ser imune ao filtro de forma de pagamento!");

// D) Filtro por busca textual: "Inexistente"
$filterSearch = $service->summary('2026-01-01', '2026-12-31', 'TermoTotalmenteInexistente');
assert(abs($filterSearch['current_balance'] - 2700.00) < 0.001, "current_balance deve ser imune ao filtro de pesquisa!");
assert($filterSearch['total_income'] == 0.0 && $filterSearch['total_expense'] == 0.0, "Filtro de busca deve zerar os totais filtrados do período");

echo "✓ 5. Imunidade total a filtros comprovada com sucesso em todas as dimensões de filtro!\n";
echo "\n🎉 TODOS OS TESTES DO CARD DE SALDO ATUAL PASSARAM COM 100% DE SUCESSO!\n";
