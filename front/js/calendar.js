// <cazo-calendar>: grila ARIA a calendarului: săgețile, Home/End, PageUp/PageDown, selecția prin
// două clicuri (sosirea, apoi plecarea), lunile următoare din /_api/calendar. Prima vizualizare vine de pe server.
const el = (tag, cls, text) => {
  const e = document.createElement(tag);
  if (cls) e.className = cls;
  if (text !== undefined) e.textContent = text;
  return e;
};
// Eticheta completă a zilei („20 decembrie 2026, liberă, 480 lei”), din textul ascuns vizual al celulei.
const eticheta = (td) => td.querySelector('.pg-zi-eticheta')?.textContent || '';
const luna = (l, n) => {
  const [a, m] = l.split('-').map(Number);
  const d = new Date(Date.UTC(a, m - 1 + n, 1));
  return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}`;
};
const ziua = (z, n) => {
  const d = new Date(`${z}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
};

class CazoCalendar extends HTMLElement {
  connectedCallback() {
    this.luna = this.dataset.luna;
    this.sosire = null;
    this.plecare = null;
    this.stare = this.querySelector('.pg-calendar-stare');
    for (const b of this.querySelectorAll('[data-pg-nav]')) {
      b.hidden = false;
      b.addEventListener('click', () => this.navigheaza(Number(b.dataset.pgNav)));
    }
    this.addEventListener('click', (e) => {
      const td = e.target.closest('td[data-zi]');
      if (td && this.contains(td)) this.alege(td);
    });
    this.addEventListener('keydown', (e) => this.tasta(e));
    this.pregateste();
  }

  zile() {
    return [...this.querySelectorAll('td[data-zi]')];
  }

  pregateste() {
    const zile = this.zile();
    let activa = zile.find((z) => z.dataset.zi === this.sosire) || zile.find((z) => z.dataset.stare === 'libera') || zile[0];
    for (const z of zile) {
      z.setAttribute('role', 'gridcell');
      z.tabIndex = z === activa ? 0 : -1;
      z.setAttribute('aria-selected', String(z.dataset.zi === this.sosire || z.dataset.zi === this.plecare));
      z.classList.toggle('pg-zi--interval', !!(this.sosire && this.plecare && z.dataset.zi > this.sosire && z.dataset.zi < this.plecare));
      if (['ocupata', 'inchisa', 'trecuta', 'fara_pret', 'necunoscuta'].includes(z.dataset.stare)) z.setAttribute('aria-disabled', 'true');
    }
  }

