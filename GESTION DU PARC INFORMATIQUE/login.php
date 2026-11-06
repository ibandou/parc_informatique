<?php
// Écran de connexion (et demande de réinitialisation "Mot de passe oublié").

require __DIR__ . '/includes/bootstrap.php';

if (count_users() === 0) {
    redirect('setup.php');
}
if (current_user()) {
    redirect('index.php');
}

$error = null;
$email = '';

if (is_post()) {
    csrf_verify();

    if (input('action') === 'forgot') {
        // Pas d'envoi d'email : la demande est transmise aux Administrateurs sous forme de
        // notification ; ils définissent un nouveau mot de passe depuis la page Utilisateurs.
        // La réponse est identique que l'email existe ou non, pour ne pas révéler les comptes.
        $account = db_fetch("SELECT * FROM utilisateurs WHERE email = ? AND statut = 'actif'", [mb_strtolower(input('email'))]);
        if ($account) {
            notify_supervision(
                'mdp_oublie',
                $account['prenom'] . ' ' . $account['nom'] . ' (' . $account['email'] . ') a oublié son mot de passe et demande sa réinitialisation.',
                null,
                false
            );
        }
        flash('success', "Si cette adresse correspond à un compte actif, la demande a été transmise à l'administrateur.");
        redirect('login.php');
    }

    $email = mb_strtolower(input('email'));
    $result = attempt_login($email, $_POST['password'] ?? '');
    if (is_string($result)) {
        $error = $result;
    } else {
        login_user((int) $result['id']);
        flash('success', 'Bienvenue ' . $result['prenom'] . ' ' . $result['nom']);
        redirect('index.php');
    }
}

ob_start();
?>
<div class="login-brand">IT CORP</div>
<h1 class="login-title">Parc informatique<br>&amp; interventions</h1>
<p class="login-subtitle">Connectez-vous à votre espace</p>

<form id="login-form" class="login-form" method="post" action="login.php">
  <?= csrf_field() ?>
  <div class="form-group">
    <label for="login-email">Adresse email</label>
    <div class="input-icon">
      <i class="fa-solid fa-envelope"></i>
      <input type="email" id="login-email" name="email" value="<?= e($email) ?>" placeholder="i.patrick@it-corp.co" autocomplete="username" required>
    </div>
  </div>
  <div class="form-group">
    <label for="login-password">Mot de passe</label>
    <div class="input-icon">
      <i class="fa-solid fa-lock"></i>
      <input type="password" id="login-password" name="password" class="has-toggle" placeholder="••••••••" autocomplete="current-password" required>
      <button type="button" class="toggle-password" data-toggle-password="login-password" aria-label="Afficher le mot de passe" title="Afficher le mot de passe"><i class="fa-solid fa-eye"></i></button>
    </div>
  </div>
  <?php if ($error): ?>
    <div id="login-error" class="login-error"><i class="fa-solid fa-circle-exclamation"></i> <span><?= e($error) ?></span></div>
  <?php endif; ?>
  <button type="submit" class="btn btn-primary btn-block">
    <i class="fa-solid fa-right-to-bracket"></i> Se connecter
  </button>
  <a href="login.php?forgot=1" class="login-link" id="login-forgot">Mot de passe oublié ?</a>
</form>
<?php
$body = ob_get_clean();

$modalHtml = '';
if (isset($_GET['forgot'])) {
    modal_open('Mot de passe oublié', 'fa-key', 'login.php', 'modal-sm');
    ?>
    <form method="post" action="login.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="forgot">
      <div class="modal-body">
        <p style="font-size:13.5px;color:var(--text-secondary);margin-bottom:14px">Saisissez votre adresse email. Une demande de réinitialisation sera transmise à l'administrateur, qui vous communiquera un nouveau mot de passe.</p>
        <div class="form-row single"><div class="form-field"><label>Email *</label><input type="email" name="email" required placeholder="ip@itcorp.com" autofocus></div></div>
      </div>
      <div class="modal-footer">
        <a class="btn btn-outline" href="login.php">Annuler</a>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> Envoyer la demande</button>
      </div>
    </form>
    <?php
    modal_close();
    $modalHtml = $GLOBALS['modals_html'];
}

$screenId = 'login-page';
$title = 'Connexion';
require __DIR__ . '/includes/layout/auth.php';
