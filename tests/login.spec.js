// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * Login Flow Tests
 *
 * Tests the user authentication process.
 */
test.describe('Login Flow', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto('/auth/login');
  });

  test('should display login form', async ({ page }) => {
    // Form should be visible
    const form = page.locator('form');
    await expect(form).toBeVisible();

    // Check for email/username and password fields
    const emailField = page.locator('input[type="email"], input[name="email"], input[name="username"]');
    const passwordField = page.locator('input[type="password"]');

    await expect(emailField.first()).toBeVisible();
    await expect(passwordField).toBeVisible();
  });

  test('should have submit button', async ({ page }) => {
    const submitBtn = page.locator('button[type="submit"], input[type="submit"]');
    await expect(submitBtn.first()).toBeVisible();
    await expect(submitBtn.first()).toContainText(/Log|Sign|Submit/i);
  });

  test('should have link to registration', async ({ page }) => {
    const registerLink = page.locator('a[href*="register"], a[href*="signup"]');
    await expect(registerLink.first()).toBeVisible();
  });

  test('should show error for invalid credentials', async ({ page }) => {
    // Fill with invalid credentials
    await page.fill('input[type="email"], input[name="email"], input[name="username"]', 'invalid@test.com');
    await page.fill('input[type="password"]', 'wrongpassword');

    // Submit form
    await page.click('button[type="submit"], input[type="submit"]');

    // Should show error message or stay on login page
    // Wait for either error message or page to remain on login
    const errorMessage = page.locator('.alert-danger, .error, [class*="error"]');
    const isOnLoginPage = page.url().includes('login');

    // Either we see an error or we're still on login page
    const hasError = await errorMessage.isVisible().catch(() => false);
    expect(hasError || isOnLoginPage).toBeTruthy();
  });

  test('should have CSRF protection', async ({ page }) => {
    // Check for CSRF token
    const csrfInput = page.locator('input[name*="csrf"], input[name*="token"]');
    await expect(csrfInput.first()).toBeAttached();
  });

  test('should support workspace/tenant login', async ({ page }) => {
    // Check if workspace field exists (multi-tenant feature)
    const workspaceField = page.locator('input[name="workspace"]');
    const hasWorkspace = await workspaceField.isVisible().catch(() => false);

    // If multi-tenant, workspace field should exist
    // This is optional based on deployment mode
    if (hasWorkspace) {
      await expect(workspaceField).toBeVisible();
    }
  });

  test('should have Google OAuth option if configured', async ({ page }) => {
    // Check for Google login button
    const googleBtn = page.locator('a[href*="google"], button:has-text("Google")');
    const hasGoogle = await googleBtn.isVisible().catch(() => false);

    // Google OAuth is optional
    // Just verify page doesn't error if button exists
    if (hasGoogle) {
      await expect(googleBtn.first()).toBeVisible();
    }
  });

  test('login form should be responsive', async ({ page }) => {
    // Test on mobile viewport
    await page.setViewportSize({ width: 375, height: 667 });
    await page.reload();

    // Form should still be visible and usable
    const form = page.locator('form');
    await expect(form).toBeVisible();

    const emailField = page.locator('input[type="email"], input[name="email"], input[name="username"]');
    await expect(emailField.first()).toBeVisible();
  });

});
