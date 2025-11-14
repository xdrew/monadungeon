#!/bin/bash
set -e

# SSL Setup Script for monadungeon.xyz

# Configuration
PROJECT_DIR="/opt/monadungeon"
DOMAIN="monadungeon.xyz"
COMPOSE_FILE="compose.prod.yml"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Functions
log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if running as root
if [[ $EUID -ne 0 ]]; then
   log_error "This script must be run as root or with sudo"
   exit 1
fi

# Navigate to project directory
cd $PROJECT_DIR

# Get email for Let's Encrypt
if [ -z "$1" ]; then
    read -p "Enter your email for Let's Encrypt notifications: " EMAIL
else
    EMAIL=$1
fi

log_info "Setting up SSL for monadungeon.xyz..."

# First, create a temporary HTTP-only config for Let's Encrypt verification
log_info "Creating temporary HTTP-only configuration..."
cat > docker/nginx/temp-http.conf << 'EOF'
server {
    listen 80;
    listen [::]:80;
    server_name monadungeon.xyz www.monadungeon.xyz;

    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        root /usr/share/nginx/html;
        try_files $uri $uri/ /index.html;
    }

    location /api {
        proxy_pass http://php:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
EOF

# Use temporary config
log_info "Switching to temporary HTTP configuration..."
docker compose -f $COMPOSE_FILE exec nginx sh -c "cp /etc/nginx/conf.d/default.conf /etc/nginx/conf.d/default.conf.bak" || true
docker cp docker/nginx/temp-http.conf nginx_monadungeon_prod:/etc/nginx/conf.d/default.conf
docker compose -f $COMPOSE_FILE exec nginx nginx -s reload

# Wait for nginx to reload
sleep 3

# Test HTTP is working
log_info "Testing HTTP access..."
if curl -f -s -o /dev/null "http://$DOMAIN"; then
    log_info "HTTP access confirmed"
else
    log_warning "HTTP access test failed, but continuing..."
fi

# Request SSL certificate
log_info "Requesting SSL certificate from Let's Encrypt..."
docker compose -f $COMPOSE_FILE run --rm certbot certonly \
    --webroot \
    --webroot-path=/var/www/certbot \
    --email $EMAIL \
    --agree-tos \
    --no-eff-email \
    --force-renewal \
    -d $DOMAIN \
    -d showcase.$DOMAIN \
    -d www.$DOMAIN

# Check if certificate was created successfully
if docker compose -f $COMPOSE_FILE exec nginx test -f /etc/letsencrypt/live/$DOMAIN/fullchain.pem; then
    log_info "SSL certificate created successfully!"
    
    # Switch back to full SSL config
    log_info "Enabling SSL configuration..."
    docker compose -f $COMPOSE_FILE restart nginx
    
    # Wait for nginx to start
    sleep 5
    
    # Test HTTPS
    log_info "Testing HTTPS access..."
    if curl -f -s -o /dev/null "https://$DOMAIN"; then
        log_info "HTTPS is working!"
    else
        log_warning "HTTPS test failed. Check nginx logs: docker logs nginx_monadungeon_prod"
    fi
    
    # Clean up temp file
    rm -f docker/nginx/temp-http.conf
    
    log_info "SSL setup completed successfully!"
    log_info "Your application is now available at:"
    log_info "  - https://monadungeon.xyz"
    log_info "  - https://www.monadungeon.xyz (redirects to non-www)"
    log_info ""
    log_info "Certificate will auto-renew via the certbot container"
    
else
    log_error "Failed to create SSL certificate"
    log_info "Reverting to HTTP-only configuration..."
    docker compose -f $COMPOSE_FILE restart nginx
    
    log_info "Please check:"
    log_info "1. DNS records for monadungeon.xyz point to this server"
    log_info "2. Ports 80 and 443 are open in firewall"
    log_info "3. No other service is using ports 80/443"
    exit 1
fi