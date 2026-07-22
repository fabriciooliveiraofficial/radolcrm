<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Flash;
use App\Http\ActionHandler;
use App\Http\Exporter;
use App\Services\ExchangeRateService;

require __DIR__ . '/app/bootstrap.php';

if (isset($_GET['logout'])) {
    $auth->logout();
    redirect('index.php');
}

if (!$auth->check()) {
    $loginError = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
        if (!Csrf::validate($_POST['_token'] ?? null)) {
            $loginError = 'Sessão expirada. Recarregue a página.';
        } elseif ($auth->attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
            redirect('index.php');
        } else {
            $loginError = 'E-mail ou senha incorretos.';
        }
    }
    require __DIR__ . '/app/Views/login.php';
    exit;
}

$rates = new ExchangeRateService($db);

if (($_GET['page'] ?? '') === 'exchange-rate') {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $quote = $rates->forDate((string) ($_GET['date'] ?? date('Y-m-d')));
        echo json_encode(['ok' => true, 'rate' => $quote], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    (new ActionHandler($db, $auth, $rates))->handle((string) $_POST['action']);
}

if (($_GET['page'] ?? '') === 'export') {
    (new Exporter($db))->download((string) ($_GET['type'] ?? ''));
}

$allowedPages = ['dashboard','clients','products','subscriptions','reminders','payments','expenses','cash','reports','settings'];
$page = (string) ($_GET['page'] ?? 'dashboard');
if (!in_array($page, $allowedPages, true)) {
    http_response_code(404);
    $page = '404';
}

$pageTitles = [
    'dashboard' => ['Visão geral', 'Acompanhe os números que movem seu negócio.'],
    'clients' => ['Clientes', 'Pessoas e empresas que compram de você.'],
    'products' => ['Produtos', 'Planos e preços locais em real e dólar.'],
    'subscriptions' => ['Assinaturas', 'Receita recorrente e próximas cobranças.'],
    'reminders' => ['Lembretes WhatsApp', 'Automatize avisos de vencimento e acompanhe os envios.'],
    'payments' => ['Pagamentos', 'Recebimentos, taxas e conversões históricas.'],
    'expenses' => ['Gastos e investimentos', 'Tudo que sai para operar e crescer.'],
    'cash' => ['Fluxo de caixa', 'Movimentações avulsas e saldo consolidado.'],
    'reports' => ['Relatórios', 'Entenda rentabilidade, moedas e desempenho.'],
    'settings' => ['Configurações', 'Empresa, câmbio, acesso e segurança.'],
    '404' => ['Página não encontrada', 'O endereço acessado não existe.'],
];

$messages = Flash::pull();
$viewFile = __DIR__ . '/app/Views/pages/' . $page . '.php';
require __DIR__ . '/app/Views/layout.php';
