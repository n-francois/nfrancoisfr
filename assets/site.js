/* nfrancois.fr · en-tête qui se cache au défilement, menu mobile et mesure des clics sur l'e-mail. Sans dépendance. */
(function () {
  /* Plausible : un clic sur une adresse e-mail compte comme objectif « Clic e-mail » (à déclarer dans Plausible) */
  document.addEventListener('click', function (e) {
    if (e.target.closest('a[href^="mailto:"]') && window.plausible) window.plausible('Clic e-mail');
  });

  var entete = document.querySelector('.entete');
  var menu = document.querySelector('.nav__menu');

  /* En-tête : il disparaît quand on descend et revient dès qu'on remonte, pour retrouver vite le tampon et la
     navigation. Il reste affiché près du haut de la page et tant que le menu est ouvert. */
  if (entete) {
    var dernier = window.scrollY;
    var attente = false;
    var SEUIL = 8;
    function majEntete() {
      attente = false;
      var y = window.scrollY;
      var delta = y - dernier;
      var enBas = y + window.innerHeight >= document.documentElement.scrollHeight - 2;
      if ((menu && menu.open) || y < 120) {
        entete.classList.remove('is-cachee');
      } else if (delta > SEUIL) {
        entete.classList.add('is-cachee');
      } else if (delta < -SEUIL && !enBas) {
        /* (en bas de page, le rebond du défilement sur iPhone ne doit pas faire réapparaître l'en-tête) */
        entete.classList.remove('is-cachee');
      }
      if (Math.abs(delta) > SEUIL) dernier = y;
    }
    window.addEventListener('scroll', function () {
      if (!attente) { attente = true; window.requestAnimationFrame(majEntete); }
    }, { passive: true });
    /* Au clavier, arriver sur un lien de l'en-tête le réaffiche */
    entete.addEventListener('focusin', function () { entete.classList.remove('is-cachee'); });
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
  /* Choisir un lien du menu ou de la barre (tampon, « Contact ») referme le menu, utile pour l'ancre #contact */
  (menu.closest('.nf-nav') || panneau).addEventListener('click', function (e) { if (e.target.closest('a')) fermer(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && menu.open) { fermer(); bouton.focus(); }
  });
})();
