<?php
// Tableau de bord : 4 indicateurs, répartitions par catégorie et par site (Administrateur
// uniquement), évolution mensuelle des interventions et interventions récentes.

$stats = [
    ['Total équipements', db_value('SELECT COUNT(*) FROM equipements WHERE deleted_at IS NULL'), 'fa-desktop', 'info'],
    ['Services', db_value('SELECT COUNT(*) FROM services'), 'fa-building', 'neutral'],
    ['Interventions en cours', db_value("SELECT COUNT(*) FROM interventions WHERE statut = 'en_cours'"), 'fa-spinner', 'warning'],
    ['Interventions clôturées', db_value("SELECT COUNT(*) FROM interventions WHERE statut = 'cloturee'"), 'fa-circle-check', 'success'],
];

function bar_chart(array $data, string $color = 'var(--primary)'): string
{
    if (!$data) {
        return '<div class="list-empty">Aucune donnée disponible.</div>';
    }
    arsort($data);
    $max = max(max($data), 1);
    $html = '';
    foreach ($data as $label => $value) {
        $html .= '<div class="bar-chart-row"><div class="bar-chart-label" title="' . e($label) . '">' . e($label) . '</div>'
            . '<div class="bar-track"><div class="bar-fill" style="width:' . round($value / $max * 100) . '%;background:' . $color . '"></div></div>'
            . '<div class="bar-chart-value">' . (int) $value . '</div></div>';
    }
    return $html;
}

// Graphique en courbe (SVG) : une valeur par libellé, dans l'ordre fourni
function line_chart(array $labels, array $values, string $ariaLabel): string
{
    if (!$labels) {
        return '<div class="list-empty">Aucune donnée disponible.</div>';
    }
    $W = 640; $H = 240; $padL = 34; $padR = 20; $padT = 24; $padB = 34;
    $innerW = $W - $padL - $padR; $innerH = $H - $padT - $padB;
    $step = max(1, (int) ceil(max(max($values), 1) / 4));
    $top = $step * 4;
    $n = count($labels);
    $x = function ($i) use ($n, $padL, $innerW) { return $n === 1 ? $padL + $innerW / 2 : $padL + ($innerW * $i) / ($n - 1); };
    $y = function ($v) use ($padT, $innerH, $top) { return $padT + $innerH - ($v / $top) * $innerH; };

    $svg = '';
    for ($k = 0; $k <= 4; $k++) {
        $v = $step * $k;
        $svg .= sprintf('<line class="lc-grid" x1="%d" x2="%d" y1="%.1f" y2="%.1f"></line><text class="lc-axis-label" x="%d" y="%.1f" text-anchor="end">%d</text>',
            $padL, $W - $padR, $y($v), $y($v), $padL - 8, $y($v) + 4, $v);
    }
    $points = [];
    foreach ($values as $i => $v) {
        $points[] = sprintf('%.1f,%.1f', $x($i), $y($v));
    }
    if ($n > 1) {
        $svg .= sprintf('<polygon class="lc-area" points="%.1f,%.1f %s %.1f,%.1f"></polygon>', $x(0), $y(0), implode(' ', $points), $x($n - 1), $y(0));
        $svg .= '<polyline class="lc-line" points="' . implode(' ', $points) . '"></polyline>';
    }
    foreach ($values as $i => $v) {
        $svg .= sprintf('<circle class="lc-dot" cx="%.1f" cy="%.1f" r="4.5"><title>%s : %d</title></circle><text class="lc-value" x="%.1f" y="%.1f">%d</text>',
            $x($i), $y($v), e($labels[$i]), $v, $x($i), $y($v) - 11, $v);
    }
    foreach ($labels as $i => $label) {
        $svg .= sprintf('<text class="lc-axis-label" x="%.1f" y="%d" text-anchor="middle">%s</text>', $x($i), $H - 10, e($label));
    }
    return '<svg class="line-chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($ariaLabel) . '">' . $svg . '</svg>';
}