  tasta(e) {
    const td = e.target.closest('td[data-zi]');
    if (!td) return;
    const pasi = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
    let tinta = null;
    if (e.key in pasi) tinta = ziua(td.dataset.zi, pasi[e.key]);
    else if (e.key === 'Home') tinta = ziua(td.dataset.zi, -((new Date(`${td.dataset.zi}T12:00:00Z`).getUTCDay() + 6) % 7));
    else if (e.key === 'End') tinta = ziua(td.dataset.zi, 6 - ((new Date(`${td.dataset.zi}T12:00:00Z`).getUTCDay() + 6) % 7));
    else if (e.key === 'PageDown' || e.key === 'PageUp') {
      const d = new Date(`${td.dataset.zi}T12:00:00Z`);
      d.setUTCMonth(d.getUTCMonth() + (e.key === 'PageDown' ? 1 : -1));
      tinta = d.toISOString().slice(0, 10);
    } else if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      this.alege(td);
      return;
    } else return;
    e.preventDefault();
    this.mutaFocus(tinta);
  }

  async mutaFocus(zi) {
    let z = this.querySelector(`td[data-zi="${zi}"]`);
    if (!z) {
      await this.navigheaza(zi > this.zile().at(-1).dataset.zi ? 1 : -1);
      z = this.querySelector(`td[data-zi="${zi}"]`);
    }
    if (!z) return;
    for (const x of this.zile()) x.tabIndex = -1;
    z.tabIndex = 0;
    z.focus();
  }

  alege(td) {
    if (td.getAttribute('aria-disabled') === 'true' && !(this.sosire && !this.plecare && td.dataset.zi > this.sosire)) return;
    const zi = td.dataset.zi;
    if (!this.sosire || this.plecare || zi <= this.sosire) {
      if ('sosireInchisa' in td.dataset) {
        this.anunta(eticheta(td));
        return;
      }
      this.sosire = zi;
      this.plecare = null;
    } else {
      this.plecare = zi;
    }
    this.pregateste();
    this.anunta(eticheta(td));
    this.dispatchEvent(new CustomEvent('cazo-selectie', { bubbles: true, detail: { camera: this.dataset.camera, sosire: this.sosire, plecare: this.plecare } }));
  }

  anunta(text) {
    if (this.stare) this.stare.textContent = text || '';
  }

  async navigheaza(n) {
    const tinta = luna(this.luna, n);
    const q = new URLSearchParams({ camera: this.dataset.camera, luna: tinta, adulti: this.dataset.oaspeti || '2', limba: this.dataset.limba || 'ro' });
    // Nopțile găsite ocupate chiar acum rămân marcate și în lunile următoare.
    if (this.dataset.ocupate) q.set('ocupate', this.dataset.ocupate);
    try {
      const r = await fetch(`${this.dataset.api}${this.dataset.api.includes('?') ? '&' : '?'}${q}`, { headers: { Accept: 'application/json' } });
      if (!r.ok) return;
      const j = await r.json();
      this.luna = tinta;
      this.deseneaza(j.luni);
    } catch (e) {
      // Fără rețea: lunile afișate rămân.
    }
  }

  deseneaza(luni) {
    const cont = this.querySelector('.pg-calendar-luni');
    const vechi = cont.querySelector('table');
    const cap = vechi ? [...vechi.querySelectorAll('thead th')].map((th) => [th.textContent, th.getAttribute('abbr')]) : [];
    const desc = vechi ? vechi.getAttribute('aria-describedby') : '';
    cont.replaceChildren();
    for (const l of luni) {
      const t = el('table', 'pg-calendar-luna');
      const id = `pg-cal-${this.dataset.camera}-${l.id}`;
      t.setAttribute('role', 'grid');
      t.setAttribute('aria-labelledby', id);
      if (desc) t.setAttribute('aria-describedby', desc);
      const c = el('caption', '', l.titlu);
      c.id = id;
      const th = el('thead');
      const tr = el('tr');
      for (const [s, a] of cap) {
        const x = el('th', '', s);
        x.scope = 'col';
        if (a) x.setAttribute('abbr', a);
        tr.append(x);
      }
      th.append(tr);
      const tb = el('tbody');
      for (const s of l.saptamani) {
        const r = el('tr');
        for (const z of s) {
          const td = el('td', z ? `pg-zi pg-zi--${z.vizibil || z.stare}${z.acum ? ' pg-zi--acum' : ''}` : 'pg-zi pg-zi--gol');
          if (z) {
            td.dataset.zi = z.zi;
            td.dataset.stare = z.stare;
            td.dataset.min = String(z.sejur_minim);
            if (z.sosire_inchisa) td.dataset.sosireInchisa = '';
            const n = el('span', 'pg-zi-numar', String(z.numar));
            n.setAttribute('aria-hidden', 'true');
            td.append(n);
            if (z.pret_scurt) {
              const p = el('span', 'pg-zi-pret', z.pret_scurt);
              p.setAttribute('aria-hidden', 'true');
              td.append(p);
            }
            td.append(el('span', 'pg-ascuns pg-zi-eticheta', z.eticheta));
          }
          r.append(td);
        }
        tb.append(r);
      }
      t.append(c, th, tb);
      cont.append(t);
    }
    this.pregateste();
  }
}

if (!customElements.get('cazo-calendar')) customElements.define('cazo-calendar', CazoCalendar);
