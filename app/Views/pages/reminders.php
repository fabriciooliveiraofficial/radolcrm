<?php

use App\Services\WhatsAppReminderService;

$reminderService = new WhatsAppReminderService($db);
$reminderConfig = $reminderService->config();
$reminderStats = $reminderService->stats() + [
    'sent_today' => 0,
    'failed_recent' => 0,
    'skipped_recent' => 0,
    'upcoming_sent' => 0,
    'overdue_sent' => 0,
];
$reminderHistory = $reminderService->history(50);
$remindersEnabled = $reminderConfig['whatsapp_enabled'] === '1';
$credentialsConfigured = $reminderService->hasCredentials($reminderConfig);
$lastSummary = json_decode($reminderConfig['whatsapp_last_run_summary'], true) ?: [];
$previewValues = [
    'nome' => 'Mariana Oliveira',
    'primeiro_nome' => 'Mariana',
    'empresa_cliente' => 'Oliveira Digital',
    'telefone' => '5511999999999',
    'pais' => 'Brasil',
    'produto' => 'Plano Mensal',
    'data_vencimento' => date('d/m/Y', strtotime('+1 day')),
    'valor' => 'R$ 149,90',
    'moeda' => 'BRL',
    'dias_para_vencimento' => '1',
    'dias_atraso' => '3',
    'forma_pagamento' => 'PIX',
    'empresa' => setting($db, 'company_name', 'Minha Empresa'),
];
?>

<section class="reminder-hero card">
    <div>
        <p class="eyebrow">AUTOMAÇÃO DE COBRANÇA</p>
        <h2>Lembretes de vencimento pelo WhatsApp</h2>
        <p>Avise clientes antes do vencimento e acompanhe automaticamente assinaturas em atraso.</p>
    </div>
    <span class="integration-state <?= $remindersEnabled ? 'enabled' : 'disabled' ?>"><i></i><?= $remindersEnabled ? 'Automação ativa' : 'Automação desativada' ?></span>
</section>

<section class="reminder-metrics">
    <article class="card"><span>Enviados hoje</span><strong><?= (int) $reminderStats['sent_today'] ?></strong><small>mensagens confirmadas pela API</small></article>
    <article class="card"><span>Antes do vencimento</span><strong><?= (int) $reminderStats['upcoming_sent'] ?></strong><small>últimos 30 dias</small></article>
    <article class="card"><span>Clientes vencidos</span><strong><?= (int) $reminderStats['overdue_sent'] ?></strong><small>últimos 30 dias</small></article>
    <article class="card"><span>Requerem atenção</span><strong><?= (int) $reminderStats['failed_recent'] + (int) $reminderStats['skipped_recent'] ?></strong><small>falhas ou telefones inválidos</small></article>
</section>

