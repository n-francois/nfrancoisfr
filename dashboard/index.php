<?php
/*
 * Tableau de bord privé des avis laissés après les conférences (base Supabase, voir api/avis.php) :
 * - vue d'ensemble : trois indicateurs (conférences, avis, moyenne), les mots qui reviennent dans les commentaires, puis une
 *   carte par événement (moyenne, répartition des notes, abonnés, citations) ;
 * - détail d'un événement (?evenement=label) : date, lieu, titre et page de l'intervention, chiffres, carrousel des
 *   citations autorisées, avis heure par heure, puis tous les avis, du plus récent au plus ancien ;
 * - export CSV (?format=csv, pour un événement ou pour tous).
 * Protégé par le mot de passe 'tableau_mot_de_passe' de nfrancois-config.php (cookie de 30 jours, 10 essais par quart
 * d'heure). La page n'est liée nulle part et exclue des moteurs de recherche.
 */

require dirname(__DIR__) . '/api/lib.php';

const COOKIE = 'nf_tableau';
const ADRESSE = '/dashboard/';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store');
header('Referrer-Policy: same-origin');

function e($texte): string
{
    return htmlspecialchars((string) $texte, ENT_QUOTES);
}

const GRAND_TITRE = '<header class="page-head"><p class="nf-label">Espace privé</p><h1 class="page-title">Tableau de bord</h1></header>';

/** La page : en-tête (grand titre par défaut, fil d'Ariane sur la page d'une conférence), puis le contenu. */
function page(string $contenu, int $statut = 200, string $entete = GRAND_TITRE): void
{
    http_response_code($statut);
    header('Content-Type: text/html; charset=utf-8');
    echo str_replace('<!--tableau-->', $entete . $contenu, (string) file_get_contents(__DIR__ . '/gabarit.html'));
    exit;
}

function message(string $titre, string $texte): string
{
    return '<div class="nf-msg nf-msg--erreur" role="alert"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8v5M12 16.5v.5"/>'
        . '<circle cx="12" cy="12" r="9"/></svg><div><p class="nf-msg__title">' . e($titre) . '</p><p class="nf-msg__text">'
        . e($texte) . '</p></div></div>';
}

function connexion(string $erreur = '', int $statut = 200): void
{
    $cible = isset($_GET['evenement']) ? '?evenement=' . rawurlencode((string) $_GET['evenement']) : '';
    page('<section class="nf-section tdb"><form class="acces" method="post" action="' . ADRESSE . e($cible) . '">'
        . ($erreur !== '' ? message('Connexion refusée', $erreur) : '')
        . '<div class="nf-field"><label class="nf-field__label" for="tdb-mot-de-passe">Mot de passe</label>'
        . '<input class="nf-input" type="password" id="tdb-mot-de-passe" name="mot_de_passe" required autocomplete="current-password"></div>'
        . '<div><button class="nf-btn nf-btn--primaire" type="submit"><span class="nf-btn__lbl">Ouvrir le tableau de bord'
        . '<svg class="nf-btn__fleche" viewBox="0 0 16 16" aria-hidden="true"><path d="M2 8h11M9 4l4 4-4 4"/></svg></span></button></div>'
        . '</form></section>', $statut);
}

function date_fr(string $iso, string $format = 'd/m/Y à H:i'): string
{
    try {
        return (new DateTime($iso))->setTimezone(new DateTimeZone('Europe/Paris'))->format($format);
    } catch (Throwable $e) {
        return $iso;
    }
}

function nombre(float $n, int $decimales = 1): string
{
    return number_format($n, $decimales, ',', "\u{202F}");
}

/** Chiffres d'un ensemble d'avis : nombre, moyenne, répartition, abonnés, citations, dernier avis. */
function bilan(array $avis): array
{
    $repartition = array_fill(1, 5, 0);
    foreach ($avis as $a) {
        $repartition[(int) $a['note']]++;
    }
    $n = count($avis);
    return [
        'n' => $n,
        'moyenne' => $n ? array_sum(array_column($avis, 'note')) / $n : 0,
        'repartition' => $repartition,
        'abonnes' => count(array_filter($avis, fn($a) => $a['newsletter'])),
        'citations' => count(array_filter($avis, fn($a) => $a['citation'] && $a['commentaire'])),
        'commentaires' => count(array_filter($avis, fn($a) => $a['commentaire'])),
        'dernier' => $n ? $avis[0]['recu_le'] : null,
    ];
}

