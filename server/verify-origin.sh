#!/bin/bash
# Post-install / periodic check for an Offerra origin.
# Usage: bash verify-origin.sh [optional-domain]
set -euo pipefail

DOMAIN="${1:-}"
FAIL=0
ok() { echo "[OK] $*"; }
bad() { echo "[FAIL] $*"; FAIL=1; }

echo "=== Offerra origin verify ==="

if ! command -v tar >/dev/null 2>&1; then
  bad "tar not installed (deploy unpacks landers with tar -xzf)"
else
  ok "tar $(command -v tar)"
fi

if ! systemctl is-active --quiet nginx; then
  bad "nginx not active"
else
  ok "nginx active"
fi

FPM_SVC="$(systemctl list-units --type=service --state=running 'php*-fpm*' --no-legend | awk '{print $1}' | head -1 || true)"
if [ -z "$FPM_SVC" ]; then
  bad "php-fpm not running"
else
  ok "php-fpm: $FPM_SVC"
fi

if [ ! -d /var/www/offers ]; then
  bad "/var/www/offers missing"
else
  ok "/var/www/offers exists"
fi

if ! grep -q 'offerra_lang_index' /etc/nginx/conf.d/offers.conf 2>/dev/null \
  && ! grep -q 'location ~ \^/(\[a-z\]{2\})/?\$' /etc/nginx/conf.d/offers.conf 2>/dev/null \
  && ! grep -q 'langs/\$1/index.php' /etc/nginx/conf.d/offers.conf 2>/dev/null \
  && ! grep -q 'langs/\$offerra_lang/index.php' /etc/nginx/conf.d/offers.conf 2>/dev/null; then
  bad "nginx missing multilang /{lang}/ rewrite (hub stays on English)"
else
  ok "multilang /{lang}/ rewrite present"
fi

if ! grep -q 'location = /sitemap.xml' /etc/nginx/conf.d/offers.conf 2>/dev/null \
  && ! grep -q 'location = /sitemap.xml' /etc/nginx/sites-available/offers 2>/dev/null; then
  bad "nginx missing sitemap.xml → sitemap.php rewrite (GSC will see HTML)"
else
  ok "sitemap.xml rewrite present"
fi

if ! grep -q 'location = /robots.txt' /etc/nginx/conf.d/offers.conf 2>/dev/null \
  && ! grep -q 'location = /robots.txt' /etc/nginx/sites-available/offers 2>/dev/null; then
  bad "nginx missing robots.txt → robots.php rewrite"
else
  ok "robots.txt rewrite present"
fi

if nginx -t 2>/dev/null; then
  ok "nginx -t"
else
  bad "nginx -t failed"
fi

# DNS
NS="$(tr -d '\r' </etc/resolv.conf 2>/dev/null | grep -E '^nameserver' | head -3 | tr '\n' ' ')"
if echo "$NS" | grep -qE '127\.0\.0\.1|::1'; then
  bad "resolv.conf points at localhost stub ($NS) — CRM curl will fail"
elif echo "$NS" | grep -qE '1\.1\.1\.1|8\.8\.8\.8'; then
  ok "DNS nameservers: $NS"
else
  bad "unexpected resolv.conf: $NS"
fi

if php -r 'exit(gethostbyname("yourleads.org") === "yourleads.org" ? 1 : 0);' 2>/dev/null; then
  ok "PHP resolves yourleads.org"
else
  bad "PHP cannot resolve yourleads.org"
fi

if ! getent hosts google.com >/dev/null 2>&1 && ! dig +short google.com @1.1.1.1 >/dev/null 2>&1; then
  bad "DNS resolution looks broken"
else
  ok "DNS works"
fi

# Smoke host map + rewrites
SMOKE="_offerra-verify-$$"
ROOT="/var/www/offers/${SMOKE}/public_html"
mkdir -p "$ROOT"
cat > "$ROOT/index.php" <<'PHP'
<?php echo 'php-ok';
PHP
cat > "$ROOT/sitemap.php" <<'PHP'
<?php header('Content-Type: application/xml; charset=UTF-8'); echo '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
PHP
cat > "$ROOT/robots.php" <<'PHP'
<?php header('Content-Type: text/plain; charset=UTF-8'); echo "User-agent: *\nAllow: /\nSitemap: https://example.test/sitemap.xml\n";
PHP
chown -R www-data:www-data "/var/www/offers/${SMOKE}"

BODY="$(curl -s -H "Host: ${SMOKE}" http://127.0.0.1/ || true)"
if [ "$BODY" = "php-ok" ]; then
  ok "Host→/var/www/offers/{domain}/public_html PHP"
else
  bad "PHP via Host map failed (got: ${BODY})"
fi

SM="$(curl -s -H "Host: ${SMOKE}" http://127.0.0.1/sitemap.xml || true)"
if echo "$SM" | grep -q '<urlset'; then
  ok "sitemap.xml serves XML (not homepage HTML)"
else
  bad "sitemap.xml did not return XML"
fi

RB="$(curl -s -H "Host: ${SMOKE}" http://127.0.0.1/robots.txt || true)"
if echo "$RB" | grep -qi 'user-agent'; then
  ok "robots.txt serves robots.php"
else
  bad "robots.txt rewrite failed"
fi

rm -rf "/var/www/offers/${SMOKE}"

if [ -n "$DOMAIN" ]; then
  DROOT="/var/www/offers/${DOMAIN}/public_html"
  if [ -f "$DROOT/index.php" ]; then
    ok "domain root exists: $DROOT"
    DSM="$(curl -s -H "Host: ${DOMAIN}" http://127.0.0.1/sitemap.xml | head -c 120 || true)"
    if echo "$DSM" | grep -q '<urlset\|<?xml'; then
      ok "${DOMAIN}/sitemap.xml looks like XML"
    else
      bad "${DOMAIN}/sitemap.xml unexpected: ${DSM}"
    fi
    if [ -d "$DROOT/langs/pt" ]; then
      DPT="$(curl -s -H "Host: ${DOMAIN}" http://127.0.0.1/pt/ | head -c 200 || true)"
      if echo "$DPT" | grep -qiE 'Opere de forma|Trade smarter'; then
        if echo "$DPT" | grep -qi 'Trade smarter'; then
          bad "${DOMAIN}/pt/ still serves English (missing multilang nginx rewrite)"
        else
          ok "${DOMAIN}/pt/ serves translated hub"
        fi
      fi
    fi
  else
    bad "no offer at $DROOT"
  fi
fi

IP="$(hostname -I | awk '{print $1}')"
echo
echo "Offerra Settings → Server: host=${IP} port=22 user=root"
echo

if [ "$FAIL" -ne 0 ]; then
  echo "RESULT: FAILED"
  exit 1
fi
echo "RESULT: OK"
