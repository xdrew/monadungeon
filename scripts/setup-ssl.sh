#!/bin/bash
set -e

# SSL Setup Script for Karak-X on Hetzner

# Configuration
PROJECT_DIR="/opt/monadungeon"
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

# Check if running as root or with sudo
if [[ $EUID -ne 0 ]]; then
   log_error "This script must be run as root or with sudo"
   exit 1
fi

# Navigate to project directory
cd $PROJECT_DIR

# Get domain from user
if [ -z "$1" ]; then
    read -p "Enter your domain name (e.g., karak.yourdomain.com): " DOMAIN
else
    DOMAIN=$1
fi

# Get email for Let's Encrypt
if [ -z "$2" ]; then
    read -p "Enter your email for Let's Encrypt notifications: " EMAIL
else
    EMAIL=$2
fi

log_info "Setting up SSL for domain: $DOMAIN"

# Ensure HTTP-only config is being used
log_info "Using HTTP-only configuration..."
sed -i 's|prod.conf|prod-http-only.conf|g' $COMPOSE_FILE

# Restart nginx with HTTP-only config
log_info "Restarting Nginx with HTTP-only configuration..."
docker compose -f $COMPOSE_FILE restart nginx

# Wait for nginx to be ready
sleep 5

# Request SSL certificate
log_info "Requesting SSL certificate from Let's Encrypt..."
docker compose -f $COMPOSE_FILE run --rm certbot certonly \
    --webroot \
    --webroot-path=/var/www/certbot \
    --email $EMAIL \
    --agree-tos \
    --no-eff-email \
    --force-renewal \
    -d $DOMAIN

# Check if certificate was created successfully
if [ -d "/opt/monadungeon/letsencrypt/live/$DOMAIN" ]; then
    log_info "SSL certificate created successfully!"
    
    # Update nginx config to use SSL
    log_info "Updating Nginx configuration for SSL..."
    
    # Create SSL-enabled config from template
    cat > docker/nginx/prod-ssl.conf << EOF
# Rate limiting
limit_req_zone \$binary_remote_addr zone=api_limit:10m rate=10r/s;
limit_req_zone \$binary_remote_addr zone=general_limit:10m rate=30r/s;

# HTTP to HTTPS redirect
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;

    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        return 301 https://\$server_name\$request_uri;
    }
}

# HTTPS server
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name $DOMAIN;

    # SSL certificates
    ssl_certificate /etc/letsencrypt/live/$DOMAIN/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$DOMAIN/privkey.pem;
    ssl_trusted_certificate /etc/letsencrypt/live/$DOMAIN/chain.pem;

    # SSL configuration
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;
    ssl_session_timeout 1d;
    ssl_session_cache shared:SSL:10m;
    ssl_session_tickets off;
    ssl_stapling on;
    ssl_stapling_verify on;

    # Security headers
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # Frontend (Vue.js)
    location / {
        root /usr/share/nginx/html;
        try_files \$uri \$uri/ /index.html;
        
        # Apply rate limiting
        limit_req zone=general_limit burst=20 nodelay;
        
        # Cache static assets
        location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
            expires 30d;
            add_header Cache-Control "public, immutable";
        }
    }

    # API proxy to PHP/RoadRunner
    location /api {
        # Apply stricter rate limiting for API
        limit_req zone=api_limit burst=5 nodelay;
        
        proxy_pass http://php:8080;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        
        # WebSocket support (if needed)
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        
        # Timeouts for long-running requests
        proxy_connect_timeout 60s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;
    }

    # Health check endpoint
    location /health {
        access_log off;
        add_header Content-Type text/plain;
        return 200 'healthy';
    }

    # Deny access to sensitive files
    location ~ /\.(git|env|htaccess) {
        deny all;
    }

    # Gzip compression
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/plain text/css text/xml text/javascript application/json application/javascript application/xml+rss application/rss+xml application/atom+xml image/svg+xml text/x-js text/x-cross-domain-policy application/x-font-ttf application/x-font-opentype application/vnd.ms-fontobject image/x-icon;

    # Logging
    access_log /var/log/nginx/access.log;
    error_log /var/log/nginx/error.log warn;
}
EOF

    # Update compose file to use SSL config
    sed -i 's|prod-http-only.conf|prod-ssl.conf|g' $COMPOSE_FILE
    
    # Restart nginx with SSL
    log_info "Restarting Nginx with SSL configuration..."
    docker compose -f $COMPOSE_FILE restart nginx
    
    log_info "SSL setup completed successfully!"
    log_info "Your application is now available at: https://$DOMAIN"
    
else
    log_error "Failed to create SSL certificate"
    log_info "Please check your domain DNS settings and try again"
    exit 1
fi