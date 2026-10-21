<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Services\MigrationService;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

$configFile = BASE_PATH . '/config/config.php';
if (is_file($configFile)) {
    $config = require $configFile;
} elseif ((string) getenv('NEXO_DB_HOST') !== '') {
    $config = [
        'app' => [
            'name' => getenv('NEXO_APP_NAME') ?: 'Nexo Gestão',
            'url' => (string) getenv('NEXO_APP_URL'),
            'timezone' => getenv('NEXO_TIMEZONE') ?: 'America/Sao_Paulo',
            'session_name' => getenv('NEXO_SESSION_NAME') ?: 'nexo_session',
            'cron_secret' => (string) getenv('NEXO_CRON_SECRET'),
            'debug' => filter_var(getenv('NEXO_DEBUG'), FILTER_VALIDATE_BOOLEAN),
        ],
        'db' => [
            'host' => (string) getenv('NEXO_DB_HOST'),
            'port' => (int) (getenv('NEXO_DB_PORT') ?: 3306),
            'database' => (string) getenv('NEXO_DB_NAME'),
            'username' => (string) getenv('NEXO_DB_USER'),
            'password' => (string) getenv('NEXO_DB_PASSWORD'),
            'charset' => 'utf8mb4',
        ],
    ];
} else {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Aplicação não instalada. Acesse /install.php primeiro.\n");
        exit(1);
    }
    header('Location: install.php');
    exit;
}
$GLOBALS['config'] = $config;

date_default_timezone_set($config['app']['timezone'] ?? 'America/Sao_Paulo');
ini_set('display_errors', !empty($config['app']['debug']) ? '1' : '0');
error_reporting(E_ALL);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require BASE_PATH . '/app/Support/helpers.php';

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_name($config['app']['session_name'] ?? 'nexo_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

try {
    $db = new Database($config['db']);
} catch (Throwable $exception) {
    http_response_code(500);
    if (!empty($config['app']['debug'])) {
        echo '<pre>' . htmlspecialchars((string) $exception) . '</pre>';
    } else {
        echo 'Não foi possível conectar ao banco de dados. Verifique config/config.php.';
    }
    exit;
}

$migrationError = null;
try {
    (new MigrationService($db))->run();
} catch (Throwable $exception) {
    $migrationError = $exception;
    error_log('[Nexo migration] ' . (string) $exception);
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('X-Nexo-Migration: pending-' . substr(hash('sha256', $exception->getMessage()), 0, 8));
    }
}

try {
    $auth = new Auth($db);
} catch (Throwable $exception) {
    http_response_code(500);
    error_log('[Nexo auth bootstrap] ' . (string) $exception);
    echo 'Não foi possível iniciar a aplicação. Tente novamente em alguns instantes.';
    exit;
}
