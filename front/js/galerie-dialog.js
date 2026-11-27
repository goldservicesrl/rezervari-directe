// Galeria pozelor, fereastra: importată de galerie.js la primul clic pe o poză (legătura .pg-poza-deschide din img()).
// Poza se arată mare peste pagină, într-un
// <dialog>, cu poza anterioară și următoare dintre pozele deschizibile ale paginii, săgețile tastaturii, glisarea pe telefon, Esc și butonul de
// închidere. Fără stiluri în linie (CSP): clasele cz-galerie-* din galerie.css, adusă de galerie.js. Textele, în limba paginii
// (ro, altfel en).
const TEXTE = {
  ro: { inchide: 'Închideți', inainte: 'Poza anterioară', dupa: 'Poza următoare', din: 'din', galerie: 'Fotografii' },
  en: { inchide: 'Close', inainte: 'Previous photo', dupa: 'Next photo', din: 'of', galerie: 'Photos' },
};
const t = TEXTE[(document.documentElement.lang || 'ro').slice(0, 2)] || TEXTE.en;
const SVG = 'http://www.w3.org/2000/svg';

const el = (tag, cls, text) => {
  const e = document.createElement(tag);
  if (cls) e.className = cls;
  if (text !== undefined) e.textContent = text;
  return e;
};
const pictograma = (d) => {
  const s = document.createElementNS(SVG, 'svg');
  s.setAttribute('viewBox', '0 0 24 24');
  s.setAttribute('aria-hidden', 'true');
  s.setAttribute('focusable', 'false');
  const p = document.createElementNS(SVG, 'path');
  p.setAttribute('d', d);
  s.appendChild(p);
  return s;
};
const buton = (cls, eticheta, d) => {
  const b = el('button', 'cz-galerie-buton ' + cls);
  b.type = 'button';
  b.setAttribute('aria-label', eticheta);
  b.appendChild(pictograma(d));
  return b;
};

let dialog = null;
let poza = null;
let legenda = null;
let numar = null;
let lista = [];
let pozitie = 0;

function construieste() {
  dialog = el('dialog', 'cz-galerie');
  dialog.setAttribute('aria-label', t.galerie);
  const inchide = buton('cz-galerie-inchide', t.inchide, 'M 6 6 L 18 18 M 18 6 L 6 18');
  const inainte = buton('cz-galerie-inainte', t.inainte, 'M 15 5 L 8 12 L 15 19');
  const dupa = buton('cz-galerie-dupa', t.dupa, 'M 9 5 L 16 12 L 9 19');
  const scena = el('figure', 'cz-galerie-scena');
  poza = el('img', 'cz-galerie-poza');
  poza.decoding = 'async';
  poza.draggable = false;
  legenda = el('figcaption', 'cz-galerie-legenda');
  numar = el('p', 'cz-galerie-numar');
  numar.setAttribute('aria-live', 'polite');
  scena.append(poza, legenda);
  dialog.append(scena, inainte, dupa, numar, inchide);
  document.body.appendChild(dialog);

  inchide.addEventListener('click', () => dialog.close());
  inainte.addEventListener('click', () => arata(pozitie - 1));
  dupa.addEventListener('click', () => arata(pozitie + 1));
  // Dublu-clicul (săgețile apăsate repede) nu selectează nimic.
  dialog.addEventListener('mousedown', (e) => { if (e.detail > 1) e.preventDefault(); });
  // Un clic pe fundal (în afara pozei și a butoanelor) închide galeria.
  dialog.addEventListener('click', (e) => { if (e.target === dialog || e.target === scena) dialog.close(); });
  dialog.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowLeft') { e.preventDefault(); arata(pozitie - 1); }
    if (e.key === 'ArrowRight') { e.preventDefault(); arata(pozitie + 1); }
  });
  // Glisarea pe telefon: peste 50 px pe orizontală, mai mult decât pe verticală.
  let x0 = null;
  let y0 = null;
  dialog.addEventListener('touchstart', (e) => { x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; }, { passive: true });
  dialog.addEventListener('touchend', (e) => {
    if (x0 === null) return;
    const dx = e.changedTouches[0].clientX - x0;
    const dy = e.changedTouches[0].clientY - y0;
    x0 = null;
    if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) arata(pozitie + (dx < 0 ? 1 : -1));
  }, { passive: true });
  dialog.addEventListener('close', () => {
    document.documentElement.classList.remove('cz-galerie-deschisa');
    const inapoi = lista[pozitie];
    if (inapoi) inapoi.focus({ preventScroll: true });
  });
}

function arata(i) {
  if (!lista.length) return;
  pozitie = (i + lista.length) % lista.length;
  const a = lista[pozitie];
  const img = a.querySelector('img');
  const fig = a.closest('figure');
  const cap = fig ? fig.querySelector('figcaption') : null;
  poza.src = a.getAttribute('href');
  poza.alt = img ? img.alt : '';
  legenda.textContent = cap ? cap.textContent.trim() : '';
  legenda.hidden = legenda.textContent === '';
  numar.textContent = lista.length > 1 ? (pozitie + 1) + ' ' + t.din + ' ' + lista.length : '';
  dialog.classList.toggle('cz-galerie-una', lista.length < 2);
  // Pozele vecine, încărcate dinainte: trecerea la ele e imediată.
  [pozitie + 1, pozitie - 1].forEach((j) => {
    const v = lista[(j + lista.length) % lista.length];
    if (v) { const p = new Image(); p.src = v.getAttribute('href'); }
  });
}

/** Deschide galeria pe poza din legătura `a` (chemată de galerie.js la primul clic și la cele următoare). */
export function deschide(a) {
  if (!dialog) construieste();
  // Vecinii: toate pozele deschizibile ale paginii, în ordinea lor, fiecare o singură dată (pe pagina camerei, prima poză și galeria).
  const vazute = new Set();
  lista = Array.from(document.querySelectorAll('a.pg-poza-deschide')).filter((x) => {
    const h = x.getAttribute('href');
    if (vazute.has(h)) return false;
    vazute.add(h);
    return true;
  });
  const i = lista.findIndex((x) => x.getAttribute('href') === a.getAttribute('href'));
  arata(i < 0 ? 0 : i);
  document.documentElement.classList.add('cz-galerie-deschisa');
  dialog.showModal();
}
