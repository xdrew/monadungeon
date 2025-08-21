#!/bin/bash

# Script to check if environment variables are in the production build

echo "Checking frontend build for environment variables..."

# Check in the frontend container volume
echo ""
echo "=== Checking frontend dist volume ==="
docker run --rm -v monadungeon_frontend_dist:/dist alpine sh -c '
    echo "Files in /dist/assets:"
    ls -la /dist/assets/*.js 2>/dev/null | head -5
    echo ""
    echo "Searching for VITE_PRIVY_APP_ID (cmeb207py01ikky0csyj8akos):"
    grep -l "cmeb207py01ikky0csyj8akos" /dist/assets/*.js 2>/dev/null || echo "NOT FOUND in any JS files"
    echo ""
    echo "Searching for VITE_MONAD_GAMES_APP_ID (cmd8euall0037le0my79qpz42):"
    grep -l "cmd8euall0037le0my79qpz42" /dist/assets/*.js 2>/dev/null || echo "NOT FOUND in any JS files"
    echo ""
    echo "Checking for undefined Privy app ID patterns:"
    grep -h "privy-app-id.*undefined\|VITE_PRIVY_APP_ID\|No Privy App ID found" /dist/assets/*.js 2>/dev/null | head -5 || echo "No undefined patterns found"
'

echo ""
echo "=== Checking what nginx is serving ==="
docker exec nginx_monadungeon_prod sh -c '
    echo "Files in /usr/share/nginx/html/assets:"
    ls -la /usr/share/nginx/html/assets/*.js 2>/dev/null | head -5
    echo ""
    echo "Searching for Privy app ID in served files:"
    grep -l "cmeb207py01ikky0csyj8akos" /usr/share/nginx/html/assets/*.js 2>/dev/null || echo "Privy app ID NOT FOUND in served files"
'

echo ""
echo "=== Testing actual HTTP response ==="
echo "Fetching index.html to see loaded scripts:"
curl -s https://monadungeon.xyz/ | grep -o '<script.*src="[^"]*"' | head -5

echo ""
echo "Fetching a JS file to check for env vars:"
# Get the first JS file from index.html
JS_FILE=$(curl -s https://monadungeon.xyz/ | grep -o '/assets/[^"]*\.js' | head -1)
if [ ! -z "$JS_FILE" ]; then
    echo "Checking $JS_FILE for environment variables..."
    curl -s "https://monadungeon.xyz$JS_FILE" | grep -o "cmeb207py01ikky0csyj8akos" | head -1 || echo "Privy app ID not found in $JS_FILE"
    curl -s "https://monadungeon.xyz$JS_FILE" | grep -o "VITE_PRIVY_APP_ID" | head -1 && echo "WARNING: Unprocessed env var reference found!"
fi