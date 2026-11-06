<?php
// Utilisateurs (Administrateur uniquement) : seul endroit de l'application où un rôle peut
// être attribué. Un administrateur ne peut ni changer son propre rôle ni désactiver son compte.

$me = current_user();
$closeUrl = page_url('utilisateurs', ['search' => $_GET['search'] ?? '', 'p' => $_GET['p'] ?? '']);
$formError = null;

if (is_post()) {
    $action = input('action');

    if ($action === 'save') {
        $id = int_param('id', 'post');
        $u = $id ? db_fetch('SELECT * FROM utilisateurs WHERE id = ?', [$id]) : null;
        if ($id && !$u) {
            redirect($closeUrl);
        }
        $isSelf = $u && (int) $u['id'] === (int) $me['id'];

        $prenom = input('prenom');
        $nom = input('nom');
        $email = mb_strtolower(input('email'));
        $password = $_POST['password'] ?? '';
        // Un administrateur ne peut jamais changer son propre rôle, même en forçant le formulaire
        $role = $isSelf ? $u['role'] : input('role');
        $serviceId = int_param('service_id', 'post');
        $siteId = int_param('site_id', 'post');
        $statut = $isSelf || !$u ? 'actif' : (!empty($_POST['actif']) ? 'actif' : 'inactif');

        if ($prenom === '' || $nom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$serviceId || !$siteId || (!$u && $password === '')) {
            $formError = 'Veuillez renseigner tous les champs obligatoires (*).';
        } elseif (!isset(ROLE_LABELS[$role])) {
            $formError = 'Rôle invalide.';
        } elseif ($password !== '' && mb_strlen($password) < PASSWORD_MIN_LENGTH) {
            $formError = 'Le mot de passe doit contenir au moins ' . PASSWORD_MIN_LENGTH . ' caractères.';
        } elseif (db_value('SELECT COUNT(*) FROM utilisateurs WHERE email = ? AND id <> ?', [$email, $id ?? 0])) {
            $formError = 'Cet email est déjà utilisé par un autre compte.';
        }

        if ($formError === null) {
            $pdo = db();
            $pdo->beginTransaction();
            $telephone = input('telephone') ?: null;
            if ($u) {
                db_query(
                    'UPDATE utilisateurs SET prenom = ?, nom = ?, email = ?, telephone = ?, role = ?, service_id = ?, site_id = ?, statut = ? WHERE id = ?',
                    [$prenom, $nom, $email, $telephone, $role, $serviceId, $siteId, $statut, $id]
                );
                if ($password !== '') {
                    db_query('UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
                }
                log_event('utilisateur', "Modification du compte $prenom $nom"
                    . ($u['role'] !== $role ? ' (rôle changé : ' . role_label($u['role']) . ' → ' . role_label($role) . ')' : '') . '.');
                $message = 'Utilisateur modifié.';
            } else {
                db_query(
                    "INSERT INTO utilisateurs (prenom, nom, email, mot_de_passe, telephone, role, service_id, site_id, statut)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'actif')",
                    [$prenom, $nom, $email, password_hash($password, PASSWORD_DEFAULT), $telephone, $role, $serviceId, $siteId]
                );
                $id = (int) $pdo->lastInsertId();
                log_event('utilisateur', "Création du compte $prenom $nom (rôle : " . role_label($role) . ').');
                $message = 'Utilisateur ajouté.';
            }
            // Un compte Technicien a toujours sa fiche technicien (interventions, charge de travail)
            if ($role === 'technicien') {
                ensure_technicien_profile($id);
            }
            $pdo->commit();
            flash('success', $message);
            redirect($closeUrl);
        }
    }

    if ($action === 'toggle') {
        $id = int_param('id', 'post');
        $u = $id ? db_fetch('SELECT * FROM utilisateurs WHERE id = ?', [$id]) : null;
        if ($u && (int) $u['id'] === (int) $me['id']) {
            flash('error', 'Vous ne pouvez pas désactiver votre propre compte.');
        } elseif ($u) {
            $statut = $u['statut'] === 'actif' ? 'inactif' : 'actif';
            db_query('UPDATE utilisateurs SET statut = ? WHERE id = ?', [$statut, $id]);
            log_event('utilisateur', "Compte {$u['prenom']} {$u['nom']} " . ($statut === 'actif' ? 'activé' : 'désactivé') . '.');
            flash('success', 'Utilisateur ' . ($statut === 'actif' ? 'activé' : 'désactivé') . '.');
        }
        redirect($closeUrl);
    }
}

/* ------------------------------ LISTE ------------------------------ */

$search = trim((string) ($_GET['search'] ?? ''));
$where = '';
$params = [];
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $where = " WHERE CONCAT_WS(' ', u.nom, u.prenom, u.email, sv.nom) LIKE ?";
    $params[] = $like;
}
$select = "SELECT u.*, sv.nom AS service_nom, si.nom AS site_nom,
                  (SELECT COUNT(*) FROM affectations a JOIN equipements e ON e.id = a.equipement_id AND e.deleted_at IS NULL
                    WHERE a.utilisateur_id = u.id AND a.date_fin IS NULL) AS nb_equipements
             FROM utilisateurs u
             LEFT JOIN services sv ON sv.id = u.service_id
             LEFT JOIN sites si    ON si.id = u.site_id" . $where;
