import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  forbidOnly: true,
  retries: 0,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL: 'http://localhost:5173',
    trace: 'off',
    screenshot: 'only-on-failure',
    video: 'off',
    // Use software rendering for CI/headless environments
    launchOptions: {
      args: ['--disable-gpu', '--no-sandbox', '--disable-setuid-sandbox']
    }
  },

  projects: [
    {
      name: 'chromium',
      use: { 
        ...devices['Desktop Chrome'],
        // Force headless mode
        headless: true
      },
    },
  ],

  webServer: {
    command: 'npm run dev',
    port: 5173,
    reuseExistingServer: true,
    timeout: 120 * 1000,
  },
});