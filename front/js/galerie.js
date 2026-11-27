// Pornirea galeriei pozelor: la încărcare doar ascultătorul clicului (câteva sute de octeți); la primul
// clic pe o poză deschizibilă (.pg-poza-deschide) vin galerie.css și fereastra (galerie-dialog.js), ale căror adrese le dă
// eticheta <script> a acestui modul. Fără JavaScript sau fără <dialog>, legătura deschide poza mare ca înainte.
const s = document.querySelector('script[data-galerie-modul]');
let incarcare = null;
const incarca = () => {
  if (!incarcare) {
    const l = document.createElement('link');
    l.rel = 'stylesheet';
    l.href = s.dataset.galerieCss;
    document.head.appendChild(l);
    incarcare = Promise.all([import(s.dataset.galerieModul), new Promise((ok) => { l.onload = ok; l.onerror = ok; })]).then(([m]) => m);
  }
  return incarcare;
};
if (s) {
  document.addEventListener('click', (e) => {
    const a = e.target.closest && e.target.closest('a.pg-poza-deschide');
    if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    if (typeof HTMLDialogElement === 'undefined') return;
    e.preventDefault();
    incarca().then((m) => m.deschide(a), () => { window.location.href = a.href; });
  });
  for (const a of document.querySelectorAll('a.pg-poza-deschide')) a.addEventListener('pointerenter', incarca, { once: true });
}
