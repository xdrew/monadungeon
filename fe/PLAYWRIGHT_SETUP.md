# Setting Up Playwright Test Environment

## Option 1: Local Development Machine (Recommended)

### Prerequisites
- Node.js 18+ installed
- Git installed
- Display (for headed mode) or headless browser support

### Steps

1. **Clone the repository** (if not already done)
```bash
git clone <your-repo-url>
cd monadungeon/fe
```

2. **Install dependencies**
```bash
npm install
```

3. **Install Playwright browsers**
```bash
npx playwright install
```

4. **Install system dependencies** (Linux/WSL only)
```bash
# Ubuntu/Debian
sudo npx playwright install-deps

# Or manually:
sudo apt-get update
sudo apt-get install -y \
    libatk1.0-0 \
    libatk-bridge2.0-0 \
    libcups2 \
    libatspi2.0-0 \
    libnspr4 \
    libnss3 \
    libgbm1 \
    libxss1 \
    libgtk-3-0
```

5. **Run tests**
```bash
npm run test:e2e
```

## Option 2: Docker with Playwright

### Create Playwright Docker setup

1. **Create Dockerfile for tests**
```dockerfile
# Dockerfile.playwright
FROM mcr.microsoft.com/playwright:v1.53.0-jammy

WORKDIR /app

# Copy package files
COPY package*.json ./

# Install dependencies
RUN npm ci

# Copy test files
COPY . .

# Run tests
CMD ["npm", "run", "test:e2e"]
```

2. **Create docker-compose for testing**
```yaml
# docker-compose.test.yml
version: '3.8'

services:
  playwright:
    build:
      context: ./fe
      dockerfile: Dockerfile.playwright
    volumes:
      - ./fe:/app
      - /app/node_modules
    environment:
      - CI=true
    network_mode: host
    depends_on:
      - api
      - postgres

  api:
    # Your existing API service
    extends:
      file: ../compose.yml
      service: php

  postgres:
    # Your existing DB service
    extends:
      file: ../compose.yml
      service: postgres
```

3. **Run tests in Docker**
```bash
docker-compose -f docker-compose.test.yml up --build playwright
```

## Option 3: CI/CD Pipeline (GitHub Actions)

### Create GitHub Actions workflow

```yaml
# .github/workflows/playwright.yml
name: Playwright Tests

on:
  push:
    branches: [ main, develop ]
  pull_request:
    branches: [ main ]

jobs:
  test:
    timeout-minutes: 60
    runs-on: ubuntu-latest
    
    services:
      postgres:
        image: postgres:15
        env:
          POSTGRES_PASSWORD: postgres
        options: >-
          --health-cmd pg_isready
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5
        ports:
          - 5432:5432

    steps:
    - uses: actions/checkout@v4
    
    - uses: actions/setup-node@v4
      with:
        node-version: 20
        
    - name: Install dependencies
      working-directory: ./fe
      run: npm ci
      
    - name: Install Playwright Browsers
      working-directory: ./fe
      run: npx playwright install --with-deps
      
    - name: Start backend
      run: |
        cd src
        composer install
        php bin/console doctrine:database:create --if-not-exists
        php bin/console doctrine:migrations:migrate --no-interaction
        symfony server:start --daemon
        
    - name: Run Playwright tests
      working-directory: ./fe
      run: npm run test:e2e
      
    - uses: actions/upload-artifact@v4
      if: always()
      with:
        name: playwright-report
        path: fe/playwright-report/
        retention-days: 30
```

## Option 4: Development Container (VS Code)

### Create devcontainer configuration

```json
// .devcontainer/devcontainer.json
{
  "name": "monadungeon Dev",
  "dockerComposeFile": [
    "../compose.yml",
    "docker-compose.extend.yml"
  ],
  "service": "devcontainer",
  "workspaceFolder": "/workspace",
  
  "features": {
    "ghcr.io/devcontainers/features/node:1": {
      "version": "20"
    },
    "ghcr.io/devcontainers-contrib/features/playwright:1": {
      "version": "latest"
    }
  },
  
  "customizations": {
    "vscode": {
      "extensions": [
        "ms-playwright.playwright",
        "Vue.volar",
        "dbaeumer.vscode-eslint"
      ]
    }
  },
  
  "postCreateCommand": "cd fe && npm install && npx playwright install --with-deps",
  
  "forwardPorts": [5173, 8000, 5432]
}
```

```yaml
# .devcontainer/docker-compose.extend.yml
version: '3.8'

services:
  devcontainer:
    image: mcr.microsoft.com/devcontainers/javascript-node:20
    volumes:
      - ..:/workspace:cached
    command: sleep infinity
    environment:
      - DISPLAY=:0
    network_mode: service:php
```

## Option 5: WSL2 with GUI Support (Windows)

### For Windows users with WSL2

1. **Enable WSLg** (Windows 11 or Windows 10 21H2+)
```powershell
# In PowerShell as Admin
wsl --update
```

2. **Install dependencies in WSL**
```bash
# In WSL Ubuntu
sudo apt update
sudo apt install -y nodejs npm

# Install Playwright deps
cd /path/to/monadungeon/fe
npm install
npx playwright install --with-deps
```

3. **Run tests with GUI**
```bash
# Headed mode will work with WSLg
npm run test:e2e:headed
```

## Troubleshooting

### Common Issues

1. **Missing system dependencies**
```bash
# Check what's missing
npx playwright install-deps --dry-run

# Install missing deps
sudo npx playwright install-deps
```

2. **Permission issues in Docker**
```bash
# Run with proper user
docker run --user pwuser playwright-tests
```

3. **Display issues**
```bash
# Force headless mode
export HEADLESS=true
npm run test:e2e
```

4. **Port conflicts**
```bash
# Check if ports are in use
lsof -i :5173
lsof -i :8000

# Kill processes or use different ports
```

### Verify Installation

Run this check script:
```bash
# check-playwright.sh
#!/bin/bash

echo "Checking Node.js..."
node --version

echo "Checking npm..."
npm --version

echo "Checking Playwright..."
npx playwright --version

echo "Checking browsers..."
npx playwright install --list

echo "Running simple test..."
npx playwright test --list
```

## Quick Start Commands

```bash
# Full setup from scratch (local machine)
git clone <repo>
cd monadungeon/fe
npm install
npx playwright install --with-deps
npm run test:e2e

# Docker setup
docker build -f Dockerfile.playwright -t monadungeon-tests .
docker run -it monadungeon-tests

# CI mode (headless)
CI=true npm run test:e2e

# Debug mode (with browser)
npm run test:e2e:debug

# UI mode (best for development)
npm run test:e2e:ui
```

## Recommended Setup

For the best development experience:

1. Use **Option 1** (local machine) for active development
2. Use **Option 3** (GitHub Actions) for CI/CD
3. Use **Option 4** (devcontainer) for consistent team environments

The local setup provides the best debugging experience with the Playwright UI mode, while CI ensures tests run on every commit.