<?php if ($auth->isAdmin()): ?>
<form method="post" class="reminder-control" data-reminder-settings data-template-values="<?= h(json_encode($previewValues, JSON_UNESCAPED_UNICODE)) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_whatsapp_reminders">

    <section class="card reminder-section">
        <div class="settings-heading">
            <span class="settings-icon">◉</span>
            <div><p class="eyebrow">Z-API</p><h2>Conexão e ativação</h2><p>As credenciais são usadas somente no servidor e nunca são expostas no navegador.</p></div>
            <label class="switch-control"><input type="checkbox" name="whatsapp_enabled" value="1" <?= $remindersEnabled ? 'checked' : '' ?>><span></span><b>Ativar automação</b></label>
        </div>
        <div class="integration-summary <?= $credentialsConfigured ? 'ready' : 'pending' ?>">
            <span><?= $credentialsConfigured ? '✓' : '!' ?></span>
            <div><b><?= $credentialsConfigured ? 'Credenciais configuradas' : 'Configuração pendente' ?></b><small><?= $credentialsConfigured ? 'Use “Testar conexão” para validar o WhatsApp vinculado.' : 'Informe o ID e o token da instância para habilitar os envios.' ?></small></div>
        </div>
        <div class="form-grid reminder-credentials">
            <label>ID da instância<input name="whatsapp_instance_id" value="<?= h($reminderConfig['whatsapp_instance_id']) ?>" autocomplete="off" placeholder="ID exibido no painel Z-API"></label>
            <label>Token da instância<input name="whatsapp_instance_token" type="password" autocomplete="new-password" placeholder="<?= $reminderConfig['whatsapp_instance_token'] !== '' ? 'Configurado — deixe em branco para manter' : 'Token da instância' ?>"><small>Nunca será exibido depois de salvo.</small></label>
            <label>Client-Token de segurança<input name="whatsapp_client_token" type="password" autocomplete="new-password" placeholder="<?= $reminderConfig['whatsapp_client_token'] !== '' ? 'Configurado — deixe em branco para manter' : 'Opcional se não estiver ativado na Z-API' ?>"><small>Recomendado pela Z-API para proteger a conta.</small></label>
            <label>Horário diário de envio<input name="whatsapp_send_time" type="time" required value="<?= h($reminderConfig['whatsapp_send_time']) ?>"><small>Usa o fuso horário configurado no sistema.</small></label>
        </div>
    </section>

    <section class="reminder-rules">
        <article class="card reminder-section">
            <div class="rule-heading"><span class="rule-icon upcoming">→</span><div><p class="eyebrow">ANTES DO VENCIMENTO</p><h2>Assinaturas a vencer</h2><p>O padrão envia uma vez, no dia anterior.</p></div><label class="compact-switch"><input type="checkbox" name="whatsapp_upcoming_enabled" value="1" <?= $reminderConfig['whatsapp_upcoming_enabled'] === '1' ? 'checked' : '' ?>><span></span></label></div>
            <div class="frequency-grid">
                <label>Começar<input name="whatsapp_upcoming_start_days" type="number" min="1" max="30" required value="<?= (int) $reminderConfig['whatsapp_upcoming_start_days'] ?>"><small>dias antes</small></label>
                <label>Repetir<input name="whatsapp_upcoming_interval_days" type="number" min="1" max="30" required value="<?= (int) $reminderConfig['whatsapp_upcoming_interval_days'] ?>"><small>a cada dia(s)</small></label>
                <label>Limitar<input name="whatsapp_upcoming_max_sends" type="number" min="1" max="10" required value="<?= (int) $reminderConfig['whatsapp_upcoming_max_sends'] ?>"><small>envio(s)</small></label>
            </div>
        </article>
        <article class="card reminder-section">
            <div class="rule-heading"><span class="rule-icon overdue">!</span><div><p class="eyebrow">APÓS O VENCIMENTO</p><h2>Assinaturas vencidas</h2><p>Repita a cobrança sem duplicar mensagens.</p></div><label class="compact-switch"><input type="checkbox" name="whatsapp_overdue_enabled" value="1" <?= $reminderConfig['whatsapp_overdue_enabled'] === '1' ? 'checked' : '' ?>><span></span></label></div>
            <div class="frequency-grid">
                <label>Começar<input name="whatsapp_overdue_start_days" type="number" min="1" max="365" required value="<?= (int) $reminderConfig['whatsapp_overdue_start_days'] ?>"><small>dia(s) depois</small></label>
                <label>Repetir<input name="whatsapp_overdue_interval_days" type="number" min="1" max="365" required value="<?= (int) $reminderConfig['whatsapp_overdue_interval_days'] ?>"><small>a cada dia(s)</small></label>
                <label>Limitar<input name="whatsapp_overdue_max_sends" type="number" min="1" max="20" required value="<?= (int) $reminderConfig['whatsapp_overdue_max_sends'] ?>"><small>envio(s)</small></label>
            </div>
        </article>
    </section>

    <section class="card reminder-section template-section">
        <div class="settings-heading"><span class="settings-icon">✎</span><div><p class="eyebrow">MENSAGENS DINÂMICAS</p><h2>Modelos dos lembretes</h2><p>Clique em uma variável para inseri-la no modelo selecionado.</p></div></div>
        <div class="variable-picker">
            <?php foreach (WhatsAppReminderService::VARIABLES as $variable => $label): ?><button type="button" data-template-variable="<?= h($variable) ?>" title="<?= h($label) ?>">{{<?= h($variable) ?>}}</button><?php endforeach; ?>
        </div>
        <div class="template-grid">
            <label><span>Mensagem antes do vencimento</span><textarea name="whatsapp_upcoming_message" rows="8" required data-template-source="upcoming"><?= h($reminderConfig['whatsapp_upcoming_message']) ?></textarea><small>Exemplo de prévia</small><em class="message-preview" data-template-preview="upcoming"></em></label>
            <label><span>Mensagem para vencidos</span><textarea name="whatsapp_overdue_message" rows="8" required data-template-source="overdue"><?= h($reminderConfig['whatsapp_overdue_message']) ?></textarea><small>Exemplo de prévia</small><em class="message-preview" data-template-preview="overdue"></em></label>
        </div>
    </section>

    <footer class="reminder-save"><p>O sistema não envia mensagens para assinaturas já pagas, pausadas ou canceladas.</p><button class="button primary">Salvar configurações</button></footer>
