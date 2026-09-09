<?php
$search = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$pageSizeOptions = [20,50,100,200];
$perPage = in_array((int) ($_GET['per_page'] ?? 20), $pageSizeOptions, true) ? (int) ($_GET['per_page'] ?? 20) : 20;
$sortOptions = [
    'client' => 'c.name',
    'country' => "CASE c.country WHEN 'BR' THEN 'Brasil' WHEN 'US' THEN 'Estados Unidos' ELSE c.country END",
    'currency' => 'c.preferred_currency',
    'subscriptions' => 'active_subscriptions',
    'status' => "CASE c.status WHEN 'active' THEN 'Ativo' WHEN 'inactive' THEN 'Inativo' WHEN 'lead' THEN 'Lead' ELSE c.status END",
];
$sort = isset($sortOptions[(string) ($_GET['sort'] ?? '')]) ? (string) $_GET['sort'] : '';
$sortDirection = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$orderBy = $sort === ''
    ? 'c.created_at DESC,c.id DESC'
    : $sortOptions[$sort] . ' ' . strtoupper($sortDirection) . ',c.name ASC,c.id ASC';
$tableSortHeader = static function (string $key, string $label) use ($sort, $sortDirection): string {
    $query = $_GET;
    unset($query['p'], $query['edit'], $query['new']);
    $active = $sort === $key;
    $nextDirection = $active && $sortDirection === 'asc' ? 'desc' : 'asc';
    $query['sort'] = $key;
    $query['dir'] = $nextDirection;
    $ariaSort = $active ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none';
    $indicator = $active ? ($sortDirection === 'asc' ? '↑' : '↓') : '↕';
    $nextLabel = $nextDirection === 'asc' ? 'crescente' : 'decrescente';

    return '<th class="sortable-column' . ($active ? ' is-sorted' : '') . '" aria-sort="' . $ariaSort . '"><a class="table-sort-link" href="?'
        . h(http_build_query($query)) . '" title="Ordenar por ' . h($label) . '" aria-label="Ordenar por ' . h($label) . ', ordem ' . $nextLabel . '"><span>'
        . h($label) . '</span><span class="table-sort-indicator" aria-hidden="true">' . $indicator . '</span></a></th>';
};
$allBusinesses = $db->fetchAll('SELECT id, name, icon, color FROM business_units WHERE active = 1 ORDER BY sort_order ASC, id ASC');

