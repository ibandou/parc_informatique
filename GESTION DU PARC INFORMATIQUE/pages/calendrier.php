<?php
// Calendrier des interventions (vues mois / semaine / jour), selon leur date de création.

$calView = in_array($_GET['cal'] ?? '', ['month', 'week', 'day'], true) ? $_GET['cal'] : 'month';
$date = isset($_GET['date']) && strtotime($_GET['date']) ? new DateTime($_GET['date']) : new DateTime('today');
$date->setTime(0, 0);

$moisNoms = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
$canOpen = can_access('interventions');

// Début de semaine (lundi)
function week_start(DateTime $d): DateTime
{
    $start = clone $d;
    $start->modify('-' . ((int) $d->format('N') - 1) . ' days');
    return $start;
}

// Période affichée et dates précédente / suivante
if ($calView === 'month') {
    $from = new DateTime($date->format('Y-m-01'));
    $to = (clone $from)->modify('last day of this month');
    $label = $moisNoms[(int) $date->format('n') - 1] . ' ' . $date->format('Y');
    $prev = (clone $from)->modify('-1 month');
    $next = (clone $from)->modify('+1 month');
} elseif ($calView === 'week') {
    $from = week_start($date);
    $to = (clone $from)->modify('+6 days');
    $label = $from->format('d/m/Y') . ' - ' . $to->format('d/m/Y');
    $prev = (clone $date)->modify('-7 days');
    $next = (clone $date)->modify('+7 days');
} else {
    $from = clone $date;
    $to = clone $date;
    $label = $date->format('d/m/Y');
    $prev = (clone $date)->modify('-1 day');
    $next = (clone $date)->modify('+1 day');
}

$events = [];
foreach (db_fetch_all(
    INTERVENTION_SELECT . ' WHERE DATE(i.date_debut) BETWEEN ? AND ? ORDER BY i.date_debut',
    [$from->format('Y-m-d'), $to->format('Y-m-d')]
) as $ev) {
    $events[date('Y-m-d', strtotime($ev['date_debut']))][] = $ev;
}

$evLink = function (array $ev) use ($canOpen) {
    return $canOpen ? ' data-href="' . e(intervention_link((int) $ev['id'])) . '"' : '';
};

$eventRow = function (array $ev) use ($evLink) {
    return '<div style="display:flex;align-items:center;gap:12px;padding:6px 0;cursor:pointer"' . $evLink($ev) . '>'
        . '<span class="badge badge-blue">' . e($ev['numero']) . '</span>'
        . '<span style="font-size:13px">' . e($ev['numero_inventaire'] ?? '?') . ' - ' . e($ev['constat']) . '</span>'
        . '<span style="margin-left:auto">' . e($ev['t_prenom'] ? $ev['t_prenom'] . ' ' . $ev['t_nom'] : 'Non affecté') . '</span>'
        . enum_badge(STATUT_INTERVENTION, $ev['statut']) . '</div>';
};

$calUrl = function (array $params) use ($calView, $date) {
    return page_url('calendrier', $params + ['cal' => $calView, 'date' => $date->format('Y-m-d')]);
};
?>
<div class="page-header">
  <div><h1><i class="fa-solid fa-calendar-days"></i> Calendrier</h1><p class="page-subtitle">Planning des interventions</p></div>
  <div class="view-switch" id="calendar-view-switch">
    <?php foreach (['month' => 'Mois', 'week' => 'Semaine', 'day' => 'Jour'] as $v => $l): ?>
      <a class="view-btn<?= $v === $calView ? ' active' : '' ?>" href="<?= e($calUrl(['cal' => $v])) ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="calendar-nav">
  <a id="cal-prev" class="icon-btn" href="<?= e($calUrl(['date' => $prev->format('Y-m-d')])) ?>"><i class="fa-solid fa-chevron-left"></i></a>
  <h3 id="cal-label"><?= e($label) ?></h3>
  <a id="cal-next" class="icon-btn" href="<?= e($calUrl(['date' => $next->format('Y-m-d')])) ?>"><i class="fa-solid fa-chevron-right"></i></a>
</div>

<div id="calendar-body" class="calendar-body">
  <?php if ($calView === 'month'): ?>
    <?php
    $startDay = (int) $from->format('N') - 1;
    $daysInMonth = (int) $from->format('t');
    $totalCells = (int) ceil(($startDay + $daysInMonth) / 7) * 7;
    $today = date('Y-m-d');
    ?>
    <div class="cal-month-grid">
      <?php foreach (['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'] as $j): ?><div class="cal-weekday"><?= $j ?></div><?php endforeach; ?>
      <?php for ($i = 0; $i < $totalCells; $i++):
          $dayNum = $i - $startDay + 1;
          $inMonth = $dayNum >= 1 && $dayNum <= $daysInMonth;
          $key = $from->format('Y-m-') . str_pad((string) $dayNum, 2, '0', STR_PAD_LEFT);
          $dayEvents = $inMonth ? ($events[$key] ?? []) : []; ?>
        <div class="cal-day-cell<?= $inMonth ? '' : ' other-month' ?><?= $inMonth && $key === $today ? ' today' : '' ?>">
          <div class="cal-day-number"><?= $inMonth ? $dayNum : '' ?></div>
          <?php foreach (array_slice($dayEvents, 0, 3) as $ev): ?>
            <div class="cal-event<?= $ev['priorite'] === 'critique' ? ' critique' : '' ?>" title="<?= e($ev['constat']) ?>"<?= $evLink($ev) ?>><?= e(substr(strrchr($ev['numero'], '-'), 1)) ?> · <?= e($ev['numero_inventaire'] ?? '?') ?></div>
          <?php endforeach; ?>
          <?php if (count($dayEvents) > 3): ?><div class="cell-sub">+<?= count($dayEvents) - 3 ?> autres</div><?php endif; ?>
        </div>
      <?php endfor; ?>
    </div>
  <?php elseif ($calView === 'week'): ?>
    <div class="cal-week-grid">
      <?php for ($i = 0; $i < 7; $i++): $d = (clone $from)->modify("+$i days"); $dayEvents = $events[$d->format('Y-m-d')] ?? []; ?>
        <div class="cal-list-row">
          <div class="cal-list-date"><?= $d->format('d/m/Y') ?></div>
          <div style="flex:1">
            <?php if (!$dayEvents): ?><span class="cell-sub">Aucune intervention planifiée</span><?php endif; ?>
            <?php foreach ($dayEvents as $ev) echo $eventRow($ev); ?>
          </div>
        </div>
      <?php endfor; ?>
    </div>
  <?php else: ?>
    <?php $dayEvents = $events[$date->format('Y-m-d')] ?? []; ?>
    <?php if (!$dayEvents): ?>
      <div class="empty-state"><i class="fa-solid fa-calendar-day"></i><p>Aucune intervention planifiée ce jour-là.</p></div>
    <?php endif; ?>
    <?php foreach ($dayEvents as $ev): ?><div class="cal-list-row"><?= $eventRow($ev) ?></div><?php endforeach; ?>
  <?php endif; ?>
</div>
