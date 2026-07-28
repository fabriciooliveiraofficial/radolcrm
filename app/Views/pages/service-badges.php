<?php
$search = trim((string) ($_GET['q'] ?? ''));
$params = [];
$where = '';
if ($search !== '') {
    $where = ' WHERE CONCAT_WS(\' \',b.name,b.icon,b.tone,CASE b.active WHEN 1 THEN \'Ativo\' ELSE \'Inativo\' END) LIKE ?';
    $params[] = '%' . $search . '%';
}
$badges = $db->fetchAll(
    "SELECT b.*,
            (SELECT COUNT(*) FROM subscription_service_badges ssb WHERE ssb.badge_id=b.id) subscription_count
     FROM service_badges b
     {$where}
     ORDER BY b.active DESC,b.name",
    $params
);
$edit = isset($_GET['edit'])
    ? $db->fetch('SELECT * FROM service_badges WHERE id=?', [(int) $_GET['edit']])
    : null;
$showForm = isset($_GET['new']) || $edit;
$iconOptions = service_badge_icon_options();
$toneOptions = service_badge_tone_options();
$selectedIcon = (string) ($edit['icon'] ?? 'sparkles');
$selectedTone = (string) ($edit['tone'] ?? 'emerald');
?>

<section class="badge-library-hero card">
    <div>
        <p class="eyebrow">IDENTIDADE DOS SERVIÇOS</p>
        <h2>Biblioteca de badges</h2>
        <p>Crie marcadores consistentes para reconhecer rapidamente cada serviço nas assinaturas.</p>
    </div>
    <div class="badge-hero-preview">
        <span class="service-badge tone-emerald"><?= service_badge_icon('shield') ?><b>Proteção</b></span>
        <span class="service-badge tone-gold"><?= service_badge_icon('crown') ?><b>Premium</b></span>
        <span class="service-badge tone-sapphire"><?= service_badge_icon('globe') ?><b>Global</b></span>
    </div>
</section>

<section class="toolbar list-toolbar">
    <form class="search-filters" method="get" data-live-filter>
        <input type="hidden" name="page" value="service-badges">
        <label class="search-box">⌕<input name="q" autocomplete="off" placeholder="Buscar badge, ícone ou estilo" value="<?= h($search) ?>"></label>
        <span class="live-filter-indicator" data-live-filter-indicator aria-live="polite">Busca automática</span>
    </form>
    <?php if ($auth->canWrite()): ?><a class="button primary" href="?page=service-badges&new=1">＋ Novo badge</a><?php endif; ?>
</section>

<div data-live-results>
    <section class="service-badge-grid">
        <?php if (!$badges): ?>
            <article class="card empty-state span-full">
                <span>✦</span><h2>Nenhum badge criado</h2>
                <p>Monte sua biblioteca visual e aplique os badges nas assinaturas.</p>
                <?php if ($auth->canWrite()): ?><a class="button primary" href="?page=service-badges&new=1">Criar primeiro badge</a><?php endif; ?>
            </article>
        <?php endif; ?>
        <?php foreach ($badges as $badge): ?>
            <article class="card service-badge-card <?= !$badge['active'] ? 'disabled' : '' ?>">
                <div class="service-badge-card-top">
                    <span class="service-badge-icon tone-<?= h($badge['tone']) ?>"><?= service_badge_icon($badge['icon']) ?></span>
                    <span class="badge <?= $badge['active'] ? 'success' : 'muted' ?>"><?= $badge['active'] ? 'Ativo' : 'Inativo' ?></span>
                </div>
                <h2><?= h($badge['name']) ?></h2>
                <span class="service-badge tone-<?= h($badge['tone']) ?>"><?= service_badge_icon($badge['icon']) ?><b><?= h($badge['name']) ?></b></span>
                <footer>
                    <span><?= (int) $badge['subscription_count'] ?> assinatura(s)</span>
                    <?php if ($auth->canWrite()): ?><a href="?page=service-badges&edit=<?= (int) $badge['id'] ?>">Editar →</a><?php endif; ?>
                </footer>
            </article>
        <?php endforeach; ?>
    </section>
</div>

<?php if ($showForm): ?>
<div class="modal open">
    <a class="modal-backdrop" href="?page=service-badges"></a>
    <section class="modal-panel service-badge-modal">
        <header>
            <div><p class="eyebrow">BADGE DE SERVIÇO</p><h2><?= $edit ? 'Editar badge' : 'Novo badge' ?></h2><p>Escolha um nome, um ícone e uma assinatura visual premium.</p></div>
            <a href="?page=service-badges" class="modal-close">×</a>
        </header>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_service_badge">
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
            <input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI']) ?>">
            <label class="span-2">Nome do badge<input name="name" maxlength="80" required value="<?= h($edit['name'] ?? '') ?>" placeholder="Ex.: Suporte prioritário"></label>
            <fieldset class="badge-option-field span-2">
                <legend>Ícone</legend>
                <div class="badge-icon-options">
                    <?php foreach ($iconOptions as $value => $label): ?>
                        <label>
                            <input type="radio" name="icon" value="<?= h($value) ?>" <?= $selectedIcon === $value ? 'checked' : '' ?>>
                            <span><?= service_badge_icon($value) ?></span><small><?= h($label) ?></small>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <fieldset class="badge-option-field span-2">
                <legend>Estilo</legend>
                <div class="badge-tone-options">
                    <?php foreach ($toneOptions as $value => $label): ?>
                        <label>
                            <input type="radio" name="tone" value="<?= h($value) ?>" <?= $selectedTone === $value ? 'checked' : '' ?>>
                            <span class="tone-<?= h($value) ?>"></span><small><?= h($label) ?></small>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <label class="check-label span-2"><input type="checkbox" name="active" value="1" <?= !isset($edit['active']) || $edit['active'] ? 'checked' : '' ?>><span>Badge disponível para novas associações</span></label>
            <footer class="span-2"><a class="button ghost" href="?page=service-badges">Cancelar</a><button class="button primary">Salvar badge</button></footer>
        </form>
        <?php if ($edit && $auth->canWrite()): ?>
            <form method="post" class="danger-zone" data-confirm="Excluir este badge? Ele será removido de todas as assinaturas.">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete_service_badge"><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
                <button>Excluir badge</button>
            </form>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>
