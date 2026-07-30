<?php

use App\Services\WhatsAppReminderService;

$reminderService = new WhatsAppReminderService($db);
$reminderConfig = $reminderService->config();
$reminderSteps = $reminderService->stepsByType();
$allReminderSteps = array_merge($reminderSteps['upcoming'], $reminderSteps['overdue']);
$reminderStats = $reminderService->stats() + [
    'sent_today' => 0,
    'failed_recent' => 0,
    'skipped_recent' => 0,
    'upcoming_sent' => 0,
    'overdue_sent' => 0,
    'upcoming_queue' => 0,
    'overdue_queue' => 0,
];
$reminderHistory = $reminderService->history(75);
$remindersEnabled = $reminderConfig['whatsapp_enabled'] === '1';
$credentialsConfigured = $reminderService->hasCredentials($reminderConfig);
$lastSummary = json_decode($reminderConfig['whatsapp_last_run_summary'], true) ?: [];
$selectedWeekdays = array_map('intval', explode(',', $reminderConfig['whatsapp_allowed_weekdays']));
$weekdayLabels = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];
$previewValues = [
    'nome' => 'Mariana Oliveira',
    'primeiro_nome' => 'Mariana',
    'empresa_cliente' => 'Oliveira Digital',
    'telefone' => '5511999999999',
    'pais' => 'Brasil',
    'produto' => 'Plano Premium',
    'quantidade' => '2',
    'data_hoje' => date('d/m/Y'),
    'data_vencimento' => date('d/m/Y', strtotime('+1 day')),
    'valor' => 'R$ 149,90',
    'moeda' => 'BRL',
    'dias_para_vencimento' => '1',
    'dias_para_vencer' => '1',
    'dias_atraso' => '3',
    'dias_em_atraso' => '3',
    'forma_pagamento' => 'PIX',
    'link_pagamento' => 'https://pagamento.exemplo/assinatura/5001',
    'telefone_suporte' => $reminderConfig['whatsapp_support_phone'] ?: '(11) 99999-9999',
    'id_cliente' => '1001',
    'id_assinatura' => '5001',
    'empresa' => setting($db, 'company_name', 'Minha Empresa'),
];
$stepPartial = dirname(__DIR__) . '/partials/reminder-step.php';
$renderReminderStep = static function (array $step, string $type, string $key) use ($stepPartial): string {
    $reminderStep = $step;
    $stepType = $type;
    $stepKey = $key;
    ob_start();
    include $stepPartial;
    return (string) ob_get_clean();
};
$defaultUpcoming = [
    'id' => 0,
    'name' => 'Lembrete de amanhã',
    'day_offset' => 1,
    'send_time' => '09:00',
    'message_template' => "Olá, {{primeiro_nome}}! Sua assinatura {{produto}} vence em {{data_vencimento}}, no valor de {{valor}}.\n\nPague pelo link: {{link_pagamento}}\n\nSe já realizou o pagamento, desconsidere. {{empresa}}",
    'image_url' => '',
    'payment_link' => '',
    'active' => 1,
    'position' => 1,
];
$defaultOverdue = [
    'id' => 0,
    'name' => 'Recuperação D+1',
    'day_offset' => 1,
    'send_time' => '09:00',
    'message_template' => "Olá, {{primeiro_nome}}! Identificamos que sua assinatura {{produto}} venceu em {{data_vencimento}} ({{dias_em_atraso}} dia(s) em atraso).\n\nRegularize pelo link: {{link_pagamento}}\n\nSe já pagou, desconsidere. Fale conosco: {{telefone_suporte}}",
    'image_url' => '',
    'payment_link' => '',
    'active' => 1,
    'position' => 1,
];
?>

<section class="reminder-hero card">
    <div>
        <p class="eyebrow">CENTRAL DE AUTOMAÇÕES</p>
        <h2>Jornadas de cobrança pelo WhatsApp</h2>
        <p>Mensagens sincronizadas com clientes, assinaturas, pagamentos e próximas datas de vencimento.</p>
    </div>
    <span class="integration-state <?= $remindersEnabled ? 'enabled' : 'disabled' ?>"><i></i><?= $remindersEnabled ? 'Motor ativo' : 'Motor pausado' ?></span>
</section>

