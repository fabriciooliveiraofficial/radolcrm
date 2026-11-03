<?php

// Imprime o cron_secret efetivo do app: config/config.php (se existir) tem prioridade sobre NEXO_CRON_SECRET,
// exatamente como em app/bootstrap.php.
error_reporting(0);

$file = '/var/www/html/config/config.php';
if (is_file($file)) {
    $config = require $file;
    echo (string) ($config['app']['cron_secret'] ?? '');
} else {
    echo (string) getenv('NEXO_CRON_SECRET');
}
