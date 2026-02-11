// @ts-check
const { defineConfig, devices } = require('@playwright/test');

/**
 * TPB Playwright Configuration
 * Ethics-first testing for civic infrastructure
 *
 * Remember the Golden Rule: We're testing for Maria, Tom, and Jamal
 */
module.exports = defineConfig({
  testDir: './tests',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: 'html',

  use: {
    baseURL: 'http://localhost',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'mobile',
      use: { ...devices['iPhone 13'] },
    },
  ],

  webServer: {
    command: 'echo "Assuming XAMPP Apache is running on localhost..."',
    url: 'http://localhost',
    reuseExistingServer: true,
  },
});
