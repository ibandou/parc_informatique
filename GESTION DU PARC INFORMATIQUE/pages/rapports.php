<?php
// Rapports d'activité.
// - Administrateur : génère les rapports (impression du navigateur = PDF).
// - Technicien     : génère les rapports ; chacun est enregistré (contenu figé) et il peut
//                    lire le commentaire laissé par le CEO.
// - CEO            : ne génère pas de rapport ; il consulte ceux des techniciens et les commente.

const REPORTS_DEF = [
    'parc'          => ['Rapport du parc informatique', 'Liste complète des équipements avec leurs caractéristiques et états.', 'fa-desktop'],
    'interventions' => ['Rapport des interventions', 'Synthèse des interventions techniques réalisées et en cours.', 'fa-screwdriver-wrench'],
    'pannes'        => ['Rapport des équipements en panne', 'Liste des équipements actuellement en panne ou en réparation.', 'fa-triangle-exclamation'],
    'techniciens'   => ['Rapport des techniciens', "Charge de travail et performance de l'équipe technique.", 'fa-user-gear'],
];

$me = current_user();
$closeUrl = page_url('rapports');

function report_number(int $id): string
{
    return 'RAP-' . str_pad((string) $id, 3, '0', STR_PAD_LEFT);
}

// Colonnes et lignes d'un rapport, à partir des données actuelles
function build_report_data(string $type): array
{
    if ($type === 'parc' || $type === 'pannes') {
        $where = $type === 'pannes' ? " AND e.etat IN ('en_panne', 'en_reparation')" : '';
        $rows = [];
        foreach (db_fetch_all(EQUIPEMENT_SELECT . $where . ' ORDER BY e.numero_inventaire') as $e) {
            $etat = enum_label(ETAT_EQUIPEMENT, $e['etat']);
            $rows[] = $type === 'parc'
                ? [$e['numero_inventaire'], $e['type'], $e['marque'] ?? '-', $e['modele'] ?? '-', $e['site_nom'] ?? '-', $etat]
                : [$e['numero_inventaire'], $e['type'], $e['marque'] ?? '-', $e['site_nom'] ?? '-', $etat];
        }
        return [
            'headers' => $type === 'parc' ? ['N° inventaire', 'Type', 'Marque', 'Modèle', 'Site', 'État'] : ['N° inventaire', 'Type', 'Marque', 'Site', 'État'],
            'rows'    => $rows,
        ];
    }
    if ($type === 'interventions') {
        $rows = [];
        foreach (db_fetch_all(INTERVENTION_SELECT . ' ORDER BY i.date_debut DESC') as $it) {
            $rows[] = [$it['numero'], format_date($it['date_debut']), $it['numero_inventaire'] ?? '-', $it['constat'],
                $it['t_prenom'] ? $it['t_prenom'] . ' ' . $it['t_nom'] : 'Non affecté', enum_label(STATUT_INTERVENTION, $it['statut'])];
        }
        return ['headers' => ['N°', 'Date', 'Équipement', 'Problème', 'Technicien', 'Statut'], 'rows' => $rows];
    }
    $rows = [];
    foreach (db_fetch_all(
        "SELECT u.prenom, u.nom, t.specialite,
                SUM(i.statut NOT IN ('cloturee', 'resolue')) AS en_cours, SUM(i.statut IN ('cloturee', 'resolue')) AS terminees
           FROM techniciens t JOIN utilisateurs u ON u.id = t.utilisateur_id AND u.role = 'technicien'
           LEFT JOIN interventions i ON i.technicien_id = t.id
          GROUP BY t.id ORDER BY u.prenom, u.nom"
    ) as $t) {
        $rows[] = [$t['prenom'] . ' ' . $t['nom'], $t['specialite'] ?: '-', (int) $t['en_cours'], (int) $t['terminees']];
    }
    return ['headers' => ['Nom', 'Spécialité', 'Interventions en cours', 'Interventions terminées'], 'rows' => $rows];
}

