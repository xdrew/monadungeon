#!/bin/bash
set -euo pipefail

# SSL setup for the main site vhost (karak.avelent.work).
#
# The nginx config references /etc/letsencrypt/live/$DOMAIN/*.pem, so nginx
# cannot start before the certificate exists. This script breaks that
# chicken-and-egg with a short-lived self-signed placeholder: nginx boots on the
# dummy, serves the ACME challenge over :80, certbot swaps in the real
# certificate, nginx restarts. The showcase.* and watermelon.* vhosts keep
# running throughout — they have their own certificate lineages and are not
# touched here.

PROJECT_DIR="${PROJECT_DIR:-/opt/monadungeon}"
DOMAIN="${DOMAIN:-karak.avelent.work}"
EMAIL="${EMAIL:-ripxdrew@gmail.com}"
COMPOSE_FILE="compose.prod.yml"
ENV_FILE=".env.prod"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log_info()    { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warning() { echo -e "${YELLOW}[WARNING]${NC} $1"; }
log_error()   { echo -e "${RED}[ERROR]${NC} $1"; }

if [[ $EUID -ne 0 ]]; then
    log_error "This script must be run as root or with sudo"
    exit 1
fi

cd "$PROJECT_DIR"

if [ ! -f "$ENV_FILE" ]; then
    log_error "Production environment file ($ENV_FILE) not found in $PROJECT_DIR"
    exit 1
fi

dc() { docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"; }

# Run a shell command inside the certbot image, which has the letsencrypt
# volume mounted read-write.
certbot_sh() { dc run --rm --entrypoint sh certbot -c "$1"; }

LIVE_DIR="/etc/letsencrypt/live/$DOMAIN"

make_dummy_cert() {
    certbot_sh "mkdir -p $LIVE_DIR && \
        openssl req -x509 -nodes -newkey rsa:2048 -days 1 \
            -keyout $LIVE_DIR/privkey.pem \
            -out $LIVE_DIR/fullchain.pem \
            -subj '/CN=$DOMAIN' 2>/dev/null && \
        cp $LIVE_DIR/fullchain.pem $LIVE_DIR/chain.pem"
}

# ---------------------------------------------------------------------------
# 1. DNS sanity check
# ---------------------------------------------------------------------------
log_info "Checking DNS for $DOMAIN..."
RESOLVED=$(getent hosts "$DOMAIN" | awk '{print $1}' | head -1 || true)
SERVER_IP=$(curl -s --max-time 10 https://ifconfig.me || true)

if [ -z "$RESOLVED" ]; then
    log_error "$DOMAIN does not resolve. Create the DNS A record first."
    exit 1
fi
log_info "  $DOMAIN resolves to $RESOLVED"
if [ -n "$SERVER_IP" ] && [ "$RESOLVED" != "$SERVER_IP" ]; then
    log_warning "  This server's public IP is $SERVER_IP — they do not match."
    log_warning "  Let's Encrypt validation will fail unless traffic still reaches this host."
fi

# ---------------------------------------------------------------------------
# 2. Placeholder certificate so nginx can load the new config
# ---------------------------------------------------------------------------
if certbot_sh "test -f $LIVE_DIR/fullchain.pem" >/dev/null 2>&1; then
    log_info "Certificate material for $DOMAIN already present, reusing it to boot nginx"
else
    log_info "Creating temporary self-signed certificate for $DOMAIN..."
    make_dummy_cert
fi

# ---------------------------------------------------------------------------
# 3. Bring nginx up on the new config
# ---------------------------------------------------------------------------
log_info "Starting nginx with the new configuration..."
dc up -d nginx
dc restart nginx
sleep 3

if ! dc exec -T nginx nginx -t; then
    log_error "nginx configuration test failed — see output above"
    exit 1
fi

# ---------------------------------------------------------------------------
# 4. Verify the ACME webroot is reachable through nginx
# ---------------------------------------------------------------------------
log_info "Verifying ACME challenge path is reachable over HTTP..."
TOKEN="preflight-$$"
certbot_sh "mkdir -p /var/www/certbot/.well-known/acme-challenge && \
    echo $TOKEN > /var/www/certbot/.well-known/acme-challenge/$TOKEN"

CHALLENGE_URL="http://$DOMAIN/.well-known/acme-challenge/$TOKEN"
if [ "$(curl -s --max-time 15 "$CHALLENGE_URL" || true)" = "$TOKEN" ]; then
    log_info "  ACME challenge path OK"
else
    log_error "  Could not fetch $CHALLENGE_URL"
    log_error "  Check that port 80 is open and DNS points here, then re-run."
    certbot_sh "rm -f /var/www/certbot/.well-known/acme-challenge/$TOKEN"
    exit 1
fi
certbot_sh "rm -f /var/www/certbot/.well-known/acme-challenge/$TOKEN"

# ---------------------------------------------------------------------------
# 5. Request the real certificate
# ---------------------------------------------------------------------------
# certbot refuses to write over a live directory it does not manage, so the
# placeholder is removed first. nginx keeps serving from its open file handles
# until it is restarted, so there is no downtime in between.
log_info "Removing placeholder certificate..."
certbot_sh "rm -rf $LIVE_DIR /etc/letsencrypt/archive/$DOMAIN /etc/letsencrypt/renewal/$DOMAIN.conf"

restore_dummy_on_failure() {
    log_error "Certificate issuance failed."
    log_warning "Restoring the placeholder certificate so nginx can still start."
    make_dummy_cert || true
    dc restart nginx || true
    log_info "Things to check:"
    log_info "  1. DNS A record for $DOMAIN points to this server"
    log_info "  2. Ports 80 and 443 are open in the firewall"
    log_info "  3. Let's Encrypt rate limits (5 failures/hour per account+host)"
    exit 1
}
trap restore_dummy_on_failure ERR

# --entrypoint certbot is required: the compose service's entrypoint is an
# infinite `certbot renew` loop, so without the override the certonly arguments
# below are silently ignored and the container just renews everything else.
log_info "Requesting SSL certificate from Let's Encrypt for $DOMAIN..."
dc run --rm --entrypoint certbot certbot certonly \
    --webroot \
    --webroot-path=/var/www/certbot \
    --email "$EMAIL" \
    --agree-tos \
    --no-eff-email \
    --cert-name "$DOMAIN" \
    -d "$DOMAIN"

trap - ERR

# ---------------------------------------------------------------------------
# 6. Reload nginx onto the real certificate
# ---------------------------------------------------------------------------
log_info "Restarting nginx with the issued certificate..."
dc restart nginx
sleep 5

log_info "Testing HTTPS..."
if curl -f -s -o /dev/null "https://$DOMAIN"; then
    log_info "HTTPS is working!"
else
    log_warning "HTTPS test failed. Check logs: docker logs nginx_monadungeon_prod"
fi

log_info ""
log_info "SSL setup completed."
log_info "  https://$DOMAIN"
log_info ""
log_info "Renewal is handled by the certbot container (checks every 12h)."
log_info "The showcase.monadungeon.xyz and watermelon.monadungeon.xyz certificates"
log_info "are separate lineages and were not modified by this script."
