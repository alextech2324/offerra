#!/bin/bash
# Offerra edge proxy — Ubuntu 22.04 / 24.04
# TLS at the edge (Let's Encrypt), HTTP to origin, CF-IPCountry via GeoIP.
# Idempotent. Run from the panel jump, never from a laptop.
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
  nginx curl ca-certificates ufw dnsutils unzip gzip \
  certbot python3-certbot-dns-cloudflare

apt-get install -y libnginx-mod-http-geoip2 || true
apt-get install -y libnginx-mod-http-geoip geoip-database || true

systemctl stop apache2 2>/dev/null || true
systemctl disable apache2 2>/dev/null || true
systemctl mask apache2 2>/dev/null || true

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
systemctl mask systemd-resolved 2>/dev/null || true
/usr/local/bin/offerra-fix-dns.sh

log "disable ipv6"
cat > /etc/sysctl.d/99-offerra-ipv6.conf <<'EOF'
net.ipv6.conf.all.disable_ipv6 = 1
net.ipv6.conf.default.disable_ipv6 = 1
net.ipv6.conf.lo.disable_ipv6 = 1
EOF
sysctl -p /etc/sysctl.d/99-offerra-ipv6.conf >/dev/null || true

log "geoip country db"
mkdir -p /usr/share/GeoIP
GEO_OK=0
YM="$(date -u +%Y-%m)"
PREV="$(date -u -d '1 month ago' +%Y-%m 2>/dev/null || date -u -v-1m +%Y-%m 2>/dev/null || echo "")"
for stamp in "$YM" "$PREV"; do
  [ -n "$stamp" ] || continue
  URL="https://download.db-ip.com/free/dbip-country-lite-${stamp}.mmdb.gz"
  if curl -fsSL --max-time 60 "$URL" | gunzip > /usr/share/GeoIP/dbip-country-lite.mmdb.tmp; then
    mv /usr/share/GeoIP/dbip-country-lite.mmdb.tmp /usr/share/GeoIP/dbip-country-lite.mmdb
    GEO_OK=1
    echo "geoip mmdb=${stamp}"
    break
  fi
  rm -f /usr/share/GeoIP/dbip-country-lite.mmdb.tmp
done
if [ "$GEO_OK" != "1" ]; then
  echo "WARN: db-ip mmdb download failed; country header may be XX until a DB is present"
fi

mkdir -p /etc/nginx/conf.d /etc/nginx/sites-available /etc/nginx/sites-enabled /var/www/certbot
rm -f /etc/nginx/sites-enabled/default

if [ -f /usr/share/GeoIP/dbip-country-lite.mmdb ] && [ -e /usr/share/nginx/modules-available/mod-http-geoip2.conf -o -e /etc/nginx/modules-enabled/50-mod-http-geoip2.conf -o -e /usr/lib/nginx/modules/ngx_http_geoip2_module.so ]; then
  cat > /etc/nginx/conf.d/offerra-geoip.conf <<'NGINX'
geoip2 /usr/share/GeoIP/dbip-country-lite.mmdb {
    auto_reload 1d;
    $geoip2_data_country_code default=XX source=$remote_addr country iso_code;
}
map $geoip2_data_country_code $offerra_country {
    default $geoip2_data_country_code;
    ""      XX;
}
NGINX
elif [ -f /usr/share/GeoIP/GeoIP.dat ]; then
  cat > /etc/nginx/conf.d/offerra-geoip.conf <<'NGINX'
geoip_country /usr/share/GeoIP/GeoIP.dat;
map $geoip_country_code $offerra_country {
    default $geoip_country_code;
    ""      XX;
}
NGINX
else
  cat > /etc/nginx/conf.d/offerra-geoip.conf <<'NGINX'
map $remote_addr $offerra_country {
    default XX;
}
NGINX
fi

# Older geoip2 builds reject auto_reload / default= — fall back to a stub map.
if ! nginx -t >/tmp/offerra-nginx-t.out 2>&1; then
  echo "WARN: geoip nginx config failed, using XX stub"
  cat /tmp/offerra-nginx-t.out || true
  cat > /etc/nginx/conf.d/offerra-geoip.conf <<'NGINX'
map $remote_addr $offerra_country {
    default XX;
}
NGINX
fi

cat > /etc/nginx/conf.d/offerra-edge-default.conf <<'NGINX'
server {
    listen 80 default_server;
    server_name _;
    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }
    location / {
        return 444;
    }
}
NGINX

mkdir -p /etc/letsencrypt
cat > /etc/letsencrypt/cli.ini <<'EOF'
agree-tos = true
non-interactive = true
keep-until-expiring = true
expand = true
renew-hook = systemctl reload nginx
EOF

log "edge helper"
install -m 755 /dev/stdin /usr/local/bin/offerra-edge <<'EOF'
#!/bin/bash
set -euo pipefail

usage() {
  echo "usage: offerra-edge bind <domain> <origin_ip> [cf.ini]" >&2
  echo "       offerra-edge unbind <domain>" >&2
  echo "       offerra-edge health" >&2
  exit 1
}

valid_domain() {
  echo "$1" | grep -Eq '^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$'
}

valid_ip() {
  echo "$1" | grep -Eq '^[0-9]{1,3}(\.[0-9]{1,3}){3}$'
}

site_path() {
  echo "/etc/nginx/sites-available/offerra-$1.conf"
}

