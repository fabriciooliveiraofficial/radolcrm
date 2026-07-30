<?php
$stepId = (int) ($reminderStep['id'] ?? 0);
$stepImage = (string) ($reminderStep['image_url'] ?? '');
$storedImage = str_starts_with($stepImage, 'storage/reminders/') ? $stepImage : '';
$externalImage = $storedImage === '' ? $stepImage : '';
$isUpcomingStep = $stepType === 'upcoming';
?>
<article class="automation-step" data-reminder-step data-step-type="<?= h($stepType) ?>">
    <input type="hidden" name="steps[<?= h($stepType) ?>][<?= h($stepKey) ?>][id]" value="<?= $stepId ?>">
    <input type="hidden" name="steps[<?= h($stepType) ?>][<?= h($stepKey) ?>][existing_image]" value="<?= h($stepImage) ?>">
    <header>
        <span class="step-sequence" data-step-sequence><?= (int) ($reminderStep['position'] ?? 1) ?></span>
        <div><b><?= $isUpcomingStep ? 'Lembrete de vencimento' : 'Mensagem de recuperação' ?></b><small><?= $isUpcomingStep ? 'Sincronizada com a próxima cobrança' : 'Interrompida assim que a assinatura for renovada' ?></small></div>
        <label class="compact-switch" title="Ativar esta etapa">
            <input type="hidden" name="steps[<?= h($stepType) ?>][<?= h($stepKey) ?>][active]" value="0">
            <input type="checkbox" name="steps[<?= h($stepType) ?>][<?= h($stepKey) ?>][active]" value="1" <?= !isset($reminderStep['active']) || (int) $reminderStep['active'] === 1 ? 'checked' : '' ?>>
            <span></span>
        </label>
        <button type="button" class="step-remove" data-remove-reminder-step title="Remover etapa">×</button>
    </header>

    <div class="step-core-grid">
        <label>Nome da etapa<input name="steps[<?= h($stepType) ?>][<?= h($stepKey) ?>][name]" maxlength="100" required value="<?= h($reminderStep['name'] ?? '') ?>" placeholder="<?= $isUpcomingStep ? 'Ex.: Aviso de amanhã' : 'Ex.: Recuperação D+3' ?>"></label>
        <label><?= $isUpcomingStep ? 'Dias antes' : 'Dias em atraso' ?><input name="steps[<?= h($stepType) ?>][<?= h($stepKey) ?>][day_offset]" type="number" min="1" max="<?= $isUpcomingStep ? 365 : 730 ?>" required value="<?= max(1, (int) ($reminderStep['day_offset'] ?? 1)) ?>"></label>
        <label>Horário<input name="steps[<?= h($stepType) ?>][<?= h($stepKey) ?>][send_time]" type="time" required value="<?= h(substr((string) ($reminderStep['send_time'] ?? '09:00'), 0, 5)) ?>"></label>
    </div>

    <div class="step-message-layout">
        <label class="step-template-field"><span>Mensagem / legenda da imagem</span><textarea name="steps[<?= h($stepType) ?>][<?= h($stepKey) ?>][message_template]" rows="7" maxlength="4000" required data-template-source><?= h($reminderStep['message_template'] ?? '') ?></textarea><small>Use as variáveis abaixo. O conteúdo será personalizado com os dados atuais da assinatura.</small></label>
        <div class="step-preview"><span>Prévia dinâmica</span><em data-template-preview></em></div>
    </div>

    <div class="variable-picker step-variables">
        <?php foreach (\App\Services\WhatsAppReminderService::VARIABLES as $variable => $label): ?>
            <button type="button" data-template-variable="<?= h($variable) ?>" title="<?= h($label) ?>">{{<?= h($variable) ?>}}</button>
        <?php endforeach; ?>
    </div>

    <div class="step-assets-grid">
        <label>Imagem por URL<input name="steps[<?= h($stepType) ?>][<?= h($stepKey) ?>][image_url]" type="url" maxlength="1000" value="<?= h($externalImage) ?>" placeholder="https://.../imagem.jpg"><small>JPG, PNG ou WebP acessível pela internet.</small></label>
        <label>Ou enviar imagem<input name="step_image_file[<?= h($stepType) ?>][<?= h($stepKey) ?>]" type="file" accept="image/jpeg,image/png,image/webp"><small>Até 6 MB; será guardada de forma privada.</small></label>
        <label>Link de pagamento padrão<input name="steps[<?= h($stepType) ?>][<?= h($stepKey) ?>][payment_link]" maxlength="1000" value="<?= h($reminderStep['payment_link'] ?? '') ?>" placeholder="https://.../?cliente={{id_cliente}}"><small>O link individual da assinatura tem prioridade.</small></label>
    </div>
    <?php if ($storedImage !== ''): ?>
        <label class="stored-media"><span>✓ Imagem armazenada no servidor</span><input type="checkbox" name="steps[<?= h($stepType) ?>][<?= h($stepKey) ?>][remove_image]" value="1"> Remover imagem atual</label>
    <?php endif; ?>
</article>