$pg = paginate((int) db_value("SELECT COUNT(*) FROM ($select) t", $params));
$users = db_fetch_all($select . " ORDER BY u.nom, u.prenom LIMIT {$pg['limit']} OFFSET {$pg['offset']}", $params);
?>
<div class="page-header">
  <div><h1><i class="fa-solid fa-users"></i> Utilisateurs</h1><p class="page-subtitle">Gestion des utilisateurs du parc</p></div>
</div>

<form class="toolbar" method="get" data-autosubmit>
  <input type="hidden" name="page" value="utilisateurs">
  <div class="input-icon toolbar-search"><i class="fa-solid fa-magnifying-glass"></i><input type="text" name="search" value="<?= e($search) ?>" placeholder="Rechercher un utilisateur..."<?= $search !== '' ? ' data-refocus' : '' ?>></div>
</form>

<div class="table-wrapper">
  <table class="data-table" id="table-users">
    <thead><tr><th>Nom</th><th>Prénom</th><th>Email</th><th>Téléphone</th><th>Service</th><th>Site</th><th>Rôle</th><th>Équipements</th><th>Statut</th><th>Actions</th></tr></thead>
    <tbody>
      <?php if (!$users) echo empty_row(10, 'Aucun utilisateur trouvé.'); ?>
      <?php foreach ($users as $u): $isSelf = (int) $u['id'] === (int) $me['id']; $actif = $u['statut'] === 'actif'; ?>
        <tr>
          <td><?= e($u['nom']) ?></td><td><?= e($u['prenom']) ?></td><td><?= e($u['email']) ?></td><td><?= e($u['telephone'] ?: '-') ?></td>
          <td><?= e($u['service_nom'] ?? '-') ?></td><td><?= e($u['site_nom'] ?? '-') ?></td>
          <td><?= role_badge($u['role']) ?></td>
          <td><?= (int) $u['nb_equipements'] ?></td>
          <td><?= status_badge($actif ? 'Actif' : 'Inactif') ?></td>
          <td class="cell-actions">
            <a class="icon-btn icon-btn-table" title="Voir" href="<?= e(current_url(['modal' => 'detail', 'id' => $u['id']])) ?>"><i class="fa-solid fa-eye"></i></a>
            <a class="icon-btn icon-btn-table" title="Modifier" href="<?= e(current_url(['modal' => 'form', 'id' => $u['id']])) ?>"><i class="fa-solid fa-pen"></i></a>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <button type="submit" class="icon-btn icon-btn-table" title="<?= $actif ? 'Désactiver' : 'Activer' ?>"<?= $isSelf ? ' disabled' : '' ?>><i class="fa-solid <?= $actif ? 'fa-user-slash' : 'fa-user-check' ?>"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?= pagination_links($pg) ?>

<?php
/* ------------------------------ MODALES ------------------------------ */

$modal = $_GET['modal'] ?? (page_view('utilisateurs') === 'ajouter' ? 'form' : '');
$modalId = int_param('id');

