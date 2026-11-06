<?php
// Historique : journal des événements du parc (Affectation, Intervention, Utilisateur).

$search = trim((string) ($_GET['search'] ?? ''));
$type = isset(HIST_CATEGORIES[$_GET['type'] ?? '']) ? $_GET['type'] : '';

$types = hist_types($type ?: null);
$where = ' WHERE h.type IN (' . sql_in($types) . ')';
$params = $types;
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $where .= " AND (h.description LIKE ? OR CONCAT(u.prenom, ' ', u.nom) LIKE ?)";
    array_push($params, $like, $like);
}

$total = (int) db_value('SELECT COUNT(*) FROM (' . HISTORIQUE_SELECT . $where . ') t', $params);
$pg = paginate($total);
$events = db_fetch_all(HISTORIQUE_SELECT . $where . " ORDER BY h.date_evenement DESC, h.id DESC LIMIT {$pg['limit']} OFFSET {$pg['offset']}", $params);
?>
<div class="page-header">
  <div><h1><i class="fa-solid fa-clock-rotate-left"></i> Historique</h1><p class="page-subtitle">Journal des événements du parc informatique</p></div>
</div>

<form class="toolbar" method="get" data-autosubmit>
  <input type="hidden" name="page" value="historique">
  <div class="input-icon toolbar-search"><i class="fa-solid fa-magnifying-glass"></i><input type="text" name="search" value="<?= e($search) ?>" placeholder="Rechercher un événement..."<?= $search !== '' ? ' data-refocus' : '' ?>></div>
  <select name="type" class="select-input">
    <option value="">Tous types</option>
    <?= options(array_combine(array_keys(HIST_CATEGORIES), array_keys(HIST_CATEGORIES)), $type) ?>
  </select>
</form>

<div id="history-timeline" class="timeline">
  <?php if (!$events): ?>
    <div class="empty-state"><i class="fa-solid fa-clock-rotate-left"></i><p>Aucun événement trouvé.</p></div>
  <?php endif; ?>
  <?php foreach ($events as $h): $cat = hist_category($h['type']); ?>
    <div class="timeline-item">
      <div class="timeline-dot"></div>
      <div class="timeline-date"><?= e(format_date($h['date_evenement'])) ?></div>
      <div class="timeline-content">
        <div class="timeline-title"><i class="fa-solid <?= e(HIST_CATEGORIES[$cat]['icon']) ?>" style="color:var(--primary);margin-right:6px"></i><?= e($cat) ?><?= $h['numero_inventaire'] ? ' · ' . e($h['numero_inventaire']) : '' ?></div>
        <div class="timeline-meta"><?= e($h['description']) ?> - <?= e($h['auteur']) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?= pagination_links($pg) ?>
