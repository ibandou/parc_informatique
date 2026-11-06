<?php
// Notifications : chaque ligne de la table est adressée à un compte précis.
// Les alertes de supervision (destinataire "admin" dans le prototype) sont envoyées
// à chaque Administrateur et à chaque CEO actif ; les rappels d'un technicien ne
// sont envoyés qu'à lui.

const NOTIF_ICONS = [
    'critique'         => ['fa-triangle-exclamation', 'red'],
    'panne'            => ['fa-circle-exclamation', 'red'],
    'fiche_provisoire' => ['fa-clipboard-question', 'blue'],
    'assignation'      => ['fa-user-gear', 'blue'],
    'retard'           => ['fa-clock', 'orange'],
    'commentaire'      => ['fa-comment-dots', 'blue'],
    'mdp_oublie'       => ['fa-key', 'orange'],
    'intervention'     => ['fa-screwdriver-wrench', 'blue'],
    'affectation'      => ['fa-link', 'blue'],
];

// Variables CSS correspondant à la couleur d'une notification
function notif_colors(string $type): array
{
    [$icon, $color] = NOTIF_ICONS[$type] ?? ['fa-bell', 'blue'];
    $var = $color === 'red' ? 'danger' : ($color === 'orange' ? 'warning' : 'info');
    return [$icon, $var];
}

function notify(int $userId, string $type, string $message, ?int $interventionId = null): void
{
    db_query(
        'INSERT INTO notifications (destinataire_id, type, message, intervention_id) VALUES (?, ?, ?, ?)',
        [$userId, $type, mb_substr($message, 0, 500), $interventionId]
    );
}

// Alerte de supervision : Administrateurs (et CEO, sauf si $withCeo = false)
function notify_supervision(string $type, string $message, ?int $interventionId = null, bool $withCeo = true): void
{
    $roles = $withCeo ? "'administrateur','ceo'" : "'administrateur'";
    $ids = db_query("SELECT id FROM utilisateurs WHERE statut = 'actif' AND role IN ($roles)")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        notify((int) $id, $type, $message, $interventionId);
    }
}

// Destination d'une notification au clic, selon les droits du compte connecté :
// - une URL : la notification ouvre l'élément signalé ;
// - null : rien à ouvrir pour ce rôle (ex. CEO, sans accès aux Interventions) ;
// - false : l'élément signalé n'est plus disponible (supprimé ou plus accessible).
function notification_target(array $n)
{
    $type = $n['type'];
    $interventionId = $n['intervention_id'] ? (int) $n['intervention_id'] : null;

    if (in_array($type, ['critique', 'assignation', 'retard', 'intervention', 'panne'], true)) {
        if (!$interventionId || !can_access('interventions')) {
            return null;
        }
        // find_intervention() applique la visibilité : un technicien ne voit que ses interventions
        // (l'ancien technicien d'une intervention réaffectée n'y a donc plus accès)
        return find_intervention($interventionId) ? intervention_link($interventionId) : false;
    }

    if ($type === 'fiche_provisoire') {
        if (!is_admin() || !$interventionId) {
            return null;
        }
        $equipementId = db_value('SELECT equipement_id FROM interventions WHERE id = ?', [$interventionId]);
        return $equipementId && find_equipement((int) $equipementId) ? equipement_link((int) $equipementId) : false;
    }

    if ($type === 'mdp_oublie') {
        if (!can_access('utilisateurs')) {
            return null;
        }
        // L'email du demandeur figure entre parenthèses dans le message
        $email = preg_match('/\(([^()\s]+@[^()\s]+)\)/', $n['message'], $m) ? $m[1] : '';
        return page_url('utilisateurs', $email !== '' ? ['search' => $email] : []);
    }

    if ($type === 'commentaire') {
        return can_access('rapports') ? page_url('rapports') : null;
    }

    return null;
}

function unread_notifications_count(int $userId): int
{
    return (int) db_value('SELECT COUNT(*) FROM notifications WHERE destinataire_id = ? AND lue = 0', [$userId]);
}

function latest_notifications(int $userId, int $limit = 6): array
{
    return db_fetch_all(
        'SELECT * FROM notifications WHERE destinataire_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit,
        [$userId]
    );
}

// Contrôle exécuté au chargement de l'application : rappel au technicien d'une intervention
// assignée, toujours "Nouvelle" ou "En attente" alors qu'au moins un jour calendaire s'est
// écoulé depuis sa création. Chaque rappel n'est envoyé qu'une fois (rappel_retard_envoye).
function run_periodic_checks(): void
{
    $overdue = db_fetch_all(
        "SELECT i.id, i.numero, i.date_debut, e.numero_inventaire, t.utilisateur_id
           FROM interventions i
           JOIN techniciens t ON t.id = i.technicien_id
           LEFT JOIN equipements e ON e.id = i.equipement_id
          WHERE i.statut IN ('nouvelle', 'en_attente')
            AND i.rappel_retard_envoye = 0
            AND DATE(i.date_debut) < CURDATE()"
    );
    foreach ($overdue as $it) {
        notify(
            (int) $it['utilisateur_id'],
            'retard',
            'Intervention en retard : ' . $it['numero'] . ($it['numero_inventaire'] ? ' (' . $it['numero_inventaire'] . ')' : '')
                . " n'a toujours pas été traitée depuis le " . format_date($it['date_debut']) . '.',
            (int) $it['id']
        );
        db_query('UPDATE interventions SET rappel_retard_envoye = 1, updated_at = updated_at WHERE id = ?', [$it['id']]);
    }
}
