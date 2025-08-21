# monadungeon Deployment Guide for Hetzner

This guide walks you through deploying the monadungeon game to a Hetzner Cloud server.

## Prerequisites

- Hetzner Cloud account
- Domain name pointed to your server
- GitHub repository (for CI/CD)
- Basic Linux/SSH knowledge

## 1. Server Setup

### 1.1 Create Hetzner Server

1. Log into [Hetzner Cloud Console](https://console.hetzner.cloud)
2. Create new server:
   - **Type**: CX22 (2 vCPU, 4GB RAM, 40GB SSD) - €4.85/month
   - **OS**: Ubuntu 24.04 LTS
   - **Location**: Nuremberg or Helsinki
   - **SSH Key**: Add your public SSH key
   - **Firewall**: Create and attach firewall with:
     - SSH (22) - Your IP only
     - HTTP (80) - All
     - HTTPS (443) - All

### 1.2 Initial Server Configuration

```bash
# Connect to server
ssh root@your-server-ip

# Update system
apt update && apt upgrade -y

# Install required packages
apt install -y docker.io docker-compose git nginx certbot python3-certbot-nginx ufw fail2ban

# Enable Docker
systemctl enable docker
systemctl start docker

# Configure firewall
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw enable

# Create application user
useradd -m -s /bin/bash monadungeon
usermod -aG docker monadungeon

# Create directories
mkdir -p /opt/monadungeon /opt/backups
chown -R monadungeon:monadungeon /opt/monadungeon /opt/backups
```

## 2. Deploy Application

### 2.1 Clone Repository

```bash
# Switch to monadungeon user
su - monadungeon
cd /opt/monadungeon

# Clone your repository
git clone https://github.com/yourusername/monadungeon.git .
```

### 2.2 Configure Environment

```bash
# Copy and edit production environment
cp .env.prod.example .env.prod
nano .env.prod

# Required changes:
# - APP_SECRET: Generate 64-character random string
# - DATABASE_URL: Set secure password
# - MONAD_PRIVATE_KEY: Your blockchain private key
# - MONAD_WALLET: Your wallet address
# - SERVER_NAME: Your domain name
# - LETSENCRYPT_EMAIL: Your email
# - DB_PASSWORD: Secure database password
# - RABBITMQ_PASSWORD: Secure RabbitMQ password
```

### 2.3 SSL Certificate Setup

```bash
# Exit to root user
exit

# Get SSL certificate
certbot certonly --standalone -d yourdomain.com -d www.yourdomain.com

# Certificate will be at:
# /etc/letsencrypt/live/yourdomain.com/
```

### 2.4 Deploy Application

```bash
# As root, run deployment
cd /opt/monadungeon
./scripts/deploy.sh
```

## 3. Configure Automatic Deployments

### 3.1 GitHub Actions (Optional)

Create `.github/workflows/deploy.yml`:

```yaml
name: Deploy to Production

on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      
      - name: Deploy to server
        uses: appleboy/ssh-action@master
        with:
          host: ${{ secrets.HOST }}
          username: root
          key: ${{ secrets.SSH_KEY }}
          script: |
            cd /opt/monadungeon
            ./scripts/deploy.sh
```

Add secrets in GitHub:
- `HOST`: Your server IP
- `SSH_KEY`: Your private SSH key

### 3.2 Manual Deployment

```bash
ssh root@your-server-ip
cd /opt/monadungeon
./scripts/deploy.sh
```

## 4. Configure Backups

### 4.1 Automated Daily Backups

```bash
# Add cron job for daily backups at 2 AM
crontab -e

# Add this line:
0 2 * * * /opt/monadungeon/scripts/backup.sh >> /opt/backups/cron.log 2>&1
```

### 4.2 Manual Backup

```bash
/opt/monadungeon/scripts/backup.sh
```

### 4.3 Restore from Backup

```bash
# Stop application
cd /opt/monadungeon
docker-compose -f compose.prod.yml down

# Restore database
gunzip -c /opt/backups/monadungeon_db_latest.sql.gz | \
  docker-compose -f compose.prod.yml exec -T db psql -U monadungeon monadungeon

# Start application
docker-compose -f compose.prod.yml up -d
```

## 5. Monitoring

### 5.1 Check Application Status

```bash
# View running containers
docker-compose -f compose.prod.yml ps

# View logs
docker-compose -f compose.prod.yml logs -f

# Check specific service
docker-compose -f compose.prod.yml logs -f php
docker-compose -f compose.prod.yml logs -f nginx
```

### 5.2 Health Checks

```bash
# API health check
curl http://localhost/api/health

# Check from outside
curl https://yourdomain.com/api/health
```

### 5.3 Resource Usage

```bash
# Check Docker resource usage
docker stats

# Check disk usage
df -h

# Check memory
free -h
```

## 6. Maintenance

### 6.1 Update Application

```bash
cd /opt/monadungeon
./scripts/deploy.sh
```

### 6.2 Database Maintenance

```bash
# Connect to database
docker-compose -f compose.prod.yml exec db psql -U monadungeon monadungeon

# Vacuum database (maintenance)
docker-compose -f compose.prod.yml exec db vacuumdb -U monadungeon -d monadungeon -z
```

### 6.3 Clear Cache

```bash
docker-compose -f compose.prod.yml exec php php bin/console cache:clear
```

### 6.4 View Logs

```bash
# Application logs
docker-compose -f compose.prod.yml logs -f php

# Nginx access logs
docker-compose -f compose.prod.yml exec nginx tail -f /var/log/nginx/access.log

# RoadRunner logs
docker-compose -f compose.prod.yml exec php tail -f /sf/app/src/var/log/prod.log
```

## 7. Troubleshooting

### Common Issues

#### Port Already in Use
```bash
# Find process using port
lsof -i :80
lsof -i :443

# Kill process if needed
kill -9 <PID>
```

#### Container Won't Start
```bash
# Check logs
docker-compose -f compose.prod.yml logs <service-name>

# Rebuild container
docker-compose -f compose.prod.yml build --no-cache <service-name>
```

#### Database Connection Issues
```bash
# Check if database is running
docker-compose -f compose.prod.yml ps db

# Test connection
docker-compose -f compose.prod.yml exec db pg_isready -U monadungeon
```

#### SSL Certificate Renewal
```bash
# Test renewal
certbot renew --dry-run

# Force renewal
certbot renew --force-renewal
```

### Emergency Rollback

```bash
# Stop current deployment
docker-compose -f compose.prod.yml down

# Checkout previous version
git checkout <previous-commit-hash>

# Restore database from backup
gunzip -c /opt/backups/monadungeon_db_<timestamp>.sql.gz | \
  docker-compose -f compose.prod.yml exec -T db psql -U monadungeon monadungeon

# Redeploy
./scripts/deploy.sh
```

## 8. Security Recommendations

1. **Regular Updates**
   ```bash
   apt update && apt upgrade -y
   docker-compose pull
   ```

2. **Firewall Rules**
   - Only allow SSH from your IP
   - Use fail2ban for brute force protection

3. **Secrets Management**
   - Never commit `.env.prod` to git
   - Use strong passwords
   - Rotate credentials regularly

4. **Monitoring**
   - Set up uptime monitoring (UptimeRobot, Pingdom)
   - Configure log aggregation
   - Set up alerts for disk space

5. **Backups**
   - Test restore procedure regularly
   - Keep offsite backups (S3, B2)
   - Encrypt sensitive backups

## 9. Performance Optimization

### 9.1 RoadRunner Tuning
Edit `src/.rr.prod.yaml`:
- Adjust `num_workers` based on CPU cores
- Enable HTTP/2 for better performance
- Configure memory limits

### 9.2 PostgreSQL Tuning
```bash
# Edit PostgreSQL config
docker-compose -f compose.prod.yml exec db bash
vi /var/lib/postgresql/data/postgresql.conf

# Key settings:
# shared_buffers = 256MB
# effective_cache_size = 1GB
# max_connections = 100
```

### 9.3 Nginx Caching
- Static assets are cached for 30 days
- API responses can be cached with proper headers

## 10. Scaling Options

When you need more resources:

1. **Vertical Scaling**: Upgrade to CX32 (4 vCPU, 8GB RAM)
2. **Horizontal Scaling**: 
   - Separate database server
   - Load balancer with multiple app servers
   - CDN for static assets (Cloudflare)

## Support

For issues specific to:
- **Hetzner**: support@hetzner.com
- **Application**: Create issue in GitHub repository
- **Emergency**: Keep backups and rollback procedure ready

---

## Quick Commands Reference

```bash
# Deploy
/opt/monadungeon/scripts/deploy.sh

# Backup
/opt/monadungeon/scripts/backup.sh

# View logs
docker-compose -f compose.prod.yml logs -f

# Restart services
docker-compose -f compose.prod.yml restart

# Stop everything
docker-compose -f compose.prod.yml down

# Start everything
docker-compose -f compose.prod.yml up -d

# Database shell
docker-compose -f compose.prod.yml exec db psql -U monadungeon monadungeon

# PHP shell
docker-compose -f compose.prod.yml exec php bash

# Clear cache
docker-compose -f compose.prod.yml exec php php bin/console cache:clear
```