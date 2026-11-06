<?php
// Équipements : tableau filtrable et triable, ajout / modification / suppression
// (Administrateur et Technicien), fiche détaillée à onglets.

$canManage = is_admin() || is_technicien();
$categories = categories_list();
$closeUrl = current_url([], ['modal', 'id']);
$formError = null;

/* ------------------------------ TRAITEMENTS ------------------------------ */

if (is_post() && $canManage) {
    $action = input('action');

    if ($action === 'save') {
        $id = int_param('id', 'post');
        $existing = $id ? find_equipement($id) : null;
        if ($id && !$existing) {
            redirect($closeUrl);
        }

        $categorieId = (int) input('categorie_id');
        $userId = int_param('utilisateur_id', 'post');
        $serviceId = int_param('service_id', 'post');
        $siteId = int_param('site_id', 'post');
        $clientId = int_param('client_id', 'post');
        $etat = input('etat');
        // Type "Autre" : le nom de l'objet est obligatoire et donne le préfixe du N° d'inventaire
        $isAutre = $categorieId === categorie_autre_id();
        $nomObjet = $isAutre ? input('nom_objet') : '';
        $data = [
            'nom_objet'            => $nomObjet !== '' ? $nomObjet : null,
            'marque'               => input('marque'),
            'modele'               => input('modele'),
            'numero_serie'         => input('numero_serie'),
            'processeur'           => input('processeur') ?: null,
            'ram'                  => input('ram') ?: null,
            'stockage'             => input('stockage') ?: null,
            'systeme_exploitation' => input('systeme_exploitation') ?: null,
            'adresse_ip'           => input('adresse_ip') ?: null,
            'adresse_mac'          => input('adresse_mac') ?: null,
            'emplacement'          => input('emplacement') ?: null,
            'date_acquisition'     => input('date_acquisition'),
            'fournisseur'          => input('fournisseur') ?: null,
        ];

        if (!isset($categories[$categorieId]) || $data['marque'] === '' || $data['modele'] === '' || $data['numero_serie'] === ''
            || !$siteId || !isset(ETAT_EQUIPEMENT[$etat]) || !strtotime($data['date_acquisition'])) {
            $formError = 'Veuillez renseigner tous les champs obligatoires (*).';
        } elseif ($isAutre && prefix_from_name($nomObjet) === null) {
            $formError = "Veuillez préciser le nom de l'objet (au moins 2 lettres).";
        } elseif ($data['adresse_ip'] && !filter_var($data['adresse_ip'], FILTER_VALIDATE_IP)) {
            $formError = "L'adresse IP n'est pas valide.";
        } elseif (db_value('SELECT COUNT(*) FROM equipements WHERE numero_serie = ? AND id <> ?', [$data['numero_serie'], $id ?? 0])) {
            $formError = 'Ce numéro de série est déjà enregistré pour un autre équipement.';
        }

        if ($formError === null) {
            $pdo = db();
            $pdo->beginTransaction();

            // N° d'inventaire automatique ; conservé tant que le type (et, pour "Autre", le
            // préfixe tiré du nom) ne change pas. Une fiche temporaire garde son N° TEMP-
            // jusqu'à sa validation.
            $keepNumero = $existing && (int) $existing['categorie_id'] === $categorieId
                && ($existing['est_temporaire'] || inventaire_prefix_of($existing['numero_inventaire']) === equipement_prefix($categorieId, $nomObjet));
            $numero = $keepNumero ? $existing['numero_inventaire'] : next_inventaire($categorieId, $nomObjet);
            $data['numero_inventaire'] = $numero;
            $data['categorie_id'] = $categorieId;
            $data['etat'] = $etat;

            if ($existing) {
                $sets = implode(', ', array_map(function ($k) { return "$k = ?"; }, array_keys($data)));
                db_query("UPDATE equipements SET $sets WHERE id = ?", array_merge(array_values($data), [$id]));
            } else {
                $data['cree_par'] = current_user_id();
                db_query(
                    'INSERT INTO equipements (' . implode(', ', array_keys($data)) . ') VALUES (' . sql_in($data) . ')',
                    array_values($data)
                );
                $id = (int) $pdo->lastInsertId();
            }

            $affectationId = set_affectation($id, $userId, $serviceId, $siteId, $clientId);

            if ($existing && $existing['etat'] !== $etat) {
                // Changement d'état (RG5, RG13) : ancien et nouvel état conservés dans l'historique
                $ancien = ETAT_EQUIPEMENT[$existing['etat']][0] ?? $existing['etat'];
                $nouveau = ETAT_EQUIPEMENT[$etat][0];
                log_event('changement_etat', "État de l'équipement $numero : $ancien → $nouveau.",
                    ['equipement_id' => $id, 'ancienne_valeur' => $ancien, 'nouvelle_valeur' => $nouveau]);
            }

            if ($existing && $affectationId) {
                // Changement de site ou de service (RG4) : journalisé, l'ancienne information n'est jamais perdue
                if ((int) $existing['site_id'] !== (int) $siteId) {
                    $sites = sites_list();
                    $ancien = $existing['site_id'] ? ($sites[$existing['site_id']] ?? '-') : '-';
                    $nouveau = $siteId ? ($sites[$siteId] ?? '-') : '-';
                    log_event('changement_site', "Site de l'équipement $numero : $ancien → $nouveau.",
                        ['equipement_id' => $id, 'affectation_id' => $affectationId, 'ancienne_valeur' => $ancien, 'nouvelle_valeur' => $nouveau]);
                }
                if ((int) $existing['service_id'] !== (int) $serviceId) {
                    $services = services_list();
                    $ancien = $existing['service_id'] ? ($services[$existing['service_id']] ?? '-') : 'Aucun';
                    $nouveau = $serviceId ? ($services[$serviceId] ?? '-') : 'Aucun';
                    log_event('changement_affectation', "Service de l'équipement $numero : $ancien → $nouveau.",
                        ['equipement_id' => $id, 'affectation_id' => $affectationId, 'ancienne_valeur' => $ancien, 'nouvelle_valeur' => $nouveau]);
                }
                // Changement d'utilisateur ou de client : journalisé, l'ancienne information n'est jamais perdue
                if ((int) $existing['utilisateur_id'] !== (int) $userId) {
                    $userName = $userId ? full_name(db_fetch('SELECT prenom, nom FROM utilisateurs WHERE id = ?', [$userId])) : null;
                    log_event('changement_affectation',
                        $userId ? "Équipement $numero affecté à $userName." : "Équipement $numero désaffecté (aucun utilisateur).",
                        ['equipement_id' => $id, 'affectation_id' => $affectationId]);
                }
                if ((int) $existing['client_id'] !== (int) $clientId) {
                    $clients = clients_list();
                    $ancien = $existing['client_id'] ? ($clients[$existing['client_id']] ?? '-') : 'Parc interne IT CORP';
                    $nouveau = $clientId ? ($clients[$clientId] ?? '-') : 'Parc interne IT CORP';
                    log_event('changement_affectation', "Client de l'équipement $numero : $ancien → $nouveau.",
                        ['equipement_id' => $id, 'affectation_id' => $affectationId, 'ancienne_valeur' => $ancien, 'nouvelle_valeur' => $nouveau]);
                }
            }

            $pdo->commit();
            flash('success', $existing ? 'Équipement modifié avec succès.' : 'Équipement ajouté avec succès.');
            redirect($closeUrl);
        }
    }

    // Validation d'une fiche temporaire créée depuis une intervention (RG14) : réservée à
    // l'Administrateur, une fois la fiche complétée. Elle reçoit alors un vrai N° d'inventaire.
    if ($action === 'validate' && is_admin()) {
        $id = int_param('id', 'post');
        $eq = $id ? find_equipement($id) : null;
        if ($eq && $eq['est_temporaire']) {
            $nomManquant = (int) $eq['categorie_id'] === categorie_autre_id() && prefix_from_name($eq['nom_objet']) === null;
            if (trim((string) $eq['numero_serie']) === '' || $eq['marque'] === 'Inconnu' || $eq['modele'] === 'À compléter' || $nomManquant) {
                flash('error', "Complétez la fiche (marque, modèle, N° de série et, pour le type Autre, nom de l'objet) avant de la valider.");
                redirect(current_url(['modal' => 'detail', 'id' => $id]));
            }
            $numero = next_inventaire((int) $eq['categorie_id'], $eq['nom_objet']);
            db_query("UPDATE equipements SET numero_inventaire = ?, est_temporaire = 0, statut_validation = 'valide' WHERE id = ?", [$numero, $id]);
            log_event('validation_fiche', "Fiche temporaire {$eq['numero_inventaire']} validée et inventoriée sous le N° $numero.",
                ['equipement_id' => $id, 'ancienne_valeur' => $eq['numero_inventaire'], 'nouvelle_valeur' => $numero]);
            flash('success', "Fiche validée : l'équipement porte désormais le N° $numero.");
        }
        redirect(current_url(['modal' => 'detail', 'id' => $id]));
    }

    if ($action === 'delete') {
        $id = int_param('id', 'post');
        if ($id && find_equipement($id)) {
            // Suppression logique : l'équipement reste référencé dans l'historique et les interventions
            db_query('UPDATE affectations SET date_fin = NOW() WHERE equipement_actif = ?', [$id]);
            db_query('UPDATE equipements SET deleted_at = NOW() WHERE id = ?', [$id]);
            flash('success', 'Équipement supprimé.');
        }
        redirect($closeUrl);
    }
}

