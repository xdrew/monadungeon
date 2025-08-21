import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  forbidOnly: true,
  retries: 0,
  maxFailures: undefined, // Don't limit failures
  workers: process.env.CI ? 1 : undefined,
  reporter: process.env.VERBOSE ? 'list' : 'dot',
  quiet: !process.env.VERBOSE, // Suppress console output for passing tests
  
  // Explicitly set output folder
  outputDir: './test-results',
  
  // Preserve output artifacts
  preserveOutput: 'always',
  
  use: {
    // Use environment variable or default
    baseURL: process.env.BASE_URL || 'http://localhost:5173',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: {
      mode: 'on',
      size: { width: 1280, height: 720 }
    },
    
    // Important for Docker - viewport size
    viewport: { width: 1280, height: 720 },
    ignoreHTTPSErrors: true,
    
    // Increase timeouts for container startup
    actionTimeout: 15000,
    navigationTimeout: 30000,
  },

  expect: {
    timeout: 10000
  },

  projects: [
    {
      name: 'chromium',
      use: { 
        ...devices['Desktop Chrome'],
        // Force headless in Docker
        headless: true,
        // Docker-specific browser args
        launchOptions: {
          args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu'
          ]
        }
      },
    },
    // {
    //   name: 'firefox',
    //   use: {
    //     ...devices['Desktop Firefox'],
    //     headless: true
    //   },
    // },
  ],

  // Don't start dev server in Docker (using compose services)
  webServer: undefined,
});