<section class="reminder-metrics">
    <article class="card"><span>Fila pré-vencimento</span><strong><?= (int) $reminderStats['upcoming_queue'] ?></strong><small>assinaturas elegíveis nas etapas atuais</small></article>
    <article class="card"><span>Fila de recuperação</span><strong><?= (int) $reminderStats['overdue_queue'] ?></strong><small>assinaturas vencidas elegíveis</small></article>
    <article class="card"><span>Enviados hoje</span><strong><?= (int) $reminderStats['sent_today'] ?></strong><small>confirmados pela Z-API</small></article>
    <article class="card"><span>Requerem atenção</span><strong><?= (int) $reminderStats['failed_recent'] + (int) $reminderStats['skipped_recent'] ?></strong><small>falhas recentes ou telefone inválido</small></article>
</section>

<?php if ($auth->isAdmin()): ?>
<form method="post" enctype="multipart/form-data" class="reminder-control" data-reminder-settings data-template-values="<?= h(json_encode($previewValues, JSON_UNESCAPED_UNICODE)) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_whatsapp_reminders">

    <section class="card reminder-section">
        <div class="settings-heading">
            <span class="settings-icon">◉</span>
            <div><p class="eyebrow">Z-API</p><h2>Conexão e controle geral</h2><p>As credenciais ficam somente no servidor. Pausar o motor não apaga etapas nem histórico.</p></div>
            <label class="switch-control"><input type="checkbox" name="whatsapp_enabled" value="1" <?= $remindersEnabled ? 'checked' : '' ?>><span></span><b>Ativar motor</b></label>
        </div>
        <div class="integration-summary <?= $credentialsConfigured ? 'ready' : 'pending' ?>">
            <span><?= $credentialsConfigured ? '✓' : '!' ?></span>
            <div><b><?= $credentialsConfigured ? 'Credenciais armazenadas' : 'Integração pendente' ?></b><small><?= $credentialsConfigured ? 'Valide a sessão em “Testar conexão” antes de ativar envios reais.' : 'Informe ID, token da instância e Client-Token da Z-API.' ?></small></div>
        </div>
        <div class="form-grid reminder-credentials">
            <label>ID da instância<input name="whatsapp_instance_id" value="<?= h($reminderConfig['whatsapp_instance_id']) ?>" autocomplete="off" placeholder="ID exibido no painel Z-API"></label>
            <label>Token da instância<input name="whatsapp_instance_token" type="password" autocomplete="new-password" placeholder="<?= $reminderConfig['whatsapp_instance_token'] !== '' ? 'Configurado — deixe vazio para manter' : 'Token da instância' ?>"></label>
            <label>Client-Token<input name="whatsapp_client_token" type="password" autocomplete="new-password" placeholder="<?= $reminderConfig['whatsapp_client_token'] !== '' ? 'Configurado — deixe vazio para manter' : 'Token de segurança da conta' ?>"></label>
            <label>Telefone de suporte<input name="whatsapp_support_phone" maxlength="50" value="<?= h($reminderConfig['whatsapp_support_phone']) ?>" placeholder="+55 11 99999-9999"><small>Disponível em {{telefone_suporte}}.</small></label>
        </div>
    </section>

    <section class="card reminder-section automation-guardrails">
        <div class="settings-heading"><span class="settings-icon">⌚</span><div><p class="eyebrow">SEGURANÇA OPERACIONAL</p><h2>Janela, dias e limites de envio</h2><p>O fuso é fixado em America/Sao_Paulo para coincidir com as datas de cobrança do sistema.</p></div></div>
        <div class="guardrail-grid">
            <label>Início da janela<input name="whatsapp_window_start" type="time" required value="<?= h($reminderConfig['whatsapp_window_start']) ?>"></label>
            <label>Fim da janela<input name="whatsapp_window_end" type="time" required value="<?= h($reminderConfig['whatsapp_window_end']) ?>"></label>
            <label>Limite diário<input name="whatsapp_daily_limit" type="number" min="1" max="5000" required value="<?= (int) $reminderConfig['whatsapp_daily_limit'] ?>"></label>
            <label>Máx. por cliente/dia<input name="whatsapp_max_per_client_daily" type="number" min="1" max="20" required value="<?= (int) $reminderConfig['whatsapp_max_per_client_daily'] ?>"></label>
            <label>Máx. tentativas<input name="whatsapp_max_attempts" type="number" min="1" max="10" required value="<?= (int) $reminderConfig['whatsapp_max_attempts'] ?>"></label>
            <label>Intervalo da retentativa<input name="whatsapp_retry_delay_minutes" type="number" min="1" max="1440" required value="<?= (int) $reminderConfig['whatsapp_retry_delay_minutes'] ?>"><small>minutos</small></label>
        </div>
        <div class="weekday-picker"><span>Dias permitidos</span><?php foreach ($weekdayLabels as $weekday => $label): ?><label><input type="checkbox" name="whatsapp_allowed_weekdays[]" value="<?= $weekday ?>" <?= in_array($weekday, $selectedWeekdays, true) ? 'checked' : '' ?>><span><?= h($label) ?></span></label><?php endforeach; ?></div>
        <div class="automation-safety-note"><b>Proteções automáticas</b><span>Uma etapa é enviada uma única vez por assinatura e vencimento. Pagamento ou renovação altera o ciclo e encerra as mensagens antigas. Clientes inativos e assinaturas pausadas/canceladas não entram na fila.</span></div>
    </section>

    <?php foreach (['upcoming','overdue'] as $type): ?>
        <?php
        $isUpcoming = $type === 'upcoming';
        $typeConfigKey = 'whatsapp_' . $type . '_enabled';
        ?>
        <section class="card reminder-section automation-flow <?= h($type) ?>" data-reminder-flow="<?= h($type) ?>">
            <div class="rule-heading">
                <span class="rule-icon <?= h($type) ?>"><?= $isUpcoming ? '→' : '!' ?></span>
                <div><p class="eyebrow"><?= $isUpcoming ? 'PRÉ-VENCIMENTO' : 'RECUPERAÇÃO' ?></p><h2><?= $isUpcoming ? 'Assinaturas próximas do vencimento' : 'Assinaturas vencidas' ?></h2><p><?= $isUpcoming ? 'Crie avisos em D-7, D-3, D-1 ou nos dias que preferir.' : 'Crie contatos em D+1, D+3, D+7 ou nos dias que preferir.' ?></p></div>
                <label class="switch-control flow-switch"><input type="checkbox" name="<?= h($typeConfigKey) ?>" value="1" <?= $reminderConfig[$typeConfigKey] === '1' ? 'checked' : '' ?>><span></span><b><?= $isUpcoming ? 'Ativar lembretes' : 'Ativar recuperação' ?></b></label>
            </div>
            <div class="automation-step-list" data-reminder-step-list="<?= h($type) ?>">
                <?php foreach ($reminderSteps[$type] as $index => $step): ?>
                    <?= $renderReminderStep($step, $type, 'saved-' . (int) $step['id']) ?>
                <?php endforeach; ?>
            </div>
            <button type="button" class="add-automation-step" data-add-reminder-step="<?= h($type) ?>">+ Adicionar etapa</button>
        </section>
    <?php endforeach; ?>

    <template data-reminder-step-template="upcoming"><?= $renderReminderStep($defaultUpcoming, 'upcoming', '__KEY__') ?></template>
    <template data-reminder-step-template="overdue"><?= $renderReminderStep($defaultOverdue, 'overdue', '__KEY__') ?></template>

    <footer class="reminder-save"><p>Salvar aplica a configuração na próxima execução do cron. Nenhuma mensagem é enviada por este botão.</p><button class="button primary">Salvar automações</button></footer>
