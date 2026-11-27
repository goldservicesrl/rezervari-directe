/* Butoanele „Copiați” de lângă adresele calendarului (asistentul trecerii): o mică extensie a paginii, care
   merge și fără ea (câmpul rămâne de selectat). Butonul, ascuns în HTML, apare doar unde browserul poate copia. */
(function () {
  'use strict';
  if (!navigator.clipboard || !navigator.clipboard.writeText) {
    return;
  }
  var butoane = document.querySelectorAll('button[data-copiaza]');
  Array.prototype.forEach.call(butoane, function (b) {
    var camp = document.getElementById(b.getAttribute('data-copiaza') || '');
    if (!camp) {
      return;
    }
    var text = b.firstChild ? b.firstChild.nodeValue : '';
    b.hidden = false;
    b.addEventListener('click', function () {
      navigator.clipboard.writeText(camp.value).then(function () {
        if (b.firstChild) {
          b.firstChild.nodeValue = b.getAttribute('data-copiat') || text;
          window.setTimeout(function () { b.firstChild.nodeValue = text; }, 2000);
        }
      }, function () {
        camp.focus();
        camp.select();
      });
    });
  });
}());
