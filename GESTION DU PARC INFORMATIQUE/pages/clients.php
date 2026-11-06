<?php
// Clients externes dont IT CORP gère tout ou partie du parc (en plus du parc interne).

$closeUrl = page_url('clients', ['p' => $_GET['p'] ?? '']);
$formError = null;

if (is_post() && input('action') === 'save') {
    $id = int_param('id', 'post');
    $nom = input('nom');
    $telephone = input('telephone') ?: null;

    if ($nom === '') {
        $formError = 'Veuillez renseigner le nom du client.';
    } elseif (db_value('SELECT COUNT(*) FROM clients WHERE nom = ? AND id <> ?', [$nom, $id ?? 0])) {
        $formError = 'Un client porte déjà ce nom.';
    }

    if ($formError === null) {
        if ($id) {
            db_query('UPDATE clients SET nom = ?, telephone = ? WHERE id = ?', [$nom, $telephone, $id]);
            flash('success', 'Client modifié.');
        } else {
            db_query('INSERT INTO clients (nom, telephone) VALUES (?, ?)', [$nom, $telephone]);
            flash('success', 'Client ajouté.');
        }
        redirect($closeUrl);
    }
}

$pg = paginate((int) db_value('SELECT COUNT(*) FROM clients'));
$clients = db_fetch_all(
    "SELECT c.*, (SELECT COUNT(*) FROM affectations a JOIN equipements e ON e.id = a.equipement_id AND e.deleted_at IS NULL
                   WHERE a.client_id = c.id AND a.date_fin IS NULL) AS nb_equip
       FROM clients c ORDER BY c.id LIMIT {$pg['limit']} OFFSET {$pg['offset']}"
);
?>
<div class="page-header">
  <div><h1><i class="fa-solid fa-briefcase"></i> Clients</h1><p class="page-subtitle">Clients externes dont IT CORP gère tout ou partie du parc</p></div>
  <a class="btn btn-primary" id="btn-add-client" href="<?= e(current_url(['modal' => 'form'], ['id'])) ?>"><i class="fa-solid fa-plus"></i> Ajouter un client</a>
</div>

<div id="clients-grid" class="sites-grid">
  <?php if (!$clients): ?>
    <div class="list-empty"><i class="fa-solid fa-briefcase"></i><br>Aucun client externe enregistré. Le parc est entièrement interne à IT CORP.</div>
  <?php endif; ?>
  <?php foreach ($clients as $c): ?>
    <div class="site-card">
      <div class="site-icon"><i class="fa-solid fa-briefcase"></i></div>
      <div class="site-name"><?= e($c['nom']) ?></div>
      <div class="site-address"><i class="fa-solid fa-phone"></i> <?= e($c['telephone'] ?: '-') ?></div>
      <div class="site-stats">
        <div><i class="fa-solid fa-desktop"></i> <?= (int) $c['nb_equip'] ?> équipements confiés</div>
      </div>
      <div style="margin-top:14px"><a class="btn btn-outline btn-sm" style="width:100%" href="<?= e(current_url(['modal' => 'form', 'id' => $c['id']])) ?>"><i class="fa-solid fa-pen"></i> Modifier</a></div>
    </div>
  <?php endforeach; ?>
</div>
<?= pagination_links($pg) ?>

<?php
if (($_GET['modal'] ?? '') === 'form') {
    $modalId = int_param('id');
    $c = $modalId ? db_fetch('SELECT * FROM clients WHERE id = ?', [$modalId]) : null;
    $editing = $c !== null;
    modal_open($editing ? 'Modifier le client' : 'Ajouter un client', $editing ? 'fa-pen' : 'fa-plus', $closeUrl);
    ?>
    <form method="post" action="<?= e(current_url()) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= $editing ? (int) $c['id'] : '' ?>">
      <div class="modal-body">
        <?= error_box($formError) ?>
        <p class="form-hint" style="margin-bottom:14px">Un client externe dont IT CORP gère tout ou partie du parc informatique, en plus du parc interne.</p>
        <div class="form-row single"><div class="form-field"><label>Nom *</label><input type="text" name="nom" value="<?= e(fv('nom', $c['nom'] ?? '')) ?>" required></div></div>
        <div class="form-row single"><div class="form-field"><label>Téléphone</label><input type="text" name="telephone" value="<?= e(fv('telephone', $c['telephone'] ?? '')) ?>" placeholder="+241 01 00 00 00"></div></div>
      </div>
      <div class="modal-footer">
        <a class="btn btn-outline" href="<?= e($closeUrl) ?>">Annuler</a>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> <?= $editing ? 'Enregistrer' : 'Ajouter' ?></button>
      </div>
    </form>
    <?php
    modal_close();
}
