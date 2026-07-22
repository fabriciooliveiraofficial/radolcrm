<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/WhatsAppReminderService.php';

use App\Services\WhatsAppReminderService;

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/app/Services/WhatsAppReminderService.php');
$view = (string) file_get_contents($root . '/app/Views/pages/reminders.php');
$migration = (string) file_get_contents($root . '/database/migrations/005_whatsapp_reminders.sql');
$cron = (string) file_get_contents($root . '/cron/send-whatsapp-reminders.php');
$index = (string) file_get_contents($root . '/index.php');

$rendered = WhatsAppReminderService::renderTemplate(
    'Olá, {{primeiro_nome}}. Seu plano {{produto}} vence em {{data_vencimento}}.',
    ['primeiro_nome' => 'Mariana', 'produto' => 'Mensal', 'data_vencimento' => '23/07/2026']
);

$contracts = [
    'normaliza telefone brasileiro' => WhatsAppReminderService::normalizePhone('(11) 99999-9999', 'BR') === '5511999999999',
    'normaliza telefone americano' => WhatsAppReminderService::normalizePhone('(305) 555-0123', 'US') === '13055550123',
    'preserva telefone com DDI' => WhatsAppReminderService::normalizePhone('+55 21 98888-7777', 'BR') === '5521988887777',
    'rejeita telefone inválido' => WhatsAppReminderService::normalizePhone('1234', 'BR') === null,
    'renderiza variáveis dinâmicas' => $rendered === 'Olá, Mariana. Seu plano Mensal vence em 23/07/2026.',
    'detecta variável desconhecida' => WhatsAppReminderService::unknownVariables('Olá, {{apelido}}') === ['apelido'],
    'usa endpoint oficial de texto' => str_contains($service, "'send-text'") && str_contains($service, 'https://api.z-api.io/instances/%s/token/%s/%s'),
    'envia client token no cabeçalho' => str_contains($service, 'Client-Token:'),
    'não cobra assinatura já paga' => str_contains($service, "paid.due_date=s.next_billing_date AND paid.status='paid'"),
    'limita novas tentativas' => str_contains($service, "(int) \$existing['attempts'] >= 3"),
    'impede lembrete duplicado' => str_contains($migration, 'UNIQUE INDEX uq_whatsapp_reminder_cycle'),
    'mantém histórico de envio' => str_contains($migration, 'provider_message_id') && str_contains($migration, 'rendered_message'),
    'cron protegido executa serviço' => str_contains($cron, 'hash_equals') && str_contains($cron, 'WhatsAppReminderService'),
    'painel configura frequência' => str_contains($view, 'whatsapp_upcoming_max_sends') && str_contains($view, 'whatsapp_overdue_interval_days'),
    'painel configura modelos' => str_contains($view, 'data-template-variable') && str_contains($view, 'whatsapp_overdue_message'),
    'rota do painel registrada' => str_contains($index, "'reminders' => ['Lembretes WhatsApp'"),
];

$failed = array_keys(array_filter($contracts, static fn(bool $passed): bool => !$passed));
if ($failed) {
    fwrite(STDERR, 'Falharam: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo count($contracts) . " contratos dos lembretes WhatsApp passaram.\n";