function chiffres(array $b): string
{
    if ($b['n'] === 0) {
        return '<p class="tdb__vide">Aucun avis pour l’instant.</p>';
    }
    $max = max($b['repartition']) ?: 1;
    $barres = '';
    for ($note = 5; $note >= 1; $note--) {
        $compte = $b['repartition'][$note];
        $barres .= '<li><span class="tdb__barre-note">' . $note . '</span><span class="tdb__barre"><span style="width:'
            . round($compte / $max * 100) . '%"></span></span><span class="tdb__barre-n">' . $compte . '</span></li>';
    }
    // Avis, commentaires, citations, abonnés : une ligne chacun, le chiffre puis son libellé
    $stat = fn(int $n, string $un, string $plusieurs) => '<li><span class="tdb__stat-n">' . $n . '</span><span class="tdb__stat-l">'
        . ($n > 1 ? $plusieurs : $un) . '</span></li>';
    return '<div class="tdb__chiffres"><p class="tdb__moyenne"><span class="nf-preuve__n">' . nombre($b['moyenne'])
        . '</span><span class="tdb__sur">/5</span></p>'
        . '<ul class="tdb__repartition" aria-label="Répartition des notes">' . $barres . '</ul>'
        . '<ul class="tdb__stats">' . $stat($b['n'], 'avis', 'avis') . $stat($b['commentaires'], 'commentaire', 'commentaires')
        . $stat($b['citations'], 'citation autorisée', 'citations autorisées')
        . $stat($b['abonnes'], 'abonné à la newsletter', 'abonnés à la newsletter') . '</ul></div>';
}

function csv(array $avis, array $evenements, string $nomFichier): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
    $sortie = fopen('php://output', 'w');
    fwrite($sortie, "\xEF\xBB\xBF"); // pour qu'Excel lise les accents
    // Une cellule qui commence par = + - @ serait lue comme une formule : on la neutralise
    $sur = fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;
    fputcsv($sortie, ['Reçu le', 'Événement', 'Note', 'Commentaire', 'Citation autorisée', 'Signature', 'E-mail', 'Newsletter', 'Ghost'], ';', '"', '');
    foreach ($avis as $a) {
        fputcsv($sortie, array_map($sur, [
            date_fr($a['recu_le'], 'd/m/Y H:i'), $evenements[$a['evenement']]['nom'] ?? $a['evenement'], $a['note'],
            $a['commentaire'] ?? '', $a['citation'] ? 'oui' : 'non', $a['signature'] ?? '', $a['email'] ?? '',
            $a['newsletter'] ? 'oui' : 'non', $a['ghost'] ?? '',
        ]), ';', '"', '');
    }
    exit;
}

const JOURS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const MOIS_COURTS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
// Flèches du carrousel : celle des boutons de la charte (nf-btn__fleche), et son reflet
const FLECHE_PRECEDENTE = '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M14 8H3M7 4 3 8l4 4"/></svg>';
const FLECHE_SUIVANTE = '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M2 8h11M9 4l4 4-4 4"/></svg>';
const LIEN_EXT = '<svg class="lien-ext" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M4 12 12 4M5 4h7v7"/></svg>';

/** « 2026-10-05 » → « lundi 5 octobre 2026 » ; $court : « lun. 5 oct. ». */
function date_longue(DateTimeInterface $d, bool $court = false): string
{
    $jour = JOURS[(int) $d->format('w')];
    $mois = MOIS[(int) $d->format('n') - 1];
    return $court
        ? mb_substr($jour, 0, 3) . '. ' . $d->format('j') . ' ' . MOIS_COURTS[(int) $d->format('n') - 1]
        : $jour . ' ' . $d->format('j') . ' ' . $mois . ' ' . $d->format('Y');
}

