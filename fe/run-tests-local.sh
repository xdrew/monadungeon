#!/bin/bash

# Simple script to run Playwright tests locally

echo "🎮 Running Monadungeon E2E Tests Locally"
echo "================================="

# Check if node_modules exists
if [ ! -d "node_modules" ]; then
    echo "📦 Installing dependencies..."
    npm install
fi

# Check if Playwright browsers are installed
if ! npx playwright --version > /dev/null 2>&1; then
    echo "🌐 Installing Playwright browsers..."
    npx playwright install
fi

# Check for system dependencies
echo "🔍 Checking system dependencies..."
if ! npx playwright install-deps --dry-run > /dev/null 2>&1; then
    echo "⚠️  Missing system dependencies. You may need to run:"
    echo "    sudo npx playwright install-deps"
    echo ""
fi

# Parse arguments
MODE="run"
if [ "$1" = "ui" ]; then
    MODE="ui"
elif [ "$1" = "debug" ]; then
    MODE="debug"
elif [ "$1" = "headed" ]; then
    MODE="headed"
elif [ "$1" = "report" ]; then
    MODE="report"
fi

# Ensure the API is running
echo "⚠️  Make sure your backend is running at http://localhost:8000"
echo "    Run 'make run' in the project root if needed"
echo ""

# Run tests based on mode
case $MODE in
    ui)
        echo "🎨 Starting Playwright UI..."
        npm run test:e2e:ui
        ;;
    debug)
        echo "🐛 Starting in debug mode..."
        npm run test:e2e:debug
        ;;
    headed)
        echo "👀 Running tests with visible browser..."
        npm run test:e2e:headed
        ;;
    report)
        echo "📊 Opening test report..."
        npm run test:e2e:report
        ;;
    *)
        echo "🧪 Running all tests..."
        npm run test:e2e
        
        echo ""
        echo "✅ Tests completed!"
        echo "📊 Run './run-tests-local.sh report' to view the report"
        ;;
esac