// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * Signup Flow Tests
 *
 * Tests the new user registration process.
 */
test.describe('Signup Flow', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto('/signup');
  });

  test('should display signup form with all required fields', async ({ page }) => {
    // Check form exists
    const form = page.locator('form');
    await expect(form).toBeVisible();

    // Required fields
    await expect(page.locator('#business_name, [name="business_name"]')).toBeVisible();
    await expect(page.locator('#email, [name="email"]')).toBeVisible();
    await expect(page.locator('#password, [name="password"]')).toBeVisible();
    await expect(page.locator('#password_confirm, [name="password_confirm"]')).toBeVisible();

    // Submit button
    const submitBtn = page.locator('button[type="submit"]');
    await expect(submitBtn).toBeVisible();
    await expect(submitBtn).toContainText(/Create|Sign|Get Started/i);
  });

  test('should show field labels for accessibility', async ({ page }) => {
    // Labels for form fields
    await expect(page.locator('label[for="business_name"]')).toBeVisible();
    await expect(page.locator('label[for="email"]')).toBeVisible();
    await expect(page.locator('label[for="password"]')).toBeVisible();
  });

  test('should validate email format on client side', async ({ page }) => {
    // Fill invalid email
    await page.fill('#email, [name="email"]', 'not-an-email');
    await page.click('button[type="submit"]');

    // Form should not submit (HTML5 validation)
    await expect(page).toHaveURL(/signup/);
  });

  test('should validate password confirmation matches', async ({ page }) => {
    // Fill form with mismatched passwords
    await page.fill('#business_name, [name="business_name"]', 'Test Company');
    await page.fill('#email, [name="email"]', 'test@example.com');
    await page.fill('#password, [name="password"]', 'password123');
    await page.fill('#password_confirm, [name="password_confirm"]', 'different123');

    // Click submit
    await page.click('button[type="submit"]');

    // Should show error or stay on page
    // The JS validation shows an alert, but form should not redirect to dashboard
    await expect(page).toHaveURL(/signup/);
  });

  test('should validate minimum password length', async ({ page }) => {
    // Password field should have minlength attribute
    const passwordField = page.locator('#password, [name="password"]');
    const minLength = await passwordField.getAttribute('minlength');

    // Should require at least 6 characters (based on view code)
    expect(parseInt(minLength || '0')).toBeGreaterThanOrEqual(6);
  });

  test('should have link to login for existing users', async ({ page }) => {
    // Check for login link/text
    const loginText = page.locator('text=/already have an account|log in/i');
    await expect(loginText).toBeVisible();
  });

  test('should display terms and privacy links', async ({ page }) => {
    const termsLink = page.locator('a[href*="terms"]');
    const privacyLink = page.locator('a[href*="privacy"]');

    await expect(termsLink).toBeVisible();
    await expect(privacyLink).toBeVisible();
  });

  test('should have CSRF protection', async ({ page }) => {
    // Check for CSRF token in form
    const csrfInput = page.locator('input[name*="csrf"], input[name*="token"]');
    await expect(csrfInput.first()).toBeAttached();
  });

  test('signup form should be accessible', async ({ page }) => {
    // Check form has proper structure for screen readers
    const formInputs = page.locator('input[required]');
    const count = await formInputs.count();

    // All required inputs should have labels
    for (let i = 0; i < count; i++) {
      const input = formInputs.nth(i);
      const id = await input.getAttribute('id');
      const name = await input.getAttribute('name');

      if (id) {
        const label = page.locator(`label[for="${id}"]`);
        const isVisible = await label.isVisible().catch(() => false);
        // Either has visible label or aria-label
        if (!isVisible) {
          const ariaLabel = await input.getAttribute('aria-label');
          const placeholder = await input.getAttribute('placeholder');
          expect(ariaLabel || placeholder).toBeTruthy();
        }
      }
    }
  });

});