/** Indicateurs de tête de la vue d'ensemble, en cartes : conférences avec des avis, avis cumulés, moyenne de tous les avis. */
function indicateurs(int $conferences, array $avis): string
{
    $n = count($avis);
    $moyenne = $n ? array_sum(array_column($avis, 'note')) / $n : 0;
    $pictos = [
        'micro' => '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21M8.5 21h7"/>',
        'bulle' => '<path d="M4 5h16v11h-9l-5 4v-4H4z"/>',
        'etoile' => '<path d="m12 3.6 2.6 5.3 5.8.8-4.2 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8-4.2-4.1 5.8-.8z"/>',
    ];
    // Le chiffre seul dans nf-preuve__n : l'animation des compteurs du site réécrit son contenu
    $carte = fn(string $picto, string $valeur, string $libelle, string $suite = '') => '<li class="tdb__indicateur"><span class="tdb__picto">'
        . '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $pictos[$picto] . '</svg></span><span class="tdb__valeur">'
        . '<span class="nf-preuve__n">' . $valeur . '</span>' . $suite . '</span><span class="tdb__indicateur-l">' . $libelle . '</span></li>';
    return '<ul class="tdb__indicateurs">'
        . $carte('micro', (string) $conferences, $conferences > 1 ? 'conférences avec des avis' : 'conférence avec des avis')
        . $carte('bulle', (string) $n, 'avis au total')
        . $carte('etoile', $n ? nombre($moyenne) : '–', 'note moyenne, tous avis confondus', $n ? '<span class="tdb__sur">/5</span>' : '')
        . '</ul>';
}

/**
 * Les mots qui reviennent dans les commentaires : un mot compte une fois par commentaire, les formes proches sont réunies
 * (intéressant, intéressante, intéressantes), les mots vides écartés. Rend [[mot, nombre de commentaires], …].
 */
function mots_cles(array $avis, int $max = 12): array
{
    static $vides = null;
    $vides ??= array_flip(explode(' ', 'avec sans pour dans sous chez vers entre mais donc comme aussi encore déjà même tout tous toute toutes'
        . ' très trop bien plus moins peu beaucoup assez cette ceux celle celles cela ceci quoi dont leur leurs notre nôtre votre vôtre'
        . ' elle elles nous vous ils sont était étaient être avoir avait avez avons fait faire peut peuvent sera seront serait quand'
        . ' alors après avant pendant lors chaque autre autres quel quelle quels quelles ainsi enfin juste vraiment merci merciii avis'
        . ' bcp quid cela permis mesurer apprend envie donne savoir déjà'
        . ' conférence conférences conférencier conférencière présentation présentations intervention interventions intervenant'
        . ' intervenante atelier ateliers table ronde soirée trophées événement événements sujet'));
    $compte = [];
    $formes = [];
    foreach ($avis as $a) {
        if (empty($a['commentaire'])) {
            continue;
        }
        $texte = mb_strtolower(str_replace(['’', '‘'], "'", $a['commentaire']));
        $texte = preg_replace("/(?<!\\p{L})(?:l|d|qu|j|c|n|s|m|t)'/u", ' ', $texte);
        $vus = [];
        foreach (preg_split('/[^\p{L}]+/u', $texte, -1, PREG_SPLIT_NO_EMPTY) as $mot) {
            if (isset($vides[$mot]) || (mb_strlen($mot) < 4 && $mot !== 'ia')) {
                continue;
            }
            $cle = preg_replace('/s$/u', '', $mot);
            $cle = mb_strlen($cle) > 4 ? preg_replace('/e$/u', '', $cle) : $cle;
            if (isset($vus[$cle])) {
                continue;
            }
            $vus[$cle] = true;
            $compte[$cle] = ($compte[$cle] ?? 0) + 1;
            $formes[$cle][$mot] = ($formes[$cle][$mot] ?? 0) + 1;
        }
    }
    arsort($compte);
    $resultat = [];
    foreach ($compte as $cle => $n) {
        if ($n < 2 || count($resultat) >= $max) {
            break;
        }
        $f = $formes[$cle];
        uksort($f, fn($x, $y) => [$f[$y], mb_strlen($x)] <=> [$f[$x], mb_strlen($y)]);
        $mot = (string) array_key_first($f);
        $resultat[] = [$mot === 'ia' ? 'IA' : $mot, $n];
    }
    return $resultat;
}

