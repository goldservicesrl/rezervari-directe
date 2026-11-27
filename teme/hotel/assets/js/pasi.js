// Contoarele de oaspeți ale barei de rezervare (tema Hotel): butoanele „−” și „+” lângă listele „Adulți” și „Copii” ale
// nucleului. Lista rămâne câmpul trimis cu formularul (fără JavaScript, rămâne lista simplă); butoanele doar o mută cu
// o poziție și se dezactivează la capete. Etichetele vin din atributele data-* ale temei, în limba paginii.
const butonPas = (text, eticheta, lista, pas, actualizeaza) => {
  const b = document.createElement('button');
  b.type = 'button';
  b.className = 'et-pas-buton';
  b.textContent = text;
  b.setAttribute('aria-label', eticheta || text);
  if (lista.id) b.setAttribute('aria-controls', lista.id);
  b.addEventListener('click', () => {
    const i = lista.selectedIndex + pas;
    if (i >= 0 && i < lista.options.length) {
      lista.selectedIndex = i;
      lista.dispatchEvent(new Event('change', { bubbles: true }));
    }
    actualizeaza();
  });
  return b;
};

for (const loc of document.querySelectorAll('[data-pasi]')) {
  for (const lista of loc.querySelectorAll('select[name="adulti"], select[name="copii"]')) {
    if (lista.closest('.et-pas')) continue;
    const cutie = document.createElement('div');
    cutie.className = 'et-pas';
    let minus = null;
    let plus = null;
    const actualizeaza = () => {
      minus.disabled = lista.selectedIndex <= 0;
      plus.disabled = lista.selectedIndex >= lista.options.length - 1;
    };
    minus = butonPas('−', loc.dataset[lista.name + 'Minus'], lista, -1, actualizeaza);
    plus = butonPas('+', loc.dataset[lista.name + 'Plus'], lista, 1, actualizeaza);
    lista.parentNode.insertBefore(cutie, lista);
    cutie.append(minus, lista, plus);
    lista.addEventListener('change', actualizeaza);
    actualizeaza();
  }
}