/* ------------------------------ LISTE ------------------------------ */

$f = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'type'   => $_GET['type'] ?? '',
    'etat'   => $_GET['etat'] ?? '',
    'site'   => $_GET['site'] ?? '',
    'client' => $_GET['client'] ?? '',
];
$where = '';
$params = [];
if ($f['type'] !== '')   { $where .= ' AND e.categorie_id = ?'; $params[] = $f['type']; }
if ($f['etat'] !== '')   { $where .= ' AND e.etat = ?';         $params[] = $f['etat']; }
if ($f['site'] !== '')   { $where .= ' AND a.site_id = ?';      $params[] = $f['site']; }
if ($f['client'] === 'interne') { $where .= ' AND a.client_id IS NULL'; }
if ($f['search'] !== '') {
    $like = '%' . addcslashes($f['search'], '%_\\') . '%';
    $where .= ' AND (e.numero_inventaire LIKE ? OR e.nom_objet LIKE ? OR e.marque LIKE ? OR e.modele LIKE ? OR e.numero_serie LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
}

$sort = ($_GET['sort'] ?? 'inventaire') === 'type' ? 'type' : 'inventaire';
$dir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
$orderBy = $sort === 'type' ? "c.libelle $dir, e.numero_inventaire" : "e.numero_inventaire $dir";

