<?php
/*
 * Outils communs du formulaire d'avis (api/avis.php) et du tableau de bord (tableau-de-bord/index.php).
 * Les réglages (clés Brevo, Supabase, Ghost, mot de passe) ne sont pas dans le dépôt, qui est public : ils vivent dans
 * nfrancois-config.php, chez Hostinger, hors de portée du web (à côté du dossier public_html ou à la racine du compte).
 */

const NF_BREVO = 'https://api.brevo.com/v3';

/** Les réglages, ou null si le fichier n'est pas en place. */
function nf_reglages(): ?array
{
    foreach (array_unique([dirname(__DIR__, 2), dirname(__DIR__, 4)]) as $dossier) {
        if (is_file("$dossier/nfrancois-config.php")) {
            $config = require "$dossier/nfrancois-config.php";
            return is_array($config) ? $config : null;
        }
    }
    return null;
}

/** Les événements déclarés dans data/evenements.json (label => réglages). */
function nf_evenements(): array
{
    $liste = json_decode((string) @file_get_contents(dirname(__DIR__) . '/data/evenements.json'), true);
    return is_array($liste) ? array_filter($liste, fn($cle) => $cle[0] !== '_', ARRAY_FILTER_USE_KEY) : [];
}

/** En-têtes d'une requête : ceux donnés remplacent ceux par défaut du même nom. */
function nf_entetes(array $entetes): array
{
    $liste = [];
    foreach (array_merge(['Content-Type: application/json', 'Accept: application/json', 'User-Agent: nfrancois.fr (formulaire d\'avis)'], $entetes) as $ligne) {
        $liste[strtolower(strtok($ligne, ':'))] = $ligne;
    }
    return array_values($liste);
}

/** Requête HTTP en JSON : [code, corps décodé ou brut]. */
function nf_http(string $methode, string $url, array $entetes, ?array $charge = null): array
{
    $requete = curl_init($url);
    curl_setopt_array($requete, [
        CURLOPT_CUSTOMREQUEST => $methode,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => nf_entetes($entetes),
    ]);
    if ($charge !== null) {
        curl_setopt($requete, CURLOPT_POSTFIELDS, json_encode($charge, JSON_UNESCAPED_UNICODE));
    }
    $reponse = curl_exec($requete);
    $code = (int) curl_getinfo($requete, CURLINFO_RESPONSE_CODE);
    $erreur = curl_error($requete);
    if ($reponse === false) {
        return [0, $erreur];
    }
    $json = json_decode((string) $reponse, true);
    return [$code, $json ?? $reponse];
}

function nf_echec(string $service, int $code, $corps): RuntimeException
{
    $detail = is_array($corps) ? json_encode($corps, JSON_UNESCAPED_UNICODE) : (string) $corps;
    return new RuntimeException("$service $code " . mb_substr($detail, 0, 400));
}

/** Vrai si toutes les clés de réglage demandées sont renseignées. */
function nf_configure(array $config, array $cles): bool
{
    foreach ($cles as $cle) {
        if (empty($config[$cle])) {
            return false;
        }
    }
    return true;
}

/** E-mail transactionnel Brevo (lève une exception en cas d'échec). */
function nf_brevo_email(array $config, array $message): void
{
    [$code, $corps] = nf_http('POST', NF_BREVO . '/smtp/email', ['api-key: ' . $config['brevo_api_key']], $message);
    if ($code < 200 || $code >= 300) {
        throw nf_echec('Brevo', $code, $corps);
    }
}

/** Appel d'une fonction de la base Supabase (schéma public, clé publique + clé des avis). */
function nf_supabase(array $config, string $fonction, array $parametres)
{
    $url = rtrim($config['supabase_url'], '/') . '/rest/v1/rpc/' . $fonction;
    $cle = $config['supabase_cle_publique'];
    // Ancienne clé « anon » (un JWT) : aussi en jeton d'autorisation ; nouvelle clé « sb_publishable_… » : en apikey seulement
    $entetes = str_starts_with($cle, 'eyJ') ? ['apikey: ' . $cle, 'Authorization: Bearer ' . $cle] : ['apikey: ' . $cle];
    [$code, $corps] = nf_http('POST', $url, $entetes,
        ['p_cle' => $config['avis_cle']] + $parametres);
    if ($code < 200 || $code >= 300) {
        throw nf_echec('Supabase', $code, $corps);
    }
    return $corps;
}

