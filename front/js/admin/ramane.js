/* Pagina rămâne unde era după o acțiune din panou (salvare, verificare, căutare): înainte de trimitere, scriptul ține
   minte formularul și locul din pagină; după încărcare, întoarce pagina acolo și mută mesajul de stare (sau eroarea)
   lângă formularul apăsat. Fără JavaScript, panoul merge la fel, cu mesajul în capul paginii. */
(function () {
  'use strict';
  // Pe telefon, paginile grupului stau pe un rând care se derulează: pagina deschisă se aduce la vedere.
  var curenta = document.querySelector('.pa-submeniu a[aria-current="page"]');
  if (curenta && curenta.parentNode && curenta.parentNode.parentNode) {
    var rand = curenta.parentNode.parentNode;
    if (rand.scrollWidth > rand.clientWidth) {
      rand.scrollLeft = Math.max(0, curenta.parentNode.offsetLeft - 16);
    }
  }
  var CHEIE = 'cazo.panou.ramane';
  var stoc;
  try {
    stoc = window.sessionStorage;
  } catch (e) {
    return;
  }
  if (!stoc) {
    return;
  }
  var principal = document.getElementById('continut');
  if (!principal) {
    return;
  }

  function semn(f) {
    var a = f.querySelector('input[name="actiune"]');
    var s = f.querySelector('input[name="sectiune"]');
    var i = f.querySelector('input[name="id"]');
    return [f.getAttribute('action') || '', a ? a.value : '', s ? s.value : '', i ? i.value : ''].join('|');
  }

  function formulare() {
    return Array.prototype.slice.call(principal.querySelectorAll('form'));
  }

  principal.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (!f || f.nodeName !== 'FORM' || (f.getAttribute('method') || '').toLowerCase() !== 'post') {
      return;
    }
    try {
      stoc.setItem(CHEIE, JSON.stringify({ cale: location.pathname, y: window.pageYOffset, semn: semn(f), pozitie: formulare().indexOf(f), la: Date.now() }));
    } catch (e) { /* stocarea lipsește: pagina se încarcă normal */ }
  });

  var salvat = null;
  try {
    salvat = JSON.parse(stoc.getItem(CHEIE) || 'null');
    stoc.removeItem(CHEIE);
  } catch (e) {
    salvat = null;
  }
  if (!salvat || salvat.cale !== location.pathname || Date.now() - salvat.la > 120000) {
    return;
  }
  var toate = formulare();
  var tinta = null;
  toate.forEach(function (f) {
    if (!tinta && semn(f) === salvat.semn) {
      tinta = f;
    }
  });
  if (!tinta && salvat.pozitie >= 0 && toate[salvat.pozitie] && semn(toate[salvat.pozitie]).split('|')[0] === salvat.semn.split('|')[0]) {
    tinta = toate[salvat.pozitie];
  }
  if (!tinta) {
    return;
  }
  // Formularul ascuns într-un <details> închis: blocul se deschide, ca mesajul să se vadă.
  var d = tinta.closest('details');
  while (d) {
    d.open = true;
    d = d.parentElement ? d.parentElement.closest('details') : null;
  }
  // Mesajul de stare din capul paginii trece după formular (doar cele din capul paginii, puse de _stare.twig).
  var mesaje = Array.prototype.filter.call(principal.querySelectorAll(':scope > .pa-mesaj, :scope > .pa-eroare'), function (m) {
    return !tinta.contains(m);
  });
  var dupa = tinta;
  mesaje.forEach(function (m) {
    m.classList.add('pa-mesaj-langa');
    dupa.parentNode.insertBefore(m, dupa.nextSibling);
    dupa = m;
  });
  if (!location.hash) {
    window.scrollTo(0, salvat.y);
  }
  var vizibil = mesaje.length ? mesaje[mesaje.length - 1] : tinta;
  var r = vizibil.getBoundingClientRect();
  if (r.top < 0 || r.bottom > window.innerHeight) {
    vizibil.scrollIntoView({ block: 'center' });
  }
}());
