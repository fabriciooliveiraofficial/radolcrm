<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'Nexo Gestão',
        'url' => 'https://seudominio.com.br',
        'timezone' => 'America/Sao_Paulo',
        'session_name' => 'nexo_session',
        'cron_secret' => 'troque-por-um-segredo-longo',
        'debug' => false,
    ],
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'seu_banco',
        'username' => 'seu_usuario',
        'password' => 'sua_senha',
        'charset' => 'utf8mb4',
    ],
];

