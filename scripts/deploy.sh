#!/bin/bash
set -e

# Deployment script for monadungeon on Hetzner

# Configuration
PROJECT_DIR="/opt/monadungeon"
BACKUP_DIR="/opt/backups"
ENV_FILE=".env.prod"
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

log_info "Starting deployment process..."

# Pull latest code from git
log_info "Pulling latest code from repository..."
git pull origin main

# Check if .env.prod exists
if [ ! -f "$ENV_FILE" ]; then
    log_error "Production environment file ($ENV_FILE) not found!"
    log_info "Please copy .env.prod.example to .env.prod and configure it"
    exit 1
fi

# Frontend will be built inside Docker container
log_info "Frontend will be built in Docker container..."

# Create backup before deployment
log_info "Creating database backup..."
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
docker compose -f $COMPOSE_FILE --env-file $ENV_FILE exec -T db pg_dump -U monadungeon monadungeon > "$BACKUP_DIR/monadungeon_backup_$TIMESTAMP.sql" 2>/dev/null || log_warning "Database backup failed (might be first deployment)"

# Build Docker images (including frontend)
log_info "Building Docker images (including frontend)..."
# Load environment variables from .env.prod for the build
docker compose -f $COMPOSE_FILE --env-file $ENV_FILE build --no-cache frontend-builder
docker compose -f $COMPOSE_FILE --env-file $ENV_FILE build

# Stop current containers
log_info "Stopping current containers..."
docker compose -f $COMPOSE_FILE --env-file $ENV_FILE down

# Start new containers
log_info "Starting new containers..."
docker compose -f $COMPOSE_FILE --env-file $ENV_FILE up -d

# Wait for services to be healthy
log_info "Waiting for services to be healthy..."
sleep 10

# Check if database is ready
until docker compose -f $COMPOSE_FILE --env-file $ENV_FILE exec -T db pg_isready -U monadungeon > /dev/null 2>&1; do
    log_info "Waiting for database to be ready..."
    sleep 2
done

# Run database migrations
log_info "Running database migrations..."
docker compose -f $COMPOSE_FILE --env-file $ENV_FILE exec -T php php bin/console doctrine:migrations:migrate --no-interaction

# Clear cache
log_info "Clearing application cache..."
docker compose -f $COMPOSE_FILE --env-file $ENV_FILE exec -T php php bin/console cache:clear

# Warm up cache
log_info "Warming up cache..."
docker compose -f $COMPOSE_FILE --env-file $ENV_FILE exec -T php php bin/console cache:warmup

# Health check - just verify nginx is responding
log_info "Performing health check..."
sleep 5
# Check if nginx is responding (frontend should return 200)
HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/ || echo "000")

if [ "$HTTP_STATUS" = "200" ] || [ "$HTTP_STATUS" = "301" ] || [ "$HTTP_STATUS" = "302" ]; then
    log_info "Health check passed! Application is running. (HTTP status: $HTTP_STATUS)"
else
    log_error "Health check failed! HTTP status: $HTTP_STATUS"
    log_info "Checking container logs..."
    docker compose -f $COMPOSE_FILE --env-file $ENV_FILE logs --tail=50
    exit 1
fi

# Clean up old backups (keep last 7 days)
log_info "Cleaning up old backups..."
find $BACKUP_DIR -name "monadungeon_backup_*.sql" -mtime +7 -delete

# Show running containers
log_info "Deployment completed successfully!"
docker compose -f $COMPOSE_FILE --env-file $ENV_FILE ps

log_info "Application is available at https://$(grep SERVER_NAME $ENV_FILE | cut -d'=' -f2)"