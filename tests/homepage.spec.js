// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * Homepage Tests
 *
 * Tests the landing page and public-facing elements.
 */
test.describe('Homepage', () => {

  test('should display the hero section with clear value proposition', async ({ page }) => {
    await page.goto('/');

    // Check for main heading
    const heading = page.locator('h1');
    await expect(heading).toBeVisible();
    await expect(heading).toContainText(/AI-Powered|Development|Team/i);
  });

  test('should have prominent signup call-to-action', async ({ page }) => {
    await page.goto('/');

    // Should have a signup button or form
    const signupCTA = page.locator('a[href*="signup"], a[href*="register"], button:has-text("Get Started")');
    await expect(signupCTA.first()).toBeVisible();
  });

  test('should display feature cards explaining the product', async ({ page }) => {
    await page.goto('/');

    // Check for feature sections
    const features = page.locator('.feature-card, [class*="feature"]');
    await expect(features.first()).toBeVisible();
  });

  test('should have working navigation links', async ({ page }) => {
    await page.goto('/');

    // Check navbar exists
    const navbar = page.locator('nav, .navbar');
    await expect(navbar).toBeVisible();

    // Check login link exists
    const loginLink = page.locator('a[href*="login"]');
    await expect(loginLink.first()).toBeVisible();
  });

  test('should display footer with essential links', async ({ page }) => {
    await page.goto('/');

    // Scroll to footer
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));

    // Check footer exists
    const footer = page.locator('footer');
    await expect(footer).toBeVisible();

    // Should have terms and privacy links
    const termsLink = page.locator('a[href*="terms"]');
    const privacyLink = page.locator('a[href*="privacy"]');
    await expect(termsLink).toBeVisible();
    await expect(privacyLink).toBeVisible();
  });

  test('should be responsive on mobile viewport', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 });
    await page.goto('/');

    // Page should still be usable
    const heading = page.locator('h1');
    await expect(heading).toBeVisible();

    // Mobile menu toggle should be visible (Bootstrap)
    const mobileToggle = page.locator('.navbar-toggler, [data-bs-toggle="collapse"]');
    await expect(mobileToggle).toBeVisible();
  });

  test('should load SVG illustrations correctly', async ({ page }) => {
    await page.goto('/');

    // Check that SVG images don't show error placeholders
    const svgImages = page.locator('img[src*=".svg"]');
    const count = await svgImages.count();

    if (count > 0) {
      // Verify at least one SVG loaded successfully (no onerror triggered)
      for (let i = 0; i < Math.min(count, 3); i++) {
        const img = svgImages.nth(i);
        const naturalWidth = await img.evaluate(el => el.naturalWidth);
        expect(naturalWidth).toBeGreaterThan(0);
      }
    }
  });

});
