// <cazo-harta>: harta interactivă, încărcată fără niciun clic: locul ei se rezervă de la început, iar Leaflet
// (din activele nucleului) și plăcile (de la serverul ales de gazdă) se cer când harta se apropie de ecran, deci o
// pagină pe care oaspetele rămâne sus face doar cereri spre site. Fără JavaScript rămân adresa și legătura spre
// aplicația de hărți. Atribuirea cerută de licența datelor (ODbL) rămâne afișată pe hartă.
const ATRIBUIRE = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';

const incarca = (tag, atribute) => new Promise((gata, eroare) => {
  const e = document.createElement(tag);
  for (const [k, v] of Object.entries(atribute)) e.setAttribute(k, v);
  e.addEventListener('load', gata, { once: true });
  e.addEventListener('error', eroare, { once: true });
  document.head.append(e);
});

class CazoHarta extends HTMLElement {
  connectedCallback() {
    if (!this.dataset.lat || this.cont) return;
    // Locul hărții, pus după componentă (stilul Leaflet rămâne neatins de protecțiile ei), cu înălțimea finală.
    // când harta stă într-un alt bloc al nucleului (confirmarea, „Rezervarea mea”), locul ei iese după cel
    // mai de sus bloc [data-pg], altfel protecțiile acelui bloc (poziția, transformările) strică plăcile; șabloanele
    // țin harta ultima în blocul lor, deci locul ajunge imediat sub ea.
    this.cont = document.createElement('div');
    this.cont.className = 'pg-harta-interactiva';
    let loc = this;
    for (let e = this.parentElement; e; e = e.parentElement) if (e.hasAttribute('data-pg')) loc = e;
    loc.after(this.cont);
    if ('IntersectionObserver' in window) {
      const o = new IntersectionObserver((intrari) => {
        if (intrari.some((i) => i.isIntersecting)) {
          o.disconnect();
          this.deschide();
        }
      }, { rootMargin: '600px 0px' });
      o.observe(this.cont);
    } else {
      this.deschide();
    }
  }

  // Pe paginile cu Trusted Types (comanda, starea), Leaflet are nevoie de o politică implicită: ea lasă să treacă
  // doar adresa lui Leaflet din activele nucleului și cele câteva fragmente HTML fixe ale lui (verificarea SVG,
  // butoanele de zoom, atribuirea de mai sus); orice alt text e refuzat, deci protecția rămâne.
  politica() {
    const tt = window.trustedTypes;
    if (!tt || tt.defaultPolicy) return;
    const html = new Set(['', '<svg/>', '<span aria-hidden="true">+</span>', '<span aria-hidden="true">&#x2212;</span>', ATRIBUIRE]);
    const js = new Set([this.dataset.leafletJs, new URL(this.dataset.leafletJs, document.baseURI).href]);
    try {
      tt.createPolicy('default', {
        createHTML: (s) => (html.has(s) ? s : null),
        createScriptURL: (s) => (js.has(s) ? s : null),
      });
    } catch (e) {
      // O politică implicită există deja: rămâne a ei.
    }
  }

  async deschide() {
    if (this.pornita) return;
    this.pornita = true;
    this.politica();
    const cont = this.cont;
    try {
      await Promise.all([
        incarca('link', { rel: 'stylesheet', href: this.dataset.leafletCss }),
        window.L ? Promise.resolve() : incarca('script', { src: this.dataset.leafletJs }),
      ]);
      const L = window.L;
      const lat = Number(this.dataset.lat);
      const lng = Number(this.dataset.lng);
      L.Icon.Default.imagePath = this.dataset.leafletCss.replace(/leaflet\.css$/, 'images/');
      // Atribuirea cerută de ODbL, fără prefixul Leaflet (licența BSD a lui nu o cere): controlul se adaugă fără prefix
      // de la început, ca primul lui HTML să fie gol (politica de mai sus îl acceptă).
      const harta = L.map(cont, { scrollWheelZoom: false, attributionControl: false }).setView([lat, lng], 15);
      L.control.attribution({ prefix: false }).addTo(harta);
      L.tileLayer(this.dataset.placi, { maxZoom: 19, attribution: ATRIBUIRE }).addTo(harta);
      L.marker([lat, lng], { title: this.dataset.nume || '' }).addTo(harta);
      cont.setAttribute('role', 'region');
      cont.setAttribute('aria-label', (this.querySelector('.pg-harta-titlu') || {}).textContent || '');
      cont.classList.add('pg-harta-interactiva--gata');
    } catch (e) {
      // Leaflet a rămas neîncărcat (rețeaua): locul hărții se strânge, iar adresa și legătura rămân.
      cont.remove();
      this.cont = null;
      this.pornita = false;
    }
  }
}

if (!customElements.get('cazo-harta')) customElements.define('cazo-harta', CazoHarta);
