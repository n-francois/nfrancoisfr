/* nfrancois.fr · menu mobile et mesure des clics sur l'e-mail. Sans dépendance. */
(function () {
  /* Plausible : un clic sur une adresse e-mail compte comme objectif « Clic e-mail » (à déclarer dans Plausible) */
  document.addEventListener('click', function (e) {
    if (e.target.closest('a[href^="mailto:"]') && window.plausible) window.plausible('Clic e-mail');
  });

  /* Menu mobile : s'ouvre en glissant depuis la droite (CSS) ; à la fermeture, on joue le mouvement inverse
     avant de refermer vraiment. Sans JavaScript, le menu s'ouvre et se ferme simplement. */
  var menu = document.querySelector('.nav__menu');
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
  /* Choisir un lien referme le menu (utile pour l'ancre #contact de l'accueil) */
  panneau.addEventListener('click', function (e) { if (e.target.closest('a')) fermer(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && menu.open) { fermer(); bouton.focus(); }
  });
})();
