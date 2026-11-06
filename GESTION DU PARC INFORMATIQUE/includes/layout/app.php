<?php
// Coquille de l'application : sidebar + topbar + contenu de la page ($content).
// Variables attendues : $page (clé de PAGES), $content (HTML de la page).

$user = current_user();
$unread = unread_notifications_count((int) $user['id']);
$latestNotifs = latest_notifications((int) $user['id']);
$toastIcons = ['success' => 'fa-circle-check', 'error' => 'fa-circle-exclamation', 'warning' => 'fa-triangle-exclamation', 'info' => 'fa-circle-info'];
?>
<!DOCTYPE html>
<html lang="fr" data-theme="<?= e(theme()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e(PAGES[$page]['title']) ?> - IT CORP - Gestion du parc informatique</title>
<link rel="icon" href="assets/images/logo-it-corp.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.3.1/css/all.min.css">
<link rel="stylesheet" href="css/style.css">
</head>
<body>
<div id="app-shell" class="app-shell">

  <aside id="sidebar" class="sidebar">
    <div class="sidebar-header">
      <img src="assets/images/logo-it-corp.png" alt="Logo IT CORP" class="sidebar-logo">
      <div class="sidebar-brand">
        <span class="brand-name">IT CORP</span>
        <span class="brand-sub">Gestion du parc informatique</span>
      </div>
      <button id="sidebar-collapse-btn" class="icon-btn sidebar-collapse-btn" title="Rétracter le menu">
        <i class="fa-solid fa-angles-left"></i>
      </button>
    </div>

    <nav id="sidebar-nav" class="sidebar-nav">
      <?php foreach (NAV_GROUPS as $groupLabel => $groupPages): ?>
        <?php $visible = array_filter($groupPages, 'can_access'); if (!$visible) continue; ?>
        <div class="nav-group">
          <div class="nav-section-label"><?= e($groupLabel) ?></div>
          <?php foreach ($visible as $p): ?>
            <?php $subs = NAV_SUBS[$p] ?? []; $isActive = $p === $page; ?>
            <a href="<?= e(page_url($p)) ?>" class="nav-item<?= $isActive ? ' active' : '' ?><?= $subs && $isActive ? ' expanded' : '' ?>" data-page="<?= e($p) ?>">
              <i class="fa-solid <?= e(PAGES[$p]['icon']) ?>"></i><span><?= e(PAGES[$p]['title']) ?></span>
              <?php if ($subs): ?><i class="fa-solid fa-chevron-down nav-caret"></i><?php endif; ?>
            </a>
            <?php if ($subs): ?>
              <div class="nav-sub<?= $isActive ? ' open' : '' ?>" data-parent="<?= e($p) ?>">
                <?php foreach ($subs as $view => [$subLabel, $subIcon]): ?>
                  <a href="<?= e(page_url($p, ['view' => $view])) ?>" class="nav-subitem<?= $isActive && page_view($p) === $view ? ' active' : '' ?>"><i class="fa-solid <?= e($subIcon) ?>"></i><span><?= e($subLabel) ?></span></a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
      <form method="post" action="logout.php">
        <?= csrf_field() ?>
        <button type="submit" class="nav-item logout-item"><i class="fa-solid fa-right-from-bracket"></i><span>Déconnexion</span></button>
      </form>
    </div>
  </aside>

  <div id="sidebar-overlay" class="sidebar-overlay"></div>

  <div class="app-main">
    <header class="topbar">
      <button id="mobile-menu-btn" class="icon-btn mobile-menu-btn" title="Menu">
        <i class="fa-solid fa-bars"></i>
      </button>

      <!-- La recherche globale n'est proposée que sur le Dashboard -->
      <div class="topbar-search"<?= $page === 'dashboard' ? '' : ' hidden' ?>>
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" id="global-search" placeholder="Rechercher..." autocomplete="off">
        <div id="search-results" class="search-results" hidden></div>
      </div>

      <div class="topbar-actions">
        <button id="theme-toggle-btn" class="icon-btn" title="Changer de thème">
          <i class="fa-solid <?= theme() === 'dark' ? 'fa-sun' : 'fa-moon' ?>"></i>
        </button>

        <div class="notif-wrapper">
          <button id="notif-btn" class="icon-btn" title="Notifications" data-dropdown="notif-dropdown">
            <i class="fa-solid fa-bell"></i>
            <span id="notif-badge" class="badge-count"<?= $unread ? '' : ' hidden' ?>><?= $unread > 99 ? '99+' : $unread ?></span>
          </button>
          <div id="notif-dropdown" class="dropdown-panel" hidden>
            <div class="notif-dropdown-header">Notifications</div>
            <?php if (!$latestNotifs): ?>
              <div class="notif-dropdown-empty">Aucune notification</div>
            <?php endif; ?>
            <?php foreach ($latestNotifs as $n): [$icon, $var] = notif_colors($n['type']); ?>
              <form method="post" action="<?= e(page_url('notifications')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="read">
                <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                <button type="submit" class="notif-dropdown-item<?= $n['lue'] ? '' : ' unread' ?>">
                  <i class="fa-solid <?= e($icon) ?>" style="color:var(--<?= e($var) ?>)"></i>
                  <div><div><?= e($n['message']) ?></div><div class="notif-card-date"><?= e(format_datetime($n['created_at'])) ?></div></div>
                </button>
              </form>
            <?php endforeach; ?>
            <a class="dropdown-item" style="justify-content:center;font-weight:700" href="<?= e(page_url('notifications')) ?>">Voir toutes les notifications</a>
          </div>
        </div>

        <div class="profile-wrapper">
          <button id="profile-btn" class="profile-btn" data-dropdown="profile-dropdown">
            <span class="avatar" id="topbar-avatar"><?= e(initials($user['prenom'], $user['nom'])) ?></span>
            <span class="profile-info">
              <span id="topbar-username" class="profile-name"><?= e($user['prenom'] . ' ' . $user['nom']) ?></span>
              <span id="topbar-role" class="profile-role"><?= e(role_label($user['role'])) ?></span>
            </span>
            <i class="fa-solid fa-chevron-down"></i>
          </button>
          <div id="profile-dropdown" class="dropdown-panel" hidden>
            <a href="<?= e(page_url('profil')) ?>" class="dropdown-item"><i class="fa-solid fa-id-badge"></i> Mon profil</a>
            <div class="dropdown-divider"></div>
            <form method="post" action="logout.php">
              <?= csrf_field() ?>
              <button type="submit" class="dropdown-item dropdown-item-danger"><i class="fa-solid fa-right-from-bracket"></i> Se déconnecter</button>
            </form>
          </div>
        </div>
      </div>
    </header>

    <main id="main-content" class="main-content">
      <section id="page-<?= e($page) ?>" class="page active">
        <?= $content ?>
      </section>
    </main>
  </div>

  <div class="watermark" aria-hidden="true"></div>
</div>

<div id="modal-root"><?= $GLOBALS['modals_html'] ?? '' ?></div>
<div id="toast-container" class="toast-container">
  <?php foreach (take_flashes() as $f): ?>
    <div class="toast <?= e($f['type']) ?>"><i class="fa-solid <?= e($toastIcons[$f['type']] ?? $toastIcons['info']) ?>"></i><span><?= e($f['message']) ?></span></div>
  <?php endforeach; ?>
</div>
<?= $printHtml ?? '<div id="print-area" style="display:none"></div>' ?>

<script>window.CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;</script>
<script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/../../assets/js/app.js') ?>"></script>
</body>
</html>
