<?php
// Centre de notifications : uniquement celles adressées au compte connecté.

$userId = current_user_id();

if (is_post()) {
    if (input('action') === 'read_all') {
        db_query('UPDATE notifications SET lue = 1, date_lecture = NOW() WHERE destinataire_id = ? AND lue = 0', [$userId]);
        flash('success', 'Toutes les notifications ont été marquées comme lues.');
    }
    // Clic sur une notification : marquée comme lue, puis ouverture de l'élément signalé
    if (input('action') === 'read' && ($id = int_param('id', 'post'))
        && ($n = db_fetch('SELECT * FROM notifications WHERE id = ? AND destinataire_id = ?', [$id, $userId]))) {
        db_query('UPDATE notifications SET lue = 1, date_lecture = NOW() WHERE id = ? AND lue = 0', [$id]);
        $target = notification_target($n);
        if ($target) {
            redirect($target);
        }
        if ($target === false) {
            flash('info', "L'élément lié à cette notification n'est plus disponible pour votre compte (supprimé ou confié à quelqu'un d'autre).");
        }
        redirect(page_url('notifications'));
    }
    redirect(current_url());
}

$pg = paginate((int) db_value('SELECT COUNT(*) FROM notifications WHERE destinataire_id = ?', [$userId]));
$notifs = db_fetch_all(
    "SELECT * FROM notifications WHERE destinataire_id = ? ORDER BY created_at DESC, id DESC LIMIT {$pg['limit']} OFFSET {$pg['offset']}",
    [$userId]
);
?>
<div class="page-header">
  <div><h1><i class="fa-solid fa-bell"></i> Notifications</h1><p class="page-subtitle">Centre de notifications</p></div>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="read_all">
    <button type="submit" class="btn btn-secondary" id="btn-mark-all-read"><i class="fa-solid fa-check-double"></i> Tout marquer comme lu</button>
  </form>
</div>

<div id="notifications-list" class="notif-list">
  <?php if (!$notifs): ?>
    <div class="empty-state"><i class="fa-solid fa-bell-slash"></i><p>Aucune notification pour le moment.</p></div>
  <?php endif; ?>
  <?php foreach ($notifs as $n): [$icon, $var] = notif_colors($n['type']); ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="read">
      <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
      <button type="submit" class="notif-card<?= $n['lue'] ? '' : ' unread' ?>">
        <div class="notif-card-icon" style="background:var(--<?= $var ?>-bg);color:var(--<?= $var ?>)"><i class="fa-solid <?= e($icon) ?>"></i></div>
        <div style="flex:1"><div class="notif-card-text"><?= e($n['message']) ?></div><div class="notif-card-date"><?= e(format_datetime($n['created_at'])) ?></div></div>
        <?php if (!$n['lue']): ?><span class="badge badge-blue">Nouveau</span><?php endif; ?>
      </button>
    </form>
  <?php endforeach; ?>
</div>
<?= pagination_links($pg) ?>
