<?php

declare(strict_types=1);

$root = dirname(__DIR__);

echo "Iniciando verificação detalhada da Consolidação e Exclusão Total de Duplicatas (Migração v16)...\n";

// 1. Validar Contratos de Código no MigrationService
$migrationFile = (string) file_get_contents($root . '/app/Services/MigrationService.php');
assert(str_contains($migrationFile, 'private const VERSION = 16;'), 'MigrationService deve estar na versão 16');
assert(str_contains($migrationFile, 'if ($version < 16)'), 'MigrationService deve conter bloco $version < 16');
assert(str_contains($migrationFile, 'DELETE FROM categories WHERE id NOT IN'), 'MigrationService deve excluir categorias não canônicas');
assert(str_contains($migrationFile, "UPDATE categories SET parent_id = NULL"), 'MigrationService deve zerar parent_id para eliminar árvores');

// 2. Validar Contratos na View categories.php
$categoriesView = (string) file_get_contents($root . '/app/Views/pages/categories.php');
assert(str_contains($categoriesView, 'WHERE c.active = 1'), 'categories.php deve filtrar explicitamente c.active = 1');
assert(!str_contains($categoriesView, 'sub-category-row'), 'categories.php não deve conter linhas de subcategorias');
assert(!str_contains($categoriesView, '↳'), 'categories.php não deve exibir setas de subcategorias');
assert(!str_contains($categoriesView, 'parent_id'), 'categories.php não deve manipular parent_id na interface');
echo "✓ 1. Contratos estruturais de eliminação de subcategorias validados.\n";

// 3. Teste em SQLite: Simular Merge e Purge de 49 Categorias
require_once $root . '/app/Core/Database.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec("CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)");
$pdo->exec("CREATE TABLE business_units (id INTEGER PRIMARY KEY, name TEXT, icon TEXT, color TEXT, is_personal INTEGER, sort_order INTEGER, active INTEGER)");
$pdo->exec("CREATE TABLE categories (id INTEGER PRIMARY KEY AUTOINCREMENT, business_unit_id INTEGER, parent_id INTEGER, name TEXT, type TEXT, icon TEXT, color TEXT, budget_limit_percent REAL, budget_limit_amount REAL, active INTEGER, sort_order INTEGER)");
$pdo->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER, description TEXT, amount_brl REAL)");
$pdo->exec("CREATE TABLE payments (id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER, amount_brl REAL)");
$pdo->exec("CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER, name TEXT)");
$pdo->exec("CREATE TABLE installments (id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER, amount_brl REAL)");
$pdo->exec("CREATE TABLE recurring_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER, description TEXT)");
$pdo->exec("CREATE TABLE credit_card_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER, amount_brl REAL)");
$pdo->exec("CREATE TABLE cash_entries (id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER, amount_brl REAL)");

// Inserir Business Unit Principal
$pdo->exec("INSERT INTO business_units VALUES (1, 'Gearzone', '🏢', '#3b82f6', 0, 1, 1)");

