<?php
// Pagination générique : ITEMS_PER_PAGE éléments par page, appliquée sur les résultats
// déjà filtrés. Les contrôles ne s'affichent qu'au-delà d'une page.

// Retourne ['page', 'pages', 'offset', 'limit', 'total'] ; une page hors limites est
// ramenée à la dernière page valide (ex. après suppression du dernier élément).
// $param : nom du paramètre d'URL portant le numéro de page (plusieurs listes par page possibles)
function paginate(int $total, string $param = 'p'): array
{
    $pages = max(1, (int) ceil($total / ITEMS_PER_PAGE));
    $page = (int) ($_GET[$param] ?? 1);
    $page = min(max(1, $page), $pages);

    return [
        'page'   => $page,
        'pages'  => $pages,
        'offset' => ($page - 1) * ITEMS_PER_PAGE,
        'limit'  => ITEMS_PER_PAGE,
        'total'  => $total,
        'param'  => $param,
    ];
}

// Contrôles de pagination ; les autres paramètres de l'URL (page, filtres) sont conservés
function pagination_links(array $p): string
{
    if ($p['total'] <= ITEMS_PER_PAGE) {
        return '';
    }

    $link = function (int $n) use ($p) {
        return current_url([$p['param'] => $n]);
    };

    $first = $p['offset'] + 1;
    $last = min($p['offset'] + $p['limit'], $p['total']);

    $html = '<div class="pagination"><div class="pagination-info">Éléments ' . $first . '-' . $last . ' sur ' . $p['total'] . '</div><div class="pagination-controls">';

    $html .= $p['page'] > 1
        ? '<a class="page-btn" href="' . e($link($p['page'] - 1)) . '" title="Précédent"><i class="fa-solid fa-chevron-left"></i></a>'
        : '<button class="page-btn" disabled><i class="fa-solid fa-chevron-left"></i></button>';

    $previous = 0;
    for ($n = 1; $n <= $p['pages']; $n++) {
        // Première, dernière et pages voisines de la page courante ; le reste en "…"
        if ($n !== 1 && $n !== $p['pages'] && abs($n - $p['page']) > 1) {
            continue;
        }
        if ($previous && $n - $previous > 1) {
            $html .= '<span class="page-ellipsis">…</span>';
        }
        $html .= $n === $p['page']
            ? '<span class="page-btn active">' . $n . '</span>'
            : '<a class="page-btn" href="' . e($link($n)) . '">' . $n . '</a>';
        $previous = $n;
    }

    $html .= $p['page'] < $p['pages']
        ? '<a class="page-btn" href="' . e($link($p['page'] + 1)) . '" title="Suivant"><i class="fa-solid fa-chevron-right"></i></a>'
        : '<button class="page-btn" disabled><i class="fa-solid fa-chevron-right"></i></button>';

    return $html . '</div></div>';
}