$where = ' WHERE c.deleted_at IS NULL'; $params = [];
if ($buFilter !== null) { 
    $where .= ' AND (c.business_unit_id=? OR (c.business_unit_id IS NULL AND ? = 1))'; 
    $params[] = $buFilter; 
    $params[] = $buFilter; 
}
if ($search !== '') { $where .= " AND CONCAT_WS(' ',c.id,c.name,c.company,c.email,c.phone,c.document,c.country,CASE c.country WHEN 'BR' THEN 'Brasil' WHEN 'US' THEN 'Estados Unidos' END,c.preferred_currency,c.status,CASE c.status WHEN 'active' THEN 'Ativo' WHEN 'lead' THEN 'Lead' WHEN 'inactive' THEN 'Inativo' END,c.notes,(SELECT COUNT(*) FROM subscriptions sx WHERE sx.client_id=c.id AND sx.status='active'),'assinaturas') LIKE ?"; $params[]='%'.$search.'%'; }
if (in_array($status,['lead','active','inactive'],true)) { $where .= ' AND c.status=?'; $params[]=$status; }
$pagination = pagination($db, 'SELECT COUNT(*) FROM clients c'.$where, "SELECT c.*, (SELECT COUNT(*) FROM subscriptions s WHERE s.client_id=c.id AND s.status='active') active_subscriptions FROM clients c".$where.' ORDER BY '.$orderBy, $params, $perPage);
$displayedFrom = $pagination['total'] > 0 ? (($pagination['page'] - 1) * $perPage) + 1 : 0;
$displayedTo = $pagination['total'] > 0 ? $displayedFrom + count($pagination['rows']) - 1 : 0;
$edit = isset($_GET['edit']) ? $db->fetch('SELECT * FROM clients WHERE id=? AND deleted_at IS NULL',[(int)$_GET['edit']]) : null;
$showForm = isset($_GET['new']) || $edit;
?>
<section class="toolbar list-toolbar"><form class="search-filters" method="get" data-live-filter><input type="hidden" name="page" value="clients"><?php if ($buFilter !== null): ?><input type="hidden" name="bu" value="<?= (int)$buFilter ?>"><?php endif; ?><input type="hidden" name="per_page" value="<?= $perPage ?>"><?php if($sort!==''): ?><input type="hidden" name="sort" value="<?= h($sort) ?>"><input type="hidden" name="dir" value="<?= h($sortDirection) ?>"><?php endif; ?><label class="search-box">⌕<input name="q" autocomplete="off" placeholder="Buscar qualquer informação" value="<?= h($search) ?>"></label><select name="status"><option value="">Todos os status</option><option value="active" <?= $status==='active'?'selected':'' ?>>Ativos</option><option value="lead" <?= $status==='lead'?'selected':'' ?>>Leads</option><option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inativos</option></select><span class="live-filter-indicator" data-live-filter-indicator aria-live="polite">Busca automática</span></form><div><a class="button ghost" href="?page=export&type=clients<?= $buFilter ? '&bu=' . (int)$buFilter : '' ?>">⇩ Exportar</a><?php if($auth->canWrite()): ?><a class="button primary" href="?page=clients&new=1<?= $buFilter ? '&bu=' . (int)$buFilter : '' ?>">＋ Novo cliente</a><?php endif; ?></div></section>
<div data-live-results>
<section class="card table-card">
    <div class="table-meta with-page-size"><span class="table-range-summary"><b><?= $pagination['total'] ?></b> clientes encontrados<small>Exibindo <?= $displayedFrom ?>–<?= $displayedTo ?> de <?= $pagination['total'] ?></small></span><form class="page-size-form" method="get"><input type="hidden" name="page" value="clients"><?php if($buFilter!==null): ?><input type="hidden" name="bu" value="<?= (int)$buFilter ?>"><?php endif; ?><?php if($search!==''): ?><input type="hidden" name="q" value="<?= h($search) ?>"><?php endif; ?><?php if($status!==''): ?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif; ?><?php if($sort!==''): ?><input type="hidden" name="sort" value="<?= h($sort) ?>"><input type="hidden" name="dir" value="<?= h($sortDirection) ?>"><?php endif; ?><label>Linhas por página<select name="per_page" data-page-size-select><?php foreach($pageSizeOptions as $pageSize): ?><option value="<?= $pageSize ?>" <?= $perPage===$pageSize?'selected':'' ?>><?= $pageSize ?></option><?php endforeach; ?></select></label></form></div>
    <div class="table-wrap"><table><thead><tr><?= $tableSortHeader('client', 'Cliente') ?><?= $tableSortHeader('country', 'País') ?><?= $tableSortHeader('currency', 'Moeda') ?><?= $tableSortHeader('subscriptions', 'Assinaturas') ?><?= $tableSortHeader('status', 'Status') ?><th class="actions-column"><span class="sr-only">Ações</span></th></tr></thead><tbody>
    <?php if(!$pagination['rows']): ?><tr><td colspan="6" class="empty-cell">Nenhum cliente encontrado. Cadastre o primeiro cliente para começar.</td></tr><?php endif; ?>
    <?php foreach($pagination['rows'] as $item): ?><tr><td><div class="entity"><span class="avatar-sm"><?= h(mb_strtoupper(mb_substr($item['name'],0,1))) ?></span><span><b><?= h($item['name']) ?></b><small><?= h($item['company'] ?: $item['email'] ?: 'Sem contato informado') ?></small></span></div></td><td><span class="country-cell"><?= country_flag_icon($item['country']) ?><span><?= $item['country']==='BR'?'Brasil':'Estados Unidos' ?></span></span></td><td><b><?= h($item['preferred_currency']) ?></b></td><td><?= (int)$item['active_subscriptions'] ?> ativa(s)</td><td><span class="badge <?= status_class($item['status']) ?>"><?= status_label($item['status']) ?></span><small class="block"><?= (int)$item['whatsapp_reminders_enabled']===1 ? 'WhatsApp autorizado' : 'WhatsApp desativado' ?></small></td><td><div style="display:inline-flex;align-items:center;gap:6px;"><a class="row-action" href="?page=clients&edit=<?= (int)$item['id'] ?><?= $buFilter ? '&bu=' . (int)$buFilter : '' ?>" title="Editar cliente">•••</a><?php if($auth->canWrite()): ?><button type="button" class="row-action" style="background:none;border:none;cursor:pointer;color:#dc2626;font-size:13px;padding:3px 6px;line-height:1;border-radius:4px;" title="Excluir cliente" onclick="openDeleteClientModal(<?= (int)$item['id'] ?>, '<?= h(addslashes($item['name'])) ?>')">🗑️</button><?php endif; ?></div></td></tr><?php endforeach; ?>
    </tbody></table></div><?= render_pagination($pagination) ?>
