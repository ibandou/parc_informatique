<?php
// Sites : cartes des sites ; un clic sur une carte affiche dessous ses équipements ou ses
// interventions, selon la sous-catégorie choisie.

$view = page_view('sites');
$closeUrl = current_url([], ['modal', 'id']);
$formError = null;

if (is_post() && input('action') === 'save') {
    $id = int_param('id', 'post');
    $nom = input('nom');
    $adresse = input('adresse');
    $description = input('description') ?: null;

    if ($nom === '' || $adresse === '') {
        $formError = 'Veuillez renseigner tous les champs obligatoires (*).';
    } elseif (db_value('SELECT COUNT(*) FROM sites WHERE nom = ? AND id <> ?', [$nom, $id ?? 0])) {
        $formError = 'Un site porte déjà ce nom.';
    }

    if ($formError === null) {
        if ($id) {
            db_query('UPDATE sites SET nom = ?, adresse = ?, description = ? WHERE id = ?', [$nom, $adresse, $description, $id]);
            flash('success', 'Site modifié.');
        } else {
            db_query('INSERT INTO sites (nom, adresse, description) VALUES (?, ?, ?)', [$nom, $adresse, $description]);
            flash('success', 'Site ajouté.');
        }
        redirect($closeUrl);
    }
}

$select = "SELECT s.*,
                  (SELECT COUNT(*) FROM affectations a JOIN equipements e ON e.id = a.equipement_id AND e.deleted_at IS NULL
                    WHERE a.site_id = s.id AND a.date_fin IS NULL) AS nb_equip,
                  (SELECT COUNT(*) FROM interventions i JOIN affectations a ON a.equipement_actif = i.equipement_id
                    WHERE a.site_id = s.id) AS nb_interv
             FROM sites s";
$pg = paginate((int) db_value('SELECT COUNT(*) FROM sites'));
$sites = db_fetch_all($select . " ORDER BY s.id LIMIT {$pg['limit']} OFFSET {$pg['offset']}");

$selectedId = int_param('site') ?? (int) db_value('SELECT id FROM sites ORDER BY id LIMIT 1');
$selected = $selectedId ? db_fetch('SELECT * FROM sites WHERE id = ?', [$selectedId]) : null;

// Liste détaillée du site sélectionné (pagination indépendante : paramètre "dp")
$showEquip = $view === 'equipements';
$detail = [];
$dpg = paginate(0, 'dp');
if ($selected) {
    if ($showEquip) {
        $where = ' AND a.site_id = ?';
        $dpg = paginate((int) db_value('SELECT COUNT(*) FROM (' . EQUIPEMENT_SELECT . $where . ') t', [$selected['id']]), 'dp');
        $detail = db_fetch_all(EQUIPEMENT_SELECT . $where . " ORDER BY e.numero_inventaire LIMIT {$dpg['limit']} OFFSET {$dpg['offset']}", [$selected['id']]);
    } else {
        $where = ' WHERE a.site_id = ?';
        $dpg = paginate((int) db_value('SELECT COUNT(*) FROM (' . INTERVENTION_SELECT . $where . ') t', [$selected['id']]), 'dp');
        $detail = db_fetch_all(INTERVENTION_SELECT . $where . " ORDER BY i.date_debut DESC LIMIT {$dpg['limit']} OFFSET {$dpg['offset']}", [$selected['id']]);
    }
}
?>
<div class="page-header">
  <div><h1><i class="fa-solid fa-building"></i> Sites</h1><p class="page-subtitle">Sites et implantations d'IT CORP</p></div>
  <a class="btn btn-primary" id="btn-add-site" href="<?= e(current_url(['modal' => 'form'], ['id'])) ?>"><i class="fa-solid fa-plus"></i> Ajouter un site</a>
</div>

<div class="tabs" id="sites-view-tabs">
  <?php foreach (['equipements' => ['Équipements par site', 'fa-desktop'], 'interventions' => ['Interventions par site', 'fa-screwdriver-wrench']] as $v => [$label, $icon]): ?>
    <a class="tab-btn<?= $v === $view ? ' active' : '' ?>" href="<?= e(current_url(['view' => $v], ['dp', 'modal', 'id'])) ?>"><i class="fa-solid <?= $icon ?>"></i> <?= $label ?></a>
  <?php endforeach; ?>
</div>

<div id="sites-grid" class="sites-grid">
  <?php foreach ($sites as $s): ?>
    <div class="site-card selectable<?= $selected && (int) $s['id'] === (int) $selected['id'] ? ' selected' : '' ?>" data-href="<?= e(current_url(['site' => $s['id']], ['dp', 'modal', 'id'])) ?>">
      <div class="site-icon"><i class="fa-solid fa-building"></i></div>
      <div class="site-name"><?= e($s['nom']) ?></div>
      <div class="site-address"><i class="fa-solid fa-location-dot"></i> <?= e(trim($s['adresse'] . ($s['ville'] ? ', ' . $s['ville'] : ''), ', ')) ?></div>
      <p style="font-size:12.5px;color:var(--text-secondary)"><?= e($s['description'] ?? '') ?></p>
      <div class="site-stats">
        <div><i class="fa-solid fa-desktop"></i> <?= (int) $s['nb_equip'] ?> équipements</div>
        <div><i class="fa-solid fa-screwdriver-wrench"></i> <?= (int) $s['nb_interv'] ?> interventions</div>
      </div>
      <div style="margin-top:14px"><a class="btn btn-outline btn-sm" style="width:100%" href="<?= e(current_url(['modal' => 'form', 'id' => $s['id']])) ?>"><i class="fa-solid fa-pen"></i> Modifier</a></div>
    </div>
  <?php endforeach; ?>