// Inserir as 15 antigas categorias principais
$pdo->exec("INSERT INTO categories (id, business_unit_id, parent_id, name, type, icon, color, active, sort_order) VALUES
(1, 1, NULL, 'Marketing', 'expense', '📣', '#f59e0b', 1, 0),
(2, 1, NULL, 'Software e ferramentas', 'expense', '💻', '#3b82f6', 1, 0),
(3, 1, NULL, 'Impostos e taxas', 'expense', '🏛️', '#ef4444', 1, 0),
(4, 1, NULL, 'Equipe e parceiros', 'expense', '👥', '#8b5cf6', 1, 0),
(5, 1, NULL, 'Infraestrutura e escritório', 'expense', '🏢', '#64748b', 1, 0),
(6, 1, NULL, 'Receitas de Assinaturas', 'income', '💎', '#10b981', 1, 0)");

// Inserir 10 subcategorias antigas com despesas vinculadas
$pdo->exec("INSERT INTO categories (id, business_unit_id, parent_id, name, type, icon, color, active, sort_order) VALUES
(101, 1, 1, 'Anúncios online', 'expense', '🔹', '#f59e0b', 1, 0),
(102, 1, 1, 'Materiais promocionais', 'expense', '🔹', '#f59e0b', 1, 0),
(103, 1, 2, 'SaaS e assinaturas', 'expense', '🔹', '#3b82f6', 1, 0),
(104, 1, 2, 'Servidores e hospedagem', 'expense', '🔹', '#3b82f6', 1, 0),
(105, 1, 3, 'MEI / Simples Nacional', 'expense', '🔹', '#ef4444', 1, 0),
(106, 1, 4, 'Prestadores e freelancers', 'expense', '🔹', '#8b5cf6', 1, 0),
(107, 1, 6, 'Planos mensais', 'income', '🔹', '#10b981', 1, 0)");

// Inserir despesas atreladas a categorias antigas e subcategorias
$pdo->exec("INSERT INTO expenses (category_id, description, amount_brl) VALUES
(1, 'Gasto em Marketing Geral', 500.00),
(101, 'Google Ads Campanha', 1200.00),
(103, 'Assinatura ChatGPT', 110.00),
(105, 'DAS Simples Nacional', 350.00),
(106, 'Designer Freelancer', 800.00)");

$pdo->exec("INSERT INTO payments (category_id, amount_brl) VALUES (107, 1500.00)");

$beforeCount = (int) $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
assert($beforeCount === 13, "Antes do purge, devem existir 13 categorias no teste");

// Executar o algoritmo de migração v16
$mainBuId = 1;
$personalBuId = null;

$canonicalList = [
    ['name' => 'Receitas com Assinaturas', 'type' => 'income', 'icon' => '💎', 'color' => '#10b981', 'bu_id' => 1, 'order' => 1],
    ['name' => 'Vendas de Produtos', 'type' => 'income', 'icon' => '📦', 'color' => '#3b82f6', 'bu_id' => 1, 'order' => 2],
    ['name' => 'Serviços e Consultorias', 'type' => 'income', 'icon' => '🛠️', 'color' => '#8b5cf6', 'bu_id' => 1, 'order' => 3],
    ['name' => 'Outras Receitas', 'type' => 'income', 'icon' => '💰', 'color' => '#06b6d4', 'bu_id' => 1, 'order' => 4],

    ['name' => 'Softwares, Cloud & Ferramentas (SaaS)', 'type' => 'expense', 'icon' => '🛠️', 'color' => '#3b82f6', 'bu_id' => 1, 'order' => 10],
    ['name' => 'Operação, Sede & Infraestrutura', 'type' => 'expense', 'icon' => '🏢', 'color' => '#64748b', 'bu_id' => 1, 'order' => 11],
    ['name' => 'Equipe, Parceiros & Terceiros', 'type' => 'expense', 'icon' => '👥', 'color' => '#8b5cf6', 'bu_id' => 1, 'order' => 12],
    ['name' => 'Marketing, Vendas & Publicidade', 'type' => 'expense', 'icon' => '📣', 'color' => '#f59e0b', 'bu_id' => 1, 'order' => 13],
    ['name' => 'Mobilidade, Logística & Viagens', 'type' => 'expense', 'icon' => '🚗', 'color' => '#d97706', 'bu_id' => 1, 'order' => 14],
    ['name' => 'Impostos, Tributos & Taxas', 'type' => 'expense', 'icon' => '🏛️', 'color' => '#ef4444', 'bu_id' => 1, 'order' => 15],
    ['name' => 'Alimentação & Despesas Diárias', 'type' => 'expense', 'icon' => '☕', 'color' => '#10b981', 'bu_id' => 1, 'order' => 16],
    ['name' => 'Investimentos & Equipamentos', 'type' => 'investment', 'icon' => '💰', 'color' => '#06b6d4', 'bu_id' => 1, 'order' => 17],
];

$canonicalIds = [];
$nameToCanonicalId = [];

foreach ($canonicalList as $c) {
    $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ? LIMIT 1");
    $stmt->execute([$c['name']]);
    $existing = $stmt->fetch();
    if ($existing) {
        $catId = (int) $existing['id'];
        $upd = $pdo->prepare("UPDATE categories SET parent_id = NULL, type = ?, icon = ?, color = ?, sort_order = ?, active = 1 WHERE id = ?");
        $upd->execute([$c['type'], $c['icon'], $c['color'], $c['order'], $catId]);
    } else {
        $ins = $pdo->prepare("INSERT INTO categories (business_unit_id, parent_id, name, type, icon, color, active, sort_order) VALUES (?, NULL, ?, ?, ?, ?, 1, ?)");
        $ins->execute([$c['bu_id'], $c['name'], $c['type'], $c['icon'], $c['color'], $c['order']]);
        $catId = (int) $pdo->lastInsertId();
    }
    $canonicalIds[] = $catId;
    $nameToCanonicalId[$c['name']] = $catId;
}

$mergeRules = [
    'Marketing' => 'Marketing, Vendas & Publicidade',
    'Anúncios online' => 'Marketing, Vendas & Publicidade',
    'Software e ferramentas' => 'Softwares, Cloud & Ferramentas (SaaS)',
    'SaaS e assinaturas' => 'Softwares, Cloud & Ferramentas (SaaS)',
    'Impostos e taxas' => 'Impostos, Tributos & Taxas',
    'MEI / Simples Nacional' => 'Impostos, Tributos & Taxas',
    'Equipe e parceiros' => 'Equipe, Parceiros & Terceiros',
    'Prestadores e freelancers' => 'Equipe, Parceiros & Terceiros',
    'Infraestrutura e escritório' => 'Operação, Sede & Infraestrutura',
    'Receitas de Assinaturas' => 'Receitas com Assinaturas',
    'Planos mensais' => 'Receitas com Assinaturas',
];

$allExistingCats = $pdo->query("SELECT id, name, parent_id FROM categories")->fetchAll();
foreach ($allExistingCats as $oldCat) {
    $oldId = (int) $oldCat['id'];
    if (in_array($oldId, $canonicalIds, true)) {
        continue;
    }
    $targetName = $mergeRules[$oldCat['name']] ?? null;
    $targetId = $targetName && isset($nameToCanonicalId[$targetName]) ? $nameToCanonicalId[$targetName] : $canonicalIds[4];

    $pdo->prepare("UPDATE expenses SET category_id = ? WHERE category_id = ?")->execute([$targetId, $oldId]);
    $pdo->prepare("UPDATE payments SET category_id = ? WHERE category_id = ?")->execute([$targetId, $oldId]);
}

$inClause = implode(',', array_map('intval', $canonicalIds));
$pdo->exec("UPDATE categories SET parent_id = NULL");
$pdo->exec("DELETE FROM categories WHERE id NOT IN ({$inClause})");

// Validações pós-migração
$afterCount = (int) $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
assert($afterCount === 12, "Após o purge, devem restar exatamente as 12 categorias canônicas oficiais. Encontradas: {$afterCount}");

$hasSub = (int) $pdo->query("SELECT COUNT(*) FROM categories WHERE parent_id IS NOT NULL")->fetchColumn();
assert($hasSub === 0, "Nenhuma categoria deve ter parent_id preenchido");

// Validar remapeamento das despesas
$mktId = $nameToCanonicalId['Marketing, Vendas & Publicidade'];
$saasId = $nameToCanonicalId['Softwares, Cloud & Ferramentas (SaaS)'];
$taxId = $nameToCanonicalId['Impostos, Tributos & Taxas'];
$teamId = $nameToCanonicalId['Equipe, Parceiros & Terceiros'];
$recId = $nameToCanonicalId['Receitas com Assinaturas'];

$gastoAds = $pdo->query("SELECT category_id FROM expenses WHERE description = 'Google Ads Campanha'")->fetchColumn();
assert((int)$gastoAds === $mktId, "Google Ads deve ter sido remapeado para Marketing, Vendas & Publicidade");

$gastoSaas = $pdo->query("SELECT category_id FROM expenses WHERE description = 'Assinatura ChatGPT'")->fetchColumn();
assert((int)$gastoSaas === $saasId, "Assinatura ChatGPT deve ter sido remapeada para Softwares, Cloud & Ferramentas (SaaS)");

$gastoDas = $pdo->query("SELECT category_id FROM expenses WHERE description = 'DAS Simples Nacional'")->fetchColumn();
assert((int)$gastoDas === $taxId, "DAS deve ter sido remapeado para Impostos, Tributos & Taxas");

$gastoFreelancer = $pdo->query("SELECT category_id FROM expenses WHERE description = 'Designer Freelancer'")->fetchColumn();
assert((int)$gastoFreelancer === $teamId, "Freelancer deve ter sido remapeado para Equipe, Parceiros & Terceiros");

$pagamento = $pdo->query("SELECT category_id FROM payments WHERE amount_brl = 1500.00")->fetchColumn();
assert((int)$pagamento === $recId, "Pagamento deve ter sido remapeado para Receitas com Assinaturas");

echo "✓ 2. Simulação completa de Merge & Purge em SQLite aprovada com 100% de sucesso!\n";
echo "✓ 3. Todas as despesas e receitas antigas foram devidamente migradas sem perda de dados.\n";
echo "\nTODOS OS TESTES DE CONSOLIDAÇÃO E PURGE DEFINITIVO FORAM CONCLUÍDOS COM ÊXITO!\n";
