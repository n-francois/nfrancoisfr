<?php
/*
 * Tableau de bord privé des avis laissés après les conférences (base Supabase, voir api/avis.php) :
 * - vue d'ensemble : une ligne par événement (moyenne, répartition des notes, abonnés, citations) ;
 * - détail d'un événement (?evenement=label) : tous les avis, du plus récent au plus ancien ;
 * - export CSV (?format=csv, pour un événement ou pour tous).
 * Protégé par le mot de passe 'tableau_mot_de_passe' de nfrancois-config.php (cookie de 30 jours, 10 essais par quart
 * d'heure). La page n'est liée nulle part et exclue des moteurs de recherche.
 */

require dirname(__DIR__) . '/api/lib.php';

const COOKIE = 'nf_tableau';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store');
header('Referrer-Policy: same-origin');

function e($texte): string
{
    return htmlspecialchars((string) $texte, ENT_QUOTES);
}

function page(string $contenu, int $statut = 200): void
{
    http_response_code($statut);
    header('Content-Type: text/html; charset=utf-8');
    echo str_replace('<!--tableau-->', $contenu, (string) file_get_contents(__DIR__ . '/gabarit.html'));
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
    page('<section class="nf-section tdb"><form class="acces" method="post" action="/tableau-de-bord/' . e($cible) . '">'
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
    $nb = fn(int $n, string $un, string $plusieurs) => $n . ' ' . ($n > 1 ? $plusieurs : $un);
    return '<div class="tdb__chiffres"><p class="tdb__moyenne"><span class="nf-preuve__n">' . nombre($b['moyenne'])
        . '</span><span class="tdb__sur">/5</span></p><ul class="tdb__repartition" aria-label="Répartition des notes">' . $barres . '</ul>'
        . '<p class="tdb__compteurs">' . $nb($b['n'], 'avis', 'avis') . ' · ' . $nb($b['commentaires'], 'commentaire', 'commentaires')
        . ' · ' . $nb($b['citations'], 'citation autorisée', 'citations autorisées') . ' · '
        . $nb($b['abonnes'], 'abonné', 'abonnés') . ' à la newsletter</p></div>';
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

// Accès
$config = nf_reglages() ?? [];
$motDePasse = (string) ($config['tableau_mot_de_passe'] ?? '');
if ($motDePasse === '' || !nf_configure($config, ['supabase_url', 'supabase_cle_publique', 'avis_cle'])) {
    page('<section class="nf-section tdb">' . message('Pas encore branché', 'Les réglages du tableau de bord manquent dans nfrancois-config.php.') . '</section>', 503);
}
// Le jeton du cookie dépend du mot de passe : en changer ferme toutes les sessions ouvertes
$jeton = hash_hmac('sha256', 'tableau-de-bord', $motDePasse . $config['avis_cle']);
$cookie = ['path' => '/tableau-de-bord/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'];

if (isset($_GET['sortir'])) {
    setcookie(COOKIE, '', ['expires' => 1] + $cookie);
    header('Location: /tableau-de-bord/', true, 303);
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
        header('Location: /tableau-de-bord/' . $cible, true, 303);
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
    page('<section class="nf-section tdb"><p class="tdb__retour"><a class="nf-link" href="/tableau-de-bord/">← Toutes les conférences</a></p>'
        . '<h2 class="nf-section__name">' . e($nom) . '</h2>' . chiffres(bilan($avis))
        . ($avis ? '<p class="tdb__outils"><a class="nf-link" href="?evenement=' . e(rawurlencode($label)) . '&amp;format=csv">Exporter ces avis (CSV)</a></p>'
            . '<ol class="tdb__liste">' . $lignes . '</ol>' : '')
        . '</section>');
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
    $cartes .= '<li class="tdb__evenement"><h2 class="nf-col__title"><a class="tdb__lien" href="?evenement=' . e(rawurlencode($cle)) . '">'
        . e($evenements[$cle]['nom'] ?? $cle) . '</a></h2><p class="nf-label">' . e($cle) . ' · ' . $etat
        . ($b['dernier'] ? ' · dernier avis le ' . date_fr($b['dernier'], 'd/m/Y') : '') . '</p>' . chiffres($b) . '</li>';
}
page('<section class="nf-section tdb"><p class="tdb__outils">' . count($avis) . ' avis au total · '
    . '<a class="nf-link" href="?format=csv">Tout exporter (CSV)</a> · <a class="nf-link" href="?sortir=1">Se déconnecter</a></p>'
    . '<ul class="tdb__evenements">' . $cartes . '</ul></section>');
