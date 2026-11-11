#!/bin/bash
# Offerra origin bootstrap — AlmaLinux / RHEL / Rocky 8.x
# Matches Ubuntu layout: /var/www/offers/{domain}/public_html, user www-data
# (OriginHostService always chowns www-data).
# Idempotent.
set -euo pipefail

log() { echo "=== $* ==="; }

if [ "$(id -u)" -ne 0 ]; then
  echo "Run as root."
  exit 1
fi

if [ ! -f /etc/redhat-release ] && [ ! -f /etc/almalinux-release ]; then
  echo "This script is for RHEL-family. Use server/bootstrap-origin.sh on Ubuntu."
  exit 1
fi

log "packages"
dnf -y install epel-release || true
dnf -y module reset php
dnf -y module enable php:8.2
dnf -y install \
  nginx \
  php-fpm php-cli php-mbstring php-xml php-json php-gd php-intl php-zip php-opcache \
  tar gzip unzip curl ca-certificates bind-utils firewalld python3

if ! php -r 'exit((int) PHP_MAJOR_VERSION >= 8 ? 0 : 1);' 2>/dev/null; then
  echo "PHP 8.x required; got $(php -v | head -1)"
  exit 1
fi
log "php $(php -r 'echo PHP_VERSION;')"

systemctl stop httpd 2>/dev/null || true
systemctl disable httpd 2>/dev/null || true
systemctl mask httpd 2>/dev/null || true

log "www-data user (matches Ubuntu deploys)"
getent group www-data >/dev/null || groupadd -r www-data
id -u www-data >/dev/null 2>&1 || useradd -r -g www-data -s /sbin/nologin -d /var/www www-data
id nginx >/dev/null 2>&1 && usermod -aG www-data nginx || true

log "selinux permissive (php-fpm as www-data + nginx catch-all)"
if command -v getenforce >/dev/null 2>&1 && [ "$(getenforce)" != "Disabled" ]; then
  setenforce 0 || true
  if [ -f /etc/selinux/config ]; then
    sed -i 's/^SELINUX=enforcing/SELINUX=permissive/' /etc/selinux/config
  fi
fi

log "dns resolvers"
mkdir -p /usr/local/bin
cat > /usr/local/bin/offerra-fix-dns.sh <<'EOF'
#!/bin/bash
chattr -i /etc/resolv.conf 2>/dev/null || true
printf 'nameserver 1.1.1.1\nnameserver 8.8.8.8\n' > /etc/resolv.conf
chattr +i /etc/resolv.conf 2>/dev/null || true
EOF
chmod 755 /usr/local/bin/offerra-fix-dns.sh
systemctl disable --now systemd-resolved 2>/dev/null || true
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

WWW_CONF="/etc/php-fpm.d/www.conf"
PHP_INI="/etc/php.ini"
FPM_SVC="php-fpm"
PHP_SOCK="/run/php-fpm/www.sock"

if [ ! -f "$WWW_CONF" ]; then
  echo "missing $WWW_CONF"
  exit 1
fi

log "php-fpm www-data + pool"
sed -i 's/^user = .*/user = www-data/' "$WWW_CONF"
sed -i 's/^group = .*/group = www-data/' "$WWW_CONF"
sed -i 's|^listen = .*|listen = /run/php-fpm/www.sock|' "$WWW_CONF"
if grep -q '^listen.owner' "$WWW_CONF"; then
  sed -i 's/^listen.owner = .*/listen.owner = www-data/' "$WWW_CONF"
  sed -i 's/^listen.group = .*/listen.group = www-data/' "$WWW_CONF"
else
  sed -i '/^listen = /a listen.owner = www-data\nlisten.group = www-data\nlisten.mode = 0660' "$WWW_CONF"
fi
if grep -q '^listen.mode' "$WWW_CONF"; then
  sed -i 's/^listen.mode = .*/listen.mode = 0660/' "$WWW_CONF"