</form>

<section class="card reminder-operations">
    <div><p class="eyebrow">VALIDAÇÃO E OPERAÇÃO</p><h2>Conexão, teste e execução</h2><p>Envie uma amostra para seu telefone antes de liberar a jornada aos clientes.</p></div>
    <div class="operation-actions">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_whatsapp_connection"><button class="button secondary">Testar conexão</button></form>
        <form method="post" data-confirm="Executar agora pode enviar mensagens reais para todos os clientes elegíveis nas etapas de hoje. Deseja continuar?"><?= csrf_field() ?><input type="hidden" name="action" value="run_whatsapp_reminders"><button class="button primary">Executar filas agora</button></form>
    </div>
</section>

<section class="card whatsapp-test-panel">
    <div><p class="eyebrow">MENSAGEM DE TESTE</p><h2>Simular uma etapa</h2><p>Usa dados fictícios e envia a mensagem/imagem real apenas ao número informado.</p></div>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="send_whatsapp_test">
        <label>País<select name="test_country"><option value="BR" <?= $reminderConfig['whatsapp_test_country'] === 'BR' ? 'selected' : '' ?>>Brasil (+55)</option><option value="US" <?= $reminderConfig['whatsapp_test_country'] === 'US' ? 'selected' : '' ?>>Estados Unidos (+1)</option></select></label>
        <label>Telefone<input name="test_phone" required value="<?= h($reminderConfig['whatsapp_test_phone']) ?>" placeholder="(11) 99999-9999"></label>
        <label>Etapa<select name="step_id" required><option value="">Selecione…</option><?php foreach ($allReminderSteps as $step): ?><option value="<?= (int) $step['id'] ?>"><?= $step['reminder_type'] === 'upcoming' ? 'Pré · ' : 'Recuperação · ' ?><?= h($step['name']) ?></option><?php endforeach; ?></select></label>
        <button class="button secondary">Enviar teste</button>
    </form>
