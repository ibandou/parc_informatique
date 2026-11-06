<?php
// Point d'entrée de l'application connectée : index.php?page=<clé de PAGES>.
// Vérifie la session et le rôle, traite les formulaires (POST) de la page, puis
// l'affiche dans la coquille commune (sidebar + topbar).

require __DIR__ . '/includes/bootstrap.php';

require_login();

$page = $_GET['page'] ?? 'dashboard';
if (!isset(PAGES[$page])) {
    $page = 'dashboard';
}
if (!can_access($page)) {
    // Page réservée à un autre rôle : même en forçant l'URL, on revient au tableau de bord
    redirect(page_url('dashboard'));
}

if (is_post()) {
    csrf_verify();
}

run_periodic_checks();

$printHtml = null;
ob_start();
require __DIR__ . '/pages/' . $page . '.php';
$content = ob_get_clean();

require __DIR__ . '/includes/layout/app.php';
