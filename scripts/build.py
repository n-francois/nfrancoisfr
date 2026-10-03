#!/usr/bin/env python3
"""Génère les pages du site (FR et EN) à partir de src/ et data/. Usage : python3 scripts/build.py"""
import html
import json
import re
from datetime import date
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SITE = "https://nfrancois.fr"
NNBSP = "\u202f"  # espace fine insécable

TYPES = {  # ce que Nicolas a fait, avec sa traduction anglaise
    "Conférence": "Talk",
    "Table ronde": "Panel",
    "Atelier": "Workshop",
    "Webinaire": "Webinar",
    "Cours": "Course",
    "Interview": "Interview",
    "Interview sur scène": "On-stage interview",
    "Podcast": "Podcast",
    "Article": "Article",
    "Citation": "Mention",
    "Étude": "Study",
    "Livre blanc": "White paper",
}

# Libellés propres à chaque langue. Les noms propres français restent tels quels en anglais.
LANGUES = {
    "fr": {
        "og_locale": "fr_FR",
        "og_image": "og-image-fr.jpg",
        "og_alt": "Nicolas François, stratégie IA et data pour le tourisme",
        "skip": "Aller au contenu",
        "nav_label": "Navigation principale",
        "fermer": "Fermer",
        "onglet": " (nouvel onglet)",
        "email": "bonjour@nfrancois.fr",
        "accueil": "/",
        "accueil_lib": "Accueil",
        "nav": [("Interventions", "/interventions/"), ("À propos", "/a-propos/")],
        "contact": "/#contact",  # le bloc e-mail en bas de l'accueil
        "legal": ("Mentions légales", "/mentions-legales/"),
        "autre": ("English", "en"),
        "mois": ["janv.", "févr.", "mars", "avr.", "mai", "juin", "juil.", "août", "sept.", "oct.", "nov.", "déc."],
        "types": {t: t for t in TYPES},
        "accreditations": "Accréditations média",
    },
    "en": {
        "og_locale": "en_US",
        "og_image": "og-image-en.jpg",
        "og_alt": "Nicolas François, AI and data strategy for tourism",
        "skip": "Skip to content",
        "nav_label": "Main navigation",
        "fermer": "Close",
        "onglet": " (opens in a new tab)",
        "email": "hello@nfrancois.fr",
        "accueil": "/en/",
        "accueil_lib": "Home",
        "nav": [("Talks", "/en/talks/"), ("About", "/en/about/")],
        "contact": "/en/#contact",
        "legal": ("Legal notice", "/en/legal-notice/"),
        "autre": ("Français", "fr"),
        "mois": ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"],
        "types": TYPES,
        "accreditations": "Media accreditations",
    },
}

FLECHE = '<svg class="nf-fiche__arrow" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6"/></svg>'
FLECHE_EXT = '<svg class="nf-fiche__arrow" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 18 18 6M8 6h10v10"/></svg>'
# Tampon NF dessiné comme une ligne d'encre : le N, la barre du F, puis le point (animé en CSS, voir site.css)
TRAIT_NF = "M32.8,332.5 V72 C32.8,47 50,33.3 70,33.3 C89,33.3 104,45 109.3,63.4 L180.7,303.5 C186,322 202,332.7 221.5,332.7 C241,332.7 257.9,317 257.9,294 V88 C257.9,57.5 282,33 312,33 H398.4"
def tampon(classe=""):
    return (f'<svg class="tampon{(" " + classe) if classe else ""}" viewBox="0 0 431 366" aria-hidden="true" focusable="false">'
            f'<path class="tampon__trait" pathLength="1" d="{TRAIT_NF}"/><circle class="tampon__point" cx="352.9" cy="188.6" r="33"/></svg>')
# Menu mobile : trois points (le point du tampon NF) pour ouvrir, une croix pour fermer
POINTS = ('<svg class="nav__picto" viewBox="0 0 24 24" aria-hidden="true">'
          '<circle cx="5" cy="12" r="2.2"/><circle cx="12" cy="12" r="2.2"/><circle cx="19" cy="12" r="2.2"/></svg>')
CROIX = '<svg class="nav__picto nav__picto--trait" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>'


def fr(text):
    """Échappe pour le HTML puis applique la typographie française de la charte."""
    t = html.escape(text, quote=False).replace("'", "’")
    t = re.sub(r" ([:;?!%»])", NNBSP + r"\1", t)
    t = t.replace("« ", "«" + NNBSP)
    return t


