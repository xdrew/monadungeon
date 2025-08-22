#!/bin/bash
set -e

# Frontend deployment entrypoint
# Handles backup and deployment of built files

echo "[Frontend Deploy] Starting deployment process..."

# Check if we have a target volume mounted
if [ ! -d "/target" ]; then
    echo "[Frontend Deploy] Warning: No /target volume mounted, files will not be deployed"
    exit 0
fi

# Backup previous build if it exists and backup volume is mounted
if [ -d "/backup" ]; then
    echo "[Frontend Deploy] Backing up previous build..."
    rm -rf /backup/*
    if [ "$(ls -A /target 2>/dev/null)" ]; then
        cp -r /target/* /backup/
        echo "[Frontend Deploy] Previous build backed up successfully"
    else
        echo "[Frontend Deploy] No previous build to backup"
    fi
else
    echo "[Frontend Deploy] No backup volume mounted, skipping backup"
fi

# Deploy new build
echo "[Frontend Deploy] Deploying new build..."
rm -rf /target/*
cp -r /app/dist/* /target/
echo "[Frontend Deploy] Deployment complete!"

# List deployed files for verification
echo "[Frontend Deploy] Deployed files:"
ls -la /target/assets/*.js /target/assets/*.css 2>/dev/null || true