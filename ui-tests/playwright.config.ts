import { defineConfig, devices } from '@playwright/test';
import * as dotenv from 'dotenv';

// Load local, uncommitted config (BASE_URL, test credentials) from ui-tests/.env
dotenv.config();

// Base URL of the running app. The signin page lives at
// `${BASE_URL}/pspf_crm/api/signin/index.php`. Override in .env per environment.
const BASE_URL = process.env.BASE_URL ?? 'http://hpkprd';

export default defineConfig({
  testDir: './tests',
  // A daily smoke run should fail fast and loud rather than hang.
  timeout: 30_000,
  expect: { timeout: 10_000 },
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: 1,
  workers: 1,
  reporter: [
    ['list'],
    ['html', { outputFolder: 'playwright-report', open: 'never' }],
  ],
  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    // The app is internal HTTP; ignore any self-signed cert quirks on the LAN.
    ignoreHTTPSErrors: true,
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
