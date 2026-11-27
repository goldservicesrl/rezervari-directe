// <cazo-rezumat>: totalul exact la alegerea datelor și a oaspeților, prin /_api/pret,
// anunțat cu aria-live="polite". Fără JavaScript, formularul reîncarcă pagina camerei cu totalul calculat pe server.
const el = (tag, cls, text) => {
  const e = document.createElement(tag);
  if (cls) e.className = cls;
  if (text !== undefined) e.textContent = text;
  return e;
};

class CazoRezumat extends HTMLElement {
  connectedCallback() {
    this.formular = this.querySelector('form');
    this.zona = this.querySelector('.pg-rezumat-total');
    if (!this.formular || !this.zona) return;
    this.cerere = 0;
    const s = this.formular.querySelector('input[name="sosire"]');
    const p = this.formular.querySelector('input[name="plecare"]');
    if (s && p) {
      s.min = new Date().toISOString().slice(0, 10);
      s.addEventListener('change', () => {
        if (s.value && (!p.value || p.value <= s.value)) {
          const d = new Date(`${s.value}T12:00:00`);
          d.setDate(d.getDate() + 1);
          p.value = d.toISOString().slice(0, 10);
        }
      });
    }
    this.buton = this.formular.querySelector('button[type="submit"]');
    this.formular.addEventListener('change', () => this.calculeaza());
    this.formular.addEventListener('submit', (e) => { e.preventDefault(); this.calculeaza(true); });
    // o dată liberă apropiată, aleasă cu un clic, completează formularul și recalculează totalul.
    this.zona.addEventListener('click', (e) => {
      const a = e.target.closest('.pg-alternativa');
      if (!a || !s || !p) return;
      e.preventDefault();
      s.value = a.dataset.sosire;
      p.value = a.dataset.plecare;
      this.calculeaza(true);
    });
    document.addEventListener('cazo-selectie', (e) => {
      if (e.detail.camera !== this.dataset.camera) return;
      if (s) s.value = e.detail.sosire || '';
      if (p) p.value = e.detail.plecare || '';
      if (e.detail.sosire && e.detail.plecare) this.calculeaza();
    });
  }

