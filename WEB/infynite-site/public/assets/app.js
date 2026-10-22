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
  if (t.matches('select[data-autosubmit], input[type=checkbox][data-autosubmit]')) t.form.submit();
});
document.addEventListener('click', function (ev) {
  if (ev.target.closest('[data-print]')) window.print();
});
// Show/hide toggle on every password field (no inline script: CSP).
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('input[type=password]').forEach(function (inp) {
    var wrap = document.createElement('div'); wrap.className = 'pwwrap';
    inp.parentNode.insertBefore(wrap, inp); wrap.appendChild(inp);
    var b = document.createElement('button'); b.type = 'button'; b.className = 'pwtoggle'; b.textContent = 'Show';
    b.setAttribute('aria-label', 'Show password'); b.setAttribute('aria-pressed', 'false');
    b.addEventListener('click', function () {
      var show = inp.type === 'password'; inp.type = show ? 'text' : 'password';
      b.textContent = show ? 'Hide' : 'Show'; b.setAttribute('aria-pressed', String(show)); b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
    wrap.appendChild(b);
  });
});

// Messages: close button, and success messages fade away by themselves.
document.addEventListener('click', function (ev) {
  var x = ev.target.closest('.flash-x'); if (x) x.parentNode.remove();
});
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.toasts .flash.ok').forEach(function (f) {
    setTimeout(function () { f.classList.add('gone'); setTimeout(function () { f.remove(); }, 450); }, 6000);
  });
});
// Keep the scroll position: after saving a form the page stays where you were, and
// returning to a page (link or back button) puts you back where you left it.
(function () {
  var KEY = 'portal:scroll', TTL = 30 * 60 * 1000;
  function load() { try { return JSON.parse(sessionStorage.getItem(KEY) || '{}'); } catch (e) { return {}; } }
  function store(m) { try { sessionStorage.setItem(KEY, JSON.stringify(m)); } catch (e) { /* storage blocked: skip */ } }
  function here() { return location.pathname + location.search; }
  function remember() { var m = load(); m[here()] = { y: window.scrollY, t: Date.now() }; store(m); }
  var timer;
  window.addEventListener('scroll', function () { clearTimeout(timer); timer = setTimeout(remember, 150); }, { passive: true });
  window.addEventListener('pagehide', remember);
  document.addEventListener('submit', remember, true);
  document.addEventListener('click', function (ev) { if (ev.target.closest('a[href]')) remember(); }, true);
  document.addEventListener('DOMContentLoaded', function () {
    if (location.hash) return;
    var r = load()[here()];
    if (!r || Date.now() - r.t > TTL || !(r.y > 0)) return;
    window.scrollTo(0, r.y);
    window.addEventListener('load', function () { window.scrollTo(0, r.y); }, { once: true });
  });
})();

// Idle sign-out: while someone is working the session is kept alive; when they stop for the set time the page returns to login.
(function () {
  var app = document.querySelector('.app[data-idle-minutes]'); var mins = app ? parseInt(app.getAttribute('data-idle-minutes'), 10) : 0;
  if (!mins || mins < 1) { return; }
  var last = Date.now(), pinged = Date.now();
  ['mousemove', 'keydown', 'scroll', 'click', 'touchstart', 'input'].forEach(function (ev) { window.addEventListener(ev, function () { last = Date.now(); }, { passive: true }); });
  setInterval(function () {
    var now = Date.now();
    if (now - last > mins * 60000) { window.location.href = '/staff'; return; }
    if (last > pinged && now - pinged > Math.min(20000, mins * 20000)) {
      pinged = now;
      fetch('/staff/ping', { credentials: 'same-origin' }).then(function (r) { if (r.redirected) { window.location.href = r.url; } }).catch(function () {});
    }
  }, 5000);
})();