/** Nuage des mots qui reviennent : plus un mot revient, plus il est grand ; le plus fréquent au centre, surligné. */
function bloc_mots(array $avis, string $niveau = 'h2'): string
{
    $mots = mots_cles($avis, 16);
    if (!$mots) {
        return '';
    }
    $max = $mots[0][1];
    $min = end($mots)[1];
    // Du plus fréquent au moins fréquent, posés alternativement à droite et à gauche : les plus grands au milieu
    $ordre = [];
    foreach ($mots as $i => $m) {
        if ($i % 2) {
            array_unshift($ordre, $m);
        } else {
            $ordre[] = $m;
        }
    }
    $liste = '';
    foreach ($ordre as [$mot, $n]) {
        $poids = $max > $min ? ($n - $min) / ($max - $min) : 1;
        $texte = $n === $max ? '<span class="nf-hl">' . e($mot) . '</span>' : e($mot);
        $liste .= '<li class="tdb__nuage-mot" style="--poids: ' . round($poids, 2) . '">' . $texte
            . '<span class="sr-only"> (' . $n . ' commentaires)</span></li>';
    }
    return '<section class="tdb__bloc" aria-labelledby="tdb-mots"><' . $niveau . ' class="tdb__titre" id="tdb-mots">Les mots qui reviennent</'
        . $niveau . '><p class="tdb__aide">Dans les commentaires : plus un mot revient, plus il est grand.</p>'
        . '<ul class="tdb__nuage">' . $liste . '</ul></section>';
}

/** Carrousel des citations autorisées, chacune avec sa signature et un bouton pour la copier. */
function bloc_citations(array $avis, string $signatureDefaut): string
{
    $cites = array_values(array_filter($avis, fn($a) => $a['citation'] && $a['commentaire']));
    $n = count($cites);
    if ($n === 0) {
        return '';
    }
    $items = '';
    foreach ($cites as $i => $a) {
        $signature = $a['signature'] ?: $signatureDefaut;
        $items .= '<li class="tdb__citation" aria-label="Citation ' . ($i + 1) . ' sur ' . $n . '"><blockquote><p>' . nl2br(e($a['commentaire']))
            . '</p></blockquote><p class="tdb__citation-signature">' . e($signature) . ' · ' . (int) $a['note'] . '/5</p>'
            . '<button class="nf-btn nf-btn--secondaire nf-btn--petit" type="button" data-copier="'
            . e('« ' . preg_replace('/\s*\n\s*/u', ' ', $a['commentaire']) . ' » ' . $signature) . '">Copier la citation</button></li>';
    }
    $fleches = $n > 1 ? '<div class="tdb__fleches"><button class="tdb__fleche" type="button" data-sens="-1" aria-label="Citation précédente">' . FLECHE_PRECEDENTE . '</button>'
        . '<span class="tdb__compteur" aria-live="polite">1 / ' . $n . '</span>'
        . '<button class="tdb__fleche" type="button" data-sens="1" aria-label="Citation suivante">' . FLECHE_SUIVANTE . '</button></div>' : '';
    return '<section class="tdb__bloc tdb__carrousel" aria-labelledby="tdb-citations"><div class="tdb__bloc-tete"><h3 class="tdb__titre" id="tdb-citations">'
        . ($n > 1 ? "Les $n citations autorisées" : 'La citation autorisée') . '</h3>' . $fleches . '</div>'
        . '<ul class="tdb__citations" tabindex="0" aria-label="Citations autorisées">' . $items . '</ul></section>';
}

