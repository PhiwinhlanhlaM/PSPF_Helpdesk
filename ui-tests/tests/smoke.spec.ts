import { test, expect } from '@playwright/test';
import { SIGNIN_PATH } from '../helpers/auth';

/**
 * Zero-config smoke checks — these run with no credentials and prove the app
 * is up and the auth path is alive.
 */

test.describe('Sign-in page', () => {
  test('loads and renders the login form', async ({ page }) => {
    const response = await page.goto(SIGNIN_PATH);

    expect(response, 'no HTTP response from signin page').not.toBeNull();
    expect(response!.status(), 'signin page returned a non-2xx status').toBeLessThan(400);

    await expect(page).toHaveTitle(/Sign in CRM/i);

    // A raw PHP fatal would render "Fatal error" / "Warning" text instead of the app.
    await expect(page.locator('body')).not.toContainText(/Fatal error|Parse error|Uncaught/i);

    await expect(page.locator('#email')).toBeVisible();
    await expect(page.locator('#password')).toBeVisible();
    await expect(page.locator('button.login-btn')).toBeVisible();
  });

  test('rejects an invalid login with an error message', async ({ page }) => {
    await page.goto(SIGNIN_PATH);
    await page.fill('#email', 'not-a-real-user@example.com');
    await page.fill('#password', 'definitely-wrong-password');
    await page.click('button.login-btn');

    await expect(page.locator('.error')).toBeVisible();
    await expect(page.locator('.error')).toContainText(/Invalid email or password/i);
    await expect(page).toHaveURL(/signin\/index\.php/i);
  });
});