</form>

<section class="card reminder-operations">
    <div><p class="eyebrow">OPERAÇÃO</p><h2>Teste e execução</h2><p>Valide a instância ou processe agora os lembretes elegíveis.</p></div>
    <div>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_whatsapp_connection"><button class="button secondary">Testar conexão</button></form>
        <form method="post" data-confirm="Executar agora pode enviar mensagens reais aos clientes elegíveis. Deseja continuar?"><?= csrf_field() ?><input type="hidden" name="action" value="run_whatsapp_reminders"><button class="button primary">Executar agora</button></form>
    </div>
</section>
<?php else: ?>
<div class="inline-warning">Somente administradores podem alterar a integração. O histórico permanece disponível para consulta.</div>
<?php endif; ?>

<section class="card reminder-history">
    <div class="card-header"><div><p class="eyebrow">HISTÓRICO</p><h2>Últimos lembretes processados</h2><p class="card-subtitle">Última execução: <?= datetime_br($reminderConfig['whatsapp_last_run_at']) ?><?= $lastSummary ? ' · ' . (int) ($lastSummary['sent'] ?? 0) . ' enviado(s)' : '' ?></p></div></div>
    <div class="table-wrap"><table><thead><tr><th>Cliente</th><th>Tipo</th><th>Vencimento</th><th>Telefone</th><th>Tentativa</th><th>Status</th><th>Processado em</th></tr></thead><tbody>
        <?php if (!$reminderHistory): ?><tr><td colspan="7" class="empty-cell">Nenhum lembrete processado até agora.</td></tr><?php endif; ?>
        <?php foreach ($reminderHistory as $item): ?><tr><td><b><?= h($item['client'] ?: 'Cliente removido') ?></b><small class="block"><?= h($item['product'] ?: 'Assinatura removida') ?></small></td><td><?= $item['reminder_type'] === 'upcoming' ? 'A vencer' : 'Vencida' ?></td><td><?= date_br($item['due_date']) ?></td><td><?= h($item['recipient_phone']) ?></td><td><?= (int) $item['reminder_number'] ?>ª</td><td><span class="badge <?= status_class($item['status']) ?>" title="<?= h($item['error_message'] ?? '') ?>"><?= h(['sent'=>'Enviado','failed'=>'Falhou','skipped'=>'Ignorado','pending'=>'Pendente'][$item['status']] ?? $item['status']) ?></span></td><td><?= datetime_br($item['sent_at'] ?: $item['last_attempt_at'] ?: $item['created_at']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</section>

<section class="cron-note">
    <b>Agendamento automático</b><span>Configure na Hostinger a execução de <code>cron/send-whatsapp-reminders.php SEU_CRON_SECRET</code> a cada 15 minutos. O horário e a prevenção de duplicidades são controlados pelo sistema.</span>
</section>