</section>
</div>
<?php if($showForm): ?>
<div class="modal open"><a class="modal-backdrop" href="?page=clients<?= $buFilter ? '&bu=' . (int)$buFilter : '' ?>"></a><section class="modal-panel"><header><div><p class="eyebrow">CLIENTES</p><h2><?= $edit?'Editar cliente':'Novo cliente' ?></h2></div><a href="?page=clients<?= $buFilter ? '&bu=' . (int)$buFilter : '' ?>" class="modal-close">×</a></header><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="save_client"><input type="hidden" name="id" value="<?= (int)($edit['id']??0) ?>"><input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">
<?php 
$activeBuClient = null;
if ($buFilter) {
    foreach ($allBusinesses as $b) {
        if ((int)$b['id'] === $buFilter) { $activeBuClient = $b; break; }
    }
}
if ($activeBuClient && !$edit): 
?>
    <label class="span-2">
        <span style="display: flex; align-items: center; justify-content: space-between;">
            <span>Unidade de Negócio / Empresa</span>
            <span class="badge success" style="font-size: 10px; font-weight: 600;">🔒 Automático & Travado</span>
        </span>
        <input type="hidden" name="business_unit_id" value="<?= (int) $activeBuClient['id'] ?>">
        <div style="display: flex; align-items: center; gap: 8px; background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 6px; padding: 0.6rem 0.8rem; font-size: 13.5px; font-weight: 600; color: var(--ink);">
            <span style="font-size: 1.2rem;"><?= h($activeBuClient['icon'] ?: '🏢') ?></span>
            <span><?= h($activeBuClient['name']) ?></span>
            <small style="margin-left: auto; color: var(--muted); font-size: 11px; font-weight: normal;">Isolado nesta página</small>
        </div>
    </label>
<?php else: ?>
    <label class="span-2">Unidade de Negócio / Empresa<select name="business_unit_id" required><?php $selectedBuClient = $edit ? (int)($edit['business_unit_id'] ?? 0) : ($buFilter ?: (int)($allBusinesses[0]['id'] ?? 1)); foreach ($allBusinesses as $bu): ?><option value="<?= (int) $bu['id'] ?>" <?= $selectedBuClient === (int) $bu['id'] ? 'selected' : '' ?>><?= h($bu['icon']) ?> <?= h($bu['name']) ?></option><?php endforeach; ?></select></label>
<?php endif; ?>
<label class="span-2">Nome completo ou razão social<input name="name" required value="<?= h($edit['name']??'') ?>"></label><label>Empresa<input name="company" value="<?= h($edit['company']??'') ?>"></label><label>E-mail<input name="email" type="email" value="<?= h($edit['email']??'') ?>"></label><label>WhatsApp<input name="phone" inputmode="tel" value="<?= h($edit['phone']??'') ?>" placeholder="Ex.: 55 11 99999-9999"><small>Informe DDI, DDD e número para receber lembretes.</small></label><label class="check-inline span-2"><input type="checkbox" name="whatsapp_reminders_enabled" value="1" <?= !$edit || (int)($edit['whatsapp_reminders_enabled']??1)===1?'checked':'' ?>> Autorizar lembretes e mensagens automáticas neste WhatsApp</label><label>CPF, CNPJ ou Tax ID<input name="document" value="<?= h($edit['document']??'') ?>"></label><label>País<select name="country" data-country><option value="BR" <?= ($edit['country']??'BR')==='BR'?'selected':'' ?>>Brasil</option><option value="US" <?= ($edit['country']??'')==='US'?'selected':'' ?>>Estados Unidos</option></select></label><label>Moeda preferida<select name="preferred_currency" data-currency><option value="BRL" <?= ($edit['preferred_currency']??'BRL')==='BRL'?'selected':'' ?>>BRL — Real</option><option value="USD" <?= ($edit['preferred_currency']??'')==='USD'?'selected':'' ?>>USD — Dólar</option></select></label><label class="span-2">Status<select name="status"><option value="active" <?= ($edit['status']??'active')==='active'?'selected':'' ?>>Ativo</option><option value="lead" <?= ($edit['status']??'')==='lead'?'selected':'' ?>>Lead</option><option value="inactive" <?= ($edit['status']??'')==='inactive'?'selected':'' ?>>Inativo</option></select></label><label class="span-2">Observações<textarea name="notes" rows="3"><?= h($edit['notes']??'') ?></textarea></label><footer class="span-2"><a class="button ghost" href="?page=clients<?= $buFilter ? '&bu=' . (int)$buFilter : '' ?>">Cancelar</a><button class="button primary">Salvar cliente</button></footer></form>
<?php if($edit&&$auth->canWrite()): ?>
    <div class="danger-zone" style="margin-top:1.5rem;padding-top:1.2rem;border-top:1px dashed var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <div>
            <b style="color:var(--red);font-size:0.88rem;display:block;">Zona de perigo</b>
            <small style="color:var(--muted);font-size:0.8rem;">Opção avançada para exclusão ou arquivamento de cliente e gestão de lançamentos.</small>
        </div>
        <button type="button" class="button" style="background:#ef4444;color:#fff;border:1px solid #dc2626;padding:0.45rem 0.9rem;border-radius:6px;font-weight:600;font-size:0.82rem;cursor:pointer;display:inline-flex;align-items:center;gap:6px;" onclick="openDeleteClientModal(<?= (int)$edit['id'] ?>, '<?= h(addslashes($edit['name'])) ?>')">
            🗑️ Excluir cliente
        </button>
    </div>
