// Paginile de rezervare (comanda, starea, „Rezervarea mea”), doar cu scripturile nucleului (CSP pe căi, Trusted Types):
//  - <cazo-altcha>: ALTCHA în fundal: cere provocarea de la /altcha, caută numărul cu SHA-256
//    (Web Crypto) și pune răspunsul în câmpul ascuns `altcha`. Fără JavaScript, comanda cere codul pe e-mail.
//  - starea: cât comanda e în lucru, interogare la 2 s, cel mult 90 s, apoi reîncărcarea paginii cu rezultatul.
const hex = (buf) => Array.from(new Uint8Array(buf), (b) => b.toString(16).padStart(2, '0')).join('');

async function rezolva(p) {
  const enc = new TextEncoder();
  for (let n = 0; n <= p.maxnumber; n += 1) {
    // eslint-disable-next-line no-await-in-loop
    if (hex(await crypto.subtle.digest('SHA-256', enc.encode(p.salt + n))) === p.challenge) return n;
  }
  return null;
}

class CazoAltcha extends HTMLElement {
  connectedCallback() {
    this.camp = this.querySelector('input[name="altcha"]');
    this.formular = this.closest('form');
    if (!this.camp || !this.formular || !window.crypto || !crypto.subtle) return;
    this.gata = this.porneste();
    this.formular.addEventListener('submit', (e) => {
      if (this.camp.value) return;
      e.preventDefault();
      const buton = e.submitter || this.formular.querySelector('button[type="submit"]');
      if (buton) buton.setAttribute('aria-busy', 'true');
      this.gata.finally(() => {
        if (buton) buton.removeAttribute('aria-busy');
        // Cu două butoane (plata online și cererea cash), metoda aleasă pleacă odată cu formularul.
        if (buton && buton.name) {
          const camp = document.createElement('input');
          camp.type = 'hidden';
          camp.name = buton.name;
          camp.value = buton.value;
          this.formular.append(camp);
        }
        this.formular.submit();
      });
    });
  }

  async porneste() {
    try {
      const r = await fetch(this.dataset.api, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const p = await r.json();
      const n = await rezolva(p);
      if (n === null) return;
      this.camp.value = btoa(JSON.stringify({ algorithm: p.algorithm, challenge: p.challenge, number: n, salt: p.salt, signature: p.signature }));
    } catch (e) {
      // Fără răspuns: formularul pleacă fără ALTCHA, iar serverul cere codul pe e-mail.
    }
  }
}

if (!customElements.get('cazo-altcha')) customElements.define('cazo-altcha', CazoAltcha);

const stare = document.querySelector('[data-pg="stare"][data-api]');
if (stare) {
  const inceput = Date.now();
  const verifica = async () => {
    try {
      const r = await fetch(stare.dataset.api, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const j = await r.json();
      if (j.stare && j.stare !== stare.dataset.stare) { window.location.reload(); return; }
    } catch (e) {
      // Rețeaua lipsă: următoarea încercare.
    }
    if (Date.now() - inceput < 90000) window.setTimeout(verifica, 2000);
  };
  window.setTimeout(verifica, 2000);
}

const erori = document.querySelector('.pg-comanda-erori');
if (erori) erori.focus();

// Datele pentru transfer: lângă IBAN, SWIFT, sumă și codul rezervării, butonul „Copiați” pune în clipboard
// valoarea din `data-pg-copiaza` (IBAN-ul fără spații, suma ca număr). Fără JavaScript sau fără acces la clipboard,
// rămâne selectarea întregii valori la un clic (user-select: all).
const transfer = document.querySelector('.pg-transfer[data-copiaza]');
if (transfer && navigator.clipboard && window.isSecureContext) {
  transfer.querySelectorAll('[data-pg-copiaza]').forEach((valoare) => {
    const buton = document.createElement('button');
    buton.type = 'button';
    buton.className = 'pg-buton-secundar pg-copiaza-buton';
    buton.textContent = transfer.dataset.copiaza;
    const eticheta = valoare.closest('.pg-transfer-rand')?.querySelector('dt')?.textContent || '';
    buton.setAttribute('aria-label', `${transfer.dataset.copiaza}: ${eticheta}`);
    const stare = document.createElement('span');
    stare.className = 'pg-ascuns';
    stare.setAttribute('role', 'status');
    buton.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(valoare.dataset.pgCopiaza);
        buton.textContent = transfer.dataset.copiat;
        stare.textContent = `${transfer.dataset.copiat}: ${eticheta}`;
        window.setTimeout(() => { buton.textContent = transfer.dataset.copiaza; stare.textContent = ''; }, 2500);
      } catch (e) {
        // Clipboardul refuzat de browser: rămâne selectarea la clic.
      }
    });
    valoare.after(buton, stare);
  });
}