write_vhost() {
  local domain="$1" origin="$2" have_cert="$3"
  local cert="/etc/letsencrypt/live/${domain}/fullchain.pem"
  local key="/etc/letsencrypt/live/${domain}/privkey.pem"
  local file
  file="$(site_path "$domain")"

  if [ "$have_cert" = "1" ]; then
    cat > "$file" <<NGINX
server {
    listen 80;
    server_name ${domain} www.${domain};
    location /.well-known/acme-challenge/ { root /var/www/certbot; }
    location / { return 301 https://${domain}\$request_uri; }
}
server {
    listen 443 ssl http2;
    server_name www.${domain};
    ssl_certificate ${cert};
    ssl_certificate_key ${key};
    ssl_session_timeout 1d;
    ssl_session_cache shared:OfferraSSL:10m;
    ssl_protocols TLSv1.2 TLSv1.3;
    return 301 https://${domain}\$request_uri;
}
server {
    listen 443 ssl http2;
    server_name ${domain};
    ssl_certificate ${cert};
    ssl_certificate_key ${key};
    ssl_session_timeout 1d;
    ssl_session_cache shared:OfferraSSL:10m;
    ssl_protocols TLSv1.2 TLSv1.3;
    client_max_body_size 32m;

    location / {
        proxy_pass http://${origin};
        proxy_http_version 1.1;
        proxy_connect_timeout 8s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header CF-Connecting-IP \$remote_addr;
        proxy_set_header CF-IPCountry \$offerra_country;
        proxy_set_header CF-Visitor '{"scheme":"https"}';
        proxy_hide_header X-Powered-By;
    }
}
NGINX
  else
    cat > "$file" <<NGINX
server {
    listen 80;
    server_name ${domain} www.${domain};
    location /.well-known/acme-challenge/ { root /var/www/certbot; }
    location / {
        proxy_pass http://${origin};
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header CF-Connecting-IP \$remote_addr;
        proxy_set_header CF-IPCountry \$offerra_country;
    }
}
NGINX
  fi

  ln -sfn "$file" "/etc/nginx/sites-enabled/offerra-${domain}.conf"
}

cmd="${1:-}"
shift || true

case "$cmd" in
  bind)
    domain="$(echo "${1:-}" | tr 'A-Z' 'a-z')"
    origin="${2:-}"
    cf_ini="${3:-}"
    valid_domain "$domain" || { echo "bad domain: $domain" >&2; exit 2; }
    valid_ip "$origin" || { echo "bad origin ip: $origin" >&2; exit 2; }
    exec 9>/var/lock/offerra-edge.lock
    flock -w 240 9

    have_cert=0
    if [ -n "$cf_ini" ]; then
      chmod 600 "$cf_ini" 2>/dev/null || true
      certbot certonly \
        --dns-cloudflare \
        --dns-cloudflare-credentials "$cf_ini" \
        --dns-cloudflare-propagation-seconds 25 \
        --non-interactive --agree-tos --register-unsafely-without-email \
        --keep-until-expiring --expand \
        -d "$domain" -d "www.$domain"
      rm -f "$cf_ini"
      have_cert=1
    elif [ -f "/etc/letsencrypt/live/${domain}/fullchain.pem" ]; then
      have_cert=1
    fi

    write_vhost "$domain" "$origin" "$have_cert"
    nginx -t
    systemctl reload nginx
    echo "BOUND ${domain} -> ${origin} cert=${have_cert}"
    ;;
  unbind)
    domain="$(echo "${1:-}" | tr 'A-Z' 'a-z')"
    valid_domain "$domain" || { echo "bad domain: $domain" >&2; exit 2; }
    exec 9>/var/lock/offerra-edge.lock
    flock -w 60 9
    rm -f "$(site_path "$domain")" "/etc/nginx/sites-enabled/offerra-${domain}.conf"
    nginx -t
    systemctl reload nginx
    echo "UNBOUND ${domain}"
    ;;
  health)
    echo "nginx=$(systemctl is-active nginx)"
    echo "geoip_mmdb=$([ -f /usr/share/GeoIP/dbip-country-lite.mmdb ] && echo yes || echo no)"
    echo "certbot=$(command -v certbot >/dev/null && echo yes || echo no)"
    echo "sites=$(ls -1 /etc/nginx/sites-enabled/offerra-*.conf 2>/dev/null | wc -l)"
    echo "ipv6=$(cat /proc/sys/net/ipv6/conf/all/disable_ipv6 2>/dev/null || echo ?)"
    curl -sI --max-time 4 http://127.0.0.1/ | head -n 1 || true
    echo "RESULT: OK"
    ;;
  *)
    usage
    ;;
esac
EOF

log "firewall"
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

log "nginx"
if grep -qE '^#?MaxStartups' /etc/ssh/sshd_config; then
  sed -i 's/^#\?MaxStartups.*/MaxStartups 80/' /etc/ssh/sshd_config
else
  echo 'MaxStartups 80' >> /etc/ssh/sshd_config
fi
systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true

mkdir -p /etc/systemd/system/nginx.service.d
cat > /etc/systemd/system/nginx.service.d/nofile.conf <<'EOF'
[Service]
LimitNOFILE=65535
EOF
systemctl daemon-reload
systemctl enable --now nginx
nginx -t
systemctl reload nginx

IP="$(hostname -I | awk '{print $1}')"
echo
echo "NGINX=$(systemctl is-active nginx)"
offerra-edge health || true
echo
echo "----------------------------------------------"
echo " Offerra edge proxy"
echo "   SSH HOST / IP : ${IP}"
echo "   PORT          : 22"
echo "   HTTPS         : Let's Encrypt on bind"
echo "----------------------------------------------"
echo "DONE"
