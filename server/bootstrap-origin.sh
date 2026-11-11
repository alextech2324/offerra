#!/bin/bash
# Offerra origin bootstrap — Ubuntu 22.04 / 24.04
# Idempotent: safe to re-run on an existing offers server.
# Usage:
#   curl -fsSL https://YOUR_PANEL/…   # or copy this file
#   bash bootstrap-origin.sh
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

log() { echo "=== $* ==="; }

if [ "$(id -u)" -ne 0 ]; then
  echo "Run as root."
  exit 1
fi

log "packages"
apt-get update -y
apt-get install -y \
  nginx \
  php-fpm php-cli php-mbstring php-xml php-curl php-zip php-gd php-intl \
  tar gzip unzip curl ca-certificates ufw dnsutils
apt-get install -y php-opcache || true

# Ubuntu 22.04/24.04 ship PHP 8.x. 20.04 is 7.4 and ondrej no longer
# publishes PHP 8 for focal — try PPA anyway, then keep going.
if php -r 'exit((int) PHP_MAJOR_VERSION >= 8 ? 0 : 1);' 2>/dev/null; then
  log "php $(php -r 'echo PHP_VERSION;')"
else
  log "trying php 8 from ondrej (distro is $(php -v 2>/dev/null | head -1 || echo none))"
  apt-get install -y software-properties-common || true
  add-apt-repository -y ppa:ondrej/php || true
  apt-get update -y || true
  PHP8_OK=0
  for ver in 8.3 8.2 8.1; do
    if apt-get install -y \
      php${ver}-fpm php${ver}-cli php${ver}-mbstring php${ver}-xml php${ver}-curl \
      php${ver}-zip php${ver}-gd php${ver}-intl php${ver}-opcache
    then
      PHP8_OK=1
      systemctl disable --now php7.4-fpm 2>/dev/null || true
      break
    fi
  done
  if [ "$PHP8_OK" != "1" ]; then
    log "WARN: no php8 packages; remaining on distro PHP — landers need 8.x"
  fi
fi

# Prefer nginx+php only (no Apache fighting for :80)
systemctl stop apache2 2>/dev/null || true
systemctl disable apache2 2>/dev/null || true
systemctl mask apache2 2>/dev/null || true

log "dns resolvers"
# Classic failure: nameserver 127.0.0.1 + systemd-resolved inactive → PHP curl
# "Could not resolve host: yourleads.org" (CRM leads die with http_code 0).
mkdir -p /usr/local/bin
cat > /usr/local/bin/offerra-fix-dns.sh <<'EOF'
#!/bin/bash
chattr -i /etc/resolv.conf 2>/dev/null || true
printf 'nameserver 1.1.1.1\nnameserver 8.8.8.8\n' > /etc/resolv.conf
chattr +i /etc/resolv.conf 2>/dev/null || true
EOF
chmod 755 /usr/local/bin/offerra-fix-dns.sh

# Never rely on the stub resolver for offer origins.
systemctl disable --now systemd-resolved 2>/dev/null || true
systemctl mask systemd-resolved 2>/dev/null || true
rm -f /etc/systemd/resolved.conf.d/offerra.conf 2>/dev/null || true
/usr/local/bin/offerra-fix-dns.sh

TMPCRON=$(mktemp)
crontab -l 2>/dev/null | grep -v offerra-fix-dns > "$TMPCRON" || true
echo '* * * * * /usr/local/bin/offerra-fix-dns.sh >/dev/null 2>&1' >> "$TMPCRON"
echo '@reboot sleep 5; /usr/local/bin/offerra-fix-dns.sh' >> "$TMPCRON"
crontab "$TMPCRON"
rm -f "$TMPCRON"

log "offers root"
mkdir -p /var/www/offers
chown www-data:www-data /var/www/offers
chmod 775 /var/www/offers

IP="$(hostname -I | awk '{print $1}')"
mkdir -p "/var/www/offers/${IP}/public_html"
cat > "/var/www/offers/${IP}/public_html/index.html" <<'HTML'
<!doctype html><title>offerra origin</title>ok
HTML
chown -R www-data:www-data "/var/www/offers/${IP}"

WWW_CONF="$(ls /etc/php/*/fpm/pool.d/www.conf | sort | tail -1)"
PHP_VER="$(echo "$WWW_CONF" | cut -d/ -f4)"
FPM_SVC="php${PHP_VER}-fpm"
PHP_SOCK="/run/php/php${PHP_VER}-fpm.sock"
if [ ! -S "$PHP_SOCK" ] && [ -S "/run/php/php-fpm.sock" ]; then
  PHP_SOCK="/run/php/php-fpm.sock"
