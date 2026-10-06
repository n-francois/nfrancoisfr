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

/** Requête HTTP en JSON : [code, corps décodé ou brut]. */
function nf_http(string $methode, string $url, array $entetes, ?array $charge = null): array
{
    $requete = curl_init($url);
    curl_setopt_array($requete, [
        CURLOPT_CUSTOMREQUEST => $methode,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $entetes),
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
 * Abonne une adresse à la newsletter dans Ghost, avec le label de l'événement. Si elle y est déjà, ajoute seulement le
 * label. Rend le résultat en clair (pour l'e-mail et la base) ; lève une exception en cas d'échec.
 */
function nf_ghost_abonner(array $config, string $email, string $label, string $note): string
{
    $api = rtrim($config['ghost_url'], '/') . '/ghost/api/admin/members/';
    $entetes = ['Authorization: Ghost ' . nf_ghost_jeton($config['ghost_admin_key']), 'Accept-Version: v5.0'];
    [$code, $corps] = nf_http('POST', $api, $entetes, ['members' => [['email' => $email, 'labels' => [$label], 'note' => $note]]]);
    if ($code >= 200 && $code < 300) {
        return 'inscrit';
    }
    $dejaLa = $code === 422 && stripos(json_encode($corps), 'already exist') !== false;
    if (!$dejaLa) {
        throw nf_echec('Ghost', $code, $corps);
    }
    [$code, $corps] = nf_http('GET', $api . '?limit=1&filter=' . rawurlencode("email:'" . str_replace("'", "\\'", $email) . "'"), $entetes);
    $membre = $corps['members'][0] ?? null;
    if ($code !== 200 || !$membre) {
        throw nf_echec('Ghost', $code, $corps);
    }
    $labels = array_values(array_unique(array_merge(array_column($membre['labels'] ?? [], 'name'), [$label])));
    [$code, $corps] = nf_http('PUT', $api . $membre['id'] . '/', $entetes, ['members' => [['labels' => $labels]]]);
    if ($code < 200 || $code >= 300) {
        throw nf_echec('Ghost', $code, $corps);
    }
    return empty($membre['newsletters']) ? 'déjà membre mais désabonné : à réabonner à la main' : 'déjà abonné, label ajouté';
}

/** Compteur d'essais par adresse IP (mots de passe, codes), dans le dossier temporaire du serveur. */
function nf_essais(string $usage, int $fenetre): array
{
    $fichier = sys_get_temp_dir() . "/nf-$usage-" . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
    $essais = is_file($fichier) && filemtime($fichier) > time() - $fenetre ? (int) file_get_contents($fichier) : 0;
    return [$fichier, $essais];
}
