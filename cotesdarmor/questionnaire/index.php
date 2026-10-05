<?php
/*
 * Résultats du questionnaire IA des Trophées du tourisme en Côtes d'Armor 2026, en accès réservé : le rapport s'affiche
 * tel quel une fois le bon code saisi. Aucune page du site n'y mène.
 *
 * Ni le rapport ni le code ne sont dans le dépôt, qui est public : ils vivent chez Hostinger, hors de portée du web, à côté
 * de nfrancois-config.php (fichier questionnaire-cotesdarmor-2026.html, réglage 'questionnaire_code').
 * Sans le bon code, le script affiche acces.html (générée par build.py), en plaçant ses messages à l'endroit marqué.
 */

const RAPPORT = 'questionnaire-cotesdarmor-2026.html';
const COOKIE = 'nf_questionnaire_cda';
const ESSAIS_MAX = 10;         // codes faux tolérés par adresse IP…
const ESSAIS_FENETRE = 900;    // …sur un quart d'heure

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store');
header('Referrer-Policy: same-origin');

function normaliser(string $code): string
{
    return strtoupper(preg_replace('/[\s\-]+/u', '', $code));
}

function page_acces(int $statut = 200, string $titre = '', string $texte = ''): void
{
    http_response_code($statut);
    header('Content-Type: text/html; charset=utf-8');
    $page = (string) file_get_contents(__DIR__ . '/acces.html');
    if ($titre !== '') {
        $message = '<div class="nf-msg nf-msg--erreur" role="alert"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8v5M12 16.5v.5"/>'
            . '<circle cx="12" cy="12" r="9"/></svg><div><p class="nf-msg__title">' . htmlspecialchars($titre) . '</p>'
            . '<p class="nf-msg__text">' . htmlspecialchars($texte) . '</p></div></div>';
        $page = str_replace('<!--message-->', $message, $page);
    }
    echo $page;
    exit;
}

// Les réglages : à côté du dossier public_html (domains/nfrancois.fr/) ou à la racine du compte
$dossier = null;
foreach (array_unique([dirname(__DIR__, 3), dirname(__DIR__, 5)]) as $candidat) {
    if (is_file("$candidat/nfrancois-config.php")) {
        $dossier = $candidat;
        break;
    }
}
$config = $dossier !== null ? require "$dossier/nfrancois-config.php" : [];
$code = normaliser((string) ($config['questionnaire_code'] ?? ''));
$rapport = $dossier !== null ? "$dossier/" . RAPPORT : '';
if ($code === '' || !is_file($rapport)) {
    error_log('questionnaire : ' . ($code === '' ? "réglage 'questionnaire_code' absent" : RAPPORT . ' introuvable'));
    page_acces(503, 'Pas encore en ligne', 'Les résultats arrivent très vite. Revenez un peu plus tard.');
}

// Le jeton du cookie dépend du code : changer le code ferme l'accès à tous ceux qui l'avaient
$jeton = hash_hmac('sha256', 'questionnaire-cotesdarmor-2026', $code);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Codes faux : compteur par adresse IP, dans le dossier temporaire du serveur
    $compteur = sys_get_temp_dir() . '/nf-questionnaire-' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
    $essais = is_file($compteur) && filemtime($compteur) > time() - ESSAIS_FENETRE ? (int) file_get_contents($compteur) : 0;
    if ($essais >= ESSAIS_MAX) {
        page_acces(429, 'Trop d’essais', 'Réessayez dans un quart d’heure, ou écrivez-moi pour recevoir le code.');
    }
    if (hash_equals($code, normaliser((string) ($_POST['code'] ?? '')))) {
        @unlink($compteur);
        setcookie(COOKIE, $jeton, ['expires' => time() + 180 * 86400, 'path' => '/cotesdarmor/questionnaire/',
            'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
        header('Location: /cotesdarmor/questionnaire/', true, 303);
        exit;
    }
    file_put_contents($compteur, (string) ($essais + 1), LOCK_EX);
    sleep(1);
    page_acces(403, 'Ce code ne fonctionne pas', 'Vérifiez-le et réessayez.');
}

if (hash_equals($jeton, (string) ($_COOKIE[COOKIE] ?? ''))) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($rapport);
    exit;
}
page_acces();