if ($modal === 'form') {
    $u = $modalId ? db_fetch('SELECT * FROM utilisateurs WHERE id = ?', [$modalId]) : null;
    $editing = $u !== null;
    $isSelf = $editing && (int) $u['id'] === (int) $me['id'];
    modal_open($editing ? "Modifier l'utilisateur" : 'Ajouter un utilisateur', $editing ? 'fa-pen' : 'fa-plus', $closeUrl);
    ?>
    <form method="post" action="<?= e(current_url()) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= $editing ? (int) $u['id'] : '' ?>">
      <div class="modal-body">
        <?= error_box($formError) ?>
        <div class="form-row">
          <div class="form-field"><label>Prénom *</label><input type="text" name="prenom" value="<?= e(fv('prenom', $u['prenom'] ?? '')) ?>" required></div>
          <div class="form-field"><label>Nom *</label><input type="text" name="nom" value="<?= e(fv('nom', $u['nom'] ?? '')) ?>" required></div>
        </div>
        <div class="form-row">
          <div class="form-field"><label>Email *</label><input type="email" name="email" value="<?= e(fv('email', $u['email'] ?? '')) ?>" required></div>
          <div class="form-field"><label>Téléphone</label><input type="text" name="telephone" value="<?= e(fv('telephone', $u['telephone'] ?? '')) ?>" placeholder="+241 07 00 00 00"></div>
        </div>
        <div class="form-row">
          <div class="form-field"><label><?= $editing ? 'Nouveau mot de passe (laisser vide pour ne pas changer)' : 'Mot de passe temporaire *' ?></label><input type="password" name="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password"<?= $editing ? ' placeholder="••••••••"' : ' required' ?>></div>
          <div class="form-field">
            <label>Rôle *</label>
            <select name="role" required<?= $isSelf ? ' disabled' : '' ?>><?= options(['administrateur' => 'Administrateur', 'technicien' => 'Technicien', 'ceo' => 'CEO'], fv('role', $u['role'] ?? 'technicien')) ?></select>
            <?php if ($isSelf): ?><div class="form-hint">Vous ne pouvez pas modifier votre propre rôle.</div><?php endif; ?>
          </div>
        </div>
        <div class="form-row">
          <div class="form-field"><label>Service *</label><select name="service_id" required><?= options(services_list(), fv('service_id', $u['service_id'] ?? '')) ?></select></div>
          <div class="form-field"><label>Site *</label><select name="site_id" required><?= options(sites_list(), fv('site_id', $u['site_id'] ?? '')) ?></select></div>
        </div>
        <?php if ($editing): ?>
          <?php $checked = is_post() ? !empty($_POST['actif']) : $u['statut'] === 'actif'; ?>
          <div class="form-row single"><div class="form-field"><label class="form-checkbox"><input type="checkbox" name="actif" value="1"<?= $checked || $isSelf ? ' checked' : '' ?><?= $isSelf ? ' disabled' : '' ?>> Compte actif</label></div></div>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <a class="btn btn-outline" href="<?= e($closeUrl) ?>">Annuler</a>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> <?= $editing ? 'Enregistrer' : 'Ajouter' ?></button>
      </div>
    </form>
    <?php
    modal_close();
}

if ($modal === 'detail' && $modalId && ($u = db_fetch(
    'SELECT u.*, sv.nom AS service_nom, si.nom AS site_nom FROM utilisateurs u
       LEFT JOIN services sv ON sv.id = u.service_id LEFT JOIN sites si ON si.id = u.site_id WHERE u.id = ?', [$modalId]))) {
    $equipements = db_fetch_all(EQUIPEMENT_SELECT . ' AND a.utilisateur_id = ? ORDER BY e.numero_inventaire', [$u['id']]);
    modal_open(e($u['prenom'] . ' ' . $u['nom']), 'fa-user', $closeUrl, 'modal-lg');
    ?>
    <div class="modal-body">
      <div class="detail-grid" style="margin-bottom:20px">
        <div class="detail-item"><div class="detail-label">Email</div><div class="detail-value"><?= e($u['email']) ?></div></div>
        <div class="detail-item"><div class="detail-label">Téléphone</div><div class="detail-value"><?= e($u['telephone'] ?: '-') ?></div></div>
        <div class="detail-item"><div class="detail-label">Rôle</div><div class="detail-value"><?= role_badge($u['role']) ?></div></div>
        <div class="detail-item"><div class="detail-label">Service</div><div class="detail-value"><?= e($u['service_nom'] ?? '-') ?></div></div>
        <div class="detail-item"><div class="detail-label">Site</div><div class="detail-value"><?= e($u['site_nom'] ?? '-') ?></div></div>
        <div class="detail-item"><div class="detail-label">Statut</div><div class="detail-value"><?= status_badge($u['statut'] === 'actif' ? 'Actif' : 'Inactif') ?></div></div>
      </div>
      <div class="section-title">Équipements affectés</div>
      <?php if (!$equipements): ?>
        <div class="list-empty">Aucun équipement affecté.</div>
      <?php else: ?>
        <div class="table-wrapper"><table class="data-table"><thead><tr><th>Inventaire</th><th>Type</th><th>État</th></tr></thead>
          <tbody>
            <?php foreach ($equipements as $eq): ?>
              <tr data-href="<?= e(equipement_link((int) $eq['id'])) ?>"><td><?= e($eq['numero_inventaire']) ?></td><td><?= e($eq['type']) ?></td><td><?= enum_badge(ETAT_EQUIPEMENT, $eq['etat']) ?></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
      <?php endif; ?>
    </div>
    <div class="modal-footer"><a class="btn btn-primary" href="<?= e($closeUrl) ?>">Fermer</a></div>
    <?php
    modal_close();
}