fi

log "php-fpm ${PHP_VER}"
sed -i 's/^pm = .*/pm = dynamic/' "$WWW_CONF"
sed -i 's/^pm.max_children = .*/pm.max_children = 40/' "$WWW_CONF"
sed -i 's/^pm.start_servers = .*/pm.start_servers = 8/' "$WWW_CONF"
sed -i 's/^pm.min_spare_servers = .*/pm.min_spare_servers = 4/' "$WWW_CONF"
sed -i 's/^pm.max_spare_servers = .*/pm.max_spare_servers = 16/' "$WWW_CONF"

PHP_INI="/etc/php/${PHP_VER}/fpm/php.ini"
if [ -f "$PHP_INI" ]; then
  sed -i 's/^memory_limit = .*/memory_limit = 256M/' "$PHP_INI"
  sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 32M/' "$PHP_INI"
  sed -i 's/^post_max_size = .*/post_max_size = 32M/' "$PHP_INI"
  sed -i 's/^;*\s*cgi.fix_pathinfo\s*=.*/cgi.fix_pathinfo = 0/' "$PHP_INI"
fi

log "nginx offers vhost"
mkdir -p /etc/nginx/conf.d /etc/nginx/sites-available /etc/nginx/sites-enabled

# Cloudflare real visitor IP (optional but useful for logs / geo)
cat > /etc/nginx/conf.d/cloudflare-realip.conf <<'NGINX'
# Updated at bootstrap; refresh periodically from https://www.cloudflare.com/ips/
set_real_ip_from 173.245.48.0/20;
set_real_ip_from 103.21.244.0/22;
set_real_ip_from 103.22.200.0/22;
set_real_ip_from 103.31.4.0/22;
set_real_ip_from 141.101.64.0/18;
set_real_ip_from 108.162.192.0/18;
set_real_ip_from 190.93.240.0/20;
set_real_ip_from 188.114.96.0/20;
set_real_ip_from 197.234.240.0/22;
set_real_ip_from 198.41.128.0/17;
set_real_ip_from 162.158.0.0/15;
set_real_ip_from 104.16.0.0/13;
set_real_ip_from 104.24.0.0/14;
set_real_ip_from 172.64.0.0/13;
set_real_ip_from 131.0.72.0/22;
set_real_ip_from 2400:cb00::/32;
set_real_ip_from 2606:4700::/32;
set_real_ip_from 2803:f800::/32;
set_real_ip_from 2405:b500::/32;
set_real_ip_from 2405:8100::/32;
set_real_ip_from 2a06:98c0::/29;
set_real_ip_from 2c0f:f248::/32;
real_ip_header CF-Connecting-IP;
NGINX

cat > /etc/nginx/conf.d/offers-map.conf <<'NGINX'
map $host $offer_domain {
    default $host;
    ~^www\.(.+)$ $1;
}
NGINX

# Single catch-all vhost: Host header selects /var/www/offers/{domain}/public_html
cat > /etc/nginx/conf.d/offers.conf <<NGINX
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    client_max_body_size 32m;

    set \$offer_root /var/www/offers/\$offer_domain/public_html;
    root \$offer_root;
    index index.php index.html;

    # GSC / bots expect these paths; files are PHP (nginx ignores .htaccess).
    # Dictionary landers generate them from index.php when the PHP file is missing.
    location = /sitemap.xml {
        if (-f \$offer_root/sitemap.php) {
            rewrite ^ /sitemap.php last;
        }
        rewrite ^ /index.php last;
    }

    location = /robots.txt {
        if (-f \$offer_root/robots.php) {
            rewrite ^ /robots.php last;
        }
        rewrite ^ /index.php last;
    }

    # Multilang hub: /{lang}/ -> langs/{lang}/index.php when that pack exists.
    # Named captures + set: numbered \$1 is empty inside nginx `if`, which
    # made Vespera /fr/ hit a missing script ("No input file specified").
    location ~ ^/(?<offerra_lang>[a-z][a-z])/?\$ {
        set \$offerra_lang_index /langs/\$offerra_lang/index.php;
        if (-f \$offer_root\$offerra_lang_index) {
            rewrite ^ \$offerra_lang_index last;
        }
        rewrite ^ /index.php last;
    }

    location ~ ^/(?<offerra_lang>[a-z][a-z])/(?<offerra_php>.+\.php)\$ {
        set \$offerra_lang_php /langs/\$offerra_lang/\$offerra_php;
        if (-f \$offer_root\$offerra_lang_php) {
            rewrite ^ \$offerra_lang_php last;
        }
        rewrite ^ /index.php last;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \\.php\$ {
        include fastcgi_params;
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_param SCRIPT_FILENAME \$offer_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$offer_root;
        fastcgi_index index.php;
    }

    location ~ /\\. {
        deny all;
    }
}
NGINX

