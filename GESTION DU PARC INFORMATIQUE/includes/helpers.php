<?php
// Fonctions utilitaires communes (affichage, redirections, messages, libellés, badges).

// Échappement HTML : toute donnée affichée dans une page passe par e()
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function page_url(string $page, array $params = []): string
{
    $params = array_filter($params, function ($v) { return $v !== null && $v !== ''; });
    return 'index.php?' . http_build_query(['page' => $page] + $params);
}

// URL de la page courante en remplaçant / retirant certains paramètres
function current_url(array $overrides = [], array $remove = []): string
{
    $params = array_merge($_GET, $overrides);
    foreach ($remove as $key) {
        unset($params[$key]);
    }
    $params = array_filter($params, function ($v) { return $v !== null && $v !== ''; });
    return 'index.php?' . http_build_query($params);
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

// Valeur d'un champ POST, nettoyée des espaces
function input(string $name, string $default = ''): string
{
    $value = $_POST[$name] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

// Valeur à afficher dans un champ de formulaire : la saisie si le formulaire vient
// d'être refusé (validation), sinon la valeur enregistrée
function fv(string $name, $stored = ''): string
{
    if (is_post() && array_key_exists($name, $_POST) && is_string($_POST[$name])) {
        return $_POST[$name];
    }
    return (string) ($stored ?? '');
}

// Entier GET/POST (identifiant...), null si absent ou invalide
function int_param(string $name, string $source = 'get'): ?int
{
    $value = $source === 'post' ? ($_POST[$name] ?? null) : ($_GET[$name] ?? null);
    if ($value === null || $value === '' || !ctype_digit((string) $value)) {
        return null;
    }
    return (int) $value;
}

// Messages "toast" : affichés une seule fois, à la page suivante
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function initials(string $prenom, string $nom): string
{
    return mb_strtoupper(mb_substr($prenom, 0, 1) . mb_substr($nom, 0, 1));
}

function full_name(?array $row, string $prefix = ''): string
{
    if (!$row || empty($row[$prefix . 'prenom'])) {
        return '-';
    }
    return $row[$prefix . 'prenom'] . ' ' . $row[$prefix . 'nom'];
}

function format_date(?string $date): string
{
    return $date ? date('d/m/Y', strtotime($date)) : '-';
}

function format_datetime(?string $date): string
{
    return $date ? date('d/m/Y H:i', strtotime($date)) : '-';
}

// Durée écoulée depuis une date (ex. "3 j 4 h")
function open_duration(string $since): string
{
    $seconds = time() - strtotime($since);
    if ($seconds <= 0) {
        return '-';
    }
    $hours = intdiv($seconds, 3600);
    $days = intdiv($hours, 24);
    if ($days >= 1) {
        return $days . ' j ' . ($hours % 24) . ' h';
    }
    return $hours >= 1 ? $hours . ' h' : "moins d'une heure";
}

function theme(): string
{
    return ($_COOKIE['itcorp_theme'] ?? 'light') === 'dark' ? 'dark' : 'light';
}

// Options d'un <select> : $items = [valeur => libellé]
function options(array $items, $selected = null): string
{
    $html = '';
    foreach ($items as $value => $label) {
        $sel = (string) $value === (string) $selected ? ' selected' : '';
        $html .= '<option value="' . e($value) . '"' . $sel . '>' . e($label) . '</option>';
    }
    return $html;
}

/* ---------- Libellés et badges ---------- */

const ROLE_LABELS = [
    'administrateur' => 'Administrateur',
    'technicien'     => 'Technicien',
    'ceo'            => 'CEO / Responsable',
];
const ROLE_COLORS = ['administrateur' => 'blue', 'technicien' => 'orange', 'ceo' => 'green'];

const STATUT_INTERVENTION = [
    'nouvelle'   => ['Nouvelle', 'blue'],
    'en_attente' => ['En attente', 'orange'],
    'en_cours'   => ['En cours', 'blue'],
    'resolue'    => ['Résolue', 'green'],
    'cloturee'   => ['Clôturée', 'grey'],
];

const PRIORITE_INTERVENTION = [
    'faible'   => ['Faible', 'grey'],
    'normale'  => ['Normale', 'blue'],
    'haute'    => ['Haute', 'orange'],
    'critique' => ['Critique', 'red'],
];

const ETAT_EQUIPEMENT = [
    'en_service'    => ['En service', 'green'],
    'disponible'    => ['Disponible', 'blue'],
    'en_panne'      => ['En panne', 'red'],
    'en_reparation' => ['En réparation', 'orange'],
    'hors_service'  => ['Hors service', 'grey'],
];

// Étapes du processus d'une intervention, dans l'ordre du stepper
const ETAPES_PROCESSUS = [
    'creation'        => 'Création',
    'qualification'   => 'Qualification',
    'diagnostic'      => 'Diagnostic',
    'intervention'    => 'Intervention',
    'test_validation' => 'Test / Validation',
    'documentation'   => 'Documentation',
    'cloture'         => 'Clôture',
];

const SPECIALITES = ['Matériel & Réseau', 'Systèmes & Serveurs', 'Postes de travail', 'Impression & Périphériques', 'Sécurité & Antivirus', 'Support général'];

// Couleur des statuts génériques (comptes, affectations)
const STATUT_COLORS = ['Active' => 'green', 'Actif' => 'green', 'Inactif' => 'grey', 'Terminée' => 'grey'];

function role_label(string $role): string
{
    return ROLE_LABELS[$role] ?? $role;
}

function enum_label(array $map, ?string $value): string
{
    return $map[$value][0] ?? ($value ?? '-');
}

function badge(string $label, string $color): string
{
    return '<span class="badge badge-' . e($color) . '"><span class="badge-dot"></span>' . e($label) . '</span>';
}

// Badge à partir d'une valeur ENUM et de sa table de libellés (STATUT_INTERVENTION...)
function enum_badge(array $map, ?string $value): string
{
    [$label, $color] = $map[$value] ?? [$value ?? '-', 'grey'];
    return badge($label, $color);
}

function status_badge(string $statut): string
{
    return badge($statut, STATUT_COLORS[$statut] ?? 'grey');
}

function role_badge(string $role): string
{
    return badge(role_label($role), ROLE_COLORS[$role] ?? 'grey');
}

/* ---------- Modales (rendues côté serveur, ouvertes via un paramètre d'URL) ---------- */

// $title est du HTML déjà échappé par l'appelant (il peut contenir des badges)
// Les modales sont mises de côté puis affichées dans #modal-root, au niveau du <body>
// (comme dans le prototype) : rendues dans .main-content, elles passeraient sous la
// sidebar et la topbar.
function modal_open(string $title, string $icon, string $closeUrl, string $size = ''): void
{
    ob_start();
    echo '<div class="modal-overlay" data-close-url="' . e($closeUrl) . '"><div class="modal-box ' . e($size) . '">'
        . '<div class="modal-header"><h2><i class="fa-solid ' . e($icon) . '"></i> ' . $title . '</h2>'
        . '<a class="icon-btn" href="' . e($closeUrl) . '" title="Fermer"><i class="fa-solid fa-xmark"></i></a></div>';
}

function modal_close(): void
{
    echo '</div></div>';
    $GLOBALS['modals_html'] = ($GLOBALS['modals_html'] ?? '') . ob_get_clean();
}

function error_box(?string $message): string
{
    if (!$message) {
        return '';
    }
    return '<div class="form-error-box"><i class="fa-solid fa-circle-exclamation"></i><span>' . e($message) . '</span></div>';
}

function empty_row(int $colspan, string $message): string
{
    return '<tr class="table-empty-row"><td colspan="' . $colspan . '"><i class="fa-solid fa-inbox"></i><br>' . e($message) . '</td></tr>';
}

// Nettoie un commentaire mis en forme : seules les balises de mise en forme simple sont
// conservées, sans aucun attribut (neutralise tout HTML externe collé ou injecté)
function sanitize_rich_text(string $html): string
{
    $allowed = '<b><strong><i><em><u><s><strike><ul><ol><li><br><p><div>';
    $html = strip_tags($html, $allowed);
    $html = preg_replace('#<(/?)(b|strong|i|em|u|s|strike|ul|ol|li|br|p|div)\b[^>]*>#i', '<$1$2>', $html);
    return trim($html);
}