def en(text):
    """Échappe pour le HTML ; en anglais, seule l'apostrophe devient typographique."""
    return html.escape(text, quote=False).replace("'", "’")


def typo(text, lang):
    return fr(text) if lang == "fr" else en(text)


def attr(text):
    return html.escape(text, quote=True)


def tr(item, cle, lang):
    """Valeur d'un champ dans la langue voulue : l'objet « en » remplace les champs traduits."""
    if lang == "en" and cle in item.get("en", {}):
        return item["en"][cle]
    return item.get(cle)


def date_courte(item, lang):
    if tr(item, "date_affichee", lang):
        return tr(item, "date_affichee", lang)
    parts = item["date"].split("-")
    return parts[0] if len(parts) == 1 else f'{LANGUES[lang]["mois"][int(parts[1]) - 1]} {parts[0]}'


def cle_date(item):
    parts = item["date"].split("-")
    return parts[0] + "-" + (parts[1] if len(parts) > 1 else "00")


def est_externe(url):
    return url.startswith("http") or url.endswith(".pdf")


def ligne(quand, nature, titre, meta, lien, lang):
    """Une ligne de liste : date et nature en texte dans la première colonne, titre et détail, flèche si lien."""
    inner = (
        f'<span class="fiche__quand"><span class="nf-fiche__date">{typo(quand, lang)}</span>'
        f'<span class="fiche__type">{typo(nature, lang)}</span></span>'
        f'<span><p class="nf-fiche__title">{typo(titre, lang)}</p><p class="nf-fiche__meta">{typo(meta, lang)}</p></span>'
    )
    li = "<li>"
    if not lien:
        return f'{li}<div class="nf-fiche nf-fiche--statique">{inner}<span></span></div></li>'
    if est_externe(lien):
        return (f'{li}<a class="nf-fiche" href="{attr(lien)}" target="_blank" rel="noopener">{inner}'
                f'<span class="sr-only">{LANGUES[lang]["onglet"]}</span>{FLECHE_EXT}</a></li>')
    return f'{li}<a class="nf-fiche" href="{attr(lien)}">{inner}{FLECHE}</a></li>'


def fiche(item, lang):
    if item["type"] not in TYPES:
        raise SystemExit(f'{item["id"]} : type inconnu « {item["type"]} » (types possibles : {", ".join(TYPES)})')
    # Qui : l'organisateur d'une prise de parole, le média d'un passage, l'éditeur d'une publication
    qui = tr(item, "organisateur", lang) or tr(item, "media", lang) or tr(item, "editeur", lang)
    meta = " · ".join(x for x in [qui, tr(item, "ville", lang), tr(item, "precision", lang)] if x)
    return ligne(date_courte(item, lang), LANGUES[lang]["types"][item["type"]], tr(item, "titre", lang), meta,
                 item.get("lien", ""), lang)


def toutes_interventions(d):
    """Prises de parole, médias et publications ensemble, de la plus récente à la plus ancienne."""
    return sorted(d["prises_de_parole"] + d["medias"] + d["publications"], key=cle_date, reverse=True)


def par_annee(items, lang):
    """Un bloc par année, du plus récent au plus ancien."""
    blocs_html = []
    for annee in sorted({i["date"][:4] for i in items}, reverse=True):
        lot = [i for i in items if i["date"].startswith(annee)]
        blocs_html.append(
            f'<section class="nf-section" aria-labelledby="annee-{annee}">'
            f'<div class="nf-section__head"><h2 class="nf-section__name" id="annee-{annee}">{annee}</h2></div>'
            '<ul class="nf-fiches">' + "".join(fiche(i, lang) for i in lot) + "</ul></section>"
        )
    return "".join(blocs_html)


def reconnaissances(d, lang):
    cols = []
    for r in d["reconnaissances"]:
        meta = " · ".join(tr(r, k, lang) for k in ("nature", "organisme", "date"))
        cols.append(f'<div class="nf-col"><h3 class="nf-col__title">{typo(tr(r, "titre", lang), lang)}</h3>'
                    f'<p class="nf-col__meta">{typo(meta, lang)}</p></div>')
    acc = " · ".join(f'{a["evenement"]}, {tr(a, "lieu", lang)}, {tr(a, "annees", lang)}' for a in d["accreditations"])
    cols.append(f'<div class="nf-col"><h3 class="nf-col__title">{LANGUES[lang]["accreditations"]}</h3>'
                f'<p class="nf-col__meta">{typo(acc, lang)}</p></div>')
    return '<div class="nf-cols">' + "".join(cols) + "</div>"


