<?php
$currentRate=$rates->current();$history=$rates->history(10);$users=$db->fetchAll('SELECT id,name,email,role,active,last_login_at FROM users ORDER BY created_at');$logs=$db->fetchAll('SELECT a.*,u.name user_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 12');$user=$auth->user();
?>
<div class="settings-layout"><nav class="settings-nav"><a href="#company">Empresa</a><a href="#exchange">Câmbio</a><a href="#automation">Automações</a><a href="#profile">Meu perfil</a><?php if($auth->isAdmin()): ?><a href="#users">Usuários</a><a href="#audit">Auditoria</a><?php endif; ?></nav><div class="settings-content">
<section class="card settings-card" id="company"><div class="settings-heading"><span class="settings-icon">⌂</span><div><p class="eyebrow">ORGANIZAÇÃO</p><h2>Empresa e caixa</h2><p>Informações básicas usadas nos relatórios.</p></div></div><?php if($auth->isAdmin()): ?><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="save_settings"><label class="span-2">Nome da empresa<input name="company_name" required value="<?= h(setting($db,'company_name','Minha Empresa')) ?>"></label><label>Saldo inicial em BRL<input name="initial_balance_brl" type="number" step="0.01" value="<?= decimal_input(setting($db,'initial_balance_brl',0)) ?>"><small>Saldo anterior ao primeiro lançamento.</small></label><label>Intervalo do cache (minutos)<input name="exchange_cache_minutes" type="number" min="60" max="1440" value="<?= (int)setting($db,'exchange_cache_minutes',720) ?>"><small>Recomendado: 720 minutos.</small></label><input type="hidden" name="manual_exchange_rate" value="<?= h(setting($db,'manual_exchange_rate',5.5)) ?>"><footer class="span-2"><button class="button primary">Salvar empresa</button></footer></form><?php else: ?><dl class="summary-list"><div><dt>Empresa</dt><dd><?= h(setting($db,'company_name')) ?></dd></div><div><dt>Saldo inicial</dt><dd><?= money(setting($db,'initial_balance_brl')) ?></dd></div></dl><?php endif; ?></section>
<section class="card settings-card" id="exchange"><div class="settings-heading"><span class="settings-icon">$</span><div><p class="eyebrow">USD → BRL</p><h2>Cotação diária do dólar</h2><p>Frankfurter open source, sem cadastro ou chave de API.</p></div><div class="current-rate"><small>ÚLTIMA COTAÇÃO DIÁRIA</small><b>US$ 1 = <?= money($currentRate['bid']) ?></b><span><?= date_br($currentRate['quoted_at']) ?></span></div></div><div class="provider-note"><b>Frankfurter</b><span>Fonte diária institucional · sem chave · histórico por data de resgate</span></div><?php if($auth->isAdmin()): ?><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="save_settings"><input type="hidden" name="company_name" value="<?= h(setting($db,'company_name')) ?>"><input type="hidden" name="initial_balance_brl" value="<?= h(setting($db,'initial_balance_brl',0)) ?>"><input type="hidden" name="exchange_cache_minutes" value="<?= h(setting($db,'exchange_cache_minutes',720)) ?>"><label class="span-2">Taxa manual de segurança<input name="manual_exchange_rate" type="number" step="0.000001" min="0.01" value="<?= h(setting($db,'manual_exchange_rate',5.5)) ?>"><small>Usada somente se a fonte diária estiver indisponível e não existir cotação salva.</small></label><footer class="span-2"><button class="button primary">Salvar fallback</button></footer></form><form method="post" class="rate-refresh"><?= csrf_field() ?><input type="hidden" name="action" value="refresh_rate"><input type="hidden" name="_return" value="?page=settings#exchange"><button class="button secondary">↻ Atualizar cotação diária</button></form><?php endif; ?><details class="rate-history"><summary>Ver últimas cotações</summary><div class="table-wrap"><table><thead><tr><th>Data</th><th>Compra</th><th>Venda</th><th>Fonte</th></tr></thead><tbody><?php foreach($history as $row): ?><tr><td><?= date_br($row['quoted_at']) ?></td><td><?= number_format($row['bid'],4,',','.') ?></td><td><?= number_format($row['ask'],4,',','.') ?></td><td><?= h($row['source']) ?></td></tr><?php endforeach; ?></tbody></table></div></details></section>
<section class="card settings-card" id="automation">
    <div class="settings-heading">
        <span class="settings-icon">⚡</span>
        <div>
            <p class="eyebrow">PROCESSAMENTO AUTOMÁTICO</p>
            <h2>Automações financeiras e rotinas cron</h2>
            <p>Execução diária de fechamento de faturas, identificação de parcelas vencidas e projeção de recorrências.</p>
        </div>
    </div>
    
    <?php
    $lastRun = setting($db, 'financial_automation_last_run_at', null);
    $lastSummaryRaw = setting($db, 'financial_automation_last_summary', null);
    $lastSummary = $lastSummaryRaw ? json_decode($lastSummaryRaw, true) : null;
    ?>

    <div class="summary-list" style="margin-bottom: 1.25rem;">
        <div>
            <dt>Última execução automática</dt>
            <dd><?= $lastRun ? datetime_br($lastRun) : 'Ainda não executada' ?></dd>
        </div>
        <div>
            <dt>Status dos processos</dt>
            <dd><span class="badge success">Ativo & Operacional</span></dd>
        </div>
        <?php if ($lastSummary): ?>
            <div>
                <dt>Faturas fechadas</dt>
                <dd><b><?= (int) ($lastSummary['closed_invoices'] ?? 0) ?></b></dd>
            </div>
            <div>
                <dt>Parcelas vencidas marcadas</dt>
                <dd><b><?= (int) ($lastSummary['overdue_installments'] ?? 0) ?></b></dd>
            </div>
            <div>
                <dt>Novas parcelas contínuas</dt>
                <dd><b><?= (int) ($lastSummary['generated_installments'] ?? 0) ?></b></dd>
            </div>
        <?php endif; ?>
    </div>

    <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 6px; padding: 1rem; margin-bottom: 1.25rem;">
        <h3 style="font-size: 0.95rem; margin-top: 0; margin-bottom: 0.5rem;">Rotinas executadas automaticamente:</h3>
        <ul style="margin: 0; padding-left: 1.2rem; font-size: 0.85rem; color: var(--text-muted, #8b9bb4); display: flex; flex-direction: column; gap: 0.35rem;">
            <li><b>Fechamento de Faturas:</b> Detecta cartões cujo dia de fechamento passou e consolida o total da fatura.</li>
            <li><b>Atualização de Status:</b> Identifica parcelas vencidas e marca status como em atraso.</li>
            <li><b>Geração Contínua:</b> Mantém sempre 6 meses de parcelas futuras para despesas fixas contínuas (luz, água, internet).</li>
            <li><b>Monitoramento de Tetos:</b> Compara despesas realizadas contra limitadores e alerta estouros de orçamento.</li>
        </ul>
    </div>

    <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
        <form method="post" style="margin: 0;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="run_financial_automation">
            <button class="button primary">⚡ Executar automação agora</button>
        </form>
        <span class="muted" style="font-size: 0.8rem;">Comando cron: <code>php cron/financial_automation.php</code></span>
    </div>
