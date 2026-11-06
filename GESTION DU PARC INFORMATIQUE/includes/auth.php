<?php
// Authentification et contrôle des rôles.
// La session ne contient que l'identifiant du compte : l'utilisateur est relu en base
// à chaque requête, si bien qu'un compte désactivé ou un rôle modifié par l'administrateur
// prend effet immédiatement, sans attendre une reconnexion.

function count_users(): int
{
    return (int) db_value('SELECT COUNT(*) FROM utilisateurs');
}

function current_user(): ?array
{
    static $user = false;

    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['user_id'])) {
            $user = db_fetch(
                "SELECT u.*, s.nom AS service_nom, si.nom AS site_nom
                   FROM utilisateurs u
                   LEFT JOIN services s ON s.id = u.service_id
                   LEFT JOIN sites si   ON si.id = u.site_id
                  WHERE u.id = ? AND u.statut = 'actif'",
                [$_SESSION['user_id']]
            );
            if ($user === null) {
                // Compte supprimé ou désactivé depuis l'ouverture de la session
                logout_user();
            }
        }
    }

    return $user;
}

function current_user_id(): ?int
{
    $u = current_user();
    return $u ? (int) $u['id'] : null;
}

function has_role(string ...$roles): bool
{
    $u = current_user();
    return $u !== null && in_array($u['role'], $roles, true);
}

function is_admin(): bool      { return has_role('administrateur'); }
function is_technicien(): bool { return has_role('technicien'); }
function is_ceo(): bool        { return has_role('ceo'); }

function require_login(): void
{
    if (current_user() === null) {
        redirect(count_users() === 0 ? 'setup.php' : 'login.php');
    }
}

// Vérifie les identifiants. Retourne le compte, ou un message d'erreur (string).
function attempt_login(string $email, string $password)
{
    $user = db_fetch('SELECT * FROM utilisateurs WHERE email = ?', [$email]);

    // Un hachage factice est vérifié quand l'email n'existe pas, pour que la durée de la
    // réponse ne révèle pas quelles adresses ont un compte.
    $hash = $user['mot_de_passe'] ?? '$2y$10$PhhJedjJqooapHcfCylmxOP0ursq.TdvhleAnNKGp.iD5lDVTxGA6';
    if (!password_verify($password, $hash) || $user === null) {
        return 'Email ou mot de passe incorrect.';
    }
    if ($user['statut'] !== 'actif') {
        return 'Ce compte est désactivé. Contactez un administrateur.';
    }

    if (password_needs_rehash($user['mot_de_passe'], PASSWORD_DEFAULT)) {
        db_query('UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }

    return $user;
}

function login_user(int $userId): void
{
    // Nouvel identifiant de session à la connexion (protection contre la fixation de session)
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['last_activity'] = time();
    db_query('UPDATE utilisateurs SET derniere_connexion = NOW() WHERE id = ?', [$userId]);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// Règles communes de mot de passe (inscription, profil, administration des comptes)
function password_error(string $password, string $confirmation): ?string
{
    if (mb_strlen($password) < PASSWORD_MIN_LENGTH) {
        return 'Le mot de passe doit contenir au moins ' . PASSWORD_MIN_LENGTH . ' caractères.';
    }
    if ($password !== $confirmation) {
        return 'Le mot de passe et sa confirmation ne correspondent pas.';
    }
    return null;
}