fi
# RHEL php-fpm ignores listen.owner/group when ACL users are set, leaving
# the sock as root:root + ACL for apache/nginx — not www-data.
sed -i 's/^listen.acl_users/;listen.acl_users/' "$WWW_CONF"
sed -i 's/^listen.acl_groups/;listen.acl_groups/' "$WWW_CONF"
sed -i 's/^pm = .*/pm = dynamic/' "$WWW_CONF"
sed -i 's/^pm.max_children = .*/pm.max_children = 40/' "$WWW_CONF"
sed -i 's/^pm.start_servers = .*/pm.start_servers = 8/' "$WWW_CONF"
sed -i 's/^pm.min_spare_servers = .*/pm.min_spare_servers = 4/' "$WWW_CONF"
sed -i 's/^pm.max_spare_servers = .*/pm.max_spare_servers = 16/' "$WWW_CONF"

if [ -f "$PHP_INI" ]; then
  sed -i 's/^memory_limit = .*/memory_limit = 256M/' "$PHP_INI"
  sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 32M/' "$PHP_INI"
  sed -i 's/^post_max_size = .*/post_max_size = 32M/' "$PHP_INI"
  sed -i 's/^;*\s*cgi.fix_pathinfo\s*=.*/cgi.fix_pathinfo = 0/' "$PHP_INI"
fi

log "nginx user www-data + offers vhost"
if grep -qE '^user ' /etc/nginx/nginx.conf; then
  sed -i 's/^user .*/user www-data;/' /etc/nginx/nginx.conf
else
  sed -i '1i user www-data;' /etc/nginx/nginx.conf
fi

mkdir -p /etc/nginx/conf.d
rm -f /etc/nginx/conf.d/default.conf /etc/nginx/conf.d/php-fpm.conf
mkdir -p /var/log/nginx /var/lib/nginx/tmp
chown -R www-data:www-data /var/log/nginx /var/lib/nginx

# Alma ships a default_server inside nginx.conf which clashes with conf.d/offers.conf.
python3 - <<'PY'
from pathlib import Path

p = Path("/etc/nginx/nginx.conf")
lines = p.read_text().splitlines(True)
out = []
i = 0
while i < len(lines):
    nxt = lines[i + 1] if i + 1 < len(lines) else ""
    if lines[i].startswith("    server {") and "listen" in nxt and "80 default_server" in nxt:
        out.append("    # offerra: packaged default_server disabled — catch-all is conf.d/offers.conf\n")
        depth = 0
        while i < len(lines):
            line = lines[i]
            depth += line.count("{") - line.count("}")
            if line.lstrip().startswith("#"):
                out.append(line)
            else:
                out.append("#" + line)
            i += 1
            if depth <= 0:
                break
        continue
    out.append(lines[i])
    i += 1
p.write_text("".join(out))
PY

cat > /etc/nginx/conf.d/cloudflare-realip.conf <<'NGINX'
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

cat > /etc/nginx/conf.d/offers.conf <<NGINX
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    client_max_body_size 32m;

    set \$offer_root /var/www/offers/\$offer_domain/public_html;
    root \$offer_root;
    index index.php index.html;

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

    # Named captures + set: numbered \$1 is empty inside nginx `if`.
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

log "systemd limits + ssh concurrency"
mkdir -p /etc/systemd/system/php-fpm.service.d /etc/systemd/system/nginx.service.d
cat > /etc/systemd/system/php-fpm.service.d/nofile.conf <<'EOF'
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
systemctl reload sshd 2>/dev/null || true

log "firewall"
systemctl enable --now firewalld 2>/dev/null || true
if command -v firewall-cmd >/dev/null 2>&1 && systemctl is-active --quiet firewalld; then
  firewall-cmd --permanent --add-service=ssh
  firewall-cmd --permanent --add-service=http
  firewall-cmd --permanent --add-service=https
  firewall-cmd --reload
fi

log "start services"
systemctl daemon-reload
# php-fpm must create the sock before nginx starts
systemctl enable --now "$FPM_SVC"
# wait for sock
for i in 1 2 3 4 5; do
  [ -S "$PHP_SOCK" ] && break
  sleep 1
done
systemctl enable --now nginx
nginx -t
systemctl restart "$FPM_SVC" nginx

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
echo " Offerra → Origin Servers (spare)"
echo "   SSH HOST / IP : ${IP}"
echo "   PORT          : 22"
echo "   SSH USER      : root"
echo " Path template   : /var/www/offers/{domain}/public_html"
echo "----------------------------------------------"
echo "DONE"
