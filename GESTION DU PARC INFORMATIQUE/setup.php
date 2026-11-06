<?php
// Premier lancement : création du compte administrateur.
// Accessible uniquement tant qu'aucun compte n'existe. Le rôle n'est jamais demandé :
// le tout premier compte devient automatiquement Administrateur.

require __DIR__ . '/includes/bootstrap.php';

if (count_users() > 0) {
    redirect(current_user() ? 'index.php' : 'login.php');
}

$error = null;

if (is_post()) {
    csrf_verify();

    $prenom = input('prenom');
    $nom = input('nom');
    $email = mb_strtolower(input('email'));
    $password = $_POST['password'] ?? '';

    if ($prenom === '' || $nom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Veuillez renseigner le prénom, le nom et un email valide.';
    } else {
        $error = password_error($password, $_POST['password_confirm'] ?? '');
    }

    if ($error === null) {
        $pdo = db();
        $pdo->beginTransaction();
        // Verrou : si deux personnes valident en même temps, une seule crée le compte
        if ((int) $pdo->query('SELECT COUNT(*) FROM utilisateurs FOR UPDATE')->fetchColumn() > 0) {
            $pdo->rollBack();
            redirect('login.php');
        }
        $firstSite = db_value('SELECT id FROM sites ORDER BY id LIMIT 1') ?: null;
        $firstService = db_value('SELECT id FROM services ORDER BY id LIMIT 1') ?: null;
        db_query(
            "INSERT INTO utilisateurs (prenom, nom, email, mot_de_passe, role, service_id, site_id, statut)
             VALUES (?, ?, ?, ?, 'administrateur', ?, ?, 'actif')",
            [$prenom, $nom, $email, password_hash($password, PASSWORD_DEFAULT), $firstService, $firstSite]
        );
        $id = (int) $pdo->lastInsertId();
        $pdo->commit();

        login_user($id);
        log_event('utilisateur', "Création du compte administrateur $prenom $nom (premier compte de l'application).");
        flash('success', "Bienvenue $prenom $nom, votre compte administrateur a été créé.");
        redirect('index.php');
    }
}

ob_start();
?>
<div class="login-logo">
  <img src="assets/images/logo-it-corp.png" alt="Logo IT CORP">
</div>
<h1 class="login-title">Créer votre compte administrateur</h1>
<p class="login-subtitle">Créez le premier compte pour commencer à utiliser IT CORP.</p>

<form id="first-admin-form" class="login-form" method="post" action="setup.php">
  <?= csrf_field() ?>
  <div class="form-row">
    <div class="form-group"><label for="fa-prenom">Prénom</label><input type="text" id="fa-prenom" name="prenom" value="<?= e(fv('prenom')) ?>" required></div>
    <div class="form-group"><label for="fa-nom">Nom</label><input type="text" id="fa-nom" name="nom" value="<?= e(fv('nom')) ?>" required></div>
  </div>
  <div class="form-group">
    <label for="fa-email">Email professionnel</label>
    <div class="input-icon"><i class="fa-solid fa-envelope"></i><input type="email" id="fa-email" name="email" value="<?= e(fv('email')) ?>" placeholder="vous@itcorp.com" required></div>
  </div>
  <div class="form-group">
    <label for="fa-password">Mot de passe</label>
    <div class="input-icon"><i class="fa-solid fa-lock"></i><input type="password" id="fa-password" name="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" required></div>
  </div>
  <div class="form-group">
    <label for="fa-password-confirm">Confirmation du mot de passe</label>
    <div class="input-icon"><i class="fa-solid fa-lock"></i><input type="password" id="fa-password-confirm" name="password_confirm" minlength="<?= PASSWORD_MIN_LENGTH ?>" required></div>
  </div>
  <?php if ($error): ?>
    <div id="first-admin-error" class="login-error"><i class="fa-solid fa-circle-exclamation"></i> <span><?= e($error) ?></span></div>
  <?php endif; ?>
  <button type="submit" class="btn btn-primary btn-block">
    <i class="fa-solid fa-user-shield"></i> Créer le compte administrateur
  </button>
</form>
<?php
$body = ob_get_clean();

$screenId = 'first-admin-page';
$title = 'Premier lancement';
require __DIR__ . '/includes/layout/auth.php';
