<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#102a2b"><title><?= h($pageTitles[$page][0]) ?> · <?= h($config['app']['name']) ?></title>
    <link rel="icon" href="assets/images/favicon.svg?v=1" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css?v=26">
</head>
<body class="app-body">
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <a class="brand brand-light" href="?page=dashboard">
            <span class="brand-mark">N</span>
            <span class="brand-text"><b>Nexo</b><small>GESTÃO</small></span>
        </a>
    </div>
    <nav class="main-nav" aria-label="Navegação principal">
        <div class="nav-section-title">
            <span>Visão Geral</span>
        </div>
        <div class="nav-links-group">
            <a class="nav-item <?= $page === 'dashboard' && !$buFilter ? 'active' : '' ?>" href="?page=dashboard">
                <span class="nav-icon">⌂</span>
                <span class="nav-label">Dashboard Global</span>
            </a>
            <a class="nav-item <?= $page === 'agenda' && !$buFilter ? 'active' : '' ?>" href="?page=agenda">
                <span class="nav-icon">📅</span>
                <span class="nav-label">Agenda Financeira</span>
            </a>
            <a class="nav-item <?= $page === 'categories' && !$buFilter ? 'active' : '' ?>" href="?page=categories">
                <span class="nav-icon">📁</span>
                <span class="nav-label">Categorias</span>
            </a>
            <a class="nav-item <?= $page === 'reports' && !$buFilter ? 'active' : '' ?>" href="?page=reports">
                <span class="nav-icon">⌁</span>
                <span class="nav-label">Relatórios</span>
            </a>
            <a class="nav-item <?= $page === 'service-badges' && !$buFilter ? 'active' : '' ?>" href="?page=service-badges">
                <span class="nav-icon">✦</span>
                <span class="nav-label">Badges de serviços</span>
            </a>
            <a class="nav-item <?= $page === 'reminders' && !$buFilter ? 'active' : '' ?>" href="?page=reminders">
                <span class="nav-icon">◉</span>
                <span class="nav-label">Lembretes WhatsApp</span>
            </a>
            <a class="nav-item <?= $page === 'businesses' && !$buFilter ? 'active' : '' ?>" href="?page=businesses">
                <span class="nav-icon">💼</span>
                <span class="nav-label">Gerenciar Negócios</span>
            </a>
        </div>

        <div class="nav-section-title">
            <span>Meus Negócios</span>
            <span class="nav-section-badge"><?= count($sidebarBusinesses) ?></span>
        </div>

        <div class="businesses-list">
        <?php foreach ($sidebarBusinesses as $bu): 
            $isOpen = $selectedBusiness && (int)$selectedBusiness['id'] === (int)$bu['id'];
        ?>
        <details class="bu-accordion <?= $isOpen ? 'is-active' : '' ?>" <?= $isOpen ? 'open' : '' ?> style="--bu-color: <?= h($bu['color']) ?>;">
            <summary class="bu-accordion-trigger">
                <span class="bu-avatar-icon" style="background: <?= h($bu['color']) ?>1f; color: <?= h($bu['color']) ?>; border-color: <?= h($bu['color']) ?>4d;">
                    <?= h($bu['icon'] ?: '🏢') ?>
                </span>
                <span class="bu-meta">
                    <span class="bu-meta-name"><?= h($bu['name']) ?></span>
                    <?php if (!empty($bu['is_personal'])): ?>
                        <span class="bu-meta-pill">Pessoal</span>
                    <?php endif; ?>
                </span>
                <svg class="bu-chevron-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 12 15 18 9"></polyline>
                </svg>
            </summary>
            
            <div class="bu-subnav-panel">
                <a class="bu-subnav-link <?= $isOpen && $page === 'clients' ? 'active' : '' ?>" href="?page=clients&bu=<?= (int)$bu['id'] ?>">
                    <span class="sub-dot"></span>
                    <span class="sub-text">Clientes</span>
                </a>
                <a class="bu-subnav-link <?= $isOpen && $page === 'products' ? 'active' : '' ?>" href="?page=products&bu=<?= (int)$bu['id'] ?>">
                    <span class="sub-dot"></span>
                    <span class="sub-text">Produtos</span>
                </a>
                <a class="bu-subnav-link <?= $isOpen && $page === 'subscriptions' ? 'active' : '' ?>" href="?page=subscriptions&bu=<?= (int)$bu['id'] ?>">
                    <span class="sub-dot"></span>
                    <span class="sub-text">Assinaturas</span>
                </a>
                <a class="bu-subnav-link <?= $isOpen && $page === 'payments' ? 'active' : '' ?>" href="?page=payments&bu=<?= (int)$bu['id'] ?>">
                    <span class="sub-dot"></span>
                    <span class="sub-text">Pagamentos</span>
                </a>
                <a class="bu-subnav-link <?= $isOpen && $page === 'expenses' ? 'active' : '' ?>" href="?page=expenses&bu=<?= (int)$bu['id'] ?>">
                    <span class="sub-dot"></span>
                    <span class="sub-text">Gastos</span>
                </a>
                <a class="bu-subnav-link <?= $isOpen && $page === 'recurring' ? 'active' : '' ?>" href="?page=recurring&bu=<?= (int)$bu['id'] ?>">
                    <span class="sub-dot"></span>
                    <span class="sub-text">Recorrências</span>
                </a>
                <a class="bu-subnav-link <?= $isOpen && $page === 'cards' ? 'active' : '' ?>" href="?page=cards&bu=<?= (int)$bu['id'] ?>">
                    <span class="sub-dot"></span>
                    <span class="sub-text">Cartões</span>
                </a>
                <a class="bu-subnav-link <?= $isOpen && $page === 'cash' ? 'active' : '' ?>" href="?page=cash&bu=<?= (int)$bu['id'] ?>">
                    <span class="sub-dot"></span>
                    <span class="sub-text">Fluxo de Caixa</span>
                </a>
            </div>
        </details>
        <?php endforeach; ?>
        </div>
    </nav>
    <div class="sidebar-bottom">
        <a class="<?= $page === 'settings' ? 'active' : '' ?>" href="?page=settings"><span>⚙</span> Configurações</a>
        <a href="?logout=1"><span>↪</span> Sair</a>
    </div>
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
<script src="assets/js/app.js?v=17"></script>
</body></html>