<?php endif; ?></section></div>
<?php endif; ?>

<!-- MODAL DE EXCLUSÃO AVANÇADA DE CLIENTE -->
<div id="modal-advanced-delete-client" class="modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:99999;align-items:center;justify-content:center;" role="dialog" aria-modal="true" aria-labelledby="adv-delete-title">
    <div class="modal-backdrop" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.6);backdrop-filter:blur(3px);" onclick="closeDeleteClientModal()"></div>
    <section class="modal-panel wide" style="position:relative;z-index:2;max-width:720px;width:92%;max-height:90vh;display:flex;flex-direction:column;background:var(--surface,#fff);border-radius:12px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);overflow:hidden;">
        <header style="padding:1.25rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;">
            <div>
                <p class="eyebrow" style="color:var(--red,#dc2626);font-weight:700;letter-spacing:0.06em;margin:0;display:inline-flex;align-items:center;gap:6px;">
                    <span>⚠️</span> EXCLUSÃO AVANÇADA DE CLIENTE
                </p>
                <h2 id="adv-delete-title" style="margin:0.25rem 0 0.2rem 0;font-size:1.3rem;">
                    Excluir <span id="adv-delete-client-name" style="color:var(--ink);">Cliente</span>
                </h2>
                <p id="adv-delete-client-sub" style="font-size:0.85rem;color:var(--muted);margin:0;">
                    Verificando histórico contábil e lançamentos vinculados...
                </p>
            </div>
            <button type="button" class="modal-close" style="background:none;border:none;font-size:1.6rem;cursor:pointer;color:var(--muted);line-height:1;" onclick="closeDeleteClientModal()" aria-label="Fechar">×</button>
        </header>

        <div id="adv-delete-body" style="padding:1.25rem 1.5rem;overflow-y:auto;flex:1;">
            <!-- Loading State -->
            <div id="adv-delete-loading" style="text-align:center;padding:2.5rem 1rem;">
                <div style="display:inline-block;width:36px;height:36px;border:3px solid #e2e8f0;border-top-color:#dc2626;border-radius:50%;animation:adv-spin 0.8s linear infinite;"></div>
                <p style="margin-top:1rem;color:var(--muted);font-size:0.9rem;">Consultando pagamentos, assinaturas e vínculos deste cliente...</p>
            </div>

            <!-- Error State -->
            <div id="adv-delete-error" style="display:none;padding:1.25rem;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#991b1b;margin-bottom:1rem;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <span>⚠️</span>
                    <strong id="adv-delete-error-msg">Não foi possível carregar os dados do cliente.</strong>
                </div>
            </div>

            <!-- Content State -->
            <div id="adv-delete-content" style="display:none;">
                <!-- Cenário A: Cliente sem lançamentos -->
                <div id="adv-delete-no-entries" style="display:none;text-align:center;padding:1.5rem 1rem;">
                    <div style="font-size:2.6rem;margin-bottom:0.75rem;">🛡️</div>
                    <h3 style="margin:0 0 0.5rem 0;font-size:1.15rem;color:var(--ink);">Nenhum lançamento vinculado</h3>
                    <p style="color:var(--muted);max-width:500px;margin:0 auto;font-size:0.9rem;line-height:1.5;">
                        Este cliente não possui nenhum pagamento ou assinatura registrada. A exclusão será direta e removerá o cadastro completamente sem impacto financeiro ou contábil.
                    </p>
                </div>

                <!-- Cenário B: Cliente com lançamentos vinculados -->
                <div id="adv-delete-has-entries" style="display:none;">
                    <div style="background:rgba(239,68,68,0.06);border:1px solid rgba(239,68,68,0.22);border-radius:8px;padding:0.9rem 1.1rem;margin-bottom:1.25rem;">
                        <div style="display:flex;align-items:flex-start;gap:10px;">
                            <span style="font-size:1.25rem;line-height:1;margin-top:2px;">ℹ️</span>
                            <div>
                                <b style="color:#991b1b;font-size:0.92rem;display:block;">Lançamentos vinculados encontrados</b>
                                <p style="margin:0.25rem 0 0 0;font-size:0.84rem;color:#7f1d1d;line-height:1.45;">
                                    Este cliente possui <span id="adv-badge-counts" style="font-weight:700;"></span>. Como esses lançamentos podem impactar o faturamento bruto e o fluxo de caixa, selecione uma das opções abaixo:
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Opções de Modo de Exclusão -->
                    <div style="display:flex;flex-direction:column;gap:0.75rem;margin-bottom:1.25rem;">
                        <!-- Opção 1: Excluir tudo -->
                        <label class="adv-choice-card" style="display:flex;align-items:flex-start;gap:12px;padding:0.9rem 1rem;border:1.5px solid var(--border);border-radius:8px;cursor:pointer;transition:all 0.15s ease;">
                            <input type="radio" name="delete_mode_radio" value="all" onchange="onDeleteModeChange('all')" style="margin-top:4px;">
                            <div>
                                <strong style="display:block;font-size:0.92rem;color:var(--red,#dc2626);">Excluir cliente e TODOS os lançamentos em massa</strong>
                                <span style="display:block;font-size:0.82rem;color:var(--muted);margin-top:2px;line-height:1.4;">
                                    Apaga o cliente e todos os seus pagamentos e assinaturas permanentemente. Os cartões de <b>faturamento bruto</b>, <b>lucro líquido</b> e <b>saldo</b> serão recalculados automaticamente.
                                </span>
                            </div>
                        </label>

                        <!-- Opção 2: Selecionar lançamentos -->
                        <label class="adv-choice-card" style="display:flex;align-items:flex-start;gap:12px;padding:0.9rem 1rem;border:1.5px solid var(--border);border-radius:8px;cursor:pointer;transition:all 0.15s ease;">
                            <input type="radio" name="delete_mode_radio" value="selected" onchange="onDeleteModeChange('selected')" style="margin-top:4px;">
                            <div>
                                <strong style="display:block;font-size:0.92rem;color:var(--ink);">Selecionar quais lançamentos excluir</strong>
                                <span style="display:block;font-size:0.82rem;color:var(--muted);margin-top:2px;line-height:1.4;">
                                    Escolha item por item quais pagamentos e assinaturas deseja excluir e quais deseja manter. Os mantidos permanecerão com a tag de cliente excluído da carteira.
                                </span>
                            </div>
                        </label>

                        <!-- Opção 3: Manter todos os lançamentos -->
                        <label class="adv-choice-card" style="display:flex;align-items:flex-start;gap:12px;padding:0.9rem 1rem;border:1.5px solid var(--border);border-radius:8px;cursor:pointer;transition:all 0.15s ease;">
                            <input type="radio" name="delete_mode_radio" value="keep" onchange="onDeleteModeChange('keep')" style="margin-top:4px;">
                            <div>
                                <strong style="display:block;font-size:0.92rem;color:#15803d;">Manter todos os lançamentos (Remover apenas o cliente da carteira)</strong>
                                <span style="display:block;font-size:0.82rem;color:var(--muted);margin-top:2px;line-height:1.4;">
                                    O cliente é removido da carteira ativa de clientes. Todos os lançamentos passados continuam íntegros nos cálculos financeiros, exibindo discretamente a tag <span class="badge muted" style="font-size:9px;padding:1px 5px;">Excluído da carteira</span> ao lado do nome.
                                </span>
                            </div>
                        </label>
                    </div>

                    <!-- Área dinâmica para seleção manual de lançamentos -->
                    <div id="adv-delete-selection-area" style="display:none;border-top:1px dashed var(--border);padding-top:1.1rem;margin-top:0.5rem;">
                        <!-- Seção Pagamentos -->
                        <div id="adv-section-payments" style="margin-bottom:1.25rem;">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem;">
                                <span style="font-weight:700;font-size:0.88rem;color:var(--ink);">
                                    💰 Recebimentos / Pagamentos (<span id="adv-pay-count">0</span>)
                                </span>
                                <button type="button" class="button ghost" style="padding:0.25rem 0.6rem;font-size:0.75rem;cursor:pointer;" onclick="toggleAllCheckboxes('payment')">
                                    Alternar todos
                                </button>
                            </div>
                            <div style="max-height:190px;overflow-y:auto;border:1px solid var(--border);border-radius:6px;background:var(--surface);">
                                <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
                                    <thead>
                                        <tr style="background:rgba(0,0,0,0.03);border-bottom:1px solid var(--border);text-align:left;">
                                            <th style="padding:7px 10px;width:36px;text-align:center;">
                                                <input type="checkbox" id="adv-check-all-payments" onchange="checkAll('payment', this.checked)" title="Selecionar todos os pagamentos">
                                            </th>
                                            <th style="padding:7px 10px;">Data</th>
                                            <th style="padding:7px 10px;">Descrição</th>
                                            <th style="padding:7px 10px;">Valor</th>
                                            <th style="padding:7px 10px;">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="adv-tbody-payments"></tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Seção Assinaturas -->
                        <div id="adv-section-subs" style="margin-bottom:0.5rem;">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem;">
                                <span style="font-weight:700;font-size:0.88rem;color:var(--ink);">
                                    📦 Assinaturas Recorrentes (<span id="adv-sub-count">0</span>)
                                </span>
                                <button type="button" class="button ghost" style="padding:0.25rem 0.6rem;font-size:0.75rem;cursor:pointer;" onclick="toggleAllCheckboxes('sub')">
                                    Alternar todas
                                </button>
                            </div>
                            <div style="max-height:160px;overflow-y:auto;border:1px solid var(--border);border-radius:6px;background:var(--surface);">
                                <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
                                    <thead>
                                        <tr style="background:rgba(0,0,0,0.03);border-bottom:1px solid var(--border);text-align:left;">
                                            <th style="padding:7px 10px;width:36px;text-align:center;">
                                                <input type="checkbox" id="adv-check-all-subs" onchange="checkAll('sub', this.checked)" title="Selecionar todas as assinaturas">
                                            </th>
                                            <th style="padding:7px 10px;">Produto / Plano</th>
                                            <th style="padding:7px 10px;">Valor Recorrente</th>
                                            <th style="padding:7px 10px;">Próximo Venc.</th>
                                            <th style="padding:7px 10px;">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="adv-tbody-subs"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Formulário oculto para POST seguro -->
        <form id="adv-delete-form" method="post" action="?page=clients<?= $buFilter ? '&bu=' . (int)$buFilter : '' ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_client">
            <input type="hidden" name="id" id="adv-form-client-id" value="">
            <input type="hidden" name="delete_mode" id="adv-form-delete-mode" value="">
            <input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">
            <div id="adv-form-dynamic-inputs"></div>
        </form>

        <footer style="padding:1rem 1.5rem;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:rgba(0,0,0,0.015);gap:12px;">
            <button type="button" class="button ghost" onclick="closeDeleteClientModal()">Cancelar</button>
            <div style="display:flex;align-items:center;gap:12px;text-align:right;">
                <span id="adv-delete-summary" style="font-size:0.82rem;color:var(--muted);font-weight:500;"></span>
                <button type="button" id="adv-delete-confirm-btn" class="button" style="background:#dc2626;color:#fff;border:1px solid #b91c1c;padding:0.5rem 1.1rem;border-radius:6px;font-weight:600;font-size:0.86rem;cursor:pointer;" onclick="submitAdvancedDelete()" disabled>
                    Confirmar exclusão
                </button>
            </div>
        </footer>
    </section>
