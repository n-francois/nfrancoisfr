<?php
/*
 * Avis sur la conférence des Trophées du tourisme en Côtes d'Armor, envoyés depuis nfrancois.fr/cotesdarmor/.
 * Envoie l'avis par e-mail à Nicolas (e-mail transactionnel Brevo), avec l'accord de citation et la demande d'inscription
 * à la newsletter s'ils sont donnés : cet e-mail daté en garde la trace. Aucun e-mail ne part vers le participant :
 * Nicolas inscrit lui-même les demandeurs dans Ghost, d'où part la newsletter IA, Tech & Travel Café.
 *
 * Les réglages (clé API Brevo, adresses, identifiants) ne sont pas dans le dépôt, qui est public : ils vivent dans
 * nfrancois-config.php, posé chez Hostinger hors de portée du web, à côté du dossier public_html (domains/nfrancois.fr/)
 * ou à la racine du compte, à côté du raccourci public_html.
 */

const EVENEMENT = "Trophées du tourisme en Côtes d'Armor 2026";
const BREVO = 'https://api.brevo.com/v3';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

function repondre(array $corps, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($corps, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    repondre(['error' => 'Méthode non autorisée.'], 405);
}

// Seuls les formulaires du site peuvent envoyer un avis
$origine = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origine !== '' && !in_array($origine, ['https://nfrancois.fr', 'https://www.nfrancois.fr'], true)) {
    repondre(['error' => 'Origine non autorisée.'], 403);
}

$candidats = array_unique([dirname(__DIR__, 2) . '/nfrancois-config.php', dirname(__DIR__, 4) . '/nfrancois-config.php']);
$fichier = null;
foreach ($candidats as $candidat) {
    if (is_file($candidat)) {
        $fichier = $candidat;
        break;
    }
}
if ($fichier === null) {
    error_log('avis.php : nfrancois-config.php introuvable (cherché : ' . implode(', ', $candidats) . ')');
    repondre(['error' => 'L’envoi n’est pas encore activé. Réessayez un peu plus tard.'], 503);
}
$config = require $fichier;

$donnees = json_decode((string) file_get_contents('php://input', false, null, 0, 20000), true);
if (!is_array($donnees)) {
    repondre(['error' => 'Requête illisible.'], 400);
}

// Pot de miel : un robot remplit le champ caché, on fait comme si tout allait bien
if (!empty($donnees['site'])) {
    repondre(['ok' => true]);
}

$note = filter_var($donnees['note'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
$commentaire = trim(mb_substr((string) ($donnees['commentaire'] ?? ''), 0, 2000));
$citation = ($donnees['citation'] ?? false) === true;
$signature = $citation ? trim(preg_replace('/\s+/u', ' ', mb_substr((string) ($donnees['signature'] ?? ''), 0, 120))) : '';
$email = mb_strtolower(trim((string) ($donnees['email'] ?? '')));
$inscription = ($donnees['newsletter'] ?? false) === true;

if ($note === false) {
    repondre(['error' => 'Choisissez une note de 1 à 5.'], 400);
}
if ($citation && $commentaire === '') {
    repondre(['error' => 'Écrivez votre commentaire pour qu’il puisse être cité.'], 400);
}
if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    repondre(['error' => 'Cette adresse e-mail ne semble pas valide.'], 400);
}
if ($inscription && $email === '') {
    repondre(['error' => 'Indiquez votre e-mail pour recevoir la newsletter.'], 400);
}

function brevo(array $config, string $chemin, array $charge): void
{
    $requete = curl_init(BREVO . $chemin);
    curl_setopt_array($requete, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['api-key: ' . $config['brevo_api_key'], 'Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode($charge, JSON_UNESCAPED_UNICODE),
    ]);
    $reponse = curl_exec($requete);
    $code = (int) curl_getinfo($requete, CURLINFO_RESPONSE_CODE);
    $erreur = curl_error($requete);
    if ($reponse === false || $code < 200 || $code >= 300) {
        throw new RuntimeException("Brevo $chemin $code $erreur " . substr((string) $reponse, 0, 500));
    }
}

try {
    $recu = (new DateTime('now', new DateTimeZone('Europe/Paris')))->format('d/m/Y à H:i');
    $lignes = [
        "Note : $note/5",
        'Commentaire : ' . ($commentaire !== '' ? $commentaire : '(aucun)'),
        'Citation autorisée : ' . ($citation
            ? ($signature !== '' ? "oui, signée « $signature »" : 'oui, sans signature (« Un participant des ' . EVENEMENT . ' »)')
            : 'non'),
        'E-mail : ' . ($email !== '' ? $email : '(anonyme)'),
        'Inscription à la newsletter : ' . ($inscription ? 'OUI, à inscrire dans Ghost' : 'non'),
        "Reçu le : $recu",
    ];
    $message = [
        'sender' => ['email' => $config['avis_expediteur'], 'name' => 'nfrancois.fr'],
        'to' => [['email' => $config['avis_destinataire']]],
        'subject' => "Avis $note/5" . ($citation ? ' · citation autorisée' : '') . ($inscription ? ' · newsletter' : '')
            . ' · ' . EVENEMENT,
        'textContent' => implode("\n", $lignes),
    ];
    if ($email !== '') {
        $message['replyTo'] = ['email' => $email];
    }
    brevo($config, '/smtp/email', $message);
    repondre(['ok' => true]);
} catch (Throwable $e) {
    error_log('avis.php : ' . $e->getMessage());
    repondre(['error' => 'L’envoi n’a pas fonctionné. Réessayez dans un instant.'], 502);
}
