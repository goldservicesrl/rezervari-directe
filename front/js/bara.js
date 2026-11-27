// <cazo-bara>: bara de rezervare. Formularul merge și fără JavaScript;
// aici se adaugă doar ziua minimă, plecarea după sosire și butonul lipicios ascuns cât formularul e vizibil.
const azi = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};
const ziua = (z, n) => {
  const d = new Date(`${z}T12:00:00`);
  d.setDate(d.getDate() + n);
  return d.toISOString().slice(0, 10);
};

/** Leagă două câmpuri de dată: plecarea rămâne după sosire. */
export function legaDatele(sosire, plecare) {
  if (!sosire || !plecare) return;
  sosire.min = azi();
  const potriveste = () => {
    if (sosire.value) {
      plecare.min = ziua(sosire.value, 1);
      if (!plecare.value || plecare.value <= sosire.value) plecare.value = ziua(sosire.value, 1);
    }
  };
  sosire.addEventListener('change', potriveste);
  potriveste();
}

class CazoBara extends HTMLElement {
  connectedCallback() {
    legaDatele(this.querySelector('input[name="sosire"]'), this.querySelector('input[name="plecare"]'));
    const lipicios = this.querySelector('.pg-bara-lipicios');
    const formular = this.querySelector('form');
    if (lipicios && formular && 'IntersectionObserver' in window) {
      new IntersectionObserver((e) => { lipicios.hidden = e[0].isIntersecting; }).observe(formular);
    }
  }
}

if (!customElements.get('cazo-bara')) customElements.define('cazo-bara', CazoBara);
for (const f of document.querySelectorAll('[data-pg="cautare"] form')) {
  legaDatele(f.querySelector('input[name="sosire"]'), f.querySelector('input[name="plecare"]'));
}
