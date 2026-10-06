<?php
/*
 * Formulaire d'avis des pages d'événement (bloc {{formulaire_avis}} de build.py). Pour chaque avis :
 * 1. l'enregistre dans la base Supabase, avec le label de l'événement (data/evenements.json) ;
 * 2. si l'adresse est donnée et la case cochée : inscription à la newsletter IA, Tech & Travel Café dans Ghost, avec le
 *    label Ghost de l'événement (e-mail de confirmation de Ghost pour une nouvelle adresse, voir nf_ghost_abonner) ;
 * 3. envoie l'avis par e-mail à Nicolas (Brevo), avec le résultat des deux étapes : cet e-mail daté garde aussi la trace
 *    des accords (citation, newsletter).
 * Chaque étape est indépendante : si l'une échoue, les autres ont lieu et l'e-mail dit quoi reprendre à la main.
 */

require __DIR__ . '/lib.php';

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

$config = nf_reglages();
if ($config === null) {
    error_log('avis : nfrancois-config.php introuvable');
    repondre(['error' => 'L’envoi n’est pas encore activé. Réessayez un peu plus tard.'], 503);
}

$donnees = json_decode((string) file_get_contents('php://input', false, null, 0, 20000), true);
if (!is_array($donnees)) {
    repondre(['error' => 'Requête illisible.'], 400);
}

// Pot de miel : un robot remplit le champ caché, on fait comme si tout allait bien
if (!empty($donnees['site'])) {
    repondre(['ok' => true]);
}

// L'événement : envoyé par le formulaire, ou fixé par l'ancienne adresse d'une page (cotesdarmor/avis.php)
$label = (string) ($donnees['evenement'] ?? ($EVENEMENT_DEFAUT ?? ''));
$evenement = nf_evenements()[$label] ?? null;
if ($evenement === null || empty($evenement['ouvert'])) {
    repondre(['error' => 'Ce formulaire n’accepte plus d’avis. Merci quand même !'], 400);
}
$nom = $evenement['nom'];
$signatureDefaut = $evenement['signature_defaut'] ?? "Un participant · $nom";

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

// 1. La base des avis
$id = null;
if (nf_configure($config, ['supabase_url', 'supabase_cle_publique', 'avis_cle'])) {
    try {
        $id = (int) nf_supabase($config, 'nf_avis_ajouter', [
            'p_evenement' => $label, 'p_note' => $note, 'p_commentaire' => $commentaire, 'p_citation' => $citation,
            'p_signature' => $signature, 'p_email' => $email, 'p_newsletter' => $inscription,
        ]);
        $base = "enregistré (n° $id)";
    } catch (Throwable $e) {
        error_log('avis : ' . $e->getMessage());
        $base = 'ÉCHEC, avis à reprendre à la main. Raison : ' . $e->getMessage();
    }
} else {
    $base = 'pas encore branchée (réglages Supabase absents)';
}

// 2. La newsletter, dans Ghost
$newsletter = null;
$ghost = 'non demandée';
if ($inscription) {
    $newsletter = 'a_faire';
    if (nf_configure($config, ['ghost_url', 'ghost_admin_key'])) {
        try {
            [$newsletter, $ghost] = nf_ghost_abonner($config, $email, $evenement['ghost_label'] ?? $nom);
        } catch (Throwable $e) {
            error_log('avis : ' . $e->getMessage());
            $ghost = 'ÉCHEC, à inscrire à la main. Raison : ' . $e->getMessage();
        }
    } else {
        $ghost = 'à inscrire à la main (réglages Ghost absents)';
    }
    if ($id !== null) {
        try {
            nf_supabase($config, 'nf_avis_ghost', ['p_id' => $id, 'p_ghost' => $ghost]);
        } catch (Throwable $e) {
            error_log('avis : ' . $e->getMessage());
        }
    }
}

// 3. L'avis, par e-mail
$envoye = false;
try {
    $recu = (new DateTime('now', new DateTimeZone('Europe/Paris')))->format('d/m/Y à H:i');
    $lignes = [
        "Événement : $nom",
        "Note : $note/5",
        'Commentaire : ' . ($commentaire !== '' ? $commentaire : '(aucun)'),
        'Citation autorisée : ' . ($citation
            ? ($signature !== '' ? "oui, signée « $signature »" : "oui, sans signature (« $signatureDefaut »)")
            : 'non'),
        'E-mail : ' . ($email !== '' ? $email : '(anonyme)'),
        "Newsletter : $ghost",
        "Base des avis : $base",
        "Reçu le : $recu",
        '',
        'Tableau de bord : https://nfrancois.fr/tableau-de-bord/?evenement=' . rawurlencode($label),
    ];
    $aReprendre = $id === null || $newsletter === 'a_faire';
    $message = [
        'sender' => ['email' => $config['avis_expediteur'], 'name' => 'nfrancois.fr'],
        'to' => [['email' => $config['avis_destinataire']]],
        'subject' => "Avis $note/5" . ($citation ? ' · citation autorisée' : '') . ($inscription ? ' · newsletter' : '')
            . ($aReprendre ? ' · à reprendre' : '') . " · $nom",
        'textContent' => implode("\n", $lignes),
    ];
    if ($email !== '') {
        $message['replyTo'] = ['email' => $email];
    }
    nf_brevo_email($config, $message);
    $envoye = true;
} catch (Throwable $e) {
    error_log('avis : ' . $e->getMessage());
}

if ($id === null && !$envoye) {
    repondre(['error' => 'L’envoi n’a pas fonctionné. Réessayez dans un instant.'], 502);
}
repondre(['ok' => true, 'newsletter' => $newsletter]);
