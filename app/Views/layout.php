<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#102a2b"><title><?= h($pageTitles[$page][0]) ?> · <?= h($config['app']['name']) ?></title>
    <link rel="stylesheet" href="assets/css/app.css?v=8">
</head>
<body class="app-body">
<aside class="sidebar" id="sidebar">
    <a class="brand brand-light" href="?page=dashboard"><span class="brand-mark">N</span><span><b>Nexo</b><small>GESTÃO</small></span></a>
    <nav class="main-nav" aria-label="Navegação principal">
        <p>VISÃO GERAL</p>
        <a class="<?= $page === 'dashboard' ? 'active' : '' ?>" href="?page=dashboard"><span>⌂</span> Dashboard</a>
        <a class="<?= $page === 'reports' ? 'active' : '' ?>" href="?page=reports"><span>⌁</span> Relatórios</a>
        <p>OPERAÇÃO</p>
        <a class="<?= $page === 'clients' ? 'active' : '' ?>" href="?page=clients"><span>♙</span> Clientes</a>
        <a class="<?= $page === 'products' ? 'active' : '' ?>" href="?page=products"><span>◇</span> Produtos</a>
        <a class="<?= $page === 'subscriptions' ? 'active' : '' ?>" href="?page=subscriptions"><span>↻</span> Assinaturas</a>
        <p>FINANCEIRO</p>
        <a class="<?= $page === 'payments' ? 'active' : '' ?>" href="?page=payments"><span>↓</span> Pagamentos</a>
        <a class="<?= $page === 'expenses' ? 'active' : '' ?>" href="?page=expenses"><span>↑</span> Gastos e investimentos</a>
        <a class="<?= $page === 'cash' ? 'active' : '' ?>" href="?page=cash"><span>▤</span> Fluxo de caixa</a>
    </nav>
    <div class="sidebar-bottom"><a class="<?= $page === 'settings' ? 'active' : '' ?>" href="?page=settings"><span>⚙</span> Configurações</a><a href="?logout=1"><span>↪</span> Sair</a></div>
</aside>
<div class="app-shell">
    <header class="topbar">
        <button class="icon-button menu-button" type="button" data-menu aria-label="Abrir menu">☰</button>
        <div class="page-heading"><h1><?= h($pageTitles[$page][0]) ?></h1><p><?= h($pageTitles[$page][1]) ?></p></div>
        <div class="top-actions"><?php if($auth->canWrite()): ?><a class="quick-add" href="?page=payments&new=1">＋ <span>Novo pagamento</span></a><?php endif; ?><div class="user-menu"><span><?= h(mb_strtoupper(mb_substr($auth->user()['name'], 0, 1))) ?></span><div><b><?= h($auth->user()['name']) ?></b><small><?= h(ucfirst($auth->user()['role'])) ?></small></div></div></div>
    </header>
    <main class="content">
        <?php if($messages): ?><div class="toast-stack" aria-live="polite" aria-atomic="true"><?php foreach ($messages as $message): $toastTitle=['success'=>'Tudo certo','danger'=>'Não foi possível','warning'=>'Atenção'][$message['type']]??'Informação'; ?><div class="toast <?= h($message['type']) ?>" data-toast role="status"><span class="toast-icon"><?= $message['type']==='success'?'✓':($message['type']==='danger'?'!':'i') ?></span><div><b><?= h($toastTitle) ?></b><p><?= h($message['message']) ?></p></div><button type="button" aria-label="Fechar notificação">×</button><i class="toast-progress"></i></div><?php endforeach; ?></div><?php endif; ?>
        <?php if (is_file($viewFile)) require $viewFile; else require __DIR__ . '/pages/404.php'; ?>
    </main>
</div>
<div class="sidebar-backdrop" data-menu></div>
<script>window.NEXO = {baseUrl: <?= json_encode(rtrim($config['app']['url'], '/')) ?>};</script>
<script src="assets/js/app.js?v=8"></script>
</body></html>
