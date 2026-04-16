import { test, expect } from '@playwright/test';

/**
 * Regression: security-hardening headers must be present on every response.
 * Covers both the nginx-level headers (X-Frame-Options, X-Content-Type-Options,
 * Referrer-Policy, Permissions-Policy, server_tokens off) and the app-level
 * headers emitted from config/web.php's response.beforeSend handler.
 */
test.describe('Security headers', () => {
  test('login page carries hardening headers', async ({ page }) => {
    const response = await page.goto('/site/login');
    expect(response).not.toBeNull();
    const headers = response!.headers();

    expect(headers['x-frame-options']?.toLowerCase()).toBe('sameorigin');
    expect(headers['x-content-type-options']?.toLowerCase()).toBe('nosniff');
    expect(headers['referrer-policy']).toBeTruthy();
    expect(headers['permissions-policy']).toBeTruthy();
  });

  test('nginx does not leak version in Server header', async ({ page }) => {
    const response = await page.goto('/site/login');
    expect(response).not.toBeNull();
    const server = response!.headers()['server'] ?? '';
    // server_tokens off ⇒ Server: nginx (no version suffix)
    expect(server).not.toMatch(/nginx\/\d/);
  });

  test('PHP version is not disclosed via X-Powered-By', async ({ page }) => {
    const response = await page.goto('/site/login');
    expect(response).not.toBeNull();
    const poweredBy = response!.headers()['x-powered-by'];
    // expose_php = Off ⇒ no X-Powered-By header at all
    expect(poweredBy).toBeFalsy();
  });

  test('openapi.yaml also carries hardening headers', async ({ request }) => {
    const response = await request.get('/openapi.yaml');
    const headers = response.headers();

    expect(headers['x-frame-options']?.toLowerCase()).toBe('sameorigin');
    expect(headers['x-content-type-options']?.toLowerCase()).toBe('nosniff');
  });
});