$total = (int) db_value('SELECT COUNT(*) FROM (' . EQUIPEMENT_SELECT . $where . ') t', $params);
$pg = paginate($total);
$equipements = db_fetch_all(EQUIPEMENT_SELECT . $where . " ORDER BY $orderBy LIMIT {$pg['limit']} OFFSET {$pg['offset']}", $params);

// Lien de tri : un second clic sur la même colonne inverse l'ordre
function sort_link(string $field, string $label, string $sort, string $dir): string
{
    $next = ($sort === $field && $dir === 'ASC') ? 'desc' : 'asc';
    return '<th data-sort="' . $field . '"><a href="' . e(current_url(['sort' => $field, 'dir' => $next], ['p'])) . '">' . e($label) . '</a></th>';
}

$etats = array_map(function ($v) { return $v[0]; }, ETAT_EQUIPEMENT);
?>
<div class="page-header">
  <div>
    <h1><i class="fa-solid fa-desktop"></i> Équipements</h1>
    <p class="page-subtitle">Gestion du parc matériel informatique</p>
  </div>
  <?php if ($canManage): ?>
    <a class="btn btn-primary" id="btn-add-equipement" href="<?= e(current_url(['modal' => 'form'], ['id'])) ?>"><i class="fa-solid fa-plus"></i> Ajouter un équipement</a>
  <?php endif; ?>
