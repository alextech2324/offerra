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
      if (window.matchMedia('(min-width: 720px)').matches) setOpen(false);
    });
  }

  function langSwitch() {
    var sel = document.getElementById('lang-switch');
    if (!sel) return;
    sel.addEventListener('change', function () {
      var next = sel.value;
      var page = sel.dataset.page || 'home';
      document.cookie = 'fortis_lang=' + encodeURIComponent(next) + ';path=/;max-age=' + (365 * 86400) + ';SameSite=Lax';
      var base = (document.body && document.body.dataset.previewBase) || '';
      var slug = page === 'home' ? '' : page + '/';
      if (next === 'en') {
        window.location.href = base + '/' + slug;
      } else {
        window.location.href = base + '/' + next + '/' + slug;
      }
    });
  }

  function money(symbol, amount) {
    var n = Math.round(amount);
    return symbol + n.toLocaleString('en-US');
  }

  function yieldBoard() {
    var root = document.querySelector('[data-yield]');
    if (!root) return;
    var stakeEl = root.querySelector('[data-yield-stake]');
    var spanEl = root.querySelector('[data-yield-span]');
    var stakeOut = root.querySelector('[data-yield-stake-out]');
    var spanOut = root.querySelector('[data-yield-span-out]');
    var totalOut = root.querySelector('[data-yield-total]');
    var rateOut = root.querySelector('[data-yield-rate]');
    var gainOut = root.querySelector('[data-yield-gain]');
    var symbol = root.dataset.symbol || '€';

    function paint() {
      var stake = parseFloat(stakeEl.value || '0');
      var days = parseFloat(spanEl.value || '45');
      var rate = Math.round(days * 0.4 * 10) / 10;
      var gain = stake * (rate / 100);
      stakeOut.textContent = money(symbol, stake);
      spanOut.textContent = String(Math.round(days));
      rateOut.textContent = String(rate);
      gainOut.textContent = money(symbol, gain);
      totalOut.textContent = money(symbol, stake + gain);
    }

    stakeEl.addEventListener('input', paint);
    spanEl.addEventListener('input', paint);
    paint();
  }

  function faq() {
    document.querySelectorAll('.ft-faq__item button').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var item = btn.parentElement;
        var open = item.classList.contains('is-open');
        item.parentElement.querySelectorAll('.ft-faq__item').forEach(function (el) {
          el.classList.remove('is-open');
        });
        if (!open) item.classList.add('is-open');
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    navDrawer();
    langSwitch();
    yieldBoard();
    faq();
  });
})();