</div>

<style>
@keyframes adv-spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
.adv-choice-card:hover {
    border-color: rgba(220, 38, 38, 0.45) !important;
    background: rgba(220, 38, 38, 0.02);
}
.adv-choice-card.is-selected {
    border-color: #dc2626 !important;
    background: rgba(220, 38, 38, 0.04) !important;
}
</style>

<script>
let advClientData = null;
let advCurrentMode = '';

function openDeleteClientModal(clientId, clientName) {
    const modal = document.getElementById('modal-advanced-delete-client');
    if (!modal) return;

    modal.style.display = 'flex';
    document.getElementById('adv-delete-client-name').textContent = clientName || 'Cliente';
    document.getElementById('adv-delete-client-sub').textContent = 'Consultando pagamentos, assinaturas e vínculos contábeis...';
    document.getElementById('adv-form-client-id').value = clientId;

    // Reset de estado
    document.getElementById('adv-delete-loading').style.display = 'block';
    document.getElementById('adv-delete-error').style.display = 'none';
    document.getElementById('adv-delete-content').style.display = 'none';
    document.getElementById('adv-delete-no-entries').style.display = 'none';
    document.getElementById('adv-delete-has-entries').style.display = 'none';
    document.getElementById('adv-delete-selection-area').style.display = 'none';
    document.getElementById('adv-delete-confirm-btn').disabled = true;
    document.getElementById('adv-delete-summary').textContent = '';
    advCurrentMode = '';
    advClientData = null;

    // Limpar radios
    document.querySelectorAll('input[name="delete_mode_radio"]').forEach(r => {
        r.checked = false;
        r.closest('.adv-choice-card')?.classList.remove('is-selected');
    });

    // Fazer requisição AJAX via POST com CSRF
    const csrfToken = document.querySelector('#adv-delete-form input[name="_token"]')?.value || '';
    const params = new URLSearchParams();
    params.append('action', 'get_client_entries');
    params.append('client_id', clientId);
    params.append('_token', csrfToken);

    fetch('index.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(res => {
        if (!res.ok) throw new Error('Falha ao consultar lançamentos do cliente.');
        return res.json();
    })
    .then(data => {
        document.getElementById('adv-delete-loading').style.display = 'none';
        if (!data.ok) {
            throw new Error(data.message || 'Erro desconhecido');
        }
        advClientData = data;
        renderDeleteModalContent();
    })
    .catch(err => {
        document.getElementById('adv-delete-loading').style.display = 'none';
        document.getElementById('adv-delete-error').style.display = 'block';
        document.getElementById('adv-delete-error-msg').textContent = err.message || 'Erro ao carregar dados.';
    });
}

