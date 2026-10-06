/* Tableau de bord des avis (tableau-de-bord/index.php) : carrousel des citations autorisées et copie d'une citation */
(function () {
  document.querySelectorAll('.tdb__carrousel').forEach(function (bloc) {
    var liste = bloc.querySelector('.tdb__citations'), compteur = bloc.querySelector('.tdb__compteur');
    var cartes = liste.querySelectorAll('.tdb__citation');
    var courante = function () {
      var gauche = liste.getBoundingClientRect().left, rang = 0;
      cartes.forEach(function (c, i) { if (Math.abs(c.getBoundingClientRect().left - gauche) < Math.abs(cartes[rang].getBoundingClientRect().left - gauche)) rang = i; });
      return rang;
    };
    var aller = function (rang) {
      rang = Math.max(0, Math.min(cartes.length - 1, rang));
      liste.scrollTo({ left: cartes[rang].offsetLeft - liste.offsetLeft, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    };
    bloc.querySelectorAll('[data-sens]').forEach(function (b) {
      b.addEventListener('click', function () { aller(courante() + Number(b.getAttribute('data-sens'))); });
    });
    var attente;
    liste.addEventListener('scroll', function () {
      clearTimeout(attente);
      attente = setTimeout(function () {
        // au bout du défilement, la dernière citation est visible même si elle ne touche pas le bord gauche
        var fin = liste.scrollLeft + liste.clientWidth >= liste.scrollWidth - 4;
        if (compteur) compteur.textContent = (fin ? cartes.length : courante() + 1) + ' / ' + cartes.length;
      }, 80);
    });
    liste.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') { e.preventDefault(); aller(courante() + (e.key === 'ArrowRight' ? 1 : -1)); }
    });
  });
  document.querySelectorAll('[data-copier]').forEach(function (b) {
    b.addEventListener('click', function () {
      var fait = function () { var o = b.textContent; b.textContent = 'Copiée'; setTimeout(function () { b.textContent = o; }, 1500); };
      if (navigator.clipboard) navigator.clipboard.writeText(b.getAttribute('data-copier')).then(fait, fait); else fait();
    });
  });
})();