</section>
<?php else: ?>
<div class="inline-warning">Somente administradores podem alterar ou executar as automações. O histórico permanece disponível para consulta.</div>
<?php endif; ?>

<section class="card reminder-history">
    <div class="card-header"><div><p class="eyebrow">AUDITORIA</p><h2>Histórico detalhado de mensagens</h2><p class="card-subtitle">Última execução: <?= datetime_br($reminderConfig['whatsapp_last_run_at']) ?><?= $lastSummary ? ' · ' . (int) ($lastSummary['sent'] ?? 0) . ' enviada(s), ' . (int) ($lastSummary['duplicates'] ?? 0) . ' duplicada(s) bloqueada(s)' : '' ?></p></div></div>
    <div class="table-wrap"><table><thead><tr><th>Cliente / assinatura</th><th>Jornada / etapa</th><th>Vencimento</th><th>Canal</th><th>Tentativas</th><th>Status</th><th>Processado em</th><th></th></tr></thead><tbody>
        <?php if (!$reminderHistory): ?><tr><td colspan="8" class="empty-cell">Nenhum lembrete processado até agora.</td></tr><?php endif; ?>
        <?php foreach ($reminderHistory as $item): ?>
            <tr>
                <td><b><?= h($item['client'] ?: 'Cliente removido') ?></b><small class="block"><?= h($item['product'] ?: 'Assinatura removida') ?> · #<?= (int) ($item['subscription_id'] ?? 0) ?></small></td>
                <td><?= $item['reminder_type'] === 'upcoming' ? 'Pré-vencimento' : 'Recuperação' ?><small class="block"><?= h($item['step_name'] ?: 'Etapa removida') ?></small></td>
                <td><?= date_br($item['due_date']) ?></td>
                <td><?= $item['payload_type'] === 'image' ? 'Imagem + legenda' : 'Texto' ?><small class="block"><?= h($item['recipient_phone']) ?></small></td>
                <td><?= (int) $item['attempts'] ?> / <?= (int) $reminderConfig['whatsapp_max_attempts'] ?></td>
                <td><span class="badge <?= status_class($item['status']) ?>" title="<?= h($item['error_message'] ?? '') ?>"><?= h(['sent'=>'Enviado','failed'=>'Falhou','skipped'=>'Ignorado','pending'=>'Pendente'][$item['status']] ?? $item['status']) ?></span><?php if (!empty($item['error_message'])): ?><small class="history-error"><?= h($item['error_message']) ?></small><?php endif; ?></td>
                <td><?= datetime_br($item['sent_at'] ?: $item['last_attempt_at'] ?: $item['created_at']) ?></td>
                <td><?php if ($auth->isAdmin() && in_array($item['status'], ['failed','skipped'], true) && !empty($item['automation_step_id'])): ?><form method="post" data-confirm="Tentar reenviar esta mensagem agora? O sistema verificará novamente pagamento e status da assinatura."><?= csrf_field() ?><input type="hidden" name="action" value="retry_whatsapp_reminder"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><button class="table-link">Reenviar</button></form><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody></table></div>
</section>

<section class="cron-note">
    <b>Execução automática</b><span>Configure a Hostinger para executar <code>cron/send-whatsapp-reminders.php SEU_CRON_SECRET</code> a cada 15 minutos. O motor aplica fuso de São Paulo, janela, dias, horários de cada etapa, limites, retentativas e bloqueio de duplicidade.</span>
</section>