// Répartitions (Administrateur uniquement)
if (is_admin()) {
    $parCategorie = db_query(
        'SELECT c.libelle, COUNT(e.id) FROM categories_equipement c
           LEFT JOIN equipements e ON e.categorie_id = c.id AND e.deleted_at IS NULL
          GROUP BY c.id, c.libelle'
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $parSite = db_query(
        'SELECT s.nom, COUNT(e.id) FROM sites s
           LEFT JOIN affectations a ON a.site_id = s.id AND a.date_fin IS NULL
           LEFT JOIN equipements e  ON e.id = a.equipement_id AND e.deleted_at IS NULL
          GROUP BY s.id, s.nom'
    )->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Évolution : nombre d'interventions par mois de création (mois observés dans les données)
$mois = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];
$evolution = db_query("SELECT DATE_FORMAT(date_debut, '%Y-%m') AS m, COUNT(*) FROM interventions GROUP BY m ORDER BY m")->fetchAll(PDO::FETCH_KEY_PAIR);
$evoLabels = [];
foreach (array_keys($evolution) as $key) {
    [$yy, $mm] = explode('-', $key);
    $evoLabels[] = $mois[(int) $mm - 1] . ' ' . $yy;
}

// Interventions récentes : les 6 dernières créées, plus toutes les critiques non clôturées
$recentes = db_fetch_all(
    INTERVENTION_SELECT . "
     WHERE i.id IN (SELECT id FROM (SELECT id FROM interventions ORDER BY date_debut DESC, id DESC LIMIT 6) r)
        OR (i.priorite = 'critique' AND i.statut <> 'cloturee')
     ORDER BY i.date_debut DESC, i.id DESC"
);
$canOpenInterventions = can_access('interventions');
?>
<div class="page-header">
  <div>
    <h1><i class="fa-solid fa-gauge-high"></i> Tableau de bord</h1>
  </div>
</div>

<div id="dashboard-stats" class="stats-grid">
  <?php foreach ($stats as [$label, $value, $icon, $var]): ?>
    <div class="stat-card">
      <div class="stat-icon" style="color:var(--<?= $var ?>);background:var(--<?= $var ?>-bg)"><i class="fa-solid <?= $icon ?>"></i></div>
      <div><div class="stat-value"><?= (int) $value ?></div><div class="stat-label"><?= e($label) ?></div></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="dashboard-grid">
  <?php if (is_admin()): ?>
    <div class="card" id="card-categorie"><div class="card-header"><h3>Répartition par catégorie</h3></div><div class="chart-body"><?= bar_chart($parCategorie) ?></div></div>
    <div class="card" id="card-site"><div class="card-header"><h3>Répartition par site</h3></div><div class="chart-body"><?= bar_chart($parSite) ?></div></div>
  <?php endif; ?>
  <div class="card wide"><div class="card-header"><h3>Évolution des interventions</h3></div><div class="chart-body"><?= line_chart($evoLabels, array_map('intval', array_values($evolution)), "Évolution du nombre d'interventions par mois") ?></div></div>
  <div class="card wide"><div class="card-header"><h3>Interventions récentes</h3></div>
    <div class="list-body">
      <?php if (!$recentes): ?>
        <div class="list-empty">Aucune intervention récente.</div>
      <?php endif; ?>
      <?php foreach ($recentes as $it): $critique = $it['priorite'] === 'critique' && $it['statut'] !== 'cloturee'; ?>
        <div class="list-item"<?= $canOpenInterventions ? ' data-href="' . e(intervention_link((int) $it['id'])) . '"' : '' ?>>
          <?php if ($critique): ?>
            <div class="list-item-icon" style="background:var(--danger-bg);color:var(--danger)"><i class="fa-solid fa-triangle-exclamation"></i></div>
          <?php else: ?>
            <div class="list-item-icon" style="background:var(--primary-light);color:var(--primary)"><i class="fa-solid fa-screwdriver-wrench"></i></div>
          <?php endif; ?>
          <div>
            <div class="list-item-title"><?= e($it['numero']) ?> - <?= e($it['numero_inventaire'] ?? 'Équipement inconnu') ?></div>
            <div class="list-item-sub"><?= e($it['constat']) ?> · <?= e($it['t_prenom'] ? $it['t_prenom'] . ' ' . $it['t_nom'] : 'Non affecté') ?></div>
          </div>
          <div class="list-item-right">
            <?php if ($critique): ?><span class="badge badge-red" style="margin-right:6px"><span class="badge-dot"></span>Critique</span><?php endif; ?>
            <?= enum_badge(STATUT_INTERVENTION, $it['statut']) ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
