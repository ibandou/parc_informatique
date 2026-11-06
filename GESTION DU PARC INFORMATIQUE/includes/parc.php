<?php
// Accès aux données du parc partagé entre les pages : listes de référence,
// équipements (avec leur affectation actuelle), affectations, techniciens, interventions.

/* ---------- Listes de référence (id => libellé) ---------- */

function categories_list(): array
{
    return db_query('SELECT id, libelle FROM categories_equipement ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
}

function sites_list(): array
{
    return db_query('SELECT id, nom FROM sites ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
}

function services_list(): array
{
    return db_query('SELECT id, nom FROM services ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
}

function clients_list(): array
{
    return db_query('SELECT id, nom FROM clients ORDER BY nom')->fetchAll(PDO::FETCH_KEY_PAIR);
}

function users_list(): array
{
    return db_query("SELECT id, CONCAT(prenom, ' ', nom) FROM utilisateurs ORDER BY prenom, nom")->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Techniciens (comptes au rôle Technicien) : id technicien => "Prénom Nom (spécialité)"
function techniciens_list(bool $withSpecialite = true): array
{
    $rows = db_fetch_all(
        "SELECT t.id, u.prenom, u.nom, t.specialite
           FROM techniciens t JOIN utilisateurs u ON u.id = t.utilisateur_id
          WHERE u.role = 'technicien'
          ORDER BY u.prenom, u.nom"
    );
    $list = [];
    foreach ($rows as $t) {
        $list[$t['id']] = $t['prenom'] . ' ' . $t['nom'] . ($withSpecialite ? ' (' . ($t['specialite'] ?: '-') . ')' : '');
    }
    return $list;
}

/* ---------- Équipements ---------- */

// Un équipement et son affectation actuelle (ligne d'affectations dont date_fin est NULL)
const EQUIPEMENT_SELECT = "
    SELECT e.*, COALESCE(NULLIF(e.nom_objet, ''), c.libelle) AS type, c.libelle AS categorie_libelle, c.prefixe_inventaire,
           a.id AS affectation_id, a.utilisateur_id, a.service_id, a.site_id, a.client_id,
           u.prenom AS u_prenom, u.nom AS u_nom, sv.nom AS service_nom, si.nom AS site_nom, cl.nom AS client_nom
      FROM equipements e
      JOIN categories_equipement c ON c.id = e.categorie_id
      LEFT JOIN affectations a ON a.equipement_actif = e.id
      LEFT JOIN utilisateurs u ON u.id = a.utilisateur_id
      LEFT JOIN services sv    ON sv.id = a.service_id
      LEFT JOIN sites si       ON si.id = a.site_id
      LEFT JOIN clients cl     ON cl.id = a.client_id
     WHERE e.deleted_at IS NULL";

function find_equipement(int $id): ?array
{
    return db_fetch(EQUIPEMENT_SELECT . ' AND e.id = ?', [$id]);
}

const PREFIXE_AUTRE = 'AUT-';

// Préfixe tiré du nom d'un objet de type "Autre" : ses 3 premières lettres, sans accents,
// en majuscules (Projecteur -> PRO-). Null si le nom contient moins de 2 lettres.
// Le même calcul est fait en JavaScript (assets/js/app.js) pour l'affichage en direct.
function prefix_from_name(?string $name): ?string
{
    $name = strtr(mb_strtolower(trim((string) $name)), [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae',
        'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i',
        'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o',
        'õ' => 'o', 'œ' => 'oe', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
    ]);
    $letters = strtoupper(preg_replace('/[^a-z]/', '', $name));
    return strlen($letters) >= 2 ? substr($letters, 0, 3) . '-' : null;
}

// Préfixe d'inventaire d'un équipement : celui de sa catégorie, ou pour le type "Autre"
// celui tiré du nom de l'objet
function equipement_prefix(int $categorieId, ?string $nomObjet = null): string
{
    $prefix = (string) db_value('SELECT prefixe_inventaire FROM categories_equipement WHERE id = ?', [$categorieId]);
    if ($prefix === PREFIXE_AUTRE) {
        return prefix_from_name($nomObjet) ?? PREFIXE_AUTRE;
    }
    return $prefix;
}

// Préfixe d'un N° d'inventaire existant (PC-B01 -> PC-B)
function inventaire_prefix_of(string $numero): string
{
    return preg_match('/^(.*?)(\d+)$/', $numero, $m) ? $m[1] : $numero;
}

function categorie_autre_id(): ?int
{
    $id = db_value('SELECT id FROM categories_equipement WHERE prefixe_inventaire = ?', [PREFIXE_AUTRE]);
    return $id === false ? null : (int) $id;
}

// Prochain N° d'inventaire libre : préfixe + (plus grand numéro + 1), sur 2 chiffres
// minimum (ex. premier ordinateur de bureau : PC-B01 ; premier projecteur : PRO-01)
function next_inventaire(int $categorieId, ?string $nomObjet = null): string
{
    $prefix = equipement_prefix($categorieId, $nomObjet);
    $numbers = db_query('SELECT numero_inventaire FROM equipements WHERE numero_inventaire LIKE ?', [$prefix . '%'])->fetchAll(PDO::FETCH_COLUMN);
    $max = 0;
    foreach ($numbers as $numero) {
        $suffix = substr($numero, strlen($prefix));
        if (ctype_digit($suffix)) {
            $max = max($max, (int) $suffix);
        }
    }
    return $prefix . str_pad((string) ($max + 1), 2, '0', STR_PAD_LEFT);
}

// Données du calcul en direct du N° d'inventaire dans le formulaire : préfixe de chaque
// catégorie, et plus grand numéro déjà utilisé pour chaque préfixe
function inventaire_form_data(): array
{
    $counters = [];
    foreach (db_query('SELECT numero_inventaire FROM equipements')->fetchAll(PDO::FETCH_COLUMN) as $numero) {
        if (preg_match('/^(.*?)(\d+)$/', $numero, $m)) {
            $counters[$m[1]] = max($counters[$m[1]] ?? 0, (int) $m[2]);
        }
    }
    return [
        'prefixes' => db_query('SELECT id, prefixe_inventaire FROM categories_equipement')->fetchAll(PDO::FETCH_KEY_PAIR),
        'counters' => (object) $counters,
        'autre'    => PREFIXE_AUTRE,
    ];
}

// Change l'affectation d'un équipement : l'affectation actuelle est clôturée (jamais
// supprimée) et une nouvelle est créée. Ne fait rien si rien n'a changé.
// Retourne l'id de la nouvelle affectation, ou null.
function set_affectation(int $equipementId, ?int $userId, ?int $serviceId, ?int $siteId, ?int $clientId): ?int
{
    $current = db_fetch('SELECT * FROM affectations WHERE equipement_actif = ?', [$equipementId]);
    if ($current
        && (int) $current['utilisateur_id'] === (int) $userId
        && (int) $current['service_id'] === (int) $serviceId
        && (int) $current['site_id'] === (int) $siteId
        && (int) $current['client_id'] === (int) $clientId) {
        return null;
    }
    if ($current) {
        db_query('UPDATE affectations SET date_fin = NOW() WHERE id = ?', [$current['id']]);
    }
    db_query(
        'INSERT INTO affectations (equipement_id, utilisateur_id, service_id, site_id, client_id, date_debut, effectue_par)
         VALUES (?, ?, ?, ?, ?, NOW(), ?)',
        [$equipementId, $userId, $serviceId, $siteId, $clientId, current_user_id()]
    );
    return (int) db()->lastInsertId();
}

/* ---------- Techniciens ---------- */

// Fiche technicien liée à un compte (null si le compte n'est pas technicien)
function technicien_id_for_user(int $userId): ?int
{
    $id = db_value('SELECT id FROM techniciens WHERE utilisateur_id = ?', [$userId]);
    return $id === false ? null : (int) $id;
}

// Un compte au rôle Technicien a toujours une fiche technicien (utilisée pour
// l'affectation des interventions et la charge de travail)
function ensure_technicien_profile(int $userId, string $specialite = 'Support général'): int
{
    $id = technicien_id_for_user($userId);
    if ($id === null) {
        db_query('INSERT INTO techniciens (utilisateur_id, specialite) VALUES (?, ?)', [$userId, $specialite]);
        $id = (int) db()->lastInsertId();
    }
    return $id;
}

function technicien_user_id(?int $technicienId): ?int
{
    if (!$technicienId) {
        return null;
    }
    $id = db_value('SELECT utilisateur_id FROM techniciens WHERE id = ?', [$technicienId]);
    return $id === false ? null : (int) $id;
}

function technicien_name(?int $technicienId): string
{
    if (!$technicienId) {
        return 'Non affecté';
    }
    $row = db_fetch('SELECT u.prenom, u.nom FROM techniciens t JOIN utilisateurs u ON u.id = t.utilisateur_id WHERE t.id = ?', [$technicienId]);
    return $row ? $row['prenom'] . ' ' . $row['nom'] : 'Non affecté';
}

/* ---------- Interventions ---------- */

// Une intervention avec son équipement, la catégorie et le site de celui-ci, et son technicien
const INTERVENTION_SELECT = "
    SELECT i.*, e.numero_inventaire, e.est_temporaire, e.marque, e.modele,
           c.id AS categorie_id, c.libelle AS categorie,
           a.site_id, si.nom AS site_nom, a.utilisateur_id AS eq_user_id, eu.prenom AS eu_prenom, eu.nom AS eu_nom,
           t.utilisateur_id AS tech_user_id, tu.prenom AS t_prenom, tu.nom AS t_nom
      FROM interventions i
      LEFT JOIN equipements e           ON e.id = i.equipement_id
      LEFT JOIN categories_equipement c ON c.id = e.categorie_id
      LEFT JOIN affectations a          ON a.equipement_actif = e.id
      LEFT JOIN sites si                ON si.id = a.site_id
      LEFT JOIN utilisateurs eu         ON eu.id = a.utilisateur_id
      LEFT JOIN techniciens t           ON t.id = i.technicien_id
      LEFT JOIN utilisateurs tu         ON tu.id = t.utilisateur_id";

// Restriction de visibilité : un technicien ne voit que les interventions qui lui sont
// affectées. Retourne [clause SQL, paramètres] à ajouter à un WHERE.
function intervention_visibility(): array
{
    if (!is_technicien()) {
        return ['1 = 1', []];
    }
    return ['i.technicien_id = ?', [technicien_id_for_user(current_user_id()) ?? 0]];
}

function find_intervention(int $id): ?array
{
    [$where, $params] = intervention_visibility();
    return db_fetch(INTERVENTION_SELECT . " WHERE i.id = ? AND $where", array_merge([$id], $params));
}

// Prochain numéro d'intervention : INT-<année>-<plus grand numéro existant + 1>
function next_intervention_numero(): string
{
    $max = 0;
    foreach (db_query('SELECT numero FROM interventions')->fetchAll(PDO::FETCH_COLUMN) as $numero) {
        if (preg_match('/(\d+)$/', $numero, $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    return 'INT-' . date('Y') . '-' . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
}

function intervention_link(int $id): string
{
    return page_url('interventions', ['modal' => 'detail', 'id' => $id]);
}

function equipement_link(int $id): string
{
    return page_url('equipements', ['modal' => 'detail', 'id' => $id]);
}