/** Les avis dans le temps : par heure (heure de Paris), ou par jour s'ils s'étalent sur plus de trois jours. */
function bloc_temps(array $avis): string
{
    if (count($avis) < 2) {
        return '';
    }
    $paris = new DateTimeZone('Europe/Paris');
    $dates = array_map(fn($a) => (new DateTime($a['recu_le']))->setTimezone($paris), $avis);
    $premier = min($dates);
    $dernier = max($dates);
    $parJour = $dernier->getTimestamp() - $premier->getTimestamp() > 72 * 3600;
    $format = $parJour ? 'Y-m-d' : 'Y-m-d H';
    $notes = [];
    foreach ($avis as $i => $a) {
        $notes[$dates[$i]->format($format)][] = (int) $a['note'];
    }
    $curseur = DateTime::createFromFormat($parJour ? '!Y-m-d' : '!Y-m-d H', $premier->format($format), $paris);
    $fin = $dernier->format($format);
    $colonnes = [];
    while (true) {
        $cle = $curseur->format($format);
        $colonnes[] = [clone $curseur, $notes[$cle] ?? []];
        if ($cle === $fin || count($colonnes) > 400) {
            break;
        }
        $curseur->modify($parJour ? '+1 day' : '+1 hour');
    }
    // Trois créneaux vides d'affilée ou plus (la nuit) : une seule colonne « … », pour garder les autres lisibles
    $serie = [];
    $vides = [];
    foreach ($colonnes as $c) {
        if (!$c[1]) {
            $vides[] = $c;
            continue;
        }
        array_push($serie, ...(count($vides) >= 3 ? [['vide', $vides[0][0], end($vides)[0]]] : array_map(fn($v) => ['plein', $v[0], $v[1]], $vides)));
        $vides = [];
        $serie[] = ['plein', $c[0], $c[1]];
    }
    $max = max(array_map(fn($c) => count($c[1]), $colonnes));
    $libelle = fn(DateTime $d) => $parJour ? $d->format('j') : $d->format('G') . ' h';
    $html = '';
    $lignes = '';
    $jourPrecedent = '';
    foreach ($serie as $c) {
        $jour = $c[1]->format('Y-m-d');
        $etiquetteJour = !$parJour && $jour !== $jourPrecedent ? '<span class="tdb__col-jour">' . date_longue($c[1], true) . '</span>' : '';
        if ($c[0] === 'vide') {
            $html .= '<li class="tdb__col tdb__col--vide" title="' . e($libelle($c[1]) . ' – ' . $libelle($c[2]) . ' : aucun avis') . '">'
                . '<span class="tdb__col-zone"></span><span class="tdb__col-h">…</span><span class="tdb__col-moy"></span>' . $etiquetteJour . '</li>';
            $jourPrecedent = $jour;
            continue;
        }
        $jourPrecedent = $jour;
        $n = count($c[2]);
        $moyenne = $n ? nombre(array_sum($c[2]) / $n) : '';
        $quandLong = date_longue($c[1], true) . ($parJour ? '' : ', ' . $libelle($c[1]));
        $html .= '<li class="tdb__col"' . ($n ? ' title="' . e("$quandLong : $n avis, moyenne $moyenne/5") . '"' : '') . '>'
            . '<span class="tdb__col-zone"><span class="tdb__col-n">' . ($n ?: '') . '</span><span class="tdb__col-barre" style="height:'
            . round($n / $max * 100) . '%"></span></span><span class="tdb__col-h">' . $libelle($c[1]) . '</span>'
            . '<span class="tdb__col-moy">' . $moyenne . '</span>' . $etiquetteJour . '</li>';
        if ($n) {
            $lignes .= '<tr><td>' . e($quandLong) . '</td><td>' . $n . '</td><td>' . $moyenne . '</td></tr>';
        }
    }
    $titre = $parJour ? 'Les avis jour par jour' : 'Les avis heure par heure';
    return '<section class="tdb__bloc" aria-labelledby="tdb-temps"><h3 class="tdb__titre" id="tdb-temps">' . $titre . '</h3>'
        . '<p class="tdb__aide">Au-dessus de chaque barre, le nombre d’avis ; en dessous, la note moyenne' . ($parJour ? '.' : ' (heure de Paris).') . '</p>'
        . '<ol class="tdb__colonnes" aria-hidden="true">' . $html . '</ol>'
        . '<table class="sr-only"><caption>' . $titre . '</caption><tr><th>Quand</th><th>Avis</th><th>Note moyenne</th></tr>' . $lignes . '</table></section>';
}

