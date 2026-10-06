/* Formulaire d'avis des pages d'événement (src/blocs/formulaire-avis.html) : vérifie les réponses, les envoie à
   api/avis.php, puis remplace le formulaire par le remerciement */
(function () {
  var f = document.getElementById('form-avis'); if (!f) return;
  var zone = document.getElementById('avis-message'), bouton = document.getElementById('avis-envoyer');
  var merci = document.getElementById('avis-merci'), blocCitation = document.getElementById('avis-bloc-citation');
  function message(cible, titre, texte, ok) {
    cible.innerHTML = '<div class="nf-msg ' + (ok ? 'nf-msg--succes' : 'nf-msg--erreur') + '"><svg viewBox="0 0 24 24" aria-hidden="true">' +
      (ok ? '<path d="M5 12.5l4.5 4.5L19 7.5"/>' : '<path d="M12 8v5M12 16.5v.5"/><circle cx="12" cy="12" r="9"/>') +
      '</svg><div><p class="nf-msg__title"></p><p class="nf-msg__text"></p></div></div>';
    cible.querySelector('.nf-msg__title').textContent = titre;
    cible.querySelector('.nf-msg__text').textContent = texte;
  }
  // Erreur au plus près du champ : contour rouge, message dessous, la page y remonte
  var champs = { note: ['avis-notes', 'note-erreur', 'note-1'], email: ['champ-email', 'avis-email-erreur', 'avis-email'] };
  function signaler(nom, texte) {
    var c = champs[nom], bloc = document.getElementById(c[0]), msg = document.getElementById(c[1]), cible = document.getElementById(c[2]);
    bloc.classList.add(nom === 'note' ? 'avis__notes--erreur' : 'nf-field--erreur');
    msg.textContent = texte; msg.hidden = false;
    if (nom === 'email') cible.setAttribute('aria-invalid', 'true');
    bloc.scrollIntoView({ block: 'center' }); cible.focus({ preventScroll: true });
  }
  function effacer(nom) {
    var c = champs[nom], bloc = document.getElementById(c[0]), msg = document.getElementById(c[1]);
    bloc.classList.remove('avis__notes--erreur', 'nf-field--erreur'); msg.hidden = true; msg.textContent = '';
    document.getElementById(c[2]).removeAttribute('aria-invalid');
  }
  f.querySelectorAll('input[name=note]').forEach(function (r) { r.addEventListener('change', function () { effacer('note'); }); });
  f.email.addEventListener('input', function () { effacer('email'); });
  f.newsletter.addEventListener('change', function () { if (!f.newsletter.checked) effacer('email'); });
  // La case « citer » n'apparaît qu'une fois le commentaire commencé
  f.commentaire.addEventListener('input', function () { blocCitation.hidden = !f.commentaire.value.trim(); });
  f.addEventListener('submit', function (e) {
    e.preventDefault();
    zone.innerHTML = '';
    var note = (f.querySelector('input[name=note]:checked') || {}).value;
    var commentaire = f.commentaire.value.trim(), citation = f.citation.checked && commentaire !== '';
    var email = f.email.value.trim(), inscription = f.newsletter.checked;
    if (!note) { signaler('note', 'Choisissez une note de 1 à 5.'); return; }
    if (inscription && !email) { signaler('email', 'Indiquez votre e-mail pour recevoir la newsletter.'); return; }
    if (email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) { signaler('email', 'Cette adresse e-mail ne semble pas valide.'); return; }
    bouton.disabled = true; zone.innerHTML = '<p class="note">Envoi en cours…</p>';
    fetch(f.action, { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ evenement: f.evenement.value, note: Number(note), commentaire: commentaire, citation: citation, signature: citation ? f.signature.value.trim() : '',
        email: email, newsletter: inscription, site: f.site.value }) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (res.ok) {
          // Le remerciement remplace le formulaire : l'envoi est confirmé, et on ne renvoie pas son avis par réflexe
          message(merci, 'Merci pour votre avis', !inscription ? 'Il m’aidera à préparer les prochaines interventions.'
            : res.j.newsletter === 'inscrit' ? 'Il est bien arrivé, et vous êtes inscrit à la newsletter.'
            : 'Il est bien arrivé. Je vous inscris à la newsletter dans les prochains jours.', true);
          f.reset(); f.hidden = true; merci.hidden = false;
          merci.scrollIntoView({ block: 'center' }); merci.focus({ preventScroll: true });
        } else message(zone, 'L’envoi n’a pas fonctionné', res.j && res.j.error ? res.j.error : 'Réessayez dans un instant.');
      })
      .catch(function () { message(zone, 'L’envoi n’a pas fonctionné', 'Vérifiez votre connexion et réessayez.'); })
      .finally(function () { bouton.disabled = false; });
  });
})();
