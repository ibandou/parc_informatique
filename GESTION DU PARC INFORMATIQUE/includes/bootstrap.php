<?php
// Chargé en tête de chaque point d'entrée (index.php, login.php, setup.php, logout.php).

require_once __DIR__ . '/../config/config.php';

date_default_timezone_set(APP_TIMEZONE);
mb_internal_encoding('UTF-8');

// En-têtes de sécurité
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// Session : cookie inaccessible au JavaScript, non envoyé depuis un autre site
ini_set('session.use_strict_mode', '1');
session_name(SESSION_NAME);
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/historique.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/parc.php';
require_once __DIR__ . '/pagination.php';
require_once __DIR__ . '/routes.php';

// Fermeture de la session après une période d'inactivité
if (!empty($_SESSION['user_id'])) {
    if (time() - ($_SESSION['last_activity'] ?? 0) > SESSION_TIMEOUT) {
        logout_user();
        session_start();
        flash('info', 'Votre session a expiré. Veuillez vous reconnecter.');
    } else {
        $_SESSION['last_activity'] = time();
    }
}