</section>
<section class="card settings-card" id="profile"><div class="settings-heading"><span class="settings-icon">♙</span><div><p class="eyebrow">CONTA</p><h2>Meu perfil</h2><p>Atualize seus dados de acesso.</p></div></div><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="save_profile"><label>Nome<input name="name" required value="<?= h($user['name']) ?>"></label><label>E-mail<input name="email" type="email" required value="<?= h($user['email']) ?>"></label><label class="span-2">Nova senha<input name="password" type="password" minlength="8" placeholder="Deixe em branco para não alterar"></label><footer class="span-2"><button class="button primary">Atualizar perfil</button></footer></form></section>
<?php if($auth->isAdmin()): ?><section class="card settings-card" id="users"><div class="settings-heading"><span class="settings-icon">♙</span><div><p class="eyebrow">EQUIPE</p><h2>Usuários e permissões</h2><p>Admin configura tudo; gestor lança dados; visualizador apenas consulta.</p></div></div><div class="user-list"><?php foreach($users as $item): ?><div><span class="avatar-sm"><?= h(mb_strtoupper(mb_substr($item['name'],0,1))) ?></span><span><b><?= h($item['name']) ?></b><small><?= h($item['email']) ?> · Último acesso <?= datetime_br($item['last_login_at']) ?></small></span><span class="badge <?= $item['active']?'success':'muted' ?>"><?= h(['admin'=>'Administrador','manager'=>'Gestor','viewer'=>'Visualizador'][$item['role']]) ?></span><?php if((int)$item['id']!==(int)$user['id']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_user"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button class="text-button"><?= $item['active']?'Desativar':'Ativar' ?></button></form><?php endif; ?></div><?php endforeach; ?></div><details class="add-user"><summary class="button secondary">＋ Adicionar usuário</summary><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="save_user"><label>Nome<input name="name" required></label><label>E-mail<input name="email" type="email" required></label><label>Senha inicial<input name="password" type="password" minlength="8" required></label><label>Perfil<select name="role"><option value="manager">Gestor</option><option value="viewer">Visualizador</option><option value="admin">Administrador</option></select></label><footer class="span-2"><button class="button primary">Criar acesso</button></footer></form></details></section>
<section class="card settings-card" id="audit"><div class="settings-heading"><span class="settings-icon">⌁</span><div><p class="eyebrow">SEGURANÇA</p><h2>Atividade recente</h2><p>Trilha das últimas alterações realizadas.</p></div></div><div class="audit-list"><?php if(!$logs): ?><div class="empty-mini">Nenhuma atividade registrada.</div><?php endif; ?><?php foreach($logs as $log): ?><div><span><?= h(mb_strtoupper(mb_substr($log['user_name']?:'S',0,1))) ?></span><p><b><?= h($log['user_name']?:'Sistema') ?></b> · <?= h($log['action']) ?> em <?= h($log['entity_type']) ?><?= $log['entity_id']?' #'.(int)$log['entity_id']:'' ?><small><?= datetime_br($log['created_at']) ?> · <?= h($log['ip_address']) ?></small></p></div><?php endforeach; ?></div></section><?php endif; ?>
</div></div>
</div></div>
