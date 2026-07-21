<?php

declare(strict_types=1);

define('BASE_PATH', __DIR__);
$configFile = BASE_PATH . '/config/config.php';
if (is_file($configFile)) {
    header('Location: index.php');
    exit;
}

session_name('nexo_installer');
session_start();
if (empty($_SESSION['_install_csrf'])) {
    $_SESSION['_install_csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$values = [
    'app_name' => $_POST['app_name'] ?? 'Nexo Gestão',
    'app_url' => $_POST['app_url'] ?? ((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')),
    'db_host' => $_POST['db_host'] ?? 'localhost',
    'db_port' => $_POST['db_port'] ?? '3306',
    'db_name' => $_POST['db_name'] ?? '',
    'db_user' => $_POST['db_user'] ?? '',
    'admin_name' => $_POST['admin_name'] ?? '',
    'admin_email' => $_POST['admin_email'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['_install_csrf'], (string) ($_POST['_token'] ?? ''))) {
        $errors[] = 'A sessão expirou. Recarregue a página.';
    }
    foreach (['app_name','app_url','db_host','db_name','db_user','admin_name','admin_email','admin_password'] as $field) {
        if (trim((string) ($_POST[$field] ?? '')) === '') {
            $errors[] = 'Preencha todos os campos obrigatórios.';
            break;
        }
    }
    if (!filter_var($_POST['admin_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Informe um e-mail válido.';
    }
    if (strlen((string) ($_POST['admin_password'] ?? '')) < 8) {
        $errors[] = 'A senha precisa ter pelo menos 8 caracteres.';
    }

    if (!$errors) {
        try {
            $dbConfig = [
                'host' => trim((string) $_POST['db_host']),
                'port' => (int) ($_POST['db_port'] ?? 3306),
                'database' => trim((string) $_POST['db_name']),
                'username' => trim((string) $_POST['db_user']),
                'password' => (string) ($_POST['db_password'] ?? ''),
                'charset' => 'utf8mb4',
            ];
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbConfig['host'], $dbConfig['port'], $dbConfig['database']);
            $pdo = new \PDO($dsn, $dbConfig['username'], $dbConfig['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $schema = file_get_contents(BASE_PATH . '/database/schema.sql');
            if ($schema === false) {
                throw new RuntimeException('Arquivo de banco não encontrado.');
            }
            foreach (array_filter(array_map('trim', explode(';', $schema))) as $sql) {
                $pdo->exec($sql);
            }
            $statement = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, active) VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE name=VALUES(name), password_hash=VALUES(password_hash), role=VALUES(role), active=1');
            $statement->execute([
                trim((string) $_POST['admin_name']),
                mb_strtolower(trim((string) $_POST['admin_email'])),
                password_hash((string) $_POST['admin_password'], PASSWORD_DEFAULT),
                'admin',
            ]);
            $config = [
                'app' => [
                    'name' => trim((string) $_POST['app_name']),
                    'url' => rtrim(trim((string) $_POST['app_url']), '/'),
                    'timezone' => 'America/Sao_Paulo',
                    'session_name' => 'nexo_' . substr(hash('sha256', random_bytes(16)), 0, 12),
                    'cron_secret' => bin2hex(random_bytes(24)),
                    'debug' => false,
                ],
                'db' => $dbConfig,
            ];
            $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
            if (file_put_contents($configFile, $content, LOCK_EX) === false) {
                throw new RuntimeException('Não foi possível gravar config/config.php. Libere permissão de escrita temporariamente na pasta config.');
            }
            $_SESSION = [];
            session_destroy();
            header('Location: index.php?installed=1');
            exit;
        } catch (Throwable $exception) {
            $errors[] = 'Instalação não concluída: ' . $exception->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Instalar Nexo Gestão</title><link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="install-page">
<main class="install-shell">
    <section class="install-intro">
        <span class="brand-mark">N</span><p class="eyebrow">CONFIGURAÇÃO INICIAL</p>
        <h1>Seu financeiro finalmente em ordem.</h1>
        <p>Uma instalação, duas moedas e uma visão clara da saúde do seu negócio.</p>
        <ul class="install-benefits"><li>PHP + MySQL compatível com Hostinger</li><li>Cotação USD/BRL automática</li><li>Dados financeiros sob seu controle</li></ul>
    </section>
    <section class="install-card">
        <div><p class="eyebrow">PASSO ÚNICO</p><h2>Conecte seu banco</h2><p class="muted">Use os dados do MySQL exibidos no hPanel da Hostinger.</p></div>
        <?php foreach ($errors as $error): ?><div class="alert danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
        <form method="post" class="form-grid">
            <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['_install_csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <label class="span-2">Nome da plataforma<input name="app_name" required value="<?= htmlspecialchars($values['app_name'], ENT_QUOTES, 'UTF-8') ?>"></label>
            <label class="span-2">URL da aplicação<input name="app_url" type="url" required value="<?= htmlspecialchars($values['app_url'], ENT_QUOTES, 'UTF-8') ?>"></label>
            <label>Servidor MySQL<input name="db_host" required value="<?= htmlspecialchars($values['db_host'], ENT_QUOTES, 'UTF-8') ?>"></label>
            <label>Porta<input name="db_port" inputmode="numeric" value="<?= htmlspecialchars($values['db_port'], ENT_QUOTES, 'UTF-8') ?>"></label>
            <label>Banco de dados<input name="db_name" required value="<?= htmlspecialchars($values['db_name'], ENT_QUOTES, 'UTF-8') ?>"></label>
            <label>Usuário MySQL<input name="db_user" required value="<?= htmlspecialchars($values['db_user'], ENT_QUOTES, 'UTF-8') ?>"></label>
            <label class="span-2">Senha MySQL<input name="db_password" type="password"></label>
            <div class="form-divider span-2"><span>Administrador</span></div>
            <label>Seu nome<input name="admin_name" required value="<?= htmlspecialchars($values['admin_name'], ENT_QUOTES, 'UTF-8') ?>"></label>
            <label>E-mail<input name="admin_email" type="email" required value="<?= htmlspecialchars($values['admin_email'], ENT_QUOTES, 'UTF-8') ?>"></label>
            <label class="span-2">Senha (mínimo 8 caracteres)<input name="admin_password" type="password" minlength="8" required></label>
            <button class="button primary span-2" type="submit">Instalar e acessar →</button>
        </form>
    </section>
</main>
</body></html>
