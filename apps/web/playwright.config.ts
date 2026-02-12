import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.BASE_URL || 'http://127.0.0.1:3000';
const isCI = !!process.env.CI;
const reuseExistingServer = !isCI;
const e2eProfile = process.env.E2E_PROFILE ?? 'core';
const runCoreOnly = isCI && e2eProfile === 'core';

export default defineConfig({
  testDir: './playwright/specs',
  globalSetup: './playwright/global-setup.ts',
  grep: runCoreOnly ? /@core/ : undefined,
  timeout: 60_000,
  expect: { timeout: 15_000 },
  fullyParallel: true,
  forbidOnly: isCI,
  retries: isCI ? 1 : 0,
  workers: isCI ? 2 : undefined,
  reporter: isCI
    ? [['github'], ['html', { open: 'never', outputFolder: 'playwright-report' }]]
    : [['html', { outputFolder: 'playwright-report' }]],
  use: {
    baseURL,
    storageState: 'playwright/.auth/state.json',
    trace: 'on-first-retry',
  },
  projects: [{ name: 'chromium', use: devices['Desktop Chrome'] }],
  webServer: {
    command: 'npm run dev:e2e',
    url: baseURL,
    reuseExistingServer,
    timeout: 120000,
  },
});
