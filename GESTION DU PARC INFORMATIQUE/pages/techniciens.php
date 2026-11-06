<?php
// Techniciens : cartes avec charge de travail. Un technicien est un compte utilisateur au
// rôle Technicien, complété d'une fiche (spécialité) : l'ajout crée donc aussi son compte.

$closeUrl = page_url('techniciens', ['p' => $_GET['p'] ?? '']);
$formError = null;

if (is_post() && input('action') === 'save') {
    $id = int_param('id', 'post');
    $t = $id ? db_fetch('SELECT t.*, u.email FROM techniciens t JOIN utilisateurs u ON u.id = t.utilisateur_id WHERE t.id = ?', [$id]) : null;
    if ($id && !$t) {
        redirect($closeUrl);
    }
    $nom = input('nom');
    $prenom = input('prenom');
    $email = mb_strtolower(input('email'));
    $telephone = input('telephone') ?: null;
    $specialite = input('specialite');
    $password = $_POST['password'] ?? '';

    if ($nom === '' || $prenom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($specialite, SPECIALITES, true) || (!$t && $password === '')) {
        $formError = 'Veuillez renseigner tous les champs obligatoires (*).';
    } elseif (!$t && mb_strlen($password) < PASSWORD_MIN_LENGTH) {
        $formError = 'Le mot de passe doit contenir au moins ' . PASSWORD_MIN_LENGTH . ' caractères.';
    } elseif (db_value('SELECT COUNT(*) FROM utilisateurs WHERE email = ? AND id <> ?', [$email, $t['utilisateur_id'] ?? 0])) {
        $formError = 'Cet email est déjà utilisé par un autre compte.';
    }

    if ($formError === null) {
        $pdo = db();
        $pdo->beginTransaction();
        if ($t) {
            db_query('UPDATE utilisateurs SET nom = ?, prenom = ?, email = ?, telephone = ? WHERE id = ?', [$nom, $prenom, $email, $telephone, $t['utilisateur_id']]);
            db_query('UPDATE techniciens SET specialite = ? WHERE id = ?', [$specialite, $id]);
            flash('success', 'Technicien modifié.');
        } else {
            db_query(
                "INSERT INTO utilisateurs (prenom, nom, email, mot_de_passe, telephone, role, statut) VALUES (?, ?, ?, ?, ?, 'technicien', 'actif')",
                [$prenom, $nom, $email, password_hash($password, PASSWORD_DEFAULT), $telephone]
            );
            ensure_technicien_profile((int) $pdo->lastInsertId(), $specialite);
            log_event('utilisateur', "Création du compte $prenom $nom (rôle : Technicien).");
            flash('success', 'Technicien ajouté.');
        }
        $pdo->commit();
        redirect($closeUrl);
    }
}

$select = "SELECT t.*, u.prenom, u.nom, u.email, u.telephone,
                  SUM(i.statut IN ('nouvelle', 'en_attente', 'en_cours')) AS en_cours,
                  SUM(i.statut IN ('resolue', 'cloturee')) AS terminees,
                  SUM(i.statut <> 'cloturee') AS ouvertes
             FROM techniciens t
             JOIN utilisateurs u ON u.id = t.utilisateur_id AND u.role = 'technicien'
             LEFT JOIN interventions i ON i.technicien_id = t.id
            GROUP BY t.id";
$pg = paginate((int) db_value("SELECT COUNT(*) FROM ($select) x"));
$techs = db_fetch_all($select . " ORDER BY u.prenom, u.nom LIMIT {$pg['limit']} OFFSET {$pg['offset']}");
// Charge relative : rapportée au technicien le plus chargé
$maxCharge = max(1, (int) db_value("SELECT MAX(ouvertes) FROM ($select) x"));
?>
<div class="page-header">
  <div><h1><i class="fa-solid fa-user-gear"></i> Techniciens</h1><p class="page-subtitle">Équipe technique et charge de travail</p></div>
  <a class="btn btn-primary" id="btn-add-tech" href="<?= e(current_url(['modal' => 'form'], ['id'])) ?>"><i class="fa-solid fa-plus"></i> Ajouter un technicien</a>
