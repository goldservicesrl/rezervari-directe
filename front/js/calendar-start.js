// Pornirea calendarului: grila vine de pe server, iar calendar.js (≤ 18 KB gzip) se importă la
// primul focus sau clic în calendar. Până atunci, scriptul acesta (câteva sute de octeți) face doar ce schimbă
// aspectul: arată butoanele lunilor și face focusabilă prima zi liberă, deci importul nu mișcă pagina (CLS).
// Un clic sau o tastă de navigare venite înaintea importului se rețin și se trimit calendarului după definire.
const TASTE = ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End', 'PageUp', 'PageDown', 'Enter', ' '];
let incarcare = null;
const incarca = (adresa) => {
  incarcare ??= import(adresa).then(() => customElements.whenDefined('cazo-calendar'));
  return incarcare;
};

for (const cal of document.querySelectorAll('cazo-calendar[data-modul]')) {
  const zile = [...cal.querySelectorAll('td[data-zi]')];
  const prima = zile.find((z) => z.dataset.stare === 'libera') || zile[0];
  if (prima && !customElements.get('cazo-calendar')) prima.tabIndex = 0;
  for (const b of cal.querySelectorAll('[data-pg-nav]')) b.hidden = false;
  const porneste = () => incarca(cal.dataset.modul);
  const retine = (e) => {
    if (customElements.get('cazo-calendar')) return;
    if (e.type === 'keydown' && !TASTE.includes(e.key)) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    const tinta = e.target;
    const copie = e.type === 'click'
      ? new MouseEvent('click', { bubbles: true, cancelable: true })
      : new KeyboardEvent('keydown', { key: e.key, code: e.code, shiftKey: e.shiftKey, bubbles: true, cancelable: true });
    porneste().then(() => tinta.dispatchEvent(copie));
  };
  cal.addEventListener('focusin', porneste, { once: true });
  cal.addEventListener('pointerdown', porneste, { once: true });
  cal.addEventListener('click', retine, true);
  cal.addEventListener('keydown', retine, true);
}