def prose_item(nom, lang):
    # Le premier mot porte la pastille sur petit écran, pour qu'elle ne s'en sépare jamais (cf. site.css)
    tete, _, reste = typo(nom, lang).partition(" ")
    reste = f" {reste}" if reste else ""
    return f'<span class="nf-prose__item"><span class="prose__tete">{tete}</span>{reste}</span>'


def galerie(d, lang):
    """Petite galerie de photos sur scène : légende « événement année © crédit », sans séparateur."""
    items = []
    for g in d.get("galerie", []):
        legende = f'{tr(g, "evenement", lang)} {g["annee"]}' + (f' © {g["credit"]}' if g["credit"] else "")
        style = f' style="object-position: {g["position"]}"' if g.get("position") else ""
        items.append(f'<li><figure><img src="/images/galerie/{g["image"]}" alt="{attr(tr(g, "alt", lang))}" width="{g["largeur"]}" '
                     f'height="{g["hauteur"]}" loading="lazy"{style}><figcaption class="nf-label">{typo(legende, lang)}</figcaption></figure></li>')
    return '<ul class="galerie">' + "".join(items) + "</ul>"


def blocs(d, lang):
    items = toutes_interventions(d)
    accueil = [i for i in items if i.get("accueil")][:3]
    return {
        "prose_accueil": '<p class="nf-prose">' + " ".join(prose_item(n, lang) for n in d["prose_accueil"]) + "</p>",
        "fiches_accueil": '<ul class="nf-fiches">' + "".join(fiche(i, lang) for i in accueil) + "</ul>",
        "interventions_par_annee": par_annee(items, lang),
        "logo_iattc": re.sub(r"<!--.*?-->\s*", "", (ROOT / "src" / "logo-iattc.svg").read_text(encoding="utf-8"), flags=re.S).strip(),
        "reconnaissances": reconnaissances(d, lang),
        "galerie": galerie(d, lang),
    }


def nav(path, lang):
    L = LANGUES[lang]
    courant = ' aria-current="page"'
    liens = "".join(f'<li><a href="{h}"{courant if path == h else ""}>{l}</a></li>' for l, h in L["nav"])
    contact = (f'<li><a class="nf-btn nf-btn--primaire nf-btn--petit" href="{L["contact"]}"'
               f'{courant if path == L["contact"] else ""}><span class="nf-btn__lbl">Contact</span></a></li>')
    panneau = "".join(f'<li><a href="{h}"{courant if path == h else ""}><span>{l}</span></a></li>'
                      for l, h in [(L["accueil_lib"], L["accueil"])] + L["nav"] + [("Contact", L["contact"])])
    return (
        f'<nav class="nf-nav" aria-label="{L["nav_label"]}">'
        f'<a class="nf-nav__id" href="{L["accueil"]}">' + tampon() +
        '<span class="nav__nom">Nicolas François</span></a>'
        '<div class="nav__droite"><ul class="nf-nav__links">' + liens + contact + "</ul>"
        '<details class="nav__menu"><summary class="nf-nav__menu nf-btn nf-btn--secondaire nf-btn--petit nav__bouton">'
        f'<span class="nav__ouvrir"><span class="sr-only">Menu</span>{POINTS}</span>'
        f'<span class="nav__fermer"><span class="sr-only">{L["fermer"]}</span>{CROIX}</span></summary>'
        '<div class="nav__panneau"><ul>' + panneau + "</ul></div></details></div></nav>"
    )