</div>

<form class="toolbar" method="get" data-autosubmit>
  <input type="hidden" name="page" value="equipements">
  <input type="hidden" name="sort" value="<?= e($_GET['sort'] ?? '') ?>">
  <input type="hidden" name="dir" value="<?= e($_GET['dir'] ?? '') ?>">
  <div class="input-icon toolbar-search"><i class="fa-solid fa-magnifying-glass"></i><input type="text" name="search" value="<?= e($f['search']) ?>" placeholder="Rechercher (inventaire, marque, série...)"<?= $f['search'] !== '' ? ' data-refocus' : '' ?>></div>
  <select name="type" class="select-input"><option value="">Tous types</option><?= options($categories, $f['type']) ?></select>
  <select name="etat" class="select-input"><option value="">Tous états</option><?= options($etats, $f['etat']) ?></select>
  <select name="site" class="select-input"><option value="">Tous sites</option><?= options(sites_list(), $f['site']) ?></select>
  <select name="client" class="select-input"><option value="">Parc interne + clients</option><?= options(['interne' => 'Parc interne uniquement'], $f['client']) ?></select>
</form>

<div class="table-wrapper">
  <table class="data-table" id="table-equipements">
    <thead><tr>
      <?= sort_link('inventaire', 'N° inventaire', $sort, $dir) ?><?= sort_link('type', 'Type', $sort, $dir) ?>
      <th>Modèle</th><th>Utilisateur</th><th>Service</th><th>État</th><th>Actions</th>
    </tr></thead>
    <tbody>
      <?php if (!$equipements) echo empty_row(7, 'Aucun équipement ne correspond à votre recherche.'); ?>
      <?php foreach ($equipements as $eq): ?>
        <tr>
          <td>
            <strong><?= e($eq['numero_inventaire']) ?></strong>
            <?php if ($eq['est_temporaire']): ?><div class="cell-sub">Fiche temporaire</div><?php endif; ?>
            <?php if ($eq['client_id']): ?><div class="cell-sub"><i class="fa-solid fa-briefcase"></i> <?= e($eq['client_nom']) ?></div><?php endif; ?>
          </td>
          <td><?= e($eq['type']) ?></td>
          <td><?= e($eq['modele'] ?: '-') ?></td>
          <td><?= $eq['utilisateur_id'] ? e(full_name($eq, 'u_')) : '<span class="cell-sub">Non affecté</span>' ?></td>
          <td><?= e($eq['service_nom'] ?? '-') ?></td>
          <td><?= enum_badge(ETAT_EQUIPEMENT, $eq['etat']) ?></td>
          <td class="cell-actions">
            <a class="icon-btn icon-btn-table" title="Voir" href="<?= e(current_url(['modal' => 'detail', 'id' => $eq['id']])) ?>"><i class="fa-solid fa-eye"></i></a>
            <?php if ($canManage): ?>
              <a class="icon-btn icon-btn-table" title="Modifier" href="<?= e(current_url(['modal' => 'form', 'id' => $eq['id']])) ?>"><i class="fa-solid fa-pen"></i></a>
              <?php $hasInterventions = (bool) db_value('SELECT COUNT(*) FROM interventions WHERE equipement_id = ?', [$eq['id']]); ?>
              <form method="post" data-confirm-title="Supprimer cet équipement ?"
                    data-confirm-message="<?= e($hasInterventions
                        ? "L'équipement {$eq['numero_inventaire']} possède des interventions liées. Il sera supprimé du parc mais restera référencé dans l'historique et les interventions existantes."
                        : "L'équipement {$eq['numero_inventaire']} sera définitivement supprimé du parc informatique.") ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $eq['id'] ?>">
                <button type="submit" class="icon-btn icon-btn-table" title="Supprimer"><i class="fa-solid fa-trash"></i></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?= pagination_links($pg) ?>