  async calculeaza(cerut = false) {
    const d = new FormData(this.formular);
    if (!d.get('sosire') || !d.get('plecare') || d.get('plecare') <= d.get('sosire')) {
      // Apăsat cu datele incomplete sau inversate: oaspetele vede de ce nu apare totalul.
      if (cerut && this.dataset.dateGresite) this.avertisment(this.dataset.dateGresite);
      return;
    }
    const q = new URLSearchParams();
    for (const k of ['camera', 'sosire', 'plecare', 'adulti', 'copii', 'plan']) if (d.get(k)) q.set(k, d.get(k));
    q.set('limba', document.documentElement.lang || 'ro');
    const nr = ++this.cerere;
    this.zona.setAttribute('aria-busy', 'true');
    this.lucreaza(true);
    // Butonul arată că lucrează cel puțin o clipă, ca răspunsul rapid să nu treacă neobservat.
    const pauza = new Promise((ok) => { setTimeout(ok, 350); });
    try {
      const r = await fetch(`${this.dataset.api}${this.dataset.api.includes('?') ? '&' : '?'}${q}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const j = await r.json();
      await pauza;
      if (nr === this.cerere) {
        this.arata(j);
        if (cerut) this.arataPeEcran();
      }
    } catch (e) {
      // Rețeaua lipsă: formularul rămâne; butonul trimite cererea către server.
    } finally {
      if (nr === this.cerere) {
        this.zona.removeAttribute('aria-busy');
        this.lucreaza(false);
      }
    }
  }

  // Starea butonului cât se calculează: textul „Calculăm totalul…” și aria-busy.
  lucreaza(da) {
    const b = this.buton;
    if (!b || !this.dataset.lucreaza) return;
    if (da) {
      if (b.dataset.text === undefined) b.dataset.text = b.textContent;
      b.textContent = this.dataset.lucreaza;
      b.setAttribute('aria-busy', 'true');
    } else if (b.dataset.text !== undefined) {
      b.textContent = b.dataset.text;
      delete b.dataset.text;
      b.removeAttribute('aria-busy');
    }
  }

  // Pe telefon, rezultatul poate rămâne sub marginea ecranului: îl aducem în vedere.
  arataPeEcran() {
    const r = this.zona.getBoundingClientRect();
    if (r.top < 0 || r.top > window.innerHeight - 80) {
      const lin = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      this.zona.scrollIntoView({ block: 'center', behavior: lin ? 'auto' : 'smooth' });
    }
  }

  // Caseta de avertisment: mesajul și, când există, datele libere apropiate, ca butoane.
  avertisment(mesaj, stare = 'restrictie', r = {}) {
    const box = el('div', `pg-total-avertisment pg-total-avertisment--${stare}`);
    box.append(el('p', `pg-total-mesaj pg-total-mesaj--${stare}`, mesaj));
    if (Array.isArray(r.alternative) && r.alternative.length) {
      box.append(el('p', 'pg-alternative-titlu', r.alternative_titlu || ''));
      const ul = el('ul', 'pg-alternative');
      for (const x of r.alternative) {
        const a = el('a', 'pg-buton-secundar pg-alternativa', x.text);
        a.href = x.url;
        a.dataset.sosire = x.sosire;
        a.dataset.plecare = x.plecare;
        const li = el('li');
        li.append(a);
        ul.append(li);
      }
      box.append(ul);
    }
    this.zona.replaceChildren(box);
  }

  // Un total: suma, echivalentul, componentele la un clic și butonul comenzii.
  total(r, tinta, tarif) {
    const p = el('p', 'pg-total');
    p.append(el('strong', '', r.titlu));
    const det = el('details', 'pg-total-componente');
    det.append(el('summary', '', this.dataset.componente || ''));
    const dl = el('dl');
    for (const c of r.componente) {
      const rand = el('div', 'pg-total-rand');
      rand.append(el('dt', '', c.eticheta), el('dd', '', c.text));
      dl.append(rand);
    }
    det.append(dl);
    tinta.append(p);
    if (r.euro) tinta.append(el('p', 'pg-total-euro', r.euro));
    // Ce se plătește separat, la sosire, în afara totalului.
    for (const x of r.separat || []) tinta.append(el('p', 'pg-total-separat', x));
    tinta.append(det);
    if (r.rezerva_url) {
      const a = el('a', 'pg-buton', r.rezerva_text || '');
      if (tarif) a.append(el('span', 'pg-ascuns', `, ${tarif}`));
      a.href = r.rezerva_url;
      const rand = el('p', 'pg-total-rezerva');
      rand.append(a);
      tinta.append(rand);
    }
  }

  arata(r) {
    this.zona.replaceChildren();
    if (Array.isArray(r.oferte) && r.oferte.length) {
      // Tarifele pentru datele alese: câte unul pe politică de anulare, fiecare cu totalul și butonul lui.
      const ul = el('ul', 'pg-oferte');
      for (const o of r.oferte) {
        const li = el('li', 'pg-oferta');
        li.append(el('h3', 'pg-oferta-titlu', o.tarif));
        if (o.tarif_nota) li.append(el('p', 'pg-oferta-nota', o.tarif_nota));
        li.append(el('p', 'pg-oferta-politica', o.politica));
        if (o.stare === 'ok') this.total(o, li, o.tarif);
        else if (o.mesaj) li.append(el('p', `pg-total-mesaj pg-total-mesaj--${o.stare}`, o.mesaj));
        ul.append(li);
      }
      this.zona.append(ul);
    } else if (r.stare === 'ok') {
      this.total(r, this.zona, '');
    } else if (r.mesaj) {
      this.avertisment(r.mesaj, r.stare, r);
    }
  }
}

if (!customElements.get('cazo-rezumat')) customElements.define('cazo-rezumat', CazoRezumat);
