import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests',
  timeout: 30_000,
  expect: { timeout: 5_000 },
  // Parallelize across files but run tests within a file sequentially
  // so CRUD specs (create → update → delete) can rely on order.
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  // Single worker: admin tests share one Yii session (one cookie jar in
  // .auth/admin.json), so parallel workers race on server-side flash state.
  workers: 1,
  reporter: [['list'], ['html', { open: 'never' }]],

  use: {
    baseURL: process.env.BASE_URL || 'http://localhost:8080',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },

  projects: [
    // Auth setup — runs first, saves session state per role
    { name: 'setup', testMatch: /auth\.setup\.ts/ },

    // Admin tests — full access to CRUD/feature specs.
    // Excludes auth setup, unauthenticated specs, and all rbac specs (those run under viewer/operator).
    {
      name: 'admin',
      use: {
        ...devices['Desktop Chrome'],
        storageState: '.auth/admin.json',
      },
      dependencies: ['setup'],
      testIgnore: /auth\.setup\.ts|rbac\.spec\.ts|site\/login\.spec\.ts|site\/forgot-password\.spec\.ts|trigger\/fire\.spec\.ts/,
    },

    // Operator tests — only runs rbac specs whose title starts with "operator".
    {
      name: 'operator',
      use: {
        ...devices['Desktop Chrome'],
        storageState: '.auth/operator.json',
      },
      dependencies: ['setup'],
      testMatch: /rbac\.spec\.ts/,
      grep: /\boperator\b/i,
    },

    // Viewer tests — only runs rbac specs whose title starts with "viewer" or "secrets".
    {
      name: 'viewer',
      use: {
        ...devices['Desktop Chrome'],
        storageState: '.auth/viewer.json',
      },
      dependencies: ['setup'],
      testMatch: /rbac\.spec\.ts/,
      grep: /\b(viewer|secrets)\b/i,
    },

    // Unauthenticated tests — login, forgot password, public endpoints
    {
      name: 'unauthenticated',
      use: { ...devices['Desktop Chrome'] },
      testMatch: /login\.spec\.ts|forgot-password\.spec\.ts|fire\.spec\.ts/,
    },
  ],
});
