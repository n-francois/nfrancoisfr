/* nfrancois.fr · menu mobile. Sans dépendance. */
(function () {
  /* Menu mobile : se referme avec Échap ou au choix d'un lien */
  var menu = document.querySelector('.nav__menu');
  if (menu) {
    menu.addEventListener('click', function (e) { if (e.target.closest('.nav__panneau a')) menu.open = false; });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && menu.open) { menu.open = false; menu.querySelector('summary').focus(); }
    });
  }
})();
