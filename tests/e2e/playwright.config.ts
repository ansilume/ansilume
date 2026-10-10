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
    // The team scoping specs run here too; their beforeEach guard keeps the "admin …" tests.
    {
      name: 'admin',
      use: {
        ...devices['Desktop Chrome'],
        storageState: '.auth/admin.json',
      },
      dependencies: ['setup'],
      testIgnore: /auth\.setup\.ts|rbac\.spec\.ts|login\.spec\.ts|site\/forgot-password\.spec\.ts|site\/totp-login\.spec\.ts|trigger\/fire\.spec\.ts/,
    },

    // Operator and viewer tests: the rbac specs and the team scoping specs
    // (team-scoping/*.spec.ts), signed in as e2e-operator or e2e-viewer.
    // Each of those files skips the tests that are not for the project's role
    // in a beforeEach guard on the test title ("operator …", "viewer …").
    // That guard is the only role filter: no `grep` here, because grep
    // matches the whole title path, which starts with the project name, so a
    // project named "operator" would match every test anyway.
    {
      name: 'operator',
      use: {
        ...devices['Desktop Chrome'],
        storageState: '.auth/operator.json',
      },
      dependencies: ['setup'],
      testMatch: /rbac\.spec\.ts|team-scoping\/[^/]+\.spec\.ts/,
    },
    {
      name: 'viewer',
      use: {
        ...devices['Desktop Chrome'],
        storageState: '.auth/viewer.json',
      },
      dependencies: ['setup'],
      testMatch: /rbac\.spec\.ts|team-scoping\/[^/]+\.spec\.ts/,
    },

    // Unauthenticated tests — login, forgot password, public endpoints
    {
      name: 'unauthenticated',
      use: { ...devices['Desktop Chrome'] },
      testMatch: /login\.spec\.ts|forgot-password\.spec\.ts|totp-login\.spec\.ts|fire\.spec\.ts/,
    },
  ],
});
