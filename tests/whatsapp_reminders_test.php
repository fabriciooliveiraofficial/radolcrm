<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/WhatsAppReminderService.php';

use App\Services\WhatsAppReminderService;

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/app/Services/WhatsAppReminderService.php');
$view = (string) file_get_contents($root . '/app/Views/pages/reminders.php');
$partial = (string) file_get_contents($root . '/app/Views/partials/reminder-step.php');
$migration = (string) file_get_contents($root . '/database/migrations/008_whatsapp_automation_engine.sql');
$schema = (string) file_get_contents($root . '/database/schema.sql');
$actions = (string) file_get_contents($root . '/app/Http/ActionHandler.php');
$clients = (string) file_get_contents($root . '/app/Views/pages/clients.php');
$subscriptions = (string) file_get_contents($root . '/app/Views/pages/subscriptions.php');
$javascript = (string) file_get_contents($root . '/assets/js/app.js');
$cron = (string) file_get_contents($root . '/cron/send-whatsapp-reminders.php');
$index = (string) file_get_contents($root . '/index.php');

$rendered = WhatsAppReminderService::renderTemplate(
    'Olá, {{primeiro_nome}}. {{produto}} vence em {{data_vencimento}}. {{link_pagamento}}',
    [
        'primeiro_nome' => 'Mariana',
        'produto' => 'Mensal',
        'data_vencimento' => '31/07/2026',
        'link_pagamento' => 'https://pay.test/5001',
    ]
);

$contracts = [
    'normaliza telefone brasileiro' => WhatsAppReminderService::normalizePhone('(11) 99999-9999', 'BR') === '5511999999999',
    'normaliza telefone americano' => WhatsAppReminderService::normalizePhone('(305) 555-0123', 'US') === '13055550123',
    'preserva telefone com DDI' => WhatsAppReminderService::normalizePhone('+55 21 98888-7777', 'BR') === '5521988887777',
    'rejeita telefone inválido' => WhatsAppReminderService::normalizePhone('1234', 'BR') === null,
    'renderiza mensagem e link dinâmicos' => $rendered === 'Olá, Mariana. Mensal vence em 31/07/2026. https://pay.test/5001',
    'detecta variável desconhecida' => WhatsAppReminderService::unknownVariables('Olá, {{apelido}}') === ['apelido'],
    'variáveis cobrem sincronização' => isset(
        WhatsAppReminderService::VARIABLES['quantidade'],
        WhatsAppReminderService::VARIABLES['link_pagamento'],
        WhatsAppReminderService::VARIABLES['dias_em_atraso'],
        WhatsAppReminderService::VARIABLES['id_assinatura']
    ),
    'usa endpoints oficiais da Z-API' => str_contains($service, "'send-text'")
        && str_contains($service, "'send-image'")
        && str_contains($service, "'caption' => \$message")
        && str_contains($service, 'https://api.z-api.io/instances/%s/token/%s/%s'),
    'envia client token no cabeçalho' => str_contains($service, 'Client-Token:'),
    'motor usa data exata em São Paulo' => str_contains($service, "'America/Sao_Paulo'")
        && str_contains($service, 's.next_billing_date=?')
        && str_contains($service, 'DATEDIFF(s.next_billing_date,?)'),
    'sincroniza cliente assinatura e pagamento' => str_contains($service, "s.status IN ('active','trial','past_due')")
        && str_contains($service, "c.status='active'")
        && str_contains($service, 'c.whatsapp_reminders_enabled=1')
        && str_contains($service, "paid.due_date=s.next_billing_date AND paid.status='paid'"),
    'cliente controla autorização de mensagens' => str_contains($schema, 'whatsapp_reminders_enabled TINYINT(1)')
        && str_contains($clients, 'name="whatsapp_reminders_enabled"')
        && str_contains($actions, 'whatsapp_reminders_enabled=?'),
    'limita tentativas e frequência' => str_contains($service, 'whatsapp_max_attempts')
        && str_contains($service, 'whatsapp_retry_delay_minutes')
        && str_contains($service, 'whatsapp_max_per_client_daily'),
    'deduplica por etapa e ciclo' => str_contains($migration, 'UNIQUE INDEX uq_whatsapp_reminder_step')
        && str_contains($service, 'automation_step_id=?'),
    'migração cria etapas mídia e link' => str_contains($migration, 'CREATE TABLE IF NOT EXISTS whatsapp_automation_steps')
        && str_contains($migration, "payload_type ENUM('text','image')")
        && str_contains($migration, 'payment_link VARCHAR(1000)'),
    'schema atualizado para versão 8' => str_contains($schema, "('schema_version', '8')")
        && str_contains($schema, 'fk_whatsapp_reminder_step'),
    'painel cria jornadas flexíveis' => str_contains($view, 'data-reminder-flow="')
        && str_contains($view, 'data-add-reminder-step')
        && str_contains($partial, 'message_template')
        && str_contains($partial, 'day_offset')
        && str_contains($partial, 'send_time'),
    'painel configura janela dias e limites' => str_contains($view, 'whatsapp_window_start')
        && str_contains($view, 'whatsapp_allowed_weekdays[]')
        && str_contains($view, 'whatsapp_daily_limit'),
    'painel aceita mídia legenda e link' => str_contains($partial, 'step_image_file')
        && str_contains($partial, 'image_url')
        && str_contains($partial, 'payment_link')
        && str_contains($partial, 'data-template-variable'),
    'assinatura aceita link individual' => str_contains($subscriptions, 'name="payment_link"')
        && str_contains($actions, 'payment_link=?, notes=?'),
    'teste execução e reenvio registrados' => str_contains($actions, "'send_whatsapp_test' =>")
        && str_contains($actions, "'run_whatsapp_reminders' =>")
        && str_contains($actions, "'retry_whatsapp_reminder' =>"),
    'interface adiciona e remove etapas' => str_contains($javascript, 'data-reminder-step-template')
        && str_contains($javascript, 'data-remove-reminder-step')
        && str_contains($javascript, 'replaceAll'),
    'cron protegido executa serviço' => str_contains($cron, 'hash_equals') && str_contains($cron, 'WhatsAppReminderService'),
    'rota do painel registrada' => str_contains($index, "'reminders' => ['Lembretes WhatsApp'"),
];

$failed = array_keys(array_filter($contracts, static fn(bool $passed): bool => !$passed));
if ($failed) {
    fwrite(STDERR, 'Falharam: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo count($contracts) . " contratos do motor de automações WhatsApp passaram.\n";