/** Date, lieu, titre de l'intervention et lien vers la page de l'événement. */
function fiche_evenement(array $evenement): string
{
    $parties = [];
    $quand = !empty($evenement['date']) ? DateTime::createFromFormat('!Y-m-d', $evenement['date'], new DateTimeZone('Europe/Paris')) : false;
    $lieu = trim(($quand ? date_longue($quand) : '') . (!empty($evenement['lieu']) ? ' · ' . $evenement['lieu'] : ''), ' ·');
    if ($lieu !== '') {
        $parties[] = '<p class="nf-label">' . e($lieu) . '</p>';
    }
    if (!empty($evenement['titre'])) {
        $parties[] = '<p class="tdb__titre-intervention">«&#8239;' . e($evenement['titre']) . '&#8239;»</p>';
    }
    if (!empty($evenement['page'])) {
        $parties[] = '<p class="tdb__lien-page"><a class="nf-link" href="' . e($evenement['page']) . '" target="_blank" rel="noopener">Voir la page de la conférence'
            . '<span class="sr-only"> (nouvel onglet)</span>' . LIEN_EXT . '</a></p>';
    }
    return $parties ? '<div class="tdb__fiche">' . implode('', $parties) . '</div>' : '';
}

// Accès
$config = nf_reglages() ?? [];
$motDePasse = (string) ($config['tableau_mot_de_passe'] ?? '');
if ($motDePasse === '' || !nf_configure($config, ['supabase_url', 'supabase_cle_publique', 'avis_cle'])) {
    page('<section class="nf-section tdb">' . message('Pas encore branché', 'Les réglages du tableau de bord manquent dans nfrancois-config.php.') . '</section>', 503);
}
// Le jeton du cookie dépend du mot de passe : en changer ferme toutes les sessions ouvertes
$jeton = hash_hmac('sha256', 'dashboard', $motDePasse . $config['avis_cle']);
$cookie = ['path' => ADRESSE, 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'];

if (isset($_GET['sortir'])) {
    setcookie(COOKIE, '', ['expires' => 1] + $cookie);
    header('Location: ' . ADRESSE, true, 303);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$compteur, $essais] = nf_essais('tableau', 900);
    if ($essais >= 10) {
        connexion('Trop d’essais. Réessayez dans un quart d’heure.', 429);
    }
    if (hash_equals($motDePasse, (string) ($_POST['mot_de_passe'] ?? ''))) {
        @unlink($compteur);
        setcookie(COOKIE, $jeton, ['expires' => time() + 30 * 86400] + $cookie);
        $cible = isset($_GET['evenement']) ? '?evenement=' . rawurlencode((string) $_GET['evenement']) : '';
        header('Location: ' . ADRESSE . $cible, true, 303);
        exit;
    }
    file_put_contents($compteur, (string) ($essais + 1), LOCK_EX);
    sleep(1);
    connexion('Ce mot de passe ne fonctionne pas.', 403);
}
if (!hash_equals($jeton, (string) ($_COOKIE[COOKIE] ?? ''))) {
    connexion();
}

// Données
try {
    $avis = nf_supabase($config, 'nf_avis_lire', []);
    $avis = is_array($avis) ? $avis : [];
} catch (Throwable $e) {
    error_log('tableau de bord : ' . $e->getMessage());
    page('<section class="nf-section tdb">' . message('La base ne répond pas', 'Réessayez dans un instant.') . '</section>', 502);
}
$evenements = nf_evenements();
$label = (string) ($_GET['evenement'] ?? '');
$duJour = date('Y-m-d');

