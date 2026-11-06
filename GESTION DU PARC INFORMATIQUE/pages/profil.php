<?php
// Mon profil : informations du compte connecté. Prénom, nom, email et téléphone sont
// modifiables ; le rôle et le statut restent réservés à l'Administrateur.
// La modification du mot de passe se fait aussi ici (tous les rôles).

$u = current_user();
$closeUrl = page_url('profil');
$formError = null;

if (is_post() && input('action') === 'save') {
    $prenom = input('prenom');
    $nom = input('nom');
    $email = mb_strtolower(input('email'));

    if ($prenom === '' || $nom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $formError = 'Veuillez renseigner tous les champs obligatoires (*).';
    } elseif (db_value('SELECT COUNT(*) FROM utilisateurs WHERE email = ? AND id <> ?', [$email, $u['id']])) {
        $formError = 'Cet email est déjà utilisé par un autre compte.';
    } else {
        db_query('UPDATE utilisateurs SET prenom = ?, nom = ?, email = ?, telephone = ? WHERE id = ?',
            [$prenom, $nom, $email, input('telephone') ?: null, $u['id']]);
        log_event('utilisateur', "$prenom $nom a modifié son profil.");
        flash('success', 'Profil mis à jour.');
        redirect($closeUrl);
    }
}

if (is_post() && input('action') === 'password') {
    $hash = (string) db_value('SELECT mot_de_passe FROM utilisateurs WHERE id = ?', [$u['id']]);
    if (!password_verify($_POST['current'] ?? '', $hash)) {
        $formError = 'Le mot de passe actuel est incorrect.';
    } else {
        $formError = password_error($_POST['next'] ?? '', $_POST['confirm'] ?? '');
    }
    if ($formError === null) {
        db_query('UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?', [password_hash($_POST['next'], PASSWORD_DEFAULT), $u['id']]);
        log_event('utilisateur', "{$u['prenom']} {$u['nom']} a modifié son mot de passe.");
        flash('success', 'Mot de passe modifié avec succès.');
        redirect($closeUrl);
    }
}
?>
<div class="page-header">
  <div><h1><i class="fa-solid fa-id-badge"></i> Mon profil</h1><p class="page-subtitle">Vos informations de compte</p></div>
</div>

<div id="profile-page-body">
  <div class="card" style="max-width:640px">
    <div class="card-body" style="display:flex;gap:20px;align-items:center;flex-wrap:wrap">
      <span class="avatar" style="width:64px;height:64px;font-size:22px"><?= e(initials($u['prenom'], $u['nom'])) ?></span>
      <div style="flex:1;min-width:200px">
        <div style="font-size:19px;font-weight:800"><?= e($u['prenom'] . ' ' . $u['nom']) ?></div>
        <div style="margin-top:4px"><?= role_badge($u['role']) ?></div>
      </div>
    </div>
    <div class="divider" style="margin:0"></div>
    <div class="card-body">
      <div class="detail-grid">
        <div class="detail-item"><div class="detail-label">Email</div><div class="detail-value"><?= e($u['email']) ?></div></div>
        <div class="detail-item"><div class="detail-label">Téléphone</div><div class="detail-value"><?= e($u['telephone'] ?: '-') ?></div></div>
        <div class="detail-item"><div class="detail-label">Service</div><div class="detail-value"><?= e($u['service_nom'] ?? '-') ?></div></div>
        <div class="detail-item"><div class="detail-label">Site</div><div class="detail-value"><?= e($u['site_nom'] ?? '-') ?></div></div>
        <div class="detail-item"><div class="detail-label">Statut</div><div class="detail-value"><?= status_badge($u['statut'] === 'actif' ? 'Actif' : 'Inactif') ?></div></div>
        <div class="detail-item"><div class="detail-label">Compte créé le</div><div class="detail-value"><?= e(format_date($u['created_at'])) ?></div></div>
      </div>
    </div>
    <div class="modal-footer" style="justify-content:flex-start;border-top:1px solid var(--border)">
      <a class="btn btn-secondary" id="btn-edit-profile" href="<?= e(page_url('profil', ['modal' => 'edit'])) ?>"><i class="fa-solid fa-pen"></i> Modifier mon profil</a>
      <a class="btn btn-secondary" id="btn-change-password" href="<?= e(page_url('profil', ['modal' => 'password'])) ?>"><i class="fa-solid fa-key"></i> Modifier mon mot de passe</a>
    </div>
  </div>
</div>

<?php
if (($_GET['modal'] ?? '') === 'edit') {
    modal_open('Modifier mon profil', 'fa-pen', $closeUrl);
    ?>
    <form method="post" action="<?= e(current_url()) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <div class="modal-body">
        <?= error_box($formError) ?>
        <div class="form-row">
          <div class="form-field"><label>Prénom *</label><input type="text" name="prenom" value="<?= e(fv('prenom', $u['prenom'])) ?>" required></div>
          <div class="form-field"><label>Nom *</label><input type="text" name="nom" value="<?= e(fv('nom', $u['nom'])) ?>" required></div>
        </div>
        <div class="form-row">
          <div class="form-field"><label>Email *</label><input type="email" name="email" value="<?= e(fv('email', $u['email'])) ?>" required></div>
          <div class="form-field"><label>Téléphone</label><input type="text" name="telephone" value="<?= e(fv('telephone', $u['telephone'])) ?>" placeholder="+241 07 00 00 00"></div>
        </div>
        <div class="form-hint" style="margin-bottom:4px"><i class="fa-solid fa-circle-info"></i> Le rôle et le statut de votre compte ne peuvent être modifiés que par un administrateur.</div>
      </div>
      <div class="modal-footer">
        <a class="btn btn-outline" href="<?= e($closeUrl) ?>">Annuler</a>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Enregistrer</button>
      </div>
    </form>
    <?php
    modal_close();
}

if (($_GET['modal'] ?? '') === 'password') {
    modal_open('Modifier mon mot de passe', 'fa-key', $closeUrl, 'modal-sm');
    ?>
    <form method="post" action="<?= e(current_url()) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <div class="modal-body">
        <?= error_box($formError) ?>
        <div class="form-row single"><div class="form-field"><label>Mot de passe actuel *</label><input type="password" name="current" autocomplete="current-password" required></div></div>
        <div class="form-row single"><div class="form-field"><label>Nouveau mot de passe *</label><input type="password" name="next" autocomplete="new-password" required minlength="<?= PASSWORD_MIN_LENGTH ?>"></div></div>
        <div class="form-row single"><div class="form-field"><label>Confirmation du nouveau mot de passe *</label><input type="password" name="confirm" autocomplete="new-password" required minlength="<?= PASSWORD_MIN_LENGTH ?>"></div></div>
      </div>
      <div class="modal-footer">
        <a class="btn btn-outline" href="<?= e($closeUrl) ?>">Annuler</a>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Modifier le mot de passe</button>
      </div>
    </form>
    <?php
    modal_close();
}