function closeDeleteClientModal() {
    const modal = document.getElementById('modal-advanced-delete-client');
    if (modal) modal.style.display = 'none';
}

function renderDeleteModalContent() {
    document.getElementById('adv-delete-content').style.display = 'block';
    const totalEntries = advClientData.counts.total;

    if (totalEntries === 0) {
        // Cenário sem lançamentos
        document.getElementById('adv-delete-no-entries').style.display = 'block';
        document.getElementById('adv-delete-has-entries').style.display = 'none';
        document.getElementById('adv-delete-confirm-btn').disabled = false;
        document.getElementById('adv-delete-confirm-btn').textContent = 'Confirmar exclusão simples';
        document.getElementById('adv-delete-summary').textContent = 'Exclusão direta do cadastro';
        advCurrentMode = 'direct';
    } else {
        // Cenário com lançamentos
        document.getElementById('adv-delete-no-entries').style.display = 'none';
        document.getElementById('adv-delete-has-entries').style.display = 'block';

        const parts = [];
        if (advClientData.counts.payments > 0) {
            parts.push(advClientData.counts.payments + (advClientData.counts.payments === 1 ? ' pagamento' : ' pagamentos'));
        }
        if (advClientData.counts.subscriptions > 0) {
            parts.push(advClientData.counts.subscriptions + (advClientData.counts.subscriptions === 1 ? ' assinatura' : ' assinaturas'));
        }
        document.getElementById('adv-badge-counts').textContent = parts.join(' e ');

        // Renderizar tabela de pagamentos
        const tbodyPay = document.getElementById('adv-tbody-payments');
        tbodyPay.innerHTML = '';
        document.getElementById('adv-pay-count').textContent = advClientData.payments.length;
        if (advClientData.payments.length === 0) {
            document.getElementById('adv-section-payments').style.display = 'none';
        } else {
            document.getElementById('adv-section-payments').style.display = 'block';
            advClientData.payments.forEach(p => {
                const tr = document.createElement('tr');
                tr.style.borderBottom = '1px solid var(--border)';
                tr.innerHTML = `
                    <td style="padding:6px 10px;text-align:center;">
                        <input type="checkbox" class="adv-payment-checkbox" value="${p.id}" onchange="updateSummary()" checked>
                    </td>
                    <td style="padding:6px 10px;font-weight:600;">${p.date}</td>
                    <td style="padding:6px 10px;">${escapeHtml(p.description)}</td>
                    <td style="padding:6px 10px;font-weight:700;">${p.amount_formatted}</td>
                    <td style="padding:6px 10px;"><span class="badge ${p.status_class}">${p.status_label}</span></td>
                `;
                tbodyPay.appendChild(tr);
            });
        }

        // Renderizar tabela de assinaturas
        const tbodySub = document.getElementById('adv-tbody-subs');
        tbodySub.innerHTML = '';
        document.getElementById('adv-sub-count').textContent = advClientData.subscriptions.length;
        if (advClientData.subscriptions.length === 0) {
            document.getElementById('adv-section-subs').style.display = 'none';
        } else {
            document.getElementById('adv-section-subs').style.display = 'block';
            advClientData.subscriptions.forEach(s => {
                const tr = document.createElement('tr');
                tr.style.borderBottom = '1px solid var(--border)';
                tr.innerHTML = `
                    <td style="padding:6px 10px;text-align:center;">
                        <input type="checkbox" class="adv-sub-checkbox" value="${s.id}" onchange="updateSummary()" checked>
                    </td>
                    <td style="padding:6px 10px;font-weight:600;">${escapeHtml(s.product_name)}</td>
                    <td style="padding:6px 10px;font-weight:700;">${s.value_formatted}</td>
                    <td style="padding:6px 10px;">${s.next_billing}</td>
                    <td style="padding:6px 10px;"><span class="badge ${s.status_class}">${s.status_label}</span></td>
                `;
                tbodySub.appendChild(tr);
            });
        }
    }
}