def footer(lang, autre_path, signature=True):
    L = LANGUES[lang]
    libelle, autre_lang = L["autre"]
    return (
        '<footer class="nf-footer">\n<div class="nf-page nf-footer__cadre">\n  <div class="nf-footer__top">\n    <div class="nf-footer__cols">\n'
        f'      <a href="mailto:{L["email"]}">{L["email"]}</a>\n'
        '      <a href="https://www.linkedin.com/in/n-francois/" rel="me">LinkedIn</a>\n'
        '      <a href="https://www.iatechtravel.cafe">IA, Tech &amp; Travel Café</a>\n'
        '    </div>\n    <div class="nf-footer__cols">\n'
        f'      <a href="{L["legal"][1]}">{L["legal"][0]}</a>\n'
        f'      <a href="{autre_path}" hreflang="{autre_lang}" lang="{autre_lang}">{libelle}</a>\n'
        f'      <span>© {date.today().year}</span>\n'
        '    </div>\n  </div>\n'
        # Le tampon signe la page, tracé quand le pied de page arrive à l'écran (site.js) ; pas sur une page qui l'a déjà en grand
        + (f'  {tampon("tampon--pied")}\n' if signature else "")
        + '</div>\n</footer>'
    )


TYPES_SCHEMA = {"Podcast": "PodcastEpisode", "Étude": "Report", "Livre blanc": "Report"}  # sinon Article


def url_absolue(lien):
    return SITE + lien if lien.startswith("/") else lien


def oeuvre(item):
    """Un article, podcast ou rapport du fichier de données, décrit pour schema.org. Ces contenus sont en français :
    on garde leur vrai titre, même sur la version anglaise."""
    o = {"@type": TYPES_SCHEMA.get(item["type"], "Article"), "name": item["titre"].replace("'", "’"),
         "url": url_absolue(item["lien"]), "datePublished": item["date"], "inLanguage": "fr"}
    qui = item.get("media") or item.get("editeur")
    if qui:
        o["publisher"] = {"@type": "Organization", "name": qui.replace("'", "’")}
    return o


def graphe_personne(d, lang):
    """Graphe de l'accueil : la Person, le site et la newsletter (src/jsonld-person*.json), complétés par les données.
    subjectOf : les interviews, podcasts et articles qui parlent de Nicolas ; les études et articles qu'il a écrits
    ou auxquels il a contribué deviennent des nœuds à part, reliés à lui."""
    fichier = "jsonld-person.json" if lang == "fr" else "jsonld-person-en.json"
    graphe = json.loads((ROOT / "src" / fichier).read_text(encoding="utf-8"))
    personne = graphe["@graph"][0]
    a_propos, siennes = [], []
    for item in d["prises_de_parole"] + d["medias"] + d["publications"]:
        if not item.get("lien", "").startswith(("http", "/")):
            continue
        if item["type"] in ("Article", "Étude", "Livre blanc") and item in d["publications"]:
            o = oeuvre(item)
            o["author" if item["type"] == "Article" else "contributor"] = {"@id": SITE + "/#person"}
            siennes.append(o)
        else:
            a_propos.append(oeuvre(item))
    personne["subjectOf"] = a_propos
    graphe["@graph"] += siennes
    return graphe


def jsonld(meta, path, lang, d):
    """Bloc <script> JSON-LD de la page, ou rien (page 404)."""
    if meta.get("jsonld") == "aucun":
        return ""
    if meta.get("jsonld") == "person":
        data = graphe_personne(d, lang)
    else:
        data = page_jsonld(meta, path, lang, d)
    corps = json.dumps(data, ensure_ascii=False, indent=2)
    return f'<script type="application/ld+json">\n{corps}\n  </script>'


def page_jsonld(meta, path, lang, d):
    accueil = SITE + LANGUES[lang]["accueil"]
    crumbs = [{"@type": "ListItem", "position": 1, "name": "Nicolas François", "item": accueil}]
    if path != LANGUES[lang]["accueil"]:
        crumbs.append({"@type": "ListItem", "position": 2, "name": meta["nav_titre"], "item": SITE + path})
    data = {
        "@context": "https://schema.org",
        "@type": meta.get("schema", "WebPage"),
        "name": meta["title"],
        "description": meta["description"],
        "url": SITE + path,
        "inLanguage": lang,
        "author": {"@id": SITE + "/#person"},
        "breadcrumb": {"@type": "BreadcrumbList", "itemListElement": crumbs},
    }
    if data["@type"] == "ProfilePage":  # À propos : la page décrit Nicolas, on y reprend sa fiche complète
        data["mainEntity"] = graphe_personne(d, lang)["@graph"][0]
    return data


def paire(meta, path, lang):
    """Chemins FR et EN d'une même page (None si la page n'existe que dans une langue)."""
    autre = meta.get("en") if lang == "fr" else meta.get("fr")
    if not autre:
        return None
    return {"fr": path, "en": autre} if lang == "fr" else {"fr": autre, "en": path}


