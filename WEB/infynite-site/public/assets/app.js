// No inline scripts are allowed by the CSP; behaviour hooks via data-attributes.
document.addEventListener('click', function (ev) {
  var el = ev.target.closest('[data-confirm]');
  if (el && !window.confirm(el.getAttribute('data-confirm'))) ev.preventDefault();
});
document.addEventListener('change', function (ev) {
  var t = ev.target;
  if (t.matches('input[type=file][data-list]')) {
    var out = document.getElementById(t.getAttribute('data-list'));
    if (out) out.textContent = Array.prototype.map.call(t.files, function (f) {
      return f.name + ' (' + (f.size / 1048576).toFixed(1) + ' MB)';
    }).join(', ');
  }
  if (t.matches('select[data-autosubmit]')) t.form.submit();
});
