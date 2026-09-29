import { defineConfig, devices } from '@playwright/test'

/**
 * Smoke tests against a running stack: the web app (`bun run dev`) and the API with the
 * demo seed (`php artisan migrate:fresh --seed && php artisan serve`).
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
