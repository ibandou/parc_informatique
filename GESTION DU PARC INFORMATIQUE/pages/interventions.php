<?php
// Interventions : sous-catégories "Par site" / "Par catégorie", création, fiche détaillée
// avec stepper du processus, diagnostic / actions / tests / résultat, mises à jour
// horodatées (timeline), affectation d'un technicien et clôture.
// Le Technicien ne voit et ne gère que les interventions qui lui sont affectées.

$view = page_view('interventions');
$closeUrl = current_url([], ['modal', 'id']);
$detailUrl = function (int $id) { return current_url(['modal' => 'detail', 'id' => $id]); };
$formError = null;
$etapeKeys = array_keys(ETAPES_PROCESSUS);

/* ------------------------------ TRAITEMENTS ------------------------------ */

if (is_post()) {
    $action = input('action');

    if ($action === 'create') {
        $nonInventorie = !empty($_POST['non_inventorie']);
        $equipementId = int_param('equipement_id', 'post');
        $technicienId = int_param('technicien_id', 'post');
        $priorite = input('priorite');
        $probleme = input('probleme');

        if ($probleme === '' || !isset(PRIORITE_INTERVENTION[$priorite])) {
            $formError = 'Veuillez renseigner tous les champs obligatoires (*).';
        } elseif (!$nonInventorie && (!$equipementId || !find_equipement($equipementId))) {
            $formError = 'Veuillez choisir un équipement.';
        } elseif ($technicienId && !isset(techniciens_list()[$technicienId])) {
            $formError = 'Technicien invalide.';
        }

        if ($formError === null) {
            $pdo = db();
            $pdo->beginTransaction();

            if ($nonInventorie) {
                // Fiche temporaire TEMP-00X créée directement depuis l'intervention
                $max = 0;
                foreach (db_query("SELECT numero_inventaire FROM equipements WHERE numero_inventaire LIKE 'TEMP-%'")->fetchAll(PDO::FETCH_COLUMN) as $n) {
                    $max = max($max, (int) substr($n, 5));
                }
                $tempNumero = 'TEMP-' . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
                $categorieId = (int) input('temp_categorie_id');
                if (!isset(categories_list()[$categorieId])) {
                    $categorieId = (int) db_value("SELECT id FROM categories_equipement WHERE prefixe_inventaire = 'AUT-'");
                }
                $description = input('temp_description');
                db_query(
                    "INSERT INTO equipements (numero_inventaire, categorie_id, marque, modele, etat, date_acquisition, est_temporaire, statut_validation, cree_par)
                     VALUES (?, ?, ?, ?, 'en_panne', CURDATE(), 1, 'en_attente', ?)",
                    [$tempNumero, $categorieId, $description ?: 'Inconnu', $description ?: 'À compléter', current_user_id()]
                );
                $equipementId = (int) $pdo->lastInsertId();
                set_affectation($equipementId, null, null, (int) db_value('SELECT id FROM sites ORDER BY id LIMIT 1') ?: null, null);
            }

            $numero = next_intervention_numero();
            db_query(
                'INSERT INTO interventions (numero, equipement_id, technicien_id, user_id, demandeur, priorite, statut, etape, constat)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$numero, $equipementId, $technicienId, current_user_id(), input('demandeur') ?: null, $priorite,
                 $technicienId ? 'en_attente' : 'nouvelle', $technicienId ? 'qualification' : 'creation', $probleme]
            );
            $interventionId = (int) $pdo->lastInsertId();
            log_event('intervention', "Ouverture de l'intervention $numero : $probleme.",
                ['equipement_id' => $equipementId, 'intervention_id' => $interventionId]);

            if ($nonInventorie) {
                // Rattachée à l'intervention : un clic sur la notification ouvre la fiche à valider
                notify_supervision('fiche_provisoire', "Un équipement temporaire ($tempNumero) attend une validation.", $interventionId);
            }
            if ($priorite === 'critique') {
                notify_supervision('critique', "L'intervention $numero est critique.", $interventionId);
            }
            if ($technicienId && ($techUser = technicien_user_id($technicienId))) {
                notify($techUser, 'assignation', "Nouvelle intervention vous a été assignée : $numero.", $interventionId);
            }

            $pdo->commit();
            flash('success', 'Intervention créée avec succès.');
            redirect($closeUrl);
        }
    }

    // Actions sur une intervention existante (visible par l'utilisateur connecté)
    $it = ($id = int_param('id', 'post')) ? find_intervention($id) : null;

    if ($it && $action === 'save_fields' && $it['statut'] !== 'cloturee') {
        $fields = [
            'diagnostic'        => input('diagnostic'),
            'actions_realisees' => input('actions_realisees'),
            'tests_effectues'   => input('tests_effectues'),
            'resultat'          => input('resultat'),
            'compte_rendu'      => input('compte_rendu'),
        ];
        // Avancement automatique de l'étape selon les champs renseignés
        $index = array_search($it['etape'], $etapeKeys, true);
        if ($fields['diagnostic'] !== '')        $index = max($index, 2);
        if ($fields['actions_realisees'] !== '') $index = max($index, 3);
        if ($fields['tests_effectues'] !== '')   $index = max($index, 4);
        $statut = ($it['statut'] === 'nouvelle' && ($fields['diagnostic'] !== '' || $fields['actions_realisees'] !== '')) ? 'en_cours' : $it['statut'];

        db_query(
            'UPDATE interventions SET diagnostic = ?, actions_realisees = ?, tests_effectues = ?, resultat = ?, compte_rendu = ?, etape = ?, statut = ? WHERE id = ?',
            array_merge(array_map(function ($v) { return $v === '' ? null : $v; }, array_values($fields)), [$etapeKeys[$index], $statut, $it['id']])
        );
        flash('success', 'Informations enregistrées.');
        redirect($detailUrl((int) $it['id']));
    }

    if ($it && $action === 'add_update') {
        $texte = input('texte');
        if ($texte === '') {
            flash('error', 'Veuillez saisir une description.');
            redirect(current_url(['modal' => 'update', 'id' => $it['id']]));
        }
        if (in_array($it['statut'], ['nouvelle', 'en_attente'], true)) {
            db_query("UPDATE interventions SET statut = 'en_cours' WHERE id = ?", [$it['id']]);
        } else {
            db_query('UPDATE interventions SET updated_at = NOW() WHERE id = ?', [$it['id']]);
        }
        log_event('intervention', "Mise à jour de l'intervention {$it['numero']} : $texte",
            ['equipement_id' => $it['equipement_id'], 'intervention_id' => $it['id']]);
        flash('success', 'Mise à jour ajoutée.');
        redirect($detailUrl((int) $it['id']));
    }

    // Affectation initiale ou réaffectation : réservée à l'Administrateur
    if ($it && $action === 'assign' && is_admin() && $it['statut'] !== 'cloturee') {
        $techId = int_param('technicien_id', 'post');
        $ancienTechId = $it['technicien_id'] ? (int) $it['technicien_id'] : null;
        if (!$techId || $techId === $ancienTechId || !isset(techniciens_list()[$techId])) {
            flash('error', 'Veuillez choisir un technicien.');
            redirect(current_url(['modal' => 'assign', 'id' => $it['id']]));
        }
        $motif = input('motif');
        $etape = array_search($it['etape'], $etapeKeys, true) < 1 ? 'qualification' : $it['etape'];
        $statut = $it['statut'] === 'nouvelle' ? 'en_attente' : $it['statut'];
        db_query('UPDATE interventions SET technicien_id = ?, statut = ?, etape = ? WHERE id = ?', [$techId, $statut, $etape, $it['id']]);

        $texte = $ancienTechId
            ? "Intervention {$it['numero']} réaffectée de " . technicien_name($ancienTechId) . ' à ' . technicien_name($techId) . ($motif !== '' ? " (motif : $motif)" : '') . '.'
            : "Intervention {$it['numero']} affectée à " . technicien_name($techId) . '.';
        log_event('intervention', $texte, ['equipement_id' => $it['equipement_id'], 'intervention_id' => $it['id']]);
        if ($ancienTechId && ($oldUser = technicien_user_id($ancienTechId))) {
            notify($oldUser, 'assignation', "L'intervention {$it['numero']} a été réaffectée à un autre technicien.", (int) $it['id']);
        }
        flash('success', $ancienTechId ? 'Intervention réaffectée avec succès.' : 'Technicien affecté avec succès.');
        redirect($detailUrl((int) $it['id']));
    }

    // Règle de clôture : diagnostic, actions réalisées et résultat obligatoires
    if ($it && $action === 'close' && $it['statut'] !== 'cloturee') {
        if (trim((string) $it['diagnostic']) === '' || trim((string) $it['actions_realisees']) === '' || trim((string) $it['resultat']) === '') {
            flash('error', 'Impossible de clôturer cette intervention. Veuillez renseigner le diagnostic, les actions réalisées et le résultat.');
            redirect($detailUrl((int) $it['id']));
        }
        db_query("UPDATE interventions SET statut = 'cloturee' WHERE id = ?", [$it['id']]);
        log_event('intervention', "Clôture de l'intervention {$it['numero']}.", ['equipement_id' => $it['equipement_id'], 'intervention_id' => $it['id']]);
        flash('success', 'Intervention clôturée avec succès.');
        redirect($closeUrl);
    }

    if ($action !== 'create') {
        redirect($closeUrl);
    }
}

