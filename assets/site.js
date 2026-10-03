/* nfrancois.fr · en-tête qui se cache au défilement, tampon tracé en pied de page, menu mobile et mesure des clics sur l’e-mail. Sans dépendance. */
(function () {
  /* Plausible : un clic sur une adresse e-mail compte comme objectif « Clic e-mail » (à déclarer dans Plausible) */
  document.addEventListener('click', function (e) {
    if (e.target.closest('a[href^="mailto:"]') && window.plausible) window.plausible('Clic e-mail');
  });

  var entete = document.querySelector('.entete');
  var menu = document.querySelector('.nav__menu');

  /* En-tête : au départ, il défile normalement avec la page. Dès qu'on remonte alors qu'il est sorti de l'écran, il
     revient en glissant depuis le haut ; si on redescend, il repart. Revenu tout en haut de la page, il reprend
     simplement sa place. Il reste affiché tant que le menu est ouvert. */
  if (entete) {
    var dernier = window.scrollY;
    var attente = false;
    var SEUIL = 10;
    function epingler() {
      /* posé hors de l'écran sans animation, puis glissé vers le bas */
      entete.classList.add('sans-transition', 'est-epinglee', 'is-cachee');
      void entete.offsetHeight;
      entete.classList.remove('sans-transition', 'is-cachee');
    }
    function majEntete() {
      attente = false;
      var y = window.scrollY;
      var delta = y - dernier;
      var epinglee = entete.classList.contains('est-epinglee');
      var enBas = y + window.innerHeight >= document.documentElement.scrollHeight - 2;
      if (y <= 0) {
        /* tout en haut : il reprend sa place dans la page, sans finir un éventuel glissement en cours */
        if (epinglee) {
          entete.classList.remove('est-epinglee', 'is-cachee');
          if (entete.getAnimations) entete.getAnimations().forEach(function (a) { a.cancel(); });
        }
      } else if (menu && menu.open) {
        entete.classList.remove('is-cachee');
      } else if (delta < -SEUIL && !enBas) {
        /* on remonte (le rebond du défilement en bas de page, sur iPhone, ne compte pas) */
        if (!epinglee && y > entete.offsetHeight) epingler();
        else entete.classList.remove('is-cachee');
      } else if (delta > SEUIL && epinglee) {
        entete.classList.add('is-cachee');
      }
      if (Math.abs(delta) > SEUIL) dernier = y;
    }
    window.addEventListener('scroll', function () {
      if (!attente) { attente = true; window.requestAnimationFrame(majEntete); }
    }, { passive: true });
    /* Au clavier, arriver sur un lien de l'en-tête caché le fait revenir */
    entete.addEventListener('focusin', function () {
      if (window.scrollY > entete.offsetHeight && !entete.classList.contains('est-epinglee')) epingler();
      else entete.classList.remove('is-cachee');
    });
  }

  /* Tampon du pied de page : il attend, effacé, d'arriver aux trois cinquièmes à l'écran, puis se trace une fois.
     Sans JavaScript ou avec les animations réduites, il est simplement affiché. */
  var pied = document.querySelector('.tampon--pied');
  if (pied && 'IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    pied.classList.add('est-en-attente');
    var observateur = new IntersectionObserver(function (entrees) {
      if (!entrees[0].isIntersecting) return;
      pied.classList.remove('est-en-attente');
      pied.classList.add('est-trace');
      observateur.disconnect();
    }, { threshold: .6 });
    observateur.observe(pied);
  }

  /* Menu mobile : s'ouvre en glissant depuis la droite (CSS) ; à la fermeture, on joue le mouvement inverse
     avant de refermer vraiment. Sans JavaScript, le menu s'ouvre et se ferme simplement. */
  if (!menu) return;
  var bouton = menu.querySelector('summary');
  var panneau = menu.querySelector('.nav__panneau');
  var calme = window.matchMedia('(prefers-reduced-motion: reduce)');

  function fermer() {
    if (!menu.open || menu.classList.contains('is-fermeture')) return;
    if (calme.matches) { menu.open = false; return; }
    menu.classList.add('is-fermeture');
    var fini = false;
    function terminer() {
      if (fini) return;
      fini = true;
      menu.classList.remove('is-fermeture');
      menu.open = false;
    }
    panneau.addEventListener('animationend', terminer, { once: true });
    /* Filet de sécurité si l'animation ne signale pas sa fin (onglet en arrière-plan, par exemple) */
    setTimeout(terminer, 450);
  }

  bouton.addEventListener('click', function (e) {
    if (menu.open) { e.preventDefault(); fermer(); }
  });
  /* Choisir un lien du menu, ou le tampon de la barre, referme le menu, utile pour l'ancre #contact */
  (menu.closest('.nf-nav') || panneau).addEventListener('click', function (e) { if (e.target.closest('a')) fermer(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && menu.open) { fermer(); bouton.focus(); }
  });
})();
