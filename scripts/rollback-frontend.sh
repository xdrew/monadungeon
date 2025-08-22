#!/bin/bash
set -e

# Rollback frontend to the previous version from backup

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

log_info "Starting frontend rollback..."

# Check if backup exists
BACKUP_CHECK=$(docker run --rm -v monadungeon_frontend_backup:/backup alpine ls -la /backup/ 2>/dev/null | wc -l)

if [ "$BACKUP_CHECK" -le 3 ]; then
    log_error "No backup found! Cannot rollback."
    exit 1
fi

log_info "Backup found. Rolling back frontend..."

# Copy backup to live volume
docker run --rm \
    -v monadungeon_frontend_dist:/target \
    -v monadungeon_frontend_backup:/backup \
    alpine sh -c "rm -rf /target/* && cp -r /backup/* /target/"

log_info "Frontend rolled back successfully!"
log_info "Note: The rolled back version is now live."
log_info "Run 'deploy' to redeploy the latest version if needed."