/* ------------------------------ LISTE ------------------------------ */

[$visWhere, $visParams] = intervention_visibility();
$groupKey = $view === 'site' ? 'site' : 'categorie';
$f = [
    'search'   => trim((string) ($_GET['search'] ?? '')),
    'statut'   => $_GET['statut'] ?? '',
    'priorite' => $_GET['priorite'] ?? '',
    'tech'     => $_GET['tech'] ?? '',
    'group'    => $_GET[$groupKey] ?? '',
];

// Pastilles de regroupement : une par site (ou par catégorie) avec son nombre d'interventions
$groups = $view === 'site'
    ? db_fetch_all("SELECT s.id AS value, s.nom AS label, COUNT(x.id) AS n FROM sites s
                     LEFT JOIN (SELECT i.id, a.site_id FROM interventions i
                                  LEFT JOIN affectations a ON a.equipement_actif = i.equipement_id WHERE $visWhere) x ON x.site_id = s.id
                    GROUP BY s.id, s.nom ORDER BY s.id", $visParams)
    : db_fetch_all("SELECT c.id AS value, c.libelle AS label, COUNT(x.id) AS n FROM categories_equipement c
                     LEFT JOIN (SELECT i.id, e.categorie_id FROM interventions i
                                  LEFT JOIN equipements e ON e.id = i.equipement_id WHERE $visWhere) x ON x.categorie_id = c.id
                    GROUP BY c.id, c.libelle ORDER BY c.id", $visParams);
$visibleTotal = (int) db_value("SELECT COUNT(*) FROM interventions i WHERE $visWhere", $visParams);

$where = " WHERE $visWhere";
$params = $visParams;
if ($f['group'] !== '') { $where .= $view === 'site' ? ' AND a.site_id = ?' : ' AND c.id = ?'; $params[] = $f['group']; }
if ($f['statut'] !== '')   { $where .= ' AND i.statut = ?';        $params[] = $f['statut']; }
if ($f['priorite'] !== '') { $where .= ' AND i.priorite = ?';      $params[] = $f['priorite']; }
if ($f['tech'] !== '')     { $where .= ' AND i.technicien_id = ?'; $params[] = $f['tech']; }
if ($f['search'] !== '') {
    $like = '%' . addcslashes($f['search'], '%_\\') . '%';
    $where .= ' AND (i.numero LIKE ? OR i.constat LIKE ? OR e.numero_inventaire LIKE ?)';
    array_push($params, $like, $like, $like);
}

$total = (int) db_value('SELECT COUNT(*) FROM (' . INTERVENTION_SELECT . $where . ') t', $params);
$pg = paginate($total);
$interventions = db_fetch_all(INTERVENTION_SELECT . $where . " ORDER BY i.date_debut DESC, i.id DESC LIMIT {$pg['limit']} OFFSET {$pg['offset']}", $params);

$labels = function (array $map) { return array_map(function ($v) { return $v[0]; }, $map); };
$chip = function ($value, string $label, int $count) use ($groupKey, $f) {
    $active = (string) $f['group'] === (string) $value ? ' active' : '';
    return '<a class="group-chip' . $active . '" href="' . e(current_url([$groupKey => $value], ['p'])) . '">' . e($label) . ' <span class="group-chip-count">' . $count . '</span></a>';
};
?>
<div class="page-header">
  <div>
    <h1><i class="fa-solid fa-screwdriver-wrench"></i> Interventions</h1>
    <p class="page-subtitle">Suivi des interventions techniques</p>
  </div>
  <a class="btn btn-primary" id="btn-add-intervention" href="<?= e(current_url(['modal' => 'create'], ['id'])) ?>"><i class="fa-solid fa-plus"></i> Nouvelle intervention</a>
</div>

<div class="tabs" id="int-view-tabs">
  <?php foreach (NAV_SUBS['interventions'] as $v => [$label, $icon]): ?>
    <a class="tab-btn<?= $v === $view ? ' active' : '' ?>" href="<?= e(page_url('interventions', ['view' => $v])) ?>"><i class="fa-solid <?= e($icon) ?>"></i> <?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<div id="int-group-chips" class="group-chips">
  <?= $chip('', $view === 'site' ? 'Tous les sites' : 'Toutes catégories', $visibleTotal) ?>
  <?php foreach ($groups as $g) echo $chip($g['value'], $g['label'], (int) $g['n']); ?>
</div>

<form class="toolbar" method="get" data-autosubmit>
  <input type="hidden" name="page" value="interventions">
  <input type="hidden" name="view" value="<?= e($view) ?>">
  <input type="hidden" name="<?= $groupKey ?>" value="<?= e($f['group']) ?>">
  <div class="input-icon toolbar-search"><i class="fa-solid fa-magnifying-glass"></i><input type="text" name="search" value="<?= e($f['search']) ?>" placeholder="Rechercher (n°, équipement, problème...)"<?= $f['search'] !== '' ? ' data-refocus' : '' ?>></div>
  <select name="statut" class="select-input"><option value="">Tous statuts</option><?= options($labels(STATUT_INTERVENTION), $f['statut']) ?></select>
  <select name="priorite" class="select-input"><option value="">Toutes priorités</option><?= options($labels(PRIORITE_INTERVENTION), $f['priorite']) ?></select>
  <select name="tech" class="select-input"><option value="">Tous techniciens</option><?= options(techniciens_list(false), $f['tech']) ?></select>
</form>

<div class="table-wrapper">
  <table class="data-table" id="table-interventions">
    <thead><tr>
      <th>N° intervention</th><th>Équipement</th><th>Catégorie</th><th>Site</th>
      <th>Technicien</th><th>Priorité</th><th>Statut</th><th>Actions</th>
    </tr></thead>
    <tbody>
      <?php if (!$interventions) echo empty_row(8, 'Aucune intervention trouvée.'); ?>
      <?php foreach ($interventions as $it): ?>
        <tr>
          <td><strong><?= e($it['numero']) ?></strong></td>
          <td><?= $it['numero_inventaire'] ? e($it['numero_inventaire']) : '<span class="cell-sub">Non inventorié</span>' ?></td>
          <td><?= e($it['categorie'] ?? '-') ?></td>
          <td><?= e($it['site_nom'] ?? '-') ?></td>
          <td><?= e($it['t_prenom'] ? $it['t_prenom'] . ' ' . $it['t_nom'] : 'Non affecté') ?></td>
          <td><?= enum_badge(PRIORITE_INTERVENTION, $it['priorite']) ?></td>
          <td><?= enum_badge(STATUT_INTERVENTION, $it['statut']) ?></td>
          <td class="cell-actions">
            <a class="icon-btn icon-btn-table" title="Voir" href="<?= e($detailUrl((int) $it['id'])) ?>"><i class="fa-solid fa-eye"></i></a>
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

if ($modal === 'create') {
    $equipOptions = [];
    foreach (db_fetch_all('SELECT id, numero_inventaire, marque, modele FROM equipements WHERE deleted_at IS NULL ORDER BY numero_inventaire') as $eq) {
        $equipOptions[$eq['id']] = $eq['numero_inventaire'] . ' - ' . trim($eq['marque'] . ' ' . $eq['modele']);
    }
    modal_open('Nouvelle intervention', 'fa-plus', $closeUrl, 'modal-lg');
    ?>
    <form method="post" action="<?= e(current_url()) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="modal-body">
        <?= error_box($formError) ?>
        <div class="form-checkbox" style="margin-bottom:16px">
          <input type="checkbox" id="int-non-inventorie" name="non_inventorie" value="1" <?= !empty($_POST['non_inventorie']) ? 'checked' : '' ?>
                 data-toggle-show="#int-equip-unknown" data-toggle-hide="#int-equip-known">
          <label for="int-non-inventorie">Équipement non inventorié ?</label>
        </div>

        <div id="int-equip-known" class="form-row single">
          <div class="form-field"><label>Équipement *</label><select name="equipement_id" data-required required><?= options($equipOptions, fv('equipement_id')) ?></select></div>
        </div>

        <div id="int-equip-unknown" style="display:none">
          <div class="form-row">
            <div class="form-field"><label>Type d'équipement</label><select name="temp_categorie_id"><?= options(categories_list(), fv('temp_categorie_id')) ?></select></div>
            <div class="form-field"><label>Marque / Modèle (si connu)</label><input type="text" name="temp_description" value="<?= e(fv('temp_description')) ?>" placeholder="Ex : HP LaserJet"></div>
          </div>
        </div>

        <div class="form-row">
          <div class="form-field"><label>Demandeur</label><input type="text" name="demandeur" value="<?= e(fv('demandeur')) ?>" placeholder="Nom de la personne qui signale le problème"></div>
          <div class="form-field"><label>Priorité *</label><select name="priorite" required><?= options($labels(PRIORITE_INTERVENTION), fv('priorite', 'normale')) ?></select></div>
        </div>
        <div class="form-row single">
          <div class="form-field"><label>Technicien assigné</label><select name="technicien_id"><option value="">- À affecter ultérieurement -</option><?= options(techniciens_list(), fv('technicien_id')) ?></select></div>
        </div>
        <div class="form-row single">
          <div class="form-field"><label>Problème signalé *</label><textarea name="probleme" required placeholder="Décrire le problème rencontré..."><?= e(fv('probleme')) ?></textarea></div>
        </div>
      </div>
      <div class="modal-footer">
        <a class="btn btn-outline" href="<?= e($closeUrl) ?>">Annuler</a>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Créer l'intervention</button>
      </div>
    </form>
    <?php
    modal_close();
}

$it = $modalId ? find_intervention($modalId) : null;

if ($it && in_array($modal, ['detail', 'update', 'assign'], true)) {
    $closed = $it['statut'] === 'cloturee';
    $currentIndex = $closed ? count($etapeKeys) : array_search($it['etape'], $etapeKeys, true);
    $timeline = db_fetch_all(HISTORIQUE_SELECT . ' WHERE h.intervention_id = ? ORDER BY h.date_evenement DESC, h.id DESC', [$it['id']]);
    $utilisateur = $it['eq_user_id'] ? $it['eu_prenom'] . ' ' . $it['eu_nom'] : '-';
    $editable = !$closed;

    modal_open(e($it['numero']), 'fa-screwdriver-wrench', $closeUrl, 'modal-lg');
    ?>
    <div class="modal-body">
      <div class="detail-grid" style="margin-bottom:20px">
        <div class="detail-item"><div class="detail-label">Équipement</div><div class="detail-value"><?= e(($it['numero_inventaire'] ?? '-') . ($it['est_temporaire'] ? ' (temporaire)' : '')) ?></div></div>
        <div class="detail-item"><div class="detail-label">Date création</div><div class="detail-value"><?= e(format_date($it['date_debut'])) ?></div></div>
        <div class="detail-item"><div class="detail-label">Demandeur</div><div class="detail-value"><?= e($it['demandeur'] ?: '-') ?></div></div>
        <div class="detail-item"><div class="detail-label">Utilisateur</div><div class="detail-value"><?= e($utilisateur) ?></div></div>
        <div class="detail-item"><div class="detail-label">Priorité</div><div class="detail-value"><?= enum_badge(PRIORITE_INTERVENTION, $it['priorite']) ?></div></div>
        <div class="detail-item"><div class="detail-label">Statut</div><div class="detail-value"><?= enum_badge(STATUT_INTERVENTION, $it['statut']) ?></div></div>
        <div class="detail-item"><div class="detail-label">Technicien</div><div class="detail-value"><?= e($it['t_prenom'] ? $it['t_prenom'] . ' ' . $it['t_nom'] : 'Non affecté') ?></div></div>
        <div class="detail-item"><div class="detail-label">Dernière mise à jour</div><div class="detail-value"><?= e(format_datetime($it['updated_at'])) ?></div></div>
        <?php if (!$closed): ?>
          <div class="detail-item"><div class="detail-label">Ouverte depuis</div><div class="detail-value"><?= e(open_duration($it['date_debut'])) ?></div></div>
        <?php endif; ?>
      </div>

      <div class="section-title"><i class="fa-solid fa-route"></i> Processus de l'intervention</div>
      <div class="stepper">
        <?php foreach (array_values(ETAPES_PROCESSUS) as $idx => $label): ?>
          <div class="stepper-step <?= $idx < $currentIndex ? 'done' : ($idx === $currentIndex ? 'current' : '') ?>">
            <div class="stepper-circle"><?= $idx < $currentIndex ? '<i class="fa-solid fa-check"></i>' : $idx + 1 ?></div>
            <div class="stepper-line"></div>
            <div class="stepper-label"><?= e($label) ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="section-title"><i class="fa-solid fa-note-sticky"></i> Problème signalé</div>
      <p style="font-size:13.5px;color:var(--text-secondary)"><?= e($it['constat']) ?></p>

      <div class="section-title"><i class="fa-solid fa-stethoscope"></i> Diagnostic, actions et résultat</div>
      <form method="post" action="<?= e(current_url()) ?>" id="int-detail-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_fields">
        <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
        <?php foreach ([
            'diagnostic' => ['Diagnostic', 'Diagnostic du problème...'],
            'actions_realisees' => ['Actions réalisées', 'Actions menées pour résoudre le problème...'],
            'tests_effectues' => ['Tests effectués', 'Tests de validation réalisés...'],
            'resultat' => ['Résultat', "Résultat final de l'intervention..."],
            'compte_rendu' => ['Compte rendu (optionnel)', 'Compte rendu détaillé...'],
        ] as $name => [$label, $placeholder]): ?>
          <div class="form-row single">
            <div class="form-field"><label><?= e($label) ?></label><textarea name="<?= $name ?>" placeholder="<?= e($placeholder) ?>"<?= $editable ? '' : ' disabled' ?>><?= e($it[$name] ?? '') ?></textarea></div>
          </div>
        <?php endforeach; ?>
      </form>

      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:24px">
        <?php if ($editable): ?>
          <button type="submit" form="int-detail-form" class="btn btn-secondary btn-sm"><i class="fa-solid fa-floppy-disk"></i> Enregistrer</button>
        <?php endif; ?>
        <a class="btn btn-secondary btn-sm" href="<?= e(current_url(['modal' => 'update'])) ?>"><i class="fa-solid fa-plus"></i> Ajouter une mise à jour</a>
        <?php if (is_admin() && !$closed): ?>
          <?php if (!$it['technicien_id']): ?>
            <a class="btn btn-secondary btn-sm" href="<?= e(current_url(['modal' => 'assign'])) ?>"><i class="fa-solid fa-user-gear"></i> Affecter un technicien</a>
          <?php else: ?>
            <a class="btn btn-secondary btn-sm" href="<?= e(current_url(['modal' => 'assign'])) ?>"><i class="fa-solid fa-people-arrows"></i> Réaffecter à un autre technicien</a>
          <?php endif; ?>
        <?php endif; ?>
        <?php if (!$closed): ?>
          <form method="post" action="<?= e(current_url()) ?>" class="inline-form" data-confirm-title="Clôturer l'intervention ?"
                data-confirm-message="L'intervention <?= e($it['numero']) ?> sera marquée comme clôturée. Elle restera consultable dans l'historique et ne pourra plus être supprimée.">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="close">
            <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-lock"></i> Clôturer l'intervention</button>
          </form>
        <?php else: ?>
          <span class="badge badge-grey"><i class="fa-solid fa-lock"></i> Intervention clôturée</span>
        <?php endif; ?>
      </div>

      <div class="section-title"><i class="fa-solid fa-clock-rotate-left"></i> Timeline des mises à jour</div>
      <div class="timeline" id="int-updates-timeline">
        <?php if (!$timeline): ?>
          <div class="list-empty">Aucune mise à jour enregistrée.</div>
        <?php endif; ?>
        <?php foreach ($timeline as $m): ?>
          <div class="timeline-item"><div class="timeline-dot"></div>
            <div class="timeline-date"><?= e(format_datetime($m['date_evenement'])) ?></div>
            <div class="timeline-content"><div class="timeline-title"><?= e($m['description']) ?></div><div class="timeline-meta"><?= e($m['auteur']) ?></div></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="modal-footer">
      <a class="btn btn-primary" href="<?= e($closeUrl) ?>">Fermer</a>
    </div>
    <?php
    modal_close();

    // Fenêtres secondaires, ouvertes par-dessus la fiche
    $backUrl = current_url(['modal' => 'detail']);

    if ($modal === 'update') {
        modal_open('Ajouter une mise à jour', 'fa-plus', $backUrl, 'modal-sm');
        ?>
        <form method="post" action="<?= e(current_url()) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_update">
          <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
          <div class="modal-body">
            <div class="form-field"><label>Description de la mise à jour *</label><textarea name="texte" placeholder="Ex : Remplacement du composant effectué, tests en cours..." required autofocus></textarea></div>
          </div>
          <div class="modal-footer">
            <a class="btn btn-outline" href="<?= e($backUrl) ?>">Annuler</a>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Ajouter</button>
          </div>
        </form>
        <?php
        modal_close();
    }

    if ($modal === 'assign' && is_admin() && !$closed) {
        $reaffectation = (bool) $it['technicien_id'];
        $candidats = techniciens_list();
        unset($candidats[$it['technicien_id']]);
        modal_open($reaffectation ? "Réaffecter l'intervention" : 'Affecter un technicien', $reaffectation ? 'fa-people-arrows' : 'fa-user-gear', $backUrl, 'modal-sm');
        ?>
        <form method="post" action="<?= e(current_url()) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="assign">
          <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
          <div class="modal-body">
            <?php if (!$candidats): ?>
              <div class="list-empty"><?= $reaffectation ? 'Aucun autre technicien disponible.' : 'Aucun technicien enregistré.' ?></div>
            <?php else: ?>
              <?php if ($reaffectation): ?>
                <p style="font-size:13.5px;color:var(--text-secondary);margin-bottom:14px">Actuellement assignée à <strong><?= e($it['t_prenom'] . ' ' . $it['t_nom']) ?></strong> depuis l'ouverture il y a <?= e(open_duration($it['date_debut'])) ?>.</p>
              <?php endif; ?>
              <div class="form-field"><label><?= $reaffectation ? 'Nouveau technicien' : 'Technicien' ?> *</label><select name="technicien_id"><?= options($candidats) ?></select></div>
              <?php if ($reaffectation): ?>
                <div class="form-field" style="margin-top:14px"><label>Motif de la réaffectation</label><textarea name="motif" placeholder="Ex : délai de traitement trop long, technicien indisponible..."></textarea></div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <div class="modal-footer">
            <a class="btn btn-outline" href="<?= e($backUrl) ?>">Annuler</a>
            <?php if ($candidats): ?>
              <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> <?= $reaffectation ? 'Réaffecter' : 'Affecter' ?></button>
            <?php endif; ?>
          </div>
        </form>
        <?php
        modal_close();
    }
}
