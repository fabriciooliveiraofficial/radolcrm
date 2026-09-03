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
        $base = strtoupper(trim((string) ($_GET['base'] ?? $_GET['currency'] ?? 'USD')));
        $quoteCurrency = strtoupper(trim((string) ($_GET['quote'] ?? ($base === 'BRL' ? 'USD' : 'BRL'))));
        $quote = $rates->forDate((string) ($_GET['date'] ?? date('Y-m-d')), false, $base, $quoteCurrency);
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

$allowedPages = ['dashboard','businesses','categories','clients','products','subscriptions','service-badges','reminders','agenda','payments','expenses','recurring','cards','cash','reports','settings'];
$page = (string) ($_GET['page'] ?? 'dashboard');
if (!in_array($page, $allowedPages, true)) {
    http_response_code(404);
    $page = '404';
}

$buFilter = isset($_GET['bu']) && $_GET['bu'] !== '' ? (int) $_GET['bu'] : null;
$sidebarBusinesses = $db->fetchAll('SELECT id, name, icon, color, is_personal FROM business_units WHERE active = 1 ORDER BY sort_order ASC, id ASC');
$selectedBusiness = null;
if ($buFilter) {
    foreach ($sidebarBusinesses as $b) {
        if ((int) $b['id'] === $buFilter) {
            $selectedBusiness = $b;
            break;
        }
    }
}

$pageTitles = [
    'dashboard' => ['Visão geral', 'Acompanhe os números que movem seu negócio.'],
    'businesses' => ['Unidades de negócio', 'Gerencie seus negócios e finanças pessoais de forma separada.'],
    'categories' => ['Categorias de receitas e gastos', 'Organize e defina limitadores de gastos por categoria.'],
    'clients' => ['Clientes', 'Pessoas e empresas que compram de você.'],
    'products' => ['Produtos', 'Planos e preços locais em real e dólar.'],
    'subscriptions' => ['Assinaturas', 'Receita recorrente e próximas cobranças.'],
    'service-badges' => ['Badges de serviços', 'Crie marcadores visuais e organize os serviços de cada assinatura.'],
    'reminders' => ['Lembretes WhatsApp', 'Automatize avisos de vencimento e acompanhe os envios.'],
    'agenda' => ['Agenda financeira', 'Calendário integrado de obrigações, vencimentos e recebimentos.'],
    'payments' => ['Pagamentos', 'Recebimentos, taxas e conversões históricas.'],
    'expenses' => ['Gastos e investimentos', 'Tudo que sai para operar e crescer.'],
    'recurring' => ['Recorrências e Parcelamentos', 'Financiamentos, despesas fixas e parcelas programadas.'],
    'cards' => ['Cartões de crédito', 'Controle de limites, faturas e parcelamentos no cartão.'],
    'cash' => ['Fluxo de caixa', 'Movimentações avulsas e saldo consolidado.'],
    'reports' => ['Relatórios', 'Entenda rentabilidade, moedas e desempenho.'],
    'settings' => ['Configurações', 'Empresa, câmbio, acesso e segurança.'],
    '404' => ['Página não encontrada', 'O endereço acessado não existe.'],
];

if ($selectedBusiness && isset($pageTitles[$page])) {
    $pageTitles[$page][0] .= ' · ' . $selectedBusiness['name'];
}

$messages = Flash::pull();
$viewFile = __DIR__ . '/app/Views/pages/' . $page . '.php';
require __DIR__ . '/app/Views/layout.php';
