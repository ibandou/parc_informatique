<?php
// Gabarit des écrans hors connexion (connexion, création du premier administrateur).
// Variables attendues : $screenId ('login-page' ou 'first-admin-page'), $title, $body (HTML),
// et éventuellement $modalHtml (fenêtre "Mot de passe oublié").
?>
<!DOCTYPE html>
<html lang="fr" data-theme="<?= e(theme()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> - IT CORP</title>
<link rel="icon" href="assets/images/logo-it-corp.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.3.1/css/all.min.css">
<link rel="stylesheet" href="css/style.css">
</head>
<body>
<div id="<?= e($screenId) ?>" class="login-page">
  <div class="login-card">
    <?= $body ?>
  </div>
</div>
<?= $modalHtml ?? '' ?>
<div id="toast-container" class="toast-container">
  <?php foreach (take_flashes() as $f): ?>
    <div class="toast <?= e($f['type']) ?>"><i class="fa-solid <?= e(['success' => 'fa-circle-check', 'error' => 'fa-circle-exclamation', 'warning' => 'fa-triangle-exclamation'][$f['type']] ?? 'fa-circle-info') ?>"></i><span><?= e($f['message']) ?></span></div>
  <?php endforeach; ?>
</div>
<script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/../../assets/js/app.js') ?>"></script>
<script src="assets/js/login-lights.js"></script>
</body>
</html>
