import { defineConfig, devices } from '@playwright/test'

/**
 * Smoke tests against a running stack on localhost:3000 (the only origin the API accepts):
 * `bun run dev`, or the production build (`bun run generate && bun run serve:static`), and
 * the API with the demo accounts (`php artisan migrate:fresh && php artisan pulseboard:demo
 * && php artisan serve`). The test logs in as DEMO_OWNER_EMAIL with DEMO_PASSWORD (or
 * E2E_EMAIL / E2E_PASSWORD), and creates and deletes its own product: run it against a
 * disposable database.
 */
export default defineConfig({
  testDir: 'tests/e2e',
  timeout: 60_000,
  fullyParallel: false,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:3000',
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'desktop',
      use: { ...devices['Desktop Chrome'], channel: process.env.E2E_CHANNEL ?? 'chrome' },
    },
  ],
})
