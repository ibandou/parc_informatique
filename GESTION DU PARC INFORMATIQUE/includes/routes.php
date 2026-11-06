<?php
// Pages de l'application et rôles autorisés : point d'entrée unique pour ajuster les droits.
// 'roles' => null : page accessible à tous les rôles connectés.
// La sidebar n'affiche que les pages autorisées, et index.php refuse toute autre page :
// le masquage des liens n'est donc pas la seule protection.

const ADMIN = 'administrateur';
const TECH  = 'technicien';
const CEO   = 'ceo';

const PAGES = [
    'dashboard'     => ['title' => 'Dashboard',     'icon' => 'fa-gauge-high',         'roles' => null],
    'equipements'   => ['title' => 'Équipements',   'icon' => 'fa-desktop',            'roles' => [ADMIN, TECH]],
    'interventions' => ['title' => 'Interventions', 'icon' => 'fa-screwdriver-wrench', 'roles' => [ADMIN, TECH]],
    'calendrier'    => ['title' => 'Calendrier',    'icon' => 'fa-calendar-days',      'roles' => null],
    'historique'    => ['title' => 'Historique',    'icon' => 'fa-clock-rotate-left',  'roles' => [ADMIN]],
    'utilisateurs'  => ['title' => 'Utilisateurs',  'icon' => 'fa-users',              'roles' => [ADMIN]],
    'techniciens'   => ['title' => 'Techniciens',   'icon' => 'fa-user-gear',          'roles' => [ADMIN]],
    'sites'         => ['title' => 'Sites',         'icon' => 'fa-building',           'roles' => [ADMIN]],
    'clients'       => ['title' => 'Clients',       'icon' => 'fa-briefcase',          'roles' => [ADMIN]],
    'affectations'  => ['title' => 'Affectations',  'icon' => 'fa-link',               'roles' => [ADMIN]],
    'rapports'      => ['title' => 'Rapports',      'icon' => 'fa-file-pdf',           'roles' => [ADMIN, TECH, CEO]],
    'notifications' => ['title' => 'Notifications', 'icon' => 'fa-bell',               'roles' => null],
    'profil'        => ['title' => 'Mon profil',    'icon' => 'fa-id-badge',           'roles' => null],
];

// Regroupement de la sidebar, dans l'ordre d'affichage
const NAV_GROUPS = [
    'Aperçu'          => ['dashboard', 'equipements', 'interventions'],
    'Gestion du parc' => ['calendrier', 'historique'],
    'Administration'  => ['utilisateurs', 'techniciens', 'sites', 'clients', 'affectations'],
    'Supervision'     => ['rapports', 'notifications'],
    'Compte'          => ['profil'],
];

// Sous-menus (sous-catégories d'une page) : view => [libellé, icône]
const NAV_SUBS = [
    'interventions' => ['site' => ['Par site', 'fa-building'], 'categorie' => ['Par catégorie', 'fa-layer-group']],
    'utilisateurs'  => ['liste' => ['Liste des utilisateurs', 'fa-list'], 'ajouter' => ['Ajouter un utilisateur', 'fa-user-plus']],
    'sites'         => ['equipements' => ['Équipements', 'fa-desktop'], 'interventions' => ['Interventions', 'fa-screwdriver-wrench']],
];

function can_access(string $page): bool
{
    if (!isset(PAGES[$page])) {
        return false;
    }
    $roles = PAGES[$page]['roles'];
    return $roles === null || has_role(...$roles);
}

// Sous-catégorie affichée d'une page à sous-menu (la première par défaut)
function page_view(string $page): string
{
    $views = array_keys(NAV_SUBS[$page] ?? []);
    $view = $_GET['view'] ?? '';
    return in_array($view, $views, true) ? $view : ($views[0] ?? '');
}