function report_table(array $data): string
{
    $html = '<table><thead><tr>';
    foreach ($data['headers'] as $h) {
        $html .= '<th>' . e($h) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($data['rows'] as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) {
            $html .= '<td>' . e($cell) . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table>';
}

// Badge d'état du commentaire du CEO
function comment_badge(array $r, bool $forTechnicien): string
{
    if (!$r['commentaire']) {
        return badge('Pas encore commenté', 'grey');
    }
    if ($forTechnicien && !$r['commentaire_lu']) {
        return badge('Nouveau commentaire', 'blue');
    }
    return badge('Commenté', 'green');
}

/* ------------------------------ TRAITEMENTS ------------------------------ */

if (is_post()) {
    $action = input('action');

    // Le CEO ne génère jamais de rapport (contrôle réel, pas seulement visuel)
    if ($action === 'generate' && !is_ceo() && isset(REPORTS_DEF[input('type')])) {
        $type = input('type');
        $params = ['print' => $type];
        if (is_technicien()) {
            // Le rapport d'un technicien est conservé (contenu figé) pour être commenté par le CEO
            db_query('INSERT INTO rapports (titre, type, contenu, user_id) VALUES (?, ?, ?, ?)',
                [REPORTS_DEF[$type][0], $type, json_encode(build_report_data($type), JSON_UNESCAPED_UNICODE), $me['id']]);
            $params['rapport'] = db()->lastInsertId();
        }
        redirect(page_url('rapports', $params));
    }

    if ($action === 'comment' && is_ceo()) {
        $r = db_fetch("SELECT r.* FROM rapports r JOIN utilisateurs u ON u.id = r.user_id AND u.role = 'technicien' WHERE r.id = ?", [int_param('id', 'post') ?? 0]);
        $texte = sanitize_rich_text((string) ($_POST['commentaire'] ?? ''));
        if ($r && trim(strip_tags($texte)) !== '') {
            $modifie = (bool) $r['commentaire'];
            db_query('UPDATE rapports SET commentaire = ?, commentaire_auteur_id = ?, commentaire_date = NOW(), commentaire_lu = 0 WHERE id = ?',
                [$texte, $me['id'], $r['id']]);
            notify((int) $r['user_id'], 'commentaire',
                'Le CEO a ' . ($modifie ? 'modifié son commentaire sur' : 'commenté') . ' votre rapport « ' . $r['titre'] . ' » (' . report_number((int) $r['id']) . ').');
            flash('success', 'Commentaire envoyé au technicien.');
        } else {
            flash('error', 'Veuillez saisir un commentaire.');
        }
        redirect($closeUrl);
    }
    redirect($closeUrl);
}

/* ------------------------------ IMPRESSION ------------------------------ */

$printType = $_GET['print'] ?? '';
if (isset(REPORTS_DEF[$printType]) && !is_ceo()) {
    $data = null;
    if (is_technicien() && ($rid = int_param('rapport'))) {
        $stored = db_fetch('SELECT contenu FROM rapports WHERE id = ? AND user_id = ?', [$rid, $me['id']]);
        $data = $stored ? json_decode($stored['contenu'], true) : null;
    }
    $data = $data ?? build_report_data($printType);
    $printHtml = '<div id="print-area" style="display:none" data-autoprint>'
        . '<div style="display:flex;align-items:center;gap:14px;margin-bottom:6px">'
        . '<img src="assets/images/logo-it-corp.png" style="width:48px;height:48px">'
        . '<div><h1>' . e(REPORTS_DEF[$printType][0]) . '</h1><p style="color:#666;font-size:12px">IT CORP · Généré le ' . date('d/m/Y') . '</p></div></div>'
        . report_table($data) . '</div>';
}

/* ------------------------------ AFFICHAGE ------------------------------ */
?>
<div class="page-header">
  <div><h1><i class="fa-solid fa-file-pdf"></i> Rapports</h1>
    <p class="page-subtitle" id="reports-subtitle"><?= is_ceo() ? 'Consultation des rapports produits par les techniciens et commentaires' : "Génération de rapports d'activité" ?></p>
  </div>
</div>

<?php if (!is_ceo()): ?>
  <div id="reports-grid" class="reports-grid">
    <?php foreach (REPORTS_DEF as $type => [$title, $desc, $icon]): ?>
      <div class="report-card">
        <div class="report-icon"><i class="fa-solid <?= $icon ?>"></i></div>
        <div class="report-title"><?= e($title) ?></div>
        <div class="report-desc"><?= e($desc) ?></div>
        <form method="post" action="<?= e($closeUrl) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="generate">
          <input type="hidden" name="type" value="<?= $type ?>">
          <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-file-pdf"></i> Générer le rapport PDF</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div id="reports-history">
<?php if (is_technicien()): ?>
  <?php $mine = db_fetch_all('SELECT * FROM rapports WHERE user_id = ? ORDER BY created_at DESC, id DESC', [$me['id']]); ?>
  <h3 class="reports-history-title"><i class="fa-solid fa-folder-open"></i> Mes rapports</h3>
  <div class="table-wrapper"><table class="data-table">
    <thead><tr><th>N°</th><th>Rapport</th><th>Date</th><th>Commentaire du CEO</th><th>Actions</th></tr></thead>
    <tbody>
      <?php if (!$mine) echo empty_row(5, "Vous n'avez encore produit aucun rapport. Générez-en un ci-dessus."); ?>
      <?php foreach ($mine as $r): ?>
        <tr>
          <td><strong><?= report_number((int) $r['id']) ?></strong></td>
          <td><?= e($r['titre']) ?></td>
          <td><?= e(format_datetime($r['created_at'])) ?></td>
          <td><?= comment_badge($r, true) ?></td>
          <td class="cell-actions"><a class="btn btn-secondary btn-sm" href="<?= e(page_url('rapports', ['modal' => 'comment', 'id' => $r['id']])) ?>"><i class="fa-solid fa-comment-dots"></i> Voir le commentaire du CEO</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
<?php elseif (is_ceo()): ?>
  <?php $list = db_fetch_all("SELECT r.*, CONCAT(u.prenom, ' ', u.nom) AS auteur FROM rapports r JOIN utilisateurs u ON u.id = r.user_id AND u.role = 'technicien' ORDER BY r.created_at DESC, r.id DESC"); ?>
  <div class="table-wrapper"><table class="data-table">
    <thead><tr><th>N°</th><th>Rapport</th><th>Technicien</th><th>Date</th><th>Commentaire</th><th>Actions</th></tr></thead>
    <tbody>
      <?php if (!$list) echo empty_row(6, "Aucun rapport n'a encore été produit par un technicien."); ?>
      <?php foreach ($list as $r): ?>
        <tr>
          <td><strong><?= report_number((int) $r['id']) ?></strong></td>
          <td><?= e($r['titre']) ?></td>
          <td><?= e($r['auteur']) ?></td>
          <td><?= e(format_datetime($r['created_at'])) ?></td>
          <td><?= comment_badge($r, false) ?></td>
          <td class="cell-actions">
            <a class="btn btn-outline btn-sm" href="<?= e(page_url('rapports', ['modal' => 'view', 'id' => $r['id']])) ?>"><i class="fa-solid fa-eye"></i> Consulter</a>
            <a class="btn btn-primary btn-sm" href="<?= e(page_url('rapports', ['modal' => 'comment_form', 'id' => $r['id']])) ?>"><i class="fa-solid fa-comment"></i> <?= $r['commentaire'] ? 'Modifier le commentaire' : 'Commenter' ?></a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>

<?php
/* ------------------------------ MODALES ------------------------------ */

$modal = $_GET['modal'] ?? '';
$modalId = int_param('id');

// CEO : consultation d'un rapport de technicien
if (is_ceo() && $modalId && in_array($modal, ['view', 'comment_form'], true)
    && ($r = db_fetch("SELECT r.*, CONCAT(u.prenom, ' ', u.nom) AS auteur FROM rapports r JOIN utilisateurs u ON u.id = r.user_id AND u.role = 'technicien' WHERE r.id = ?", [$modalId]))) {

    if ($modal === 'view') {
        $data = json_decode((string) $r['contenu'], true) ?: ['headers' => [], 'rows' => []];
        modal_open(e($r['titre']), 'fa-file-lines', $closeUrl, 'modal-lg');
        ?>
        <div class="modal-body">
          <p class="cell-sub" style="margin-bottom:12px"><?= report_number((int) $r['id']) ?> · Produit par <?= e($r['auteur']) ?> le <?= e(format_datetime($r['created_at'])) ?></p>
          <?php if ($r['commentaire']): ?>
            <div class="form-hint richtext-display" style="margin-bottom:12px"><i class="fa-solid fa-comment-dots"></i> <strong>Votre commentaire :</strong><div><?= sanitize_rich_text($r['commentaire']) ?></div></div>
          <?php endif; ?>
          <div class="table-wrapper report-view-table">
            <table class="data-table">
              <thead><tr><?php foreach ($data['headers'] as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead>
              <tbody>
                <?php if (!$data['rows']) echo empty_row(max(1, count($data['headers'])), 'Ce rapport ne contient aucune donnée.'); ?>
                <?php foreach ($data['rows'] as $row): ?>
                  <tr><?php foreach ($row as $cell): ?><td><?= e($cell) ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="modal-footer">
          <a class="btn btn-outline" href="<?= e($closeUrl) ?>">Fermer</a>
          <a class="btn btn-primary" href="<?= e(page_url('rapports', ['modal' => 'comment_form', 'id' => $r['id']])) ?>"><i class="fa-solid fa-comment"></i> <?= $r['commentaire'] ? 'Modifier le commentaire' : 'Commenter' ?></a>
        </div>
        <?php
        modal_close();
    }

    if ($modal === 'comment_form') {
        $buttons = [['bold', 'fa-bold', 'Gras'], ['italic', 'fa-italic', 'Italique'], ['underline', 'fa-underline', 'Souligné'], ['strikeThrough', 'fa-strikethrough', 'Barré'], null,
            ['insertUnorderedList', 'fa-list-ul', 'Liste à puces'], ['insertOrderedList', 'fa-list-ol', 'Liste numérotée'], null,
            ['outdent', 'fa-outdent', 'Diminuer le retrait'], ['indent', 'fa-indent', 'Augmenter le retrait']];
        modal_open('Commenter le rapport', 'fa-comment', $closeUrl);
        ?>
        <form method="post" action="<?= e($closeUrl) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="comment">
          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <input type="hidden" name="commentaire" value="">
          <div class="modal-body">
            <p class="cell-sub" style="margin-bottom:12px"><?= report_number((int) $r['id']) ?> · <?= e($r['titre']) ?> - le commentaire sera transmis à <?= e($r['auteur']) ?>.</p>
            <div class="form-row single">
              <div class="form-field"><label>Votre commentaire *</label>
                <div class="richtext" data-input="commentaire">
                  <div class="richtext-toolbar" role="toolbar" aria-label="Mise en forme">
                    <?php foreach ($buttons as $b): ?>
                      <?php if ($b === null): ?><span class="richtext-sep"></span><?php else: ?>
                        <button type="button" class="richtext-btn" data-rt-cmd="<?= $b[0] ?>" title="<?= $b[2] ?>" aria-label="<?= $b[2] ?>"><i class="fa-solid <?= $b[1] ?>"></i></button>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </div>
                  <div class="richtext-editor" contenteditable="true" data-placeholder="Saisissez votre commentaire pour le technicien..."><?= $r['commentaire'] ? sanitize_rich_text($r['commentaire']) : '' ?></div>
                </div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <a class="btn btn-outline" href="<?= e($closeUrl) ?>">Annuler</a>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> Envoyer au technicien</button>
          </div>
        </form>
        <?php
        modal_close();
    }
}

// Technicien : commentaire du CEO sur l'un de ses rapports (l'ouverture le marque comme lu)
if (is_technicien() && $modal === 'comment' && $modalId
    && ($r = db_fetch("SELECT r.*, CONCAT(c.prenom, ' ', c.nom) AS commentateur FROM rapports r LEFT JOIN utilisateurs c ON c.id = r.commentaire_auteur_id WHERE r.id = ? AND r.user_id = ?", [$modalId, $me['id']]))) {
    if ($r['commentaire'] && !$r['commentaire_lu']) {
        db_query('UPDATE rapports SET commentaire_lu = 1 WHERE id = ?', [$r['id']]);
    }
    modal_open('Commentaire du CEO', 'fa-comment-dots', $closeUrl, 'modal-sm');
    ?>
    <div class="modal-body">
      <p class="cell-sub" style="margin-bottom:12px"><?= report_number((int) $r['id']) ?> · <?= e($r['titre']) ?></p>
      <?php if ($r['commentaire']): ?>
        <div class="form-hint richtext-display" style="font-size:14px"><?= sanitize_rich_text($r['commentaire']) ?></div>
        <p class="cell-sub" style="margin-top:10px"><?= e($r['commentateur'] ?? 'CEO') ?> · <?= e(format_datetime($r['commentaire_date'])) ?></p>
      <?php else: ?>
        <div class="list-empty">Le CEO n'a pas encore commenté ce rapport.</div>
      <?php endif; ?>
    </div>
    <div class="modal-footer"><a class="btn btn-primary" href="<?= e($closeUrl) ?>">Fermer</a></div>
    <?php
    modal_close();
}
