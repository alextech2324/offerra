(function () {
  function navDrawer() {
    var btn = document.getElementById('nav-burger');
    var drawer = document.getElementById('nav-drawer');
    if (!btn || !drawer) return;

    function setOpen(open) {
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      drawer.hidden = !open;
    }

    btn.addEventListener('click', function () {
      setOpen(btn.getAttribute('aria-expanded') !== 'true');
    });
    drawer.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () { setOpen(false); });
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') setOpen(false);
    });
    window.addEventListener('resize', function () {
      if (window.matchMedia('(min-width: 960px)').matches) setOpen(false);
    });
  }

  function langSwitch() {
    var sel = document.getElementById('lang-switch');
    if (!sel) return;
    sel.addEventListener('change', function () {
      var next = sel.value;
      var page = sel.dataset.page || 'home';
      document.cookie = 'bluegrove_lang=' + encodeURIComponent(next) + ';path=/;max-age=' + (365 * 86400) + ';SameSite=Lax';
      var base = (document.body && document.body.dataset.previewBase) || '';
      var slugs = {
        home: '',
        product: 'platform/',
        offer: 'pricing/',
        'sign-up': 'open-account/',
      };
      var slug = Object.prototype.hasOwnProperty.call(slugs, page) ? slugs[page] : page + '/';
      if (next === 'en') {
        window.location.href = base + '/' + slug;
      } else {
        window.location.href = base + '/' + next + '/' + slug;
      }
    });
  }

  function themeToggle() {
    var btn = document.getElementById('theme-toggle');
    if (!btn) return;
    var label = btn.querySelector('[data-theme-label]');
    var nightText = (window.APP_LANG && window.APP_LANG.themeNight) || 'Night';
    var lightText = (window.APP_LANG && window.APP_LANG.themeLight) || 'Light';

    function paint() {
      var dark = document.documentElement.classList.contains('dark');
      btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
      if (label) label.textContent = dark ? lightText : nightText;
    }

    btn.addEventListener('click', function () {
      var next = !document.documentElement.classList.contains('dark');
      document.documentElement.classList.toggle('dark', next);
      try { localStorage.setItem('theme', next ? 'dark' : 'light'); } catch (e) {}
      paint();
    });
    paint();
  }

  function faq() {
    document.querySelectorAll('.bg-faq__item button').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var item = btn.parentElement;
        var open = item.classList.contains('is-open');
        var root = item.parentElement;
        root.querySelectorAll('.bg-faq__item').forEach(function (el) {
          el.classList.remove('is-open');
        });
        if (!open) item.classList.add('is-open');
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    navDrawer();
    langSwitch();
    themeToggle();
    faq();
  });
})();
