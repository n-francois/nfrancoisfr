/* nfrancois.fr · menu mobile et mesure des clics sur l'e-mail. Sans dépendance. */
(function () {
  /* Plausible : un clic sur une adresse e-mail compte comme objectif « Clic e-mail » (à déclarer dans Plausible) */
  document.addEventListener('click', function (e) {
    if (e.target.closest('a[href^="mailto:"]') && window.plausible) window.plausible('Clic e-mail');
  });

  /* Menu mobile : se referme avec Échap ou au choix d'un lien */
  var menu = document.querySelector('.nav__menu');
  if (menu) {
    menu.addEventListener('click', function (e) { if (e.target.closest('.nav__panneau a')) menu.open = false; });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && menu.open) { menu.open = false; menu.querySelector('summary').focus(); }
    });
  }
})();
