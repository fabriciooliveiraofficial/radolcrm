<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

$configFile = BASE_PATH . '/config/config.php';
if (!is_file($configFile)) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Aplicação não instalada. Acesse /install.php primeiro.\n");
        exit(1);
    }
    header('Location: install.php');
    exit;
}

$config = require $configFile;
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
    $auth = new Auth($db);
} catch (Throwable $exception) {
    http_response_code(500);
    if (!empty($config['app']['debug'])) {
        echo '<pre>' . htmlspecialchars((string) $exception) . '</pre>';
    } else {
        echo 'Não foi possível conectar ao banco de dados. Verifique config/config.php.';
    }
    exit;
}

