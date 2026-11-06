<?php
// Protection CSRF : chaque formulaire POST embarque un jeton lié à la session.

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

// À appeler en tête de tout traitement POST : stoppe la requête si le jeton est absent ou faux
function csrf_verify(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Session expirée ou requête invalide. Rechargez la page et réessayez.');
    }
}