</div>
<?= pagination_links($pg) ?>

<div class="site-detail">
  <?php if ($selected): ?>
    <h3 id="site-detail-title" class="site-detail-title"><i class="fa-solid <?= $showEquip ? 'fa-desktop' : 'fa-screwdriver-wrench' ?>"></i> <?= $showEquip ? 'Équipements' : 'Interventions' ?> - <?= e($selected['nom']) ?></h3>
  <?php endif; ?>
  <div class="table-wrapper">
    <table class="data-table" id="table-site-detail">
      <?php if ($showEquip): ?>
        <thead><tr><th>N° inventaire</th><th>Type</th><th>Modèle</th><th>Utilisateur</th><th>Emplacement</th><th>État</th><th>Actions</th></tr></thead>
        <tbody>
          <?php if (!$detail) echo empty_row(7, 'Aucun équipement sur ce site.'); ?>
          <?php foreach ($detail as $eq): ?>
            <tr>
              <td><strong><?= e($eq['numero_inventaire']) ?></strong></td>
              <td><?= e($eq['type']) ?></td>
              <td><?= e($eq['modele'] ?: '-') ?></td>
              <td><?= $eq['utilisateur_id'] ? e(full_name($eq, 'u_')) : '<span class="cell-sub">Non affecté</span>' ?></td>
              <td><?= e($eq['emplacement'] ?: '-') ?></td>
              <td><?= enum_badge(ETAT_EQUIPEMENT, $eq['etat']) ?></td>
              <td class="cell-actions"><a class="icon-btn icon-btn-table" title="Voir" href="<?= e(equipement_link((int) $eq['id'])) ?>"><i class="fa-solid fa-eye"></i></a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      <?php else: ?>
        <thead><tr><th>N° intervention</th><th>Date</th><th>Équipement</th><th>Catégorie</th><th>Technicien</th><th>Priorité</th><th>Statut</th><th>Actions</th></tr></thead>
        <tbody>
          <?php if (!$detail) echo empty_row(8, 'Aucune intervention sur ce site.'); ?>
          <?php foreach ($detail as $it): ?>
            <tr>
              <td><strong><?= e($it['numero']) ?></strong></td>
              <td><?= e(format_date($it['date_debut'])) ?></td>
              <td><?= e($it['numero_inventaire'] ?? '-') ?></td>
              <td><?= e($it['categorie'] ?? '-') ?></td>
              <td><?= e($it['t_prenom'] ? $it['t_prenom'] . ' ' . $it['t_nom'] : 'Non affecté') ?></td>
              <td><?= enum_badge(PRIORITE_INTERVENTION, $it['priorite']) ?></td>
              <td><?= enum_badge(STATUT_INTERVENTION, $it['statut']) ?></td>
              <td class="cell-actions"><a class="icon-btn icon-btn-table" title="Voir" href="<?= e(intervention_link((int) $it['id'])) ?>"><i class="fa-solid fa-eye"></i></a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      <?php endif; ?>
    </table>
  </div>
  <?= pagination_links($dpg) ?>
</div>

<?php
if (($_GET['modal'] ?? '') === 'form') {
    $modalId = int_param('id');
    $s = $modalId ? db_fetch('SELECT * FROM sites WHERE id = ?', [$modalId]) : null;
    $editing = $s !== null;
    modal_open($editing ? 'Modifier le site' : 'Ajouter un site', $editing ? 'fa-pen' : 'fa-plus', $closeUrl);
    ?>
    <form method="post" action="<?= e(current_url()) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= $editing ? (int) $s['id'] : '' ?>">
      <div class="modal-body">
        <?= error_box($formError) ?>
        <div class="form-row single"><div class="form-field"><label>Nom *</label><input type="text" name="nom" value="<?= e(fv('nom', $s['nom'] ?? '')) ?>" required></div></div>
        <div class="form-row single"><div class="form-field"><label>Adresse *</label><input type="text" name="adresse" value="<?= e(fv('adresse', $s['adresse'] ?? '')) ?>" required></div></div>
        <div class="form-row single"><div class="form-field"><label>Description</label><textarea name="description"><?= e(fv('description', $s['description'] ?? '')) ?></textarea></div></div>
      </div>
      <div class="modal-footer">
        <a class="btn btn-outline" href="<?= e($closeUrl) ?>">Annuler</a>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> <?= $editing ? 'Enregistrer' : 'Ajouter' ?></button>
      </div>
    </form>
    <?php
    modal_close();
}
