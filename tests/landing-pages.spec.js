// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * Landing Pages Tests
 *
 * Tests feature and services landing pages.
 */
test.describe('Feature Pages', () => {

  test('AI Developer page loads with platform pricing', async ({ page }) => {
    await page.goto('/landing/aideveloper');

    const heading = page.locator('h1');
    await expect(heading).toBeVisible();

    // Should show platform pricing
    const platformPricing = page.locator('text=MyCTOBot Pro');
    await expect(platformPricing.first()).toBeVisible();

    // Should have signup CTA
    const signupLink = page.locator('a[href="/signup"]');
    await expect(signupLink.first()).toBeVisible();
  });

  test('Code Review page loads with platform pricing', async ({ page }) => {
    await page.goto('/landing/codereview');

    const heading = page.locator('h1');
    await expect(heading).toBeVisible();

    // Should show platform pricing
    const platformPricing = page.locator('text=MyCTOBot Pro');
    await expect(platformPricing.first()).toBeVisible();
  });

  test('Pipelines page loads with platform pricing', async ({ page }) => {
    await page.goto('/landing/pipelines');

    const heading = page.locator('h1');
    await expect(heading).toBeVisible();

    // Should show platform pricing
    const platformPricing = page.locator('text=MyCTOBot Pro');
    await expect(platformPricing.first()).toBeVisible();
  });

});

test.describe('Services Pages', () => {

  test('PHP Modernization page loads with project pricing', async ({ page }) => {
    await page.goto('/landing/phpmodernization');

    const heading = page.locator('h1');
    await expect(heading).toBeVisible();
    await expect(heading).toContainText(/PHP|Modernize/i);

    // Should have pricing section
    const pricingSection = page.locator('#pricing');
    await expect(pricingSection).toBeVisible();
  });

  test('Shopify Themes page loads with project pricing', async ({ page }) => {
    await page.goto('/landing/shopifythemes');

    const heading = page.locator('h1');
    await expect(heading).toBeVisible();
    await expect(heading).toContainText(/Shopify|Theme/i);

    // Should have pricing section
    const pricingSection = page.locator('#pricing');
    await expect(pricingSection).toBeVisible();
  });

});

test.describe('Footer Consistency', () => {

  const pages = [
    '/landing/aideveloper',
    '/landing/codereview',
    '/landing/pipelines',
    '/landing/phpmodernization',
    '/landing/shopifythemes'
  ];

  for (const pagePath of pages) {
    test(`${pagePath} has Features and Services footer columns`, async ({ page }) => {
      await page.goto(pagePath);
      await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));

      const footer = page.locator('footer');
      await expect(footer).toBeVisible();

      // Should have privacy link
      const privacyLink = footer.locator('a[href*="privacy"]');
      await expect(privacyLink).toBeVisible();
    });
  }

});