if ($label !== '') {
    $avis = array_values(array_filter($avis, fn($a) => $a['evenement'] === $label));
    $nom = $evenements[$label]['nom'] ?? $label;
    if (($_GET['format'] ?? '') === 'csv') {
        csv($avis, $evenements, "avis-$label-$duJour.csv");
    }
    $lignes = '';
    foreach ($avis as $a) {
        $infos = [date_fr($a['recu_le'])];
        if ($a['citation'] && $a['commentaire']) {
            $infos[] = '<strong>Citation autorisée</strong>' . ($a['signature'] ? ' : « ' . e($a['signature']) . ' »' : ', sans signature');
        }
        if ($a['email']) {
            $infos[] = '<a class="nf-link" href="mailto:' . e($a['email']) . '">' . e($a['email']) . '</a>';
        }
        if ($a['newsletter']) {
            $infos[] = 'Newsletter : ' . e($a['ghost'] ?? 'demandée');
        }
        $lignes .= '<li class="tdb__avis-ligne"><span class="tdb__note" aria-label="Note ' . (int) $a['note'] . ' sur 5">' . (int) $a['note'] . '</span><div>'
            . ($a['commentaire'] ? '<p class="tdb__commentaire">' . nl2br(e($a['commentaire'])) . '</p>' : '<p class="tdb__vide">Sans commentaire</p>')
            . '<p class="tdb__infos">' . implode(' · ', $infos) . '</p></div></li>';
    }
    $evenement = $evenements[$label] ?? [];
    $fil = '<nav class="tdb__fil" aria-label="Fil d’Ariane"><a class="nf-link" href="' . ADRESSE . '">Tableau de bord</a>'
        . '<span aria-hidden="true">/</span><span aria-current="page">' . e($nom) . '</span></nav>';
    page('<section class="tdb tdb--conference"><h1 class="tdb__nom">' . e($nom) . '</h1>' . fiche_evenement($evenement) . chiffres(bilan($avis))
        . bloc_citations($avis, $evenement['signature_defaut'] ?? "Un participant · $nom") . bloc_temps($avis)
        . ($avis ? '<section class="tdb__bloc" aria-labelledby="tdb-tous"><div class="tdb__bloc-tete"><h3 class="tdb__titre" id="tdb-tous">Tous les avis</h3>'
            . '<a class="nf-link tdb__export" href="?evenement=' . e(rawurlencode($label)) . '&amp;format=csv">Exporter en CSV</a></div>'
            . '<ol class="tdb__liste">' . $lignes . '</ol></section>' : '')
        . '</section>', 200, $fil);
}

if (($_GET['format'] ?? '') === 'csv') {
    csv($avis, $evenements, "avis-$duJour.csv");
}
// Vue d'ensemble : les événements qui ont des avis (du plus récent au plus ancien), puis ceux encore sans avis
$parEvenement = [];
foreach ($avis as $a) {
    $parEvenement[$a['evenement']][] = $a;
}
foreach (array_keys($evenements) as $cle) {
    $parEvenement[$cle] = $parEvenement[$cle] ?? [];
}
$cartes = '';
foreach ($parEvenement as $cle => $liste) {
    $b = bilan($liste);
    $etat = !empty($evenements[$cle]['ouvert']) ? 'Formulaire ouvert' : 'Formulaire fermé';
    $e = $evenements[$cle] ?? [];
    $quand = !empty($e['date']) ? DateTime::createFromFormat('!Y-m-d', $e['date'], new DateTimeZone('Europe/Paris')) : false;
    $cartes .= '<li class="tdb__evenement"><h3 class="nf-col__title"><a class="tdb__lien" href="?evenement=' . e(rawurlencode($cle)) . '">'
        . e($e['nom'] ?? $cle) . '</a></h3><p class="nf-label">' . ($quand ? e(date_longue($quand)) . ' · ' : '')
        . (!empty($e['lieu']) ? e($e['lieu']) . ' · ' : '') . $etat
        . ($b['dernier'] ? ' · dernier avis le ' . date_fr($b['dernier'], 'd/m/Y') : '') . '</p>' . chiffres($b) . '</li>';
}
$conferences = count(array_filter($parEvenement, fn($liste) => $liste));
page('<section class="nf-section tdb">' . indicateurs($conferences, $avis) . bloc_mots($avis)
    . '<section class="tdb__bloc" aria-labelledby="tdb-conferences"><h2 class="tdb__titre" id="tdb-conferences">Les conférences</h2>'
    . '<ul class="tdb__evenements">' . $cartes . '</ul></section>'
    . '<p class="tdb__outils tdb__outils--pied"><a class="nf-link" href="?format=csv">Tout exporter (CSV)</a> · '
    . '<a class="nf-link" href="?sortir=1">Se déconnecter</a></p></section>');
