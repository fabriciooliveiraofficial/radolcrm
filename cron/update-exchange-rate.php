<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\ExchangeRateService;

$provided = PHP_SAPI === 'cli'
    ? (string) ($argv[1] ?? '')
    : (string) ($_GET['token'] ?? '');
$expected = (string) ($config['app']['cron_secret'] ?? '');

if ($expected === '' || !hash_equals($expected, $provided)) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode(['ok' => false, 'message' => 'Token inválido.'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

try {
    $rate = (new ExchangeRateService($db))->current(true);
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode(['ok' => true, 'rate' => $rate], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $exception) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(503);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