<?php
/* ------------------------------ MODALES ------------------------------ */

$modal = $_GET['modal'] ?? '';
$modalId = int_param('id');

if ($modal === 'form' && $canManage) {
    $eq = $modalId ? find_equipement($modalId) : null;
    $editing = $eq !== null;
    $keepCategorie = $editing ? (string) $eq['categorie_id'] : '';
    modal_open($editing ? "Modifier l'équipement" : 'Ajouter un équipement', $editing ? 'fa-pen' : 'fa-plus', $closeUrl, 'modal-lg');
    ?>
    <form method="post" action="<?= e(current_url()) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= $editing ? (int) $eq['id'] : '' ?>">
      <div class="modal-body">
        <?= error_box($formError) ?>
        <div class="section-title"><i class="fa-solid fa-id-card"></i> Identification</div>
        <div class="form-row three">
          <div class="form-field"><label>Type *</label>
            <select name="categorie_id" required data-inventaire-target="#equip-inventaire"
                    data-inventaire="<?= e(json_encode(inventaire_form_data())) ?>" data-nom-objet="#equip-nom-objet"
                    data-inventaire-keep-for="<?= e($keepCategorie) ?>" data-inventaire-keep-value="<?= e($eq['numero_inventaire'] ?? '') ?>"
                    data-inventaire-keep-any="<?= $editing && $eq['est_temporaire'] ? '1' : '' ?>">
              <?= options($categories, fv('categorie_id', $eq['categorie_id'] ?? '')) ?>
            </select>
          </div>
          <div class="form-field"><label>N° inventaire</label><input type="text" id="equip-inventaire" readonly tabindex="-1"><div class="form-hint"></div></div>
          <div class="form-field"><label>Marque *</label><input type="text" name="marque" value="<?= e(fv('marque', $eq['marque'] ?? '')) ?>" required></div>
        </div>
        <div class="form-row single" hidden>
          <div class="form-field"><label>Nom de l'objet *</label><input type="text" name="nom_objet" id="equip-nom-objet" maxlength="100" value="<?= e(fv('nom_objet', $eq['nom_objet'] ?? '')) ?>" placeholder=""><div class="form-hint"></div></div>
        </div>
        <div class="form-row">
          <div class="form-field"><label>Modèle *</label><input type="text" name="modele" value="<?= e(fv('modele', $eq['modele'] ?? '')) ?>" required></div>
          <div class="form-field"><label>N° série *</label><input type="text" name="numero_serie" value="<?= e(fv('numero_serie', $eq['numero_serie'] ?? '')) ?>" required></div>
        </div>
        <div class="form-row">
          <div class="form-field"><label>Date d'achat *</label><input type="date" name="date_acquisition" value="<?= e(fv('date_acquisition', $eq['date_acquisition'] ?? '')) ?>" required></div>
          <div class="form-field"><label>Fournisseur</label><input type="text" name="fournisseur" value="<?= e(fv('fournisseur', $eq['fournisseur'] ?? '')) ?>"></div>
        </div>

        <div class="section-title"><i class="fa-solid fa-microchip"></i> Caractéristiques</div>
        <div class="form-row three">
          <div class="form-field"><label>Processeur</label><input type="text" name="processeur" value="<?= e(fv('processeur', $eq['processeur'] ?? '')) ?>"></div>
          <div class="form-field"><label>RAM</label><input type="text" name="ram" value="<?= e(fv('ram', $eq['ram'] ?? '')) ?>"></div>
          <div class="form-field"><label>Stockage</label><input type="text" name="stockage" value="<?= e(fv('stockage', $eq['stockage'] ?? '')) ?>"></div>
        </div>
        <div class="form-row three">
          <div class="form-field"><label>Système d'exploitation</label><input type="text" name="systeme_exploitation" value="<?= e(fv('systeme_exploitation', $eq['systeme_exploitation'] ?? '')) ?>"></div>
          <div class="form-field"><label>Adresse IP</label><input type="text" name="adresse_ip" value="<?= e(fv('adresse_ip', $eq['adresse_ip'] ?? '')) ?>" placeholder="192.168.1.10"></div>
          <div class="form-field"><label>Adresse MAC</label><input type="text" name="adresse_mac" value="<?= e(fv('adresse_mac', $eq['adresse_mac'] ?? '')) ?>" placeholder="00:1B:44:11:3A:B7" maxlength="17"></div>
        </div>

        <div class="section-title"><i class="fa-solid fa-link"></i> Affectation</div>
        <div class="form-row three">
          <div class="form-field"><label>Utilisateur</label><select name="utilisateur_id"><option value="">- Non affecté -</option><?= options(users_list(), fv('utilisateur_id', $eq['utilisateur_id'] ?? '')) ?></select></div>
          <div class="form-field"><label>Service</label><select name="service_id"><?= options(services_list(), fv('service_id', $eq['service_id'] ?? '')) ?></select></div>
          <div class="form-field"><label>Site *</label><select name="site_id" required><?= options(sites_list(), fv('site_id', $eq['site_id'] ?? '')) ?></select></div>
        </div>
        <div class="form-row">
          <div class="form-field"><label>Client</label><select name="client_id"><option value="">- Parc interne IT CORP -</option><?= options(clients_list(), fv('client_id', $eq['client_id'] ?? '')) ?></select><div class="form-hint">À renseigner uniquement si cet équipement appartient à un client externe géré par le Pôle Infogérance.</div></div>
          <div class="form-field"><label>Emplacement</label><input type="text" name="emplacement" value="<?= e(fv('emplacement', $eq['emplacement'] ?? '')) ?>" placeholder="Bureau 12 - Étage 1"></div>
        </div>

        <div class="section-title"><i class="fa-solid fa-circle-info"></i> État</div>
        <div class="form-row">
          <div class="form-field"><label>État *</label><select name="etat" required><?= options($etats, fv('etat', $eq['etat'] ?? 'en_service')) ?></select></div>
        </div>
      </div>
      <div class="modal-footer">
        <a class="btn btn-outline" href="<?= e($closeUrl) ?>">Annuler</a>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> <?= $editing ? 'Enregistrer' : 'Ajouter' ?></button>
      </div>
    </form>
    <?php
    modal_close();
}

