/* nfrancois.fr · déclencheur des animations (1 Ko, sans dépendance)
   Ajoute .nf-motion sur <html>, puis .is-in une seule fois quand un élément entre à l'écran.
   Sans JavaScript ou avec « réduire les animations », tout reste affiché normalement. */
(function () {
  var reduit = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduit || !('IntersectionObserver' in window)) return;
  document.documentElement.classList.add('nf-motion');

  var cibles = document.querySelectorAll('.nf-hl--trace, .nf-bars, .nf-reveal');
  var obs = new IntersectionObserver(function (entrees) {
    entrees.forEach(function (e) {
      if (e.isIntersecting) { e.target.classList.add('is-in'); obs.unobserve(e.target); }
    });
  }, { rootMargin: '0px 0px -12% 0px', threshold: 0.2 });
  cibles.forEach(function (el) { obs.observe(el); });

  /* Pied de page : on surveille le bloc jaune entier (il entre toujours à l'écran,
     même tout en bas de la page) et on fait monter le nom dès qu'il apparaît. */
  var obsPied = new IntersectionObserver(function (entrees) {
    entrees.forEach(function (e) {
      if (!e.isIntersecting) return;
      var nom = e.target.querySelector('.nf-footer__big');
      if (nom) nom.classList.add('is-in');
      obsPied.unobserve(e.target);
    });
  }, { threshold: 0 });
  document.querySelectorAll('.nf-footer').forEach(function (el) { obsPied.observe(el); });

  /* Compteur : uniquement pour les chiffres du bandeau de preuve (.nf-preuve__n).
     La valeur finale reste dans le HTML (référencement, lecteurs d'écran, capture) ;
     seule une copie visible défile, une fois, en 1,1 s, avec ralentissement final. */
  var fmt = function (n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '\u202F'); };
  var compteurs = document.querySelectorAll('.nf-preuve__n');
  var obsC = new IntersectionObserver(function (entrees) {
    entrees.forEach(function (e) {
      if (!e.isIntersecting) return;
      obsC.unobserve(e.target);
      var el = e.target, txt = el.textContent.trim();
      var m = txt.match(/^([^0-9]*)([0-9][0-9\s\u202F\u00A0]*)(.*)$/);
      if (!m) return;
      var cible = parseInt(m[2].replace(/\D/g, ''), 10), avant = m[1], apres = m[3];
      if (!cible) return;
      el.setAttribute('aria-label', txt);
      var vu = document.createElement('span'); vu.setAttribute('aria-hidden', 'true');
      el.textContent = ''; el.appendChild(vu);
      var t0 = null, duree = 1100;
      function pas(t) {
        if (!t0) t0 = t;
        var k = Math.min(1, (t - t0) / duree), e3 = 1 - Math.pow(1 - k, 3);
        vu.textContent = avant + fmt(Math.round(cible * e3)) + apres;
        if (k < 1) requestAnimationFrame(pas); else { el.textContent = txt; el.removeAttribute('aria-label'); }
      }
      requestAnimationFrame(pas);
    });
  }, { threshold: 0.6 });
  compteurs.forEach(function (el) { obsC.observe(el); });

  document.querySelectorAll('img[loading="lazy"]').forEach(function (img) {
    if (img.complete) { img.classList.add('is-loaded'); return; }
    img.addEventListener('load', function () { img.classList.add('is-loaded'); }, { once: true });
  });
})();