function onDeleteModeChange(mode) {
    advCurrentMode = mode;

    document.querySelectorAll('.adv-choice-card').forEach(card => card.classList.remove('is-selected'));
    const selectedRadio = document.querySelector(`input[name="delete_mode_radio"][value="${mode}"]`);
    if (selectedRadio) {
        selectedRadio.closest('.adv-choice-card')?.classList.add('is-selected');
    }

    const selectionArea = document.getElementById('adv-delete-selection-area');
    if (mode === 'selected') {
        selectionArea.style.display = 'block';
    } else {
        selectionArea.style.display = 'none';
    }

    updateSummary();
}

function checkAll(type, isChecked) {
    const selector = type === 'payment' ? '.adv-payment-checkbox' : '.adv-sub-checkbox';
    document.querySelectorAll(selector).forEach(cb => cb.checked = isChecked);
    updateSummary();
}

function toggleAllCheckboxes(type) {
    const selector = type === 'payment' ? '.adv-payment-checkbox' : '.adv-sub-checkbox';
    const checkboxes = document.querySelectorAll(selector);
    const anyUnchecked = Array.from(checkboxes).some(cb => !cb.checked);
    checkboxes.forEach(cb => cb.checked = anyUnchecked);
    const headCheck = document.getElementById(type === 'payment' ? 'adv-check-all-payments' : 'adv-check-all-subs');
    if (headCheck) headCheck.checked = anyUnchecked;
    updateSummary();
}