# Avoid conflicting default sites
rm -f /etc/nginx/sites-enabled/default
# Keep sites-available/offers in sync for older docs / ops habits
cp -f /etc/nginx/conf.d/offers.conf /etc/nginx/sites-available/offers
rm -f /etc/nginx/sites-enabled/offers

log "systemd limits + ssh concurrency"
mkdir -p "/etc/systemd/system/${FPM_SVC}.service.d" /etc/systemd/system/nginx.service.d
cat > "/etc/systemd/system/${FPM_SVC}.service.d/nofile.conf" <<'EOF'
[Service]
LimitNOFILE=65535
EOF
cat > /etc/systemd/system/nginx.service.d/nofile.conf <<'EOF'
[Service]
LimitNOFILE=65535
EOF

if grep -qE '^#?MaxStartups' /etc/ssh/sshd_config; then
  sed -i 's/^#\?MaxStartups.*/MaxStartups 80/' /etc/ssh/sshd_config
else
  echo 'MaxStartups 80' >> /etc/ssh/sshd_config
fi
if grep -qE '^#?MaxSessions' /etc/ssh/sshd_config; then
  sed -i 's/^#\?MaxSessions.*/MaxSessions 80/' /etc/ssh/sshd_config
else
  echo 'MaxSessions 80' >> /etc/ssh/sshd_config
fi
systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true

log "firewall"
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

log "start services"
systemctl daemon-reload
systemctl enable --now nginx "$FPM_SVC"
nginx -t
systemctl restart "$FPM_SVC" nginx

# Smoke: PHP via Host map
SMOKE_DIR="/var/www/offers/_offerra-smoke/public_html"
mkdir -p "$SMOKE_DIR"
cat > "$SMOKE_DIR/index.php" <<'PHP'
<?php echo 'php-ok';
PHP
cat > "$SMOKE_DIR/sitemap.php" <<'PHP'
<?php header('Content-Type: application/xml; charset=UTF-8'); echo '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
PHP
cat > "$SMOKE_DIR/robots.php" <<'PHP'
<?php header('Content-Type: text/plain; charset=UTF-8'); echo "User-agent: *\nAllow: /\n";
PHP
chown -R www-data:www-data /var/www/offers/_offerra-smoke

HTTP_CODE="$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: _offerra-smoke' http://127.0.0.1/)"
PHP_BODY="$(curl -s -H 'Host: _offerra-smoke' http://127.0.0.1/)"
SITEMAP_CT="$(curl -sI -H 'Host: _offerra-smoke' http://127.0.0.1/sitemap.xml | tr -d '\r' | grep -i '^content-type:' | head -1)"
SITEMAP_BODY="$(curl -s -H 'Host: _offerra-smoke' http://127.0.0.1/sitemap.xml | head -c 80)"
ROBOTS_BODY="$(curl -s -H 'Host: _offerra-smoke' http://127.0.0.1/robots.txt | head -c 80)"
rm -rf /var/www/offers/_offerra-smoke

echo
echo "PHP=$(php -v | head -1)"
echo "FPM=$(systemctl is-active "$FPM_SVC") sock=${PHP_SOCK}"
echo "NGINX=$(systemctl is-active nginx)"
echo "SMOKE_HTTP=${HTTP_CODE} BODY=${PHP_BODY}"
echo "SMOKE_SITEMAP_CT=${SITEMAP_CT}"
echo "SMOKE_SITEMAP=${SITEMAP_BODY}"
echo "SMOKE_ROBOTS=${ROBOTS_BODY}"
echo
echo "----------------------------------------------"
echo " Offerra → Settings → Server"
echo "   SSH HOST / IP : ${IP}"
echo "   PORT          : 22"
echo "   SSH USER      : root"
echo "   SSH PASSWORD  : (root password of this VPS)"
echo " Path template (auto): /var/www/offers/{domain}/public_html"
echo "----------------------------------------------"
echo "DONE"
