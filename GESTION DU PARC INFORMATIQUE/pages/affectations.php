<?php
// Affectations : historique complet des affectations d'équipements (actives et terminées).

$search = trim((string) ($_GET['search'] ?? ''));
$statut = in_array($_GET['statut'] ?? '', ['actif', 'inactif'], true) ? $_GET['statut'] : '';

$select = "SELECT a.*, e.numero_inventaire, e.deleted_at, u.prenom, u.nom, sv.nom AS service_nom, si.nom AS site_nom,
                  CONCAT(p.prenom, ' ', p.nom) AS effectue_par_nom
             FROM affectations a
             JOIN equipements e       ON e.id = a.equipement_id
             LEFT JOIN utilisateurs u ON u.id = a.utilisateur_id
             LEFT JOIN services sv    ON sv.id = a.service_id
             LEFT JOIN sites si       ON si.id = a.site_id
             LEFT JOIN utilisateurs p ON p.id = a.effectue_par
            WHERE 1 = 1";
$params = [];
if ($statut === 'actif')   { $select .= ' AND a.date_fin IS NULL'; }
if ($statut === 'inactif') { $select .= ' AND a.date_fin IS NOT NULL'; }
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $select .= " AND CONCAT_WS(' ', e.numero_inventaire, u.nom, u.prenom, sv.nom) LIKE ?";
    $params[] = $like;
}

$pg = paginate((int) db_value("SELECT COUNT(*) FROM ($select) t", $params));
$rows = db_fetch_all($select . " ORDER BY a.date_debut DESC, a.id DESC LIMIT {$pg['limit']} OFFSET {$pg['offset']}", $params);
?>
<div class="page-header">
  <div><h1><i class="fa-solid fa-link"></i> Affectations</h1><p class="page-subtitle">Suivi des affectations d'équipements</p></div>
</div>

<form class="toolbar" method="get" data-autosubmit>
  <input type="hidden" name="page" value="affectations">
  <div class="input-icon toolbar-search"><i class="fa-solid fa-magnifying-glass"></i><input type="text" name="search" value="<?= e($search) ?>" placeholder="Rechercher (équipement, utilisateur...)"<?= $search !== '' ? ' data-refocus' : '' ?>></div>
  <select name="statut" class="select-input">
    <option value="">Toutes affectations</option>
    <?= options(['actif' => 'Actives', 'inactif' => 'Terminées'], $statut) ?>
  </select>
</form>

<div class="table-wrapper">
  <table class="data-table" id="table-affectations">
    <thead><tr><th>Équipement</th><th>Utilisateur</th><th>Service</th><th>Site</th><th>Début</th><th>Fin</th><th>Statut</th><th>Effectué par</th></tr></thead>
    <tbody>
      <?php if (!$rows) echo empty_row(8, 'Aucune affectation trouvée.'); ?>
      <?php foreach ($rows as $a): ?>
        <tr>
          <td><?php if ($a['deleted_at'] === null): ?><a class="link-strong" href="<?= e(equipement_link((int) $a['equipement_id'])) ?>"><?= e($a['numero_inventaire']) ?></a><?php else: ?><?= e($a['numero_inventaire']) ?><?php endif; ?></td>
          <td><?= e($a['utilisateur_id'] ? full_name($a) : '-') ?></td>
          <td><?= e($a['service_nom'] ?? '-') ?></td>
          <td><?= e($a['site_nom'] ?? '-') ?></td>
          <td><?= e(format_date($a['date_debut'])) ?></td>
          <td><?= e(format_date($a['date_fin'])) ?></td>
          <td><?= status_badge($a['date_fin'] === null ? 'Active' : 'Terminée') ?></td>
          <td><?= e($a['effectue_par_nom'] ?? 'Système') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?= pagination_links($pg) ?>