function updateSummary() {
    const confirmBtn = document.getElementById('adv-delete-confirm-btn');
    const summarySpan = document.getElementById('adv-delete-summary');

    if (!advCurrentMode) {
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Selecione uma opção';
        summarySpan.textContent = '';
        return;
    }

    confirmBtn.disabled = false;

    if (advCurrentMode === 'direct') {
        confirmBtn.textContent = 'Confirmar exclusão simples';
        summarySpan.textContent = 'Remover cliente';
    } else if (advCurrentMode === 'all') {
        confirmBtn.textContent = 'Excluir tudo permanentemente';
        summarySpan.textContent = `Será excluído: Cliente + todos os ${advClientData.counts.total} lançamentos`;
    } else if (advCurrentMode === 'keep') {
        confirmBtn.textContent = 'Excluir cliente e manter lançamentos';
        summarySpan.textContent = `Cliente removido da carteira (todos os ${advClientData.counts.total} lançamentos mantidos)`;
    } else if (advCurrentMode === 'selected') {
        const selectedPayments = document.querySelectorAll('.adv-payment-checkbox:checked').length;
        const totalPayments = document.querySelectorAll('.adv-payment-checkbox').length;
        const selectedSubs = document.querySelectorAll('.adv-sub-checkbox:checked').length;
        const totalSubs = document.querySelectorAll('.adv-sub-checkbox').length;

        const totalSelected = selectedPayments + selectedSubs;
        const totalAll = totalPayments + totalSubs;

        if (totalSelected === 0) {
            confirmBtn.disabled = true;
            confirmBtn.textContent = 'Selecione ao menos 1 lançamento';
            summarySpan.textContent = 'Nenhum lançamento selecionado para exclusão';
        } else {
            confirmBtn.textContent = `Excluir ${totalSelected} lançamento(s) selecionado(s)`;
            summarySpan.textContent = `Será excluído: Cliente + ${totalSelected} de ${totalAll} lançamentos (${totalAll - totalSelected} mantidos)`;
        }
    }
}

function submitAdvancedDelete() {
    const form = document.getElementById('adv-delete-form');
    const dynamicInputs = document.getElementById('adv-form-dynamic-inputs');
    dynamicInputs.innerHTML = '';

    document.getElementById('adv-form-delete-mode').value = advCurrentMode;

    if (advCurrentMode === 'selected') {
        const payChecked = document.querySelectorAll('.adv-payment-checkbox:checked');
        payChecked.forEach(cb => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_payments[]';
            input.value = cb.value;
            dynamicInputs.appendChild(input);
        });

        const subChecked = document.querySelectorAll('.adv-sub-checkbox:checked');
        subChecked.forEach(cb => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_subscriptions[]';
            input.value = cb.value;
            dynamicInputs.appendChild(input);
        });
    }

    const btn = document.getElementById('adv-delete-confirm-btn');
    btn.disabled = true;
    btn.textContent = 'Processando exclusão...';

    form.submit();
}

function escapeHtml(text) {
    if (!text) return '';
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}
</script>
