<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Entrar · <?= h($config['app']['name']) ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="login-page">
<main class="login-shell">
    <section class="login-panel">
        <a class="brand brand-light" href="index.php"><span class="brand-mark">N</span><span><b>Nexo</b><small>GESTÃO</small></span></a>
        <div class="login-message"><p class="eyebrow">DECISÕES COM CLAREZA</p><h1>Brasil e Estados Unidos. Uma só visão financeira.</h1><p>Receitas, assinaturas e custos convertidos sem perder a história de cada transação.</p></div>
        <div class="login-proof"><span>USD</span><i>→</i><span>BRL</span><p>Cotação automática e rastreável</p></div>
    </section>
    <section class="login-form-wrap">
        <form method="post" class="login-card">
            <?= csrf_field() ?><input type="hidden" name="action" value="login">
            <div><p class="eyebrow">BEM-VINDO DE VOLTA</p><h2>Acesse sua empresa</h2><p class="muted">Use seu e-mail e senha para continuar.</p></div>
            <?php if ($loginError): ?><div class="alert danger"><?= h($loginError) ?></div><?php endif; ?>
            <?php if (isset($_GET['installed'])): ?><div class="alert success">Instalação concluída. Faça seu primeiro acesso.</div><?php endif; ?>
            <label>E-mail<input name="email" type="email" autocomplete="email" placeholder="voce@empresa.com" required autofocus></label>
            <label>Senha<div class="password-field"><input id="password" name="password" type="password" autocomplete="current-password" required><button type="button" data-toggle-password aria-label="Exibir senha">Ver</button></div></label>
            <button class="button primary" type="submit">Entrar na plataforma →</button>
            <p class="login-help">Problemas para acessar? Fale com o administrador da sua conta.</p>
        </form>
    </section>
</main>
<script src="assets/js/app.js"></script>
</body></html>

