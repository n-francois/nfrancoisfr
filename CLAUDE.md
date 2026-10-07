# nfrancois.fr

Site personnel de Nicolas François : HTML/CSS statique, sans build ni framework. Bilingue FR (racine) et EN (`/en/`).

## Structure

| Page FR | Page EN |
|---|---|
| `index.html` → `/` | `en/index.html` → `/en/` |
| `interventions/index.html` → `/interventions/` | `en/talks/index.html` → `/en/talks/` |

Fichiers communs : `styles.css`, `images/`, `sitemap.xml`, `robots.txt`, `llms.txt`, `README.md`.

## Déploiement

- Push sur `main` → webhook Hostinger (git pull automatique dans `public_html`), en ligne en ~10 s. Vérifier ensuite avec `curl`.
- Tout le repo est servi publiquement (y compris `README.md` et ce fichier) : ne rien y mettre de sensible.
- `.htaccess` n'est pas dans le repo : il vit sur Hostinger (HTTPS, www → non-www, redirections 301 de l'ancien blog). Toute modif se fait dans le gestionnaire de fichiers Hostinger, par Nicolas.
- Toujours `git pull --rebase` avant `git push` : Nicolas modifie parfois le README directement sur GitHub.

## Façon de travailler

- Modifier en local, montrer le résultat dans l'aperçu, et attendre un « commit et push » explicite avant de commiter.
- Quand Nicolas demande un avis, une vérification ou un brief, répondre sans modifier les fichiers.
- Aperçu local : Google Drive empêche `python3 -m http.server` de lire le dossier. Copier le site dans le scratchpad de la session (`rsync -a --delete --exclude .git --exclude .claude`), pointer `.claude/launch.json` (config `static`, port 8765, ignoré par git) vers cette copie, et relancer le `rsync` après chaque modification.

## Parité FR / EN

- Toute modification de contenu d'une langue s'applique aussi à l'autre (proposer la traduction si le texte est nouveau).
- Anglais américain : organizations, travelers, analyze ; virgule avant le dernier élément d'une liste (« A, B, and C ») ; present perfect avec « since ».
- Garder les noms propres français tels quels (ART Grand Est, Next Tourisme, Rencontres…, « Osez l'IA »). Écrire « French Ministry of Economy ».
- Les contenus de Nicolas sont en français : « In French. » sur les cartes EN, « Talks are delivered in French. » sur les textes d'interventions EN ; en tête de la page Talks, qui liste aussi médias et publications : « …, all in French. ».
- Email : `bonjour@nfrancois.fr` en FR, `hello@nfrancois.fr` en EN.

## Chiffres de référence

| Donnée | Valeur |
|---|---|
| Expérience | 20 ans dans le digital, dont 13 dans le tourisme |
| Newsletter IA, Tech & Travel Café | 3 000+ abonnés (aligné sur iatechtravel.cafe) |
| LinkedIn | 5 800+ abonnés |
| Rencontres IA & Tourisme Grand Est 2025 | 9 dates, 1 000+ participants |

Quand un chiffre change, le mettre à jour partout : compteurs, meta descriptions (x3 par page), hero, À propos, JSON-LD, `llms.txt`, `README.md`. Les compteurs animés portent la valeur finale en dur dans le HTML (`3&nbsp;000+` en FR, `3,000+` en EN), jamais `0`, pour les robots et les lecteurs sans JavaScript.

## IA, Tech & Travel Café

- Plus de Substack : tout est sur `https://www.iatechtravel.cafe`. Tous les liens, y compris « S'abonner à ma newsletter », pointent vers cette page d'accueil.
- Logo : virgule mint `#3de5bc` sur fond encre `#1a1a1a`, défini une fois par page dans le symbole SVG `#logo-ittc`.

## Pages Interventions / Talks

- Blocs par année, ordre antichronologique.
- Prise de parole : `<li><strong>Titre (format)</strong> — Organisateur, Ville, Pays · mois année</li>`
- Média : `<li><strong>Média</strong> · type — <a href="…" target="_blank" rel="noopener">Titre</a> · mois année</li>`
- Mois en toutes lettres (« juin 2026 » / « June 2026 »).
- Villes : à l'étranger avec le pays (« Houffalize, Belgique ») ; en France, la ville seule en FR, suivie de « , France » en EN (ajouté par `scripts/build.py`).

## Pages d'événement et avis

- Une page d'événement (ex. `/cotesdarmor/`) déclare `"evenement": "<label>"` dans ses méta et place `{{formulaire_avis}}` sous le titre de sa section `#avis` : bloc `src/blocs/formulaire-avis.html`, script `assets/avis.js`. Pages en `noindex`, hors sitemap.
- Les événements sont listés dans `data/evenements.json` (nom, date, lieu, titre de l'intervention, page, signature par défaut d'une citation, label Ghost, `ouvert`). Nouvel événement : une entrée ici et une page dans `src/pages/`.
- `api/avis.php` (outils communs : `api/lib.php`) : enregistre l'avis dans Supabase (schéma privé `nf`, fonctions `nf_avis_*`), abonne à la newsletter dans Ghost avec le label de l'événement, puis envoie l'avis par e-mail à Nicolas (Brevo). Chaque étape est indépendante ; l'e-mail signale ce qui est à reprendre.
- Tableau de bord privé : `/dashboard/` (mot de passe ; `dashboard/index.php` remplit le gabarit généré depuis `src/pages/dashboard.html`, script `assets/tableau.js`) : indicateurs, mots qui reviennent, puis par conférence sa fiche, ses citations autorisées (carrousel), ses avis heure par heure et l'export CSV.
- Clés, codes et mots de passe vivent uniquement dans `nfrancois-config.php` chez Hostinger, hors du dépôt public. Jamais de secret ni de donnée d'avis dans le dépôt.

## SEO

- URLs finales avec slash (`/interventions/`, `/en/talks/`) partout : canonical, hreflang, og:url, JSON-LD, sitemap, liens internes. Les versions sans slash redirigent en 301.
- `sitemap.xml` est généré par `scripts/build.py` (le `<lastmod>` d'une page passe à la date du jour quand elle change) : ne pas l'éditer à la main.
- `title`, `og:title` et `twitter:title` identiques ; même chose pour les trois descriptions. `&` encodé en `&amp;` dans les attributs.
- JSON-LD des accueils : `@graph` avec la Person `https://nfrancois.fr/#person` et la newsletter (CreativeWorkSeries). Garder FR et EN alignés.
- Images de partage : `images/og-image-fr.jpg` et `images/og-image-en.jpg` (1200×630, JPG). La photo portrait `Nicolas-Francois.webp` reste l'image du hero et du JSON-LD.
- Pas de `twitter:site` (compte X inactif).
- Tenir `llms.txt` à jour avec les pages du site.
- Analytics : Plausible (sans cookies).
