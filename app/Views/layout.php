<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#102a2b"><title><?= h($pageTitles[$page][0]) ?> · <?= h($config['app']['name']) ?></title>
    <link rel="icon" href="assets/images/favicon.svg?v=1" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css?v=21">
</head>
<body class="app-body">
<aside class="sidebar" id="sidebar">
    <a class="brand brand-light" href="?page=dashboard"><span class="brand-mark">N</span><span><b>Nexo</b><small>GESTÃO</small></span></a>
    <nav class="main-nav" aria-label="Navegação principal">
        <p>VISÃO GERAL</p>
        <a class="<?= $page === 'dashboard' ? 'active' : '' ?>" href="?page=dashboard"><span>⌂</span> Dashboard</a>
        <a class="<?= $page === 'agenda' ? 'active' : '' ?>" href="?page=agenda"><span>📅</span> Agenda Financeira</a>
        <a class="<?= $page === 'categories' ? 'active' : '' ?>" href="?page=categories"><span>📁</span> Categorias</a>
        <a class="<?= $page === 'reports' ? 'active' : '' ?>" href="?page=reports"><span>⌁</span> Relatórios</a>
        <a class="<?= $page === 'service-badges' ? 'active' : '' ?>" href="?page=service-badges"><span>✦</span> Badges de serviços</a>
        <a class="<?= $page === 'reminders' ? 'active' : '' ?>" href="?page=reminders"><span>◉</span> Lembretes WhatsApp</a>
        <a class="<?= $page === 'businesses' ? 'active' : '' ?>" href="?page=businesses"><span>💼</span> Gerenciar Negócios</a>

        <p>MEUS NEGÓCIOS</p>
        <?php foreach ($sidebarBusinesses as $bu): 
            $isOpen = $selectedBusiness && $selectedBusiness['id'] === $bu['id'];
        ?>
        <details class="nav-business" <?= $isOpen ? 'open' : '' ?> style="--bu-color: <?= h($bu['color']) ?>;">
            <summary>
                <div class="bu-icon" style="color: <?= h($bu['color']) ?>; background: <?= h($bu['color']) ?>22; border-color: <?= h($bu['color']) ?>55;">
                    <?= h($bu['icon']) ?>
                </div>
                <b><?= h($bu['name']) ?></b>
                <span class="chevron">▾</span>
            </summary>
            <div class="sub-nav">
                <a class="<?= $isOpen && $page === 'clients' ? 'active' : '' ?>" href="?page=clients&bu=<?= (int)$bu['id'] ?>"><span>♙</span> Clientes</a>
                <a class="<?= $isOpen && $page === 'products' ? 'active' : '' ?>" href="?page=products&bu=<?= (int)$bu['id'] ?>"><span>◇</span> Produtos</a>
                <a class="<?= $isOpen && $page === 'subscriptions' ? 'active' : '' ?>" href="?page=subscriptions&bu=<?= (int)$bu['id'] ?>"><span>↻</span> Assinaturas</a>
                <a class="<?= $isOpen && $page === 'payments' ? 'active' : '' ?>" href="?page=payments&bu=<?= (int)$bu['id'] ?>"><span>↓</span> Pagamentos</a>
                <a class="<?= $isOpen && $page === 'expenses' ? 'active' : '' ?>" href="?page=expenses&bu=<?= (int)$bu['id'] ?>"><span>↑</span> Gastos</a>
                <a class="<?= $isOpen && $page === 'recurring' ? 'active' : '' ?>" href="?page=recurring&bu=<?= (int)$bu['id'] ?>"><span>🔁</span> Recorrências</a>
                <a class="<?= $isOpen && $page === 'cards' ? 'active' : '' ?>" href="?page=cards&bu=<?= (int)$bu['id'] ?>"><span>💳</span> Cartões</a>
                <a class="<?= $isOpen && $page === 'cash' ? 'active' : '' ?>" href="?page=cash&bu=<?= (int)$bu['id'] ?>"><span>▤</span> Fluxo de caixa</a>
            </div>
        </details>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-bottom"><a class="<?= $page === 'settings' ? 'active' : '' ?>" href="?page=settings"><span>⚙</span> Configurações</a><a href="?logout=1"><span>↪</span> Sair</a></div>
</aside>
<div class="app-shell">
    <header class="topbar">
        <button class="icon-button menu-button" type="button" data-menu aria-label="Abrir menu">☰</button>
        <div class="page-heading">
            <h1>
                <?php if ($selectedBusiness): ?>
                    <span class="bu-badge" style="background: <?= h($selectedBusiness['color']) ?>22; color: <?= h($selectedBusiness['color']) ?>; border: 1px solid <?= h($selectedBusiness['color']) ?>55;">
                        <?= h($selectedBusiness['icon']) ?>
                    </span>
                <?php endif; ?>
                <?= h($pageTitles[$page][0]) ?>
            </h1>
            <p><?= h($pageTitles[$page][1]) ?></p>
        </div>
        <div class="top-actions"><?php if($auth->canWrite()): ?><a class="quick-add" href="?page=payments&new=1<?= $selectedBusiness ? '&bu=' . $selectedBusiness['id'] : '' ?>">＋ <span>Novo pagamento</span></a><?php endif; ?><div class="user-menu"><span><?= h(mb_strtoupper(mb_substr($auth->user()['name'], 0, 1))) ?></span><div><b><?= h($auth->user()['name']) ?></b><small><?= h(ucfirst($auth->user()['role'])) ?></small></div></div></div>
    </header>
    <main class="content">
        <?php if (isset($migrationError) && $migrationError && $auth->isAdmin()): ?><div class="inline-warning">A atualização do banco está pendente. O CRM continua disponível e tentará concluir novamente no próximo acesso. Código: <?= h(substr(hash('sha256', $migrationError->getMessage()), 0, 8)) ?>.</div><?php endif; ?>
        <?php if($messages): ?><div class="toast-stack" aria-live="polite" aria-atomic="true"><?php foreach ($messages as $message): $toastTitle=['success'=>'Tudo certo','danger'=>'Não foi possível','warning'=>'Atenção'][$message['type']]??'Informação'; ?><div class="toast <?= h($message['type']) ?>" data-toast role="status"><span class="toast-icon"><?= $message['type']==='success'?'✓':($message['type']==='danger'?'!':'i') ?></span><div><b><?= h($toastTitle) ?></b><p><?= h($message['message']) ?></p></div><button type="button" aria-label="Fechar notificação">×</button><i class="toast-progress"></i></div><?php endforeach; ?></div><?php endif; ?>
        <?php if (is_file($viewFile)) require $viewFile; else require __DIR__ . '/pages/404.php'; ?>
    </main>
</div>
<div class="sidebar-backdrop" data-menu></div>
<script>window.NEXO = {baseUrl: <?= json_encode(rtrim($config['app']['url'], '/')) ?>};</script>
<script src="assets/js/app.js?v=16"></script>
</body></html>
