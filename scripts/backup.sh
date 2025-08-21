#!/bin/bash
set -e

# Database backup script for monadungeon
# Can be run manually or via cron job

# Configuration
BACKUP_DIR="/opt/backups"
PROJECT_DIR="/opt/monadungeon"
COMPOSE_FILE="compose.prod.yml"
BACKUP_RETENTION_DAYS=30
S3_BUCKET="" # Optional: S3 bucket for offsite backups
MAX_BACKUP_SIZE="500M" # Maximum expected backup size for alerts

# Create backup directory if it doesn't exist
mkdir -p $BACKUP_DIR

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Functions
log_info() {
    echo -e "${GREEN}[INFO]${NC} $(date '+%Y-%m-%d %H:%M:%S') - $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $(date '+%Y-%m-%d %H:%M:%S') - $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $(date '+%Y-%m-%d %H:%M:%S') - $1"
}

# Navigate to project directory
cd $PROJECT_DIR

# Generate timestamp
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
DATE=$(date +%Y-%m-%d)

# Backup filenames
DB_BACKUP_FILE="$BACKUP_DIR/monadungeon_db_${TIMESTAMP}.sql"
DB_BACKUP_COMPRESSED="$BACKUP_DIR/monadungeon_db_${TIMESTAMP}.sql.gz"
FILES_BACKUP="$BACKUP_DIR/monadungeon_files_${TIMESTAMP}.tar.gz"

log_info "Starting backup process..."

# 1. Database Backup
log_info "Backing up PostgreSQL database..."
if docker-compose -f $COMPOSE_FILE exec -T db pg_dump -U monadungeon monadungeon > "$DB_BACKUP_FILE" 2>/dev/null; then
    log_info "Database backup completed: $DB_BACKUP_FILE"
    
    # Compress the backup
    log_info "Compressing database backup..."
    gzip -9 "$DB_BACKUP_FILE"
    log_info "Compressed backup: $DB_BACKUP_COMPRESSED"
    
    # Check backup size
    BACKUP_SIZE=$(du -h "$DB_BACKUP_COMPRESSED" | cut -f1)
    log_info "Backup size: $BACKUP_SIZE"
else
    log_error "Database backup failed!"
    exit 1
fi

# 2. Backup uploaded files (if any)
if [ -d "src/public/uploads" ]; then
    log_info "Backing up uploaded files..."
    tar -czf "$FILES_BACKUP" -C src/public uploads 2>/dev/null || log_warning "No uploaded files to backup"
fi

# 3. Backup environment configuration (encrypted)
log_info "Backing up environment configuration..."
ENV_BACKUP="$BACKUP_DIR/monadungeon_env_${TIMESTAMP}.tar.gz.gpg"
tar -czf - .env.prod docker/nginx/prod.conf src/.rr.prod.yaml | \
    gpg --symmetric --cipher-algo AES256 --output "$ENV_BACKUP" 2>/dev/null || log_warning "Environment backup requires GPG passphrase"

# 4. Create daily symlink for latest backup
ln -sf "$DB_BACKUP_COMPRESSED" "$BACKUP_DIR/monadungeon_db_latest.sql.gz"

# 5. Upload to S3 (optional)
if [ ! -z "$S3_BUCKET" ]; then
    log_info "Uploading backup to S3..."
    if command -v aws &> /dev/null; then
        aws s3 cp "$DB_BACKUP_COMPRESSED" "s3://$S3_BUCKET/backups/daily/${DATE}/" || log_warning "S3 upload failed"
        
        # Keep weekly backup (every Sunday)
        if [ $(date +%u) -eq 7 ]; then
            aws s3 cp "$DB_BACKUP_COMPRESSED" "s3://$S3_BUCKET/backups/weekly/${DATE}/" || log_warning "Weekly S3 upload failed"
        fi
        
        # Keep monthly backup (first day of month)
        if [ $(date +%d) -eq 01 ]; then
            aws s3 cp "$DB_BACKUP_COMPRESSED" "s3://$S3_BUCKET/backups/monthly/${DATE}/" || log_warning "Monthly S3 upload failed"
        fi
    else
        log_warning "AWS CLI not installed, skipping S3 upload"
    fi
fi

# 6. Test backup integrity
log_info "Testing backup integrity..."
if gunzip -t "$DB_BACKUP_COMPRESSED" 2>/dev/null; then
    log_info "Backup integrity check passed"
else
    log_error "Backup integrity check failed!"
    exit 1
fi

# 7. Clean up old backups
log_info "Cleaning up old backups..."

# Remove daily backups older than retention period
find $BACKUP_DIR -name "monadungeon_db_*.sql.gz" -mtime +$BACKUP_RETENTION_DAYS -delete
find $BACKUP_DIR -name "monadungeon_files_*.tar.gz" -mtime +$BACKUP_RETENTION_DAYS -delete
find $BACKUP_DIR -name "monadungeon_env_*.tar.gz.gpg" -mtime +$BACKUP_RETENTION_DAYS -delete

# Keep weekly backups for 3 months
find $BACKUP_DIR -name "monadungeon_weekly_*.sql.gz" -mtime +90 -delete

# Keep monthly backups for 1 year
find $BACKUP_DIR -name "monadungeon_monthly_*.sql.gz" -mtime +365 -delete

# 8. Report disk usage
BACKUP_DISK_USAGE=$(du -sh $BACKUP_DIR | cut -f1)
log_info "Total backup directory size: $BACKUP_DISK_USAGE"

# 9. Send notification (optional - configure your notification method)
# Example: Send email notification
# echo "Backup completed successfully at $(date)" | mail -s "monadungeon Backup Success" admin@yourdomain.com

log_info "Backup process completed successfully!"

# Create a backup log entry
echo "$(date '+%Y-%m-%d %H:%M:%S') - Backup completed: $DB_BACKUP_COMPRESSED ($BACKUP_SIZE)" >> "$BACKUP_DIR/backup.log"

# Rotate backup log
tail -n 1000 "$BACKUP_DIR/backup.log" > "$BACKUP_DIR/backup.log.tmp"
mv "$BACKUP_DIR/backup.log.tmp" "$BACKUP_DIR/backup.log"