/** Jeton de l'API d'administration de Ghost (clé « id:secret » d'une intégration personnalisée), valable 5 minutes. */
function nf_ghost_jeton(string $cleAdmin): string
{
    [$id, $secret] = explode(':', $cleAdmin, 2) + ['', ''];
    $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $entete = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $id]));
    $maintenant = time();
    $contenu = $b64(json_encode(['iat' => $maintenant, 'exp' => $maintenant + 300, 'aud' => '/admin/']));
    $signature = $b64(hash_hmac('sha256', "$entete.$contenu", (string) hex2bin($secret), true));
    return "$entete.$contenu.$signature";
}

/**
 * Inscription à la newsletter dans Ghost, avec le label de l'événement :
 * - adresse inconnue de Ghost : déclenche l'inscription publique de Ghost (comme le formulaire du site de la newsletter).
 *   La personne reçoit l'e-mail de confirmation de Ghost ; elle est abonnée en cliquant, puis reçoit l'e-mail de
 *   bienvenue de Ghost s'il est activé ;
 * - adresse déjà dans Ghost : ajoute seulement le label (sinon Ghost lui enverrait un e-mail de connexion).
 * Rend [code, texte] : code 'confirmation', 'inscrit' ou 'a_faire' (à reprendre à la main) ; lève une exception en cas
 * d'échec.
 */
function nf_ghost_abonner(array $config, string $email, string $label): array
{
    $site = rtrim($config['ghost_url'], '/');
    $admin = $site . '/ghost/api/admin/members/';
    $entetes = ['Authorization: Ghost ' . nf_ghost_jeton($config['ghost_admin_key']), 'Accept-Version: v5.0'];
    [$code, $corps] = nf_http('GET', $admin . '?limit=1&filter=' . rawurlencode("email:'" . str_replace("'", "\\'", $email) . "'"), $entetes);
    if ($code !== 200) {
        throw nf_echec('Ghost (recherche du membre)', $code, $corps);
    }
    $membre = $corps['members'][0] ?? null;
    if ($membre) {
        $labels = array_values(array_unique(array_merge(array_column($membre['labels'] ?? [], 'name'), [$label])));
        [$code, $corps] = nf_http('PUT', $admin . $membre['id'] . '/', $entetes, ['members' => [['labels' => $labels]]]);
        if ($code < 200 || $code >= 300) {
            throw nf_echec('Ghost (ajout du label)', $code, $corps);
        }
        return empty($membre['newsletters'])
            ? ['a_faire', 'déjà membre mais désabonné, label ajouté : à réabonner à la main']
            : ['inscrit', 'déjà abonné, label ajouté'];
    }
    // Inscription publique, sur l'adresse publique du site : avec Ghost(Pro), l'API d'administration répond sur
    // xxx.ghost.io, mais l'inscription n'y fonctionne pas (redirection vers le domaine du site)
    [$code, $corps] = nf_http('GET', $site . '/ghost/api/admin/site/', ['Accept-Version: v5.0']);
    $public = rtrim((string) ($corps['site']['url'] ?? ''), '/');
    if ($code !== 200 || $public === '') {
        throw nf_echec('Ghost (adresse du site)', $code, $corps);
    }
    // Comme un formulaire d'inscription intégré à un autre site (Ghost doit autoriser les inscriptions externes) : jeton
    // anti-robot, puis demande du lien d'inscription, en disant d'où vient la demande
    $navigateur = ['Origin: https://nfrancois.fr', 'Referer: https://nfrancois.fr/'];
    $charge = ['email' => $email, 'emailType' => 'signup', 'labels' => [$label]];
    [$code, $jeton] = nf_http('GET', $public . '/members/api/integrity-token/', array_merge($navigateur, ['Accept: text/plain, */*']));
    if ($code === 200 && is_string($jeton) && trim($jeton) !== '') {
        $charge['integrityToken'] = trim($jeton);
    } else {
        throw nf_echec('Ghost (jeton anti-robot)', $code, $jeton);
    }
    [$code, $corps] = nf_http('POST', $public . '/members/api/send-magic-link/', $navigateur, $charge);
    if ($code < 200 || $code >= 300) {
        throw nf_echec('Ghost (inscription)', $code, $corps);
    }
    return ['confirmation', 'e-mail de confirmation envoyé par Ghost (abonné dès qu’il clique)'];
}

/** Compteur d'essais par adresse IP (mots de passe, codes), dans le dossier temporaire du serveur. */
function nf_essais(string $usage, int $fenetre): array
{
    $fichier = sys_get_temp_dir() . "/nf-$usage-" . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
    $essais = is_file($fichier) && filemtime($fichier) > time() - $fenetre ? (int) file_get_contents($fichier) : 0;
    return [$fichier, $essais];
}
