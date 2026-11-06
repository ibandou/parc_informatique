<?php
// Journal des événements (table historique_evenements, en ajout seul).
// Catégories affichées : celles du prototype (Affectation, Intervention, Utilisateur),
// plus Équipement pour les changements d'état et la validation des fiches temporaires
// (RG13 et RG14 du cahier des charges).

const HIST_CATEGORIES = [
    'Affectation'  => ['types' => ['changement_affectation', 'changement_site'], 'icon' => 'fa-link'],
    'Intervention' => ['types' => ['intervention', 'maintenance'],                'icon' => 'fa-screwdriver-wrench'],
    'Équipement'   => ['types' => ['changement_etat', 'validation_fiche'],         'icon' => 'fa-desktop'],
    'Utilisateur'  => ['types' => ['utilisateur'],                                'icon' => 'fa-user-gear'],
];

function hist_category(string $type): ?string
{
    foreach (HIST_CATEGORIES as $label => $cat) {
        if (in_array($type, $cat['types'], true)) {
            return $label;
        }
    }
    return null;
}

// Types SQL des catégories affichées (pour un filtre IN (...))
function hist_types(?string $category = null): array
{
    if ($category !== null) {
        return HIST_CATEGORIES[$category]['types'] ?? [];
    }
    return array_merge(...array_column(HIST_CATEGORIES, 'types'));
}

function sql_in(array $values): string
{
    return implode(',', array_fill(0, count($values), '?'));
}

// $refs : equipement_id, intervention_id, affectation_id, ancienne_valeur, nouvelle_valeur
function log_event(string $type, string $description, array $refs = []): void
{
    db_query(
        'INSERT INTO historique_evenements
            (type, description, ancienne_valeur, nouvelle_valeur, equipement_id, intervention_id, affectation_id, auteur_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $type,
            $description,
            $refs['ancienne_valeur'] ?? null,
            $refs['nouvelle_valeur'] ?? null,
            $refs['equipement_id'] ?? null,
            $refs['intervention_id'] ?? null,
            $refs['affectation_id'] ?? null,
            current_user_id(),
        ]
    );
}

// Événements avec le nom de leur auteur ("Système" si aucun)
const HISTORIQUE_SELECT = "
    SELECT h.*, e.numero_inventaire,
           COALESCE(CONCAT(u.prenom, ' ', u.nom), 'Système') AS auteur
      FROM historique_evenements h
      LEFT JOIN equipements e  ON e.id = h.equipement_id
      LEFT JOIN utilisateurs u ON u.id = h.auteur_id";