if ($modal === 'detail' && $modalId && ($eq = find_equipement($modalId))) {
    $affectations = db_fetch_all(
        "SELECT a.*, u.prenom, u.nom, sv.nom AS service_nom, si.nom AS site_nom
           FROM affectations a
           LEFT JOIN utilisateurs u ON u.id = a.utilisateur_id
           LEFT JOIN services sv    ON sv.id = a.service_id
           LEFT JOIN sites si       ON si.id = a.site_id
          WHERE a.equipement_id = ?
          ORDER BY a.date_debut DESC, a.id DESC",
        [$eq['id']]
    );
    $interventions = can_access('interventions')
        ? db_fetch_all('SELECT id, numero, date_debut, constat, statut FROM interventions WHERE equipement_id = ? ORDER BY date_debut DESC', [$eq['id']])
        : [];
    $types = hist_types();
    $journal = db_fetch_all(
        HISTORIQUE_SELECT . ' WHERE h.equipement_id = ? AND h.type IN (' . sql_in($types) . ') ORDER BY h.date_evenement DESC, h.id DESC LIMIT 15',
        array_merge([$eq['id']], $types)
    );

    $title = e($eq['numero_inventaire'])
        . ($eq['est_temporaire'] ? ' <span class="badge badge-orange" style="margin-left:8px">Fiche temporaire</span>' : '')
        . ($eq['client_id'] ? ' <span class="badge badge-blue" style="margin-left:8px"><i class="fa-solid fa-briefcase"></i> ' . e($eq['client_nom']) . '</span>' : '');
    modal_open($title, 'fa-desktop', $closeUrl, 'modal-lg');
    $v = function ($value) { return e($value !== null && $value !== '' ? $value : '-'); };
    ?>
    <div class="modal-body">
      <div class="tabs" data-tabs>
        <button class="tab-btn active" data-tab="tab-identification">Identification</button>
        <button class="tab-btn" data-tab="tab-caracteristiques">Caractéristiques</button>
        <button class="tab-btn" data-tab="tab-affectation">Affectation</button>
        <button class="tab-btn" data-tab="tab-historique">Historique</button>
      </div>

      <div class="tab-panel active" id="tab-identification">
        <div class="detail-grid">
          <div class="detail-item"><div class="detail-label">N° inventaire</div><div class="detail-value"><?= $v($eq['numero_inventaire']) ?></div></div>
          <div class="detail-item"><div class="detail-label">Type</div><div class="detail-value"><?= $v($eq['type']) ?><?= $eq['nom_objet'] ? ' <span class="cell-sub">(' . e($eq['categorie_libelle']) . ')</span>' : '' ?></div></div>
          <div class="detail-item"><div class="detail-label">Marque</div><div class="detail-value"><?= $v($eq['marque']) ?></div></div>
          <div class="detail-item"><div class="detail-label">Modèle</div><div class="detail-value"><?= $v($eq['modele']) ?></div></div>
          <div class="detail-item"><div class="detail-label">N° série</div><div class="detail-value"><?= $v($eq['numero_serie']) ?></div></div>
          <div class="detail-item"><div class="detail-label">État actuel</div><div class="detail-value"><?= enum_badge(ETAT_EQUIPEMENT, $eq['etat']) ?></div></div>
          <div class="detail-item"><div class="detail-label">Date d'achat</div><div class="detail-value"><?= e(format_date($eq['date_acquisition'])) ?></div></div>
          <div class="detail-item"><div class="detail-label">Fournisseur</div><div class="detail-value"><?= $v($eq['fournisseur']) ?></div></div>
        </div>
      </div>

      <div class="tab-panel" id="tab-caracteristiques">
        <div class="detail-grid">
          <div class="detail-item"><div class="detail-label">Processeur</div><div class="detail-value"><?= $v($eq['processeur']) ?></div></div>
          <div class="detail-item"><div class="detail-label">RAM</div><div class="detail-value"><?= $v($eq['ram']) ?></div></div>
          <div class="detail-item"><div class="detail-label">Stockage</div><div class="detail-value"><?= $v($eq['stockage']) ?></div></div>
          <div class="detail-item"><div class="detail-label">Système d'exploitation</div><div class="detail-value"><?= $v($eq['systeme_exploitation']) ?></div></div>
          <div class="detail-item"><div class="detail-label">Adresse IP</div><div class="detail-value"><?= $v($eq['adresse_ip']) ?></div></div>
          <div class="detail-item"><div class="detail-label">Adresse MAC</div><div class="detail-value"><?= $v($eq['adresse_mac']) ?></div></div>
        </div>
      </div>

      <div class="tab-panel" id="tab-affectation">
        <div class="detail-grid">
          <div class="detail-item"><div class="detail-label">Utilisateur</div><div class="detail-value"><?= $eq['utilisateur_id'] ? e(full_name($eq, 'u_')) : 'Non affecté' ?></div></div>
          <div class="detail-item"><div class="detail-label">Service</div><div class="detail-value"><?= $v($eq['service_nom']) ?></div></div>
          <div class="detail-item"><div class="detail-label">Site</div><div class="detail-value"><?= $v($eq['site_nom']) ?></div></div>
          <div class="detail-item"><div class="detail-label">Client</div><div class="detail-value"><?= e($eq['client_id'] ? $eq['client_nom'] : 'Parc interne IT CORP') ?></div></div>
          <div class="detail-item"><div class="detail-label">Emplacement</div><div class="detail-value"><?= $v($eq['emplacement']) ?></div></div>
        </div>
        <div class="divider"></div>
        <div class="section-title">Historique des affectations</div>
        <?php if (!$affectations): ?>
          <div class="list-empty">Aucune affectation enregistrée.</div>
        <?php else: ?>
          <div class="timeline">
            <?php foreach ($affectations as $a): $actif = $a['date_fin'] === null; ?>
              <div class="timeline-item"><div class="timeline-dot" style="background:<?= $actif ? 'var(--success)' : 'var(--neutral)' ?>"></div>
                <div class="timeline-date"><?= e(format_date($a['date_debut'])) ?> <?= $actif ? '→ en cours' : '→ ' . e(format_date($a['date_fin'])) ?></div>
                <div class="timeline-content">
                  <div class="timeline-title"><?= e($a['utilisateur_id'] ? full_name($a) : 'Non affecté') ?> <?= $actif ? '<span class="badge badge-green" style="margin-left:6px">Active</span>' : '<span class="badge badge-grey" style="margin-left:6px">Terminée</span>' ?></div>
                  <div class="timeline-meta"><?= e($a['service_nom'] ?? '-') ?> · <?= e($a['site_nom'] ?? '-') ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="tab-panel" id="tab-historique">
        <?php if ($interventions): ?>
          <div class="section-title">Interventions liées</div>
          <div class="table-wrapper" style="margin-bottom:20px"><table class="data-table"><thead><tr><th>N°</th><th>Date</th><th>Problème</th><th>Statut</th></tr></thead>
            <tbody>
              <?php foreach ($interventions as $it): ?>
                <tr data-href="<?= e(intervention_link((int) $it['id'])) ?>"><td><?= e($it['numero']) ?></td><td><?= e(format_date($it['date_debut'])) ?></td><td><?= e($it['constat']) ?></td><td><?= enum_badge(STATUT_INTERVENTION, $it['statut']) ?></td></tr>
              <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
        <div class="section-title">Journal des événements</div>
        <?php if (!$journal): ?>
          <div class="list-empty">Aucun événement enregistré.</div>
        <?php else: ?>
          <div class="timeline">
            <?php foreach ($journal as $h): ?>
              <div class="timeline-item"><div class="timeline-dot"></div><div class="timeline-date"><?= e(format_date($h['date_evenement'])) ?></div>
                <div class="timeline-content"><div class="timeline-title"><?= e(hist_category($h['type'])) ?></div><div class="timeline-meta"><?= e($h['description']) ?> - <?= e($h['auteur']) ?></div></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="modal-footer">
      <?php if ($eq['est_temporaire'] && is_admin()): ?>
        <a class="btn btn-secondary" href="<?= e(current_url(['modal' => 'form', 'id' => $eq['id']])) ?>"><i class="fa-solid fa-pen"></i> Compléter la fiche</a>
        <form method="post" action="<?= e(current_url()) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="validate">
          <input type="hidden" name="id" value="<?= (int) $eq['id'] ?>">
          <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Valider la fiche</button>
        </form>
      <?php endif; ?>
      <a class="btn btn-<?= $eq['est_temporaire'] && is_admin() ? 'outline' : 'primary' ?>" href="<?= e($closeUrl) ?>">Fermer</a>
    </div>
    <?php
    modal_close();
}
