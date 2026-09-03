<?php
declare(strict_types=1);

echo "Iniciando verificação de Automação e Isolamento de Unidade de Negócio por Página...\n";

$root = dirname(__DIR__);
$views = [
    'expenses' => $root . '/app/Views/pages/expenses.php',
    'recurring' => $root . '/app/Views/pages/recurring.php',
    'cards' => $root . '/app/Views/pages/cards.php',
    'cash' => $root . '/app/Views/pages/cash.php',
    'payments' => $root . '/app/Views/pages/payments.php',
    'products' => $root . '/app/Views/pages/products.php',
    'clients' => $root . '/app/Views/pages/clients.php',
    'categories' => $root . '/app/Views/pages/categories.php',
];

foreach ($views as $name => $path) {
    assert(file_exists($path), "View {$name} não encontrada em {$path}");
}

// 1. expenses.php
$expensesContent = file_get_contents($views['expenses']);
assert(str_contains($expensesContent, "new=1<?= \$buFilter ? '&bu=' . (int)\$buFilter : '' ?>"), "Botão novo gasto deve preservar buFilter");
assert(str_contains($expensesContent, "🔒 Automático & Travado"), "expenses.php deve exibir badge Automático & Travado quando buFilter estiver ativo");
assert(str_contains($expensesContent, 'input type="hidden" name="business_unit_id"'), "expenses.php deve travar business_unit_id via hidden input no modal");
assert(str_contains($expensesContent, "?page=expenses<?= \$buFilter ? '&bu=' . (int)\$buFilter : '' ?>"), "expenses.php deve preservar buFilter no Cancelar/Backdrop");
echo "✓ 1. Automação e travamento em Gastos (expenses.php) validado.\n";

// 2. recurring.php
$recurringContent = file_get_contents($views['recurring']);
assert(str_contains($recurringContent, "new=1<?= \$buFilter ? '&bu=' . (int)\$buFilter : '' ?>"), "Botão nova recorrência deve preservar buFilter");
assert(str_contains($recurringContent, "🔒 Automático & Travado"), "recurring.php deve exibir badge Automático & Travado quando buFilter estiver ativo");
assert(str_contains($recurringContent, 'input type="hidden" name="business_unit_id"'), "recurring.php deve travar business_unit_id via hidden input no modal");
assert(str_contains($recurringContent, "?page=recurring<?= \$buFilter ? '&bu=' . (int)\$buFilter : '' ?>"), "recurring.php deve preservar buFilter no Cancelar/Backdrop");
echo "✓ 2. Automação e travamento em Recorrências (recurring.php) validado.\n";

// 3. cards.php
$cardsContent = file_get_contents($views['cards']);
assert(str_contains($cardsContent, "new_tx=1<?= \$buFilter ? '&bu=' . (int)\$buFilter : '' ?>"), "Botão nova compra no cartão deve preservar buFilter");
assert(str_contains($cardsContent, "🔒 Automático & Travado"), "cards.php deve exibir badge Automático & Travado quando buFilter estiver ativo");
assert(str_contains($cardsContent, 'input type="hidden" name="business_unit_id"'), "cards.php deve travar business_unit_id via hidden input no modal");
echo "✓ 3. Automação e travamento em Cartões (cards.php) validado.\n";

// 4. cash.php
$cashContent = file_get_contents($views['cash']);
assert(str_contains($cashContent, "new=1<?= \$buFilter ? '&bu=' . (int)\$buFilter : '' ?>"), "Botão movimento avulso deve preservar buFilter");
assert(str_contains($cashContent, "🔒 Automático & Travado"), "cash.php deve exibir badge Automático & Travado quando buFilter estiver ativo");
assert(str_contains($cashContent, 'input type="hidden" name="business_unit_id"'), "cash.php deve travar business_unit_id via hidden input no modal");
assert(str_contains($cashContent, "?page=cash<?= \$buFilter ? '&bu=' . (int)\$buFilter : '' ?>"), "cash.php deve preservar buFilter no Cancelar/Backdrop");
echo "✓ 4. Automação e travamento em Caixa (cash.php) validado.\n";

// 5. payments.php
$paymentsContent = file_get_contents($views['payments']);
assert(str_contains($paymentsContent, "new=1<?= \$buFilter ? '&bu=' . (int)\$buFilter : '' ?>"), "Botão novo pagamento deve preservar buFilter");
assert(str_contains($paymentsContent, "🔒 Automático & Travado"), "payments.php deve exibir badge Automático & Travado quando buFilter estiver ativo");
assert(str_contains($paymentsContent, "?page=payments<?= \$buFilter ? '&bu=' . (int)\$buFilter : '' ?>"), "payments.php deve preservar buFilter no Cancelar/Backdrop");
echo "✓ 5. Automação e travamento em Pagamentos (payments.php) validado.\n";

// 6. products.php
$productsContent = file_get_contents($views['products']);
assert(str_contains($productsContent, "new=1<?= \$buFilter ? '&bu=' . (int)\$buFilter : '' ?>"), "Botão novo produto deve preservar buFilter");
assert(str_contains($productsContent, "🔒 Automático & Travado"), "products.php deve exibir badge Automático & Travado quando buFilter estiver ativo");
assert(str_contains($productsContent, "?page=products<?= \$buFilter ? '&bu=' . (int)\$buFilter : '' ?>"), "products.php deve preservar buFilter no Cancelar/Backdrop");
echo "✓ 6. Automação e travamento em Produtos (products.php) validado.\n";

// 7. clients.php
$clientsContent = file_get_contents($views['clients']);
assert(str_contains($clientsContent, "new=1<?= \$buFilter ? '&bu=' . (int)\$buFilter : '' ?>"), "Botão novo cliente deve preservar buFilter");
assert(str_contains($clientsContent, "🔒 Automático & Travado"), "clients.php deve exibir badge Automático & Travado quando buFilter estiver ativo");
assert(str_contains($clientsContent, "?page=clients<?= \$buFilter ? '&bu=' . (int)\$buFilter : '' ?>"), "clients.php deve preservar buFilter no Cancelar/Backdrop");
echo "✓ 7. Automação e travamento em Clientes (clients.php) validado.\n";

// 8. ActionHandler.php businessUnitId method
$actionHandlerContent = file_get_contents($root . '/app/Http/ActionHandler.php');
assert(str_contains($actionHandlerContent, 'function businessUnitId()'), "ActionHandler deve implementar helper businessUnitId()");
echo "✓ 8. Recuperação resiliente de business_unit_id em ActionHandler validada.\n";

echo "\nTODOS OS TESTES DE AUTOMAÇÃO E ISOLAMENTO DE UNIDADES DE NEGÓCIO PASSARAM COM SUCESSO!\n";
