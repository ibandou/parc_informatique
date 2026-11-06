<?php
// Recherche globale du Dashboard (appelée en JavaScript) : renvoie au plus 4 équipements,
// 4 interventions et 4 utilisateurs, limités à ce que le rôle connecté peut ouvrir.

require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user()) {
    http_response_code(401);
    echo json_encode([]);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$results = [];

if (mb_strlen($q) >= 2) {
    $like = '%' . addcslashes($q, '%_\\') . '%';

    if (can_access('equipements')) {
        foreach (db_fetch_all(
            "SELECT id, numero_inventaire, marque, modele FROM equipements
              WHERE deleted_at IS NULL AND (numero_inventaire LIKE ? OR marque LIKE ? OR modele LIKE ? OR numero_serie LIKE ?)
              ORDER BY numero_inventaire LIMIT 4",
            [$like, $like, $like, $like]
        ) as $eq) {
            $results[] = ['group' => 'Équipements', 'icon' => 'fa-desktop', 'url' => equipement_link((int) $eq['id']),
                'label' => $eq['numero_inventaire'] . ' - ' . trim($eq['marque'] . ' ' . $eq['modele'])];
        }
    }

    if (can_access('interventions')) {
        [$where, $params] = intervention_visibility();
        foreach (db_fetch_all(
            "SELECT i.id, i.numero, i.constat FROM interventions i
              WHERE $where AND (i.numero LIKE ? OR i.constat LIKE ?)
              ORDER BY i.date_debut DESC LIMIT 4",
            array_merge($params, [$like, $like])
        ) as $it) {
            $results[] = ['group' => 'Interventions', 'icon' => 'fa-screwdriver-wrench', 'url' => intervention_link((int) $it['id']),
                'label' => $it['numero'] . ' - ' . $it['constat']];
        }
    }

    if (can_access('utilisateurs')) {
        foreach (db_fetch_all(
            'SELECT id, prenom, nom FROM utilisateurs WHERE nom LIKE ? OR prenom LIKE ? OR email LIKE ? ORDER BY prenom LIMIT 4',
            [$like, $like, $like]
        ) as $u) {
            $results[] = ['group' => 'Utilisateurs', 'icon' => 'fa-user', 'url' => page_url('utilisateurs', ['modal' => 'detail', 'id' => $u['id']]),
                'label' => $u['prenom'] . ' ' . $u['nom']];
        }
    }
}

echo json_encode($results);