def alternates(meta, path, lang):
    p = paire(meta, path, lang)
    if not p:
        return ""
    autre_locale = LANGUES["en" if lang == "fr" else "fr"]["og_locale"]
    return "\n  ".join([
        f'<link rel="alternate" hreflang="fr" href="{SITE}{p["fr"]}">',
        f'<link rel="alternate" hreflang="en" href="{SITE}{p["en"]}">',
        f'<link rel="alternate" hreflang="x-default" href="{SITE}{p["fr"]}">',
        f'<meta property="og:locale:alternate" content="{autre_locale}">',
    ])


def sitemap(pages):
    """sitemap.xml des pages indexables. lastmod : la date du jour si la page a changé à ce build, sinon celle d'avant."""
    fichier = ROOT / "sitemap.xml"
    avant = dict(re.findall(r"<loc>(.*?)</loc>\s*<lastmod>(.*?)</lastmod>", fichier.read_text(encoding="utf-8"))) if fichier.exists() else {}
    blocs_xml = []
    for path, lang, p, change in sorted(pages, key=lambda x: (x[1] != "fr", x[0])):
        loc = SITE + path
        lastmod = date.today().isoformat() if change or loc not in avant else avant[loc]
        lignes = [f"    <loc>{loc}</loc>", f"    <lastmod>{lastmod}</lastmod>"]
        if p:
            lignes += [f'    <xhtml:link rel="alternate" hreflang="{h}" href="{SITE}{p[c]}"/>'
                       for h, c in (("fr", "fr"), ("en", "en"), ("x-default", "fr"))]
        blocs_xml.append("  <url>\n" + "\n".join(lignes) + "\n  </url>")
    fichier.write_text('<?xml version="1.0" encoding="UTF-8"?>\n'
                       '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"\n'
                       '        xmlns:xhtml="http://www.w3.org/1999/xhtml">\n' + "\n".join(blocs_xml) + "\n</urlset>\n",
                       encoding="utf-8")
    print("  sitemap.xml")


def build():
    data = json.loads((ROOT / "data" / "interventions.json").read_text(encoding="utf-8"))
    generes = {lang: blocs(data, lang) for lang in LANGUES}
    layout = (ROOT / "src" / "layout.html").read_text(encoding="utf-8")
    indexables = []
    for src in sorted((ROOT / "src" / "pages").rglob("*.html")):
        raw = src.read_text(encoding="utf-8")
        m = re.match(r"\s*<!--(.*?)-->\s*", raw, re.S)
        meta = json.loads(m.group(1))
        lang = meta.get("lang", "fr")
        L = LANGUES[lang]
        contenu = raw[m.end():]
        for cle, valeur in generes[lang].items():
            contenu = contenu.replace("{{" + cle + "}}", valeur)
        path = meta["path"]
        p = paire(meta, path, lang)
        autre_path = p["en" if lang == "fr" else "fr"] if p else LANGUES["en" if lang == "fr" else "fr"]["accueil"]
        page = layout
        remplacements = {
            "lang": lang,
            "title": attr(meta["title"]),
            "description": attr(meta["description"]),
            "canonical": SITE + path,
            "robots": meta.get("robots", "index, follow"),
            "alternates": alternates(meta, path, lang),
            "og_image": f'{SITE}/images/{L["og_image"]}',
            "og_alt": attr(L["og_alt"]),
            "og_locale": L["og_locale"],
            "jsonld": jsonld(meta, path, lang, data),
            "skip": L["skip"],
            "nav": nav(path, lang),
            "contenu": contenu.strip(),
            "footer": footer(lang, autre_path, signature="tampon--lent" not in contenu),
        }
        for cle, valeur in remplacements.items():
            page = page.replace("{{" + cle + "}}", valeur)
        reste = re.findall(r"\{\{[a-z_]+\}\}", page)
        if reste:
            raise SystemExit(f"{src.name} : balises non remplacées {sorted(set(reste))}")
        out = ROOT / meta["out"]
        change = not out.exists() or out.read_text(encoding="utf-8") != page
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(page, encoding="utf-8")
        print(f"  {meta['out']}")
        if "noindex" not in remplacements["robots"]:
            indexables.append((path, lang, p, change))
    sitemap(indexables)


if __name__ == "__main__":
    build()