</div>

<div id="techniciens-grid" class="tech-grid">
  <?php if (!$techs): ?>
    <div class="empty-state"><i class="fa-solid fa-user-gear"></i><p>Aucun technicien enregistré.</p></div>
  <?php endif; ?>
  <?php foreach ($techs as $t):
      $enCours = (int) $t['en_cours'];
      $pct = (int) round($enCours / $maxCharge * 100);
      $color = $pct > 75 ? 'var(--danger)' : ($pct > 45 ? 'var(--warning)' : 'var(--success)'); ?>
    <div class="tech-card">
      <div class="tech-card-header">
        <div class="tech-avatar"><?= e(initials($t['prenom'], $t['nom'])) ?></div>
        <div><div class="tech-name"><?= e($t['prenom'] . ' ' . $t['nom']) ?></div><div class="tech-role"><?= e($t['specialite'] ?: '-') ?></div></div>
      </div>
      <div class="workload-row"><span>Charge de travail actuelle</span><span><?= $enCours ?> interv.</span></div>
      <div class="workload-bar"><div class="workload-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div></div>
      <div class="site-stats">
        <div><i class="fa-solid fa-spinner"></i> <?= $enCours ?> en cours</div>
        <div><i class="fa-solid fa-circle-check"></i> <?= (int) $t['terminees'] ?> terminées</div>
      </div>
      <div style="margin-top:12px;font-size:12px;color:var(--text-muted)"><i class="fa-solid fa-envelope"></i> <?= e($t['email']) ?></div>
      <div style="margin-top:14px;display:flex;gap:8px">
        <a class="btn btn-outline btn-sm" href="<?= e(current_url(['modal' => 'form', 'id' => $t['id']])) ?>" style="flex:1"><i class="fa-solid fa-pen"></i> Modifier</a>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?= pagination_links($pg) ?>

<?php
if (($_GET['modal'] ?? '') === 'form') {
    $modalId = int_param('id');
    $t = $modalId ? db_fetch('SELECT t.*, u.prenom, u.nom, u.email, u.telephone FROM techniciens t JOIN utilisateurs u ON u.id = t.utilisateur_id WHERE t.id = ?', [$modalId]) : null;
    $editing = $t !== null;
    modal_open($editing ? 'Modifier le technicien' : 'Ajouter un technicien', $editing ? 'fa-pen' : 'fa-plus', $closeUrl);
    ?>
    <form method="post" action="<?= e(current_url()) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= $editing ? (int) $t['id'] : '' ?>">
      <div class="modal-body">
        <?= error_box($formError) ?>
        <div class="form-row">
          <div class="form-field"><label>Nom *</label><input type="text" name="nom" value="<?= e(fv('nom', $t['nom'] ?? '')) ?>" required></div>
          <div class="form-field"><label>Prénom *</label><input type="text" name="prenom" value="<?= e(fv('prenom', $t['prenom'] ?? '')) ?>" required></div>
        </div>
        <div class="form-row">
          <div class="form-field"><label>Email *</label><input type="email" name="email" value="<?= e(fv('email', $t['email'] ?? '')) ?>" required></div>
          <div class="form-field"><label>Téléphone</label><input type="text" name="telephone" value="<?= e(fv('telephone', $t['telephone'] ?? '')) ?>"></div>
        </div>
        <div class="form-row<?= $editing ? ' single' : '' ?>">
          <div class="form-field"><label>Spécialité *</label><select name="specialite" required><?= options(array_combine(SPECIALITES, SPECIALITES), fv('specialite', $t['specialite'] ?? '')) ?></select></div>
          <?php if (!$editing): ?>
            <div class="form-field"><label>Mot de passe temporaire *</label><input type="password" name="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required><div class="form-hint">Le technicien se connectera avec son email et ce mot de passe.</div></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="modal-footer">
        <a class="btn btn-outline" href="<?= e($closeUrl) ?>">Annuler</a>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> <?= $editing ? 'Enregistrer' : 'Ajouter' ?></button>
      </div>
    </form>
    <?php
    modal_close();
}
