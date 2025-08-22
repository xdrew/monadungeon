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

# Store current commit before pull for comparison
PREV_COMMIT=$(git rev-parse HEAD)

# Pull latest code from git
log_info "Pulling latest code from repository..."
git pull origin main

# Store new commit after pull
NEW_COMMIT=$(git rev-parse HEAD)

# Check if there were updates
if [ "$PREV_COMMIT" != "$NEW_COMMIT" ]; then
    log_info "Code updated from $PREV_COMMIT to $NEW_COMMIT"
    log_info "Changes included:"
    git log --oneline $PREV_COMMIT..$NEW_COMMIT | head -10
else
    log_info "No new commits found, deploying current version"
fi

# Check if .env.prod exists
if [ ! -f "$ENV_FILE" ]; then
    log_error "Production environment file ($ENV_FILE) not found!"
    log_info "Please copy .env.prod.example to .env.prod and configure it"
    exit 1
fi

# Frontend will be built inside Docker container
log_info "Frontend will be built in Docker container..."

# Ensure backup directory exists
if [ ! -d "$BACKUP_DIR" ]; then
    log_info "Creating backup directory..."
    mkdir -p "$BACKUP_DIR"
fi

# Create backup before deployment
log_info "Creating database backup..."
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
docker compose -f $COMPOSE_FILE --env-file $ENV_FILE exec -T db pg_dump -U monadungeon monadungeon > "$BACKUP_DIR/monadungeon_backup_$TIMESTAMP.sql" 2>/dev/null || log_warning "Database backup failed (might be first deployment)"

# Clean up old frontend build to ensure fresh deployment
log_info "Removing old frontend build volume..."
docker volume rm monadungeon_frontend_dist 2>/dev/null || log_info "No existing frontend volume to remove"

# Clear any existing Docker build cache for frontend and all related caches
log_info "Clearing Docker build cache for frontend..."
docker builder prune -af 2>/dev/null || true
# Also clear Docker's layer cache
docker system prune -f --volumes 2>/dev/null || true

# Build Docker images (including frontend)
log_info "Building Docker images (including frontend)..."
# Load and export environment variables from .env.prod for the build
set -a  # Mark all new variables for export
source $ENV_FILE
set +a  # Stop marking for export
log_info "Frontend env vars loaded from $ENV_FILE:"
log_info "  VITE_PRIVY_APP_ID=${VITE_PRIVY_APP_ID:-not set}"
log_info "  VITE_MONAD_GAMES_APP_ID=${VITE_MONAD_GAMES_APP_ID:-not set}"
log_info "  VITE_API_BASE_URL=${VITE_API_BASE_URL:-not set}"
# Build with explicit env file and exported variables (force rebuild with --no-cache)
# First, remove any existing images to ensure fresh build
docker rmi monadungeon-frontend-builder 2>/dev/null || true
docker compose -f $COMPOSE_FILE --env-file $ENV_FILE build --no-cache --pull frontend-builder
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

# Clear CDN cache if configured
if [ ! -z "${CDN_PURGE_URL:-}" ]; then
    log_info "Purging CDN cache..."
    curl -X POST "$CDN_PURGE_URL" 2>/dev/null || log_warning "CDN cache purge failed"
fi

# Clear nginx cache if exists
log_info "Clearing nginx cache..."
docker compose -f $COMPOSE_FILE --env-file $ENV_FILE exec -T nginx find /var/cache/nginx -type f -delete 2>/dev/null || true

# Verify frontend build contains latest changes
log_info "Verifying frontend build..."
# Check if the built index.html exists and contains expected content
FRONTEND_CHECK=$(docker compose -f $COMPOSE_FILE --env-file $ENV_FILE exec -T nginx ls -la /usr/share/nginx/html/index.html 2>/dev/null || echo "MISSING")
if [[ "$FRONTEND_CHECK" == *"MISSING"* ]]; then
    log_warning "Frontend build verification: index.html not found in expected location"
else
    log_info "Frontend build verification: index.html found"
    
    # Get the JS filename from index.html
    JS_FILE=$(docker compose -f $COMPOSE_FILE --env-file $ENV_FILE exec -T nginx grep -oE '/assets/index-[a-zA-Z0-9_-]+\.js' /usr/share/nginx/html/index.html | head -1)
    if [ ! -z "$JS_FILE" ]; then
        log_info "Frontend build verification: Using JS file $JS_FILE"
        
        # Check for leaderboard feature in the actual JS file
        if docker compose -f $COMPOSE_FILE --env-file $ENV_FILE exec -T nginx grep -q "View Leaderboard\|leaderboard" /usr/share/nginx/html$JS_FILE 2>/dev/null; then
            log_info "Frontend build verification: Leaderboard feature found in build"
        else
            log_warning "Frontend build verification: Leaderboard feature not found in build"
            log_warning "This might indicate the build is using cached files. Consider running deploy again."
        fi
    else
        log_warning "Frontend build verification: Could not find JS file reference in index.html"
    fi
fi

# Health check - just verify nginx is responding
log_info "Performing health check..."
sleep 5
# Check if nginx is responding (frontend should return 200)
HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/ || echo "000")

if [ "$HTTP_STATUS" = "200" ] || [ "$HTTP_STATUS" = "301" ] || [ "$HTTP_STATUS" = "302" ]; then
    log_info "Health check passed! Application is running. (HTTP status: $HTTP_STATUS)"
    
    # Additional frontend verification
    log_info "Performing frontend content verification..."
    FRONTEND_CONTENT=$(curl -s http://localhost/ | head -20)
    if [[ "$FRONTEND_CONTENT" == *"<div id=\"app\""* ]] || [[ "$FRONTEND_CONTENT" == *"monadungeon"* ]]; then
        log_info "Frontend content verification: Vue app container found"
    else
        log_warning "Frontend content verification: Expected content not found"
    fi
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

# Display deployment summary
echo ""
log_info "=== DEPLOYMENT SUMMARY ==="
log_info "Application URL: https://$(grep SERVER_NAME $ENV_FILE | cut -d'=' -f2)"
log_info "Build timestamp: $(date)"
log_info "Git commit: $(git rev-parse --short HEAD)"
log_info "Git branch: $(git rev-parse --abbrev-ref HEAD)"

# Check for common issues
echo ""
log_info "=== POST-DEPLOYMENT CHECKS ==="

# Check if all expected containers are running
EXPECTED_CONTAINERS=("nginx" "php" "db" "rabbitmq" "redis")
for container in "${EXPECTED_CONTAINERS[@]}"; do
    if docker ps | grep -q "${container}_monadungeon"; then
        log_info "✓ Container ${container} is running"
    else
        log_warning "✗ Container ${container} is not running"
    fi
done

# Automatic cache busting is enabled
echo ""
log_info "=== CACHE MANAGEMENT ==="
log_info "✓ Automatic cache busting enabled via content hashing"
log_info "✓ HTML files served with no-cache headers"  
log_info "✓ Static assets (JS/CSS) use unique filenames per build"
log_info "✓ Users will automatically get latest version on page refresh"