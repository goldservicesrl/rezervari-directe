/* Harta de pe „Proprietatea”, primul script al panoului: dalele se așază una lângă alta și alunecă sub un ac fix,
   în mijloc, ca la hărțile obișnuite și ca harta din panoul EasyCheck. Dalele vin tot de la
   site (/admin/harta/z/x/y.png), din cache: CSP-ul panoului rămâne „self”, iar OpenStreetMap nu primește cereri din
   browser. Tragerea completează câmpurile de latitudine și longitudine, salvate apoi cu formularul; coordonatele scrise
   de mână mută harta. Fără JavaScript rămân câmpurile și căutarea după adresă, ca până acum. Fără innerHTML (Trusted Types). */
(function () {
  'use strict';
  var L = 256;
  var Z_MIN = 6;
  var Z_MAX = 18;

  function laPx(lat, lng, z) {
    var scara = L * Math.pow(2, z);
    var latR = Math.max(-85.05112878, Math.min(85.05112878, lat)) * Math.PI / 180;
    return [(lng + 180) / 360 * scara, (1 - Math.log(Math.tan(latR) + 1 / Math.cos(latR)) / Math.PI) / 2 * scara];
  }
  function laGeo(x, y, z) {
    var scara = L * Math.pow(2, z);
    var n = Math.PI - 2 * Math.PI * y / scara;
    var lng = x / scara * 360 - 180;
    lng = ((lng + 180) % 360 + 360) % 360 - 180;          // lumea se închide pe orizontală
    return [Math.atan(Math.sinh(n)) * 180 / Math.PI, lng];
  }
  function numar(v) {
    var x = parseFloat(String(v).replace(',', '.'));
    return isFinite(x) ? x : null;
  }

  function porneste(loc) {
    var inLa = document.getElementById(loc.getAttribute('data-harta-lat') || '');
    var inLo = document.getElementById(loc.getAttribute('data-harta-lng') || '');
    var baza = loc.getAttribute('data-harta-dale');
    if (!inLa || !inLo || !baza) { return; }
    var rezerva = (loc.getAttribute('data-harta-centru') || '45.94,24.97').split(',');
    var c0 = [numar(inLa.value), numar(inLo.value)];
    var arePunct = c0[0] !== null && c0[1] !== null && Math.abs(c0[0]) <= 85 && Math.abs(c0[1]) <= 180;
    var centru = arePunct ? c0 : [numar(rezerva[0]) || 0, numar(rezerva[1]) || 0];
    var z = arePunct ? 17 : Z_MIN;

    var indrumare = document.createElement('p');
    indrumare.className = 'pa-ajutor';
    indrumare.id = 'pa-harta-indrumare';
    indrumare.textContent = loc.getAttribute('data-harta-indrumare') || '';
    var cutie = document.createElement('div');
    cutie.className = 'pa-harta-cutie';
    cutie.tabIndex = 0;
    cutie.setAttribute('role', 'application');
    cutie.setAttribute('aria-label', loc.getAttribute('data-harta-eticheta') || '');
    cutie.setAttribute('aria-describedby', indrumare.id);
    var strat = document.createElement('div');
    strat.className = 'pa-harta-strat';
    var ac = document.createElement('span');
    ac.className = 'pa-harta-ac';
    ac.setAttribute('aria-hidden', 'true');
    var zoom = document.createElement('div');
    zoom.className = 'pa-harta-zoom';
    function buton(text, eticheta, pas) {
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = text;
      b.setAttribute('aria-label', eticheta);
      b.addEventListener('click', function () { apropie(pas); });
      zoom.appendChild(b);
    }
    buton('+', loc.getAttribute('data-harta-apropie') || '+', 1);
    buton('−', loc.getAttribute('data-harta-departeaza') || '-', -1);
    var atribuire = document.createElement('a');
    atribuire.className = 'pa-harta-atribuire';
    atribuire.href = 'https://www.openstreetmap.org/copyright';
    atribuire.rel = 'noopener';
    atribuire.textContent = '© OpenStreetMap';
    cutie.appendChild(strat);
    cutie.appendChild(ac);
    cutie.appendChild(zoom);
    cutie.appendChild(atribuire);
    loc.appendChild(indrumare);
    loc.appendChild(cutie);
    loc.hidden = false;

    /* O dală care nu vine (frâna site-ului, serviciul de hărți ne refuză) aduce avizul, o singură dată. */
    var aviz = null;
    function arataAviz() {
      if (aviz) { return; }
      aviz = document.createElement('p');
      aviz.className = 'pa-harta-aviz';
      aviz.setAttribute('role', 'status');
      aviz.textContent = loc.getAttribute('data-harta-aviz') || '';
      cutie.appendChild(aviz);
    }

    var dale = {};
    function asaza() {
      var w = cutie.clientWidth || 640;
      var h = cutie.clientHeight || 400;
      var c = laPx(centru[0], centru[1], z);
      var x0 = c[0] - w / 2, y0 = c[1] - h / 2;
      var max = Math.pow(2, z);
      var noi = {};
      for (var tx = Math.floor(x0 / L) - 1; tx <= Math.floor((x0 + w) / L) + 1; tx++) {
        for (var ty = Math.floor(y0 / L) - 1; ty <= Math.floor((y0 + h) / L) + 1; ty++) {
          if (ty < 0 || ty >= max) { continue; }
          var cheie = z + '/' + (((tx % max) + max) % max) + '/' + ty;
          var poz = cheie + '@' + tx;                   // aceeași dală poate apărea de două ori, la marginea lumii
          var el = dale[poz];
          if (!el) {
            el = document.createElement('img');
            el.className = 'pa-harta-dala';
            el.alt = '';
            el.draggable = false;
            el.addEventListener('error', arataAviz);
            el.src = baza + cheie + '.png';
            strat.appendChild(el);
          }
          el.style.left = Math.round(tx * L - x0) + 'px';
          el.style.top = Math.round(ty * L - y0) + 'px';
          noi[poz] = el;
        }
      }
      Object.keys(dale).forEach(function (k) { if (!noi[k]) { dale[k].remove(); } });
      dale = noi;
      strat.style.transform = '';
    }

    /* Centrul nou ajunge în câmpuri; câmpurile anunță schimbarea (validarea browserului, alte ascultări). */
    function scrie() {
      inLa.value = centru[0].toFixed(6);
      inLo.value = centru[1].toFixed(6);
      [inLa, inLo].forEach(function (i) { i.dispatchEvent(new Event('input', { bubbles: true })); });
    }
    function muta(dx, dy) {
      var c = laPx(centru[0], centru[1], z);
      centru = laGeo(c[0] - dx, c[1] - dy, z);
      scrie();
      asaza();
    }
    function apropie(pas) {
      var nou = Math.max(Z_MIN, Math.min(Z_MAX, z + pas));
      if (nou === z) { return; }
      z = nou;
      Object.keys(dale).forEach(function (k) { dale[k].remove(); });
      dale = {};
      asaza();
    }

    var apasat = false, xp = 0, yp = 0, dx = 0, dy = 0, tras = false;
    cutie.addEventListener('pointerdown', function (e) {
      if (e.target.closest && e.target.closest('.pa-harta-zoom, .pa-harta-atribuire')) { return; }
      apasat = true; tras = false; dx = 0; dy = 0; xp = e.clientX; yp = e.clientY;
      if (cutie.setPointerCapture) { cutie.setPointerCapture(e.pointerId); }
      cutie.classList.add('pa-harta-trage');
      cutie.focus({ preventScroll: true });
      e.preventDefault();
    });
    cutie.addEventListener('pointermove', function (e) {
      if (!apasat) { return; }
      dx = e.clientX - xp; dy = e.clientY - yp;
      if (Math.abs(dx) > 3 || Math.abs(dy) > 3) { tras = true; }
      strat.style.transform = 'translate3d(' + dx + 'px,' + dy + 'px,0)';
    });
    function ridicat() {
      if (!apasat) { return; }
      apasat = false;
      cutie.classList.remove('pa-harta-trage');
      if (!tras) { strat.style.transform = ''; return; }
      muta(dx, dy);
    }
    cutie.addEventListener('pointerup', ridicat);
    cutie.addEventListener('pointercancel', ridicat);
    cutie.addEventListener('dragstart', function (e) { e.preventDefault(); });
    /* Tastatura: săgețile mută harta cu 64 de puncte, + și - o apropie și o depărtează. */
    cutie.addEventListener('keydown', function (e) {
      var pasi = { ArrowLeft: [64, 0], ArrowRight: [-64, 0], ArrowUp: [0, 64], ArrowDown: [0, -64] };
      if (pasi[e.key]) { muta(pasi[e.key][0], pasi[e.key][1]); e.preventDefault(); }
      else if (e.key === '+' || e.key === '=') { apropie(1); e.preventDefault(); }
      else if (e.key === '-' || e.key === '_') { apropie(-1); e.preventDefault(); }
    });
    /* Coordonatele scrise de mână (sau lipite din aplicația de hărți) aduc harta acolo. */
    [inLa, inLo].forEach(function (i) {
      i.addEventListener('change', function () {
        var la = numar(inLa.value), lo = numar(inLo.value);
        if (la === null || lo === null || Math.abs(la) > 85 || Math.abs(lo) > 180) { return; }
        centru = [la, lo];
        if (z < 15) { z = 17; Object.keys(dale).forEach(function (k) { dale[k].remove(); }); dale = {}; }
        asaza();
      });
    });
    window.addEventListener('resize', asaza);
    asaza();
  }

  document.querySelectorAll('[data-harta-dale]').forEach(porneste);
}());
