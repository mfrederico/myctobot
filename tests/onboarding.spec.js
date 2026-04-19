// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * Onboarding Wizard Tests
 *
 * Tests the step-by-step setup wizard for new users.
 * Note: These tests require an authenticated session.
 *
 * Test user credentials should be set via environment variables:
 * - TEST_USER_EMAIL
 * - TEST_USER_PASSWORD
 */
test.describe('Onboarding Wizard', () => {

  // Skip if no test credentials provided
  test.skip(!process.env.TEST_USER_EMAIL, 'Skipping - TEST_USER_EMAIL not set');

  test.beforeEach(async ({ page }) => {
    // Login first
    await page.goto('/auth/login');

    await page.fill('input[name="email"], input[name="username"]', process.env.TEST_USER_EMAIL || '');
    await page.fill('input[type="password"]', process.env.TEST_USER_PASSWORD || '');
    await page.click('button[type="submit"]');

    // Wait for redirect to dashboard
    await page.waitForURL(/settings|dashboard/, { timeout: 10000 });
  });

  test('should display onboarding wizard modal when setup incomplete', async ({ page }) => {
    await page.goto('/settings/connections');

    // Check if wizard modal exists
    const wizardModal = page.locator('#onboardingWizard');
    await expect(wizardModal).toBeAttached();
  });

  test('should have Setup Guide button to manually open wizard', async ({ page }) => {
    await page.goto('/settings/connections');

    // Look for Setup Guide button
    const setupButton = page.locator('button:has-text("Setup Guide"), a:has-text("Setup Guide")');
    await expect(setupButton).toBeVisible();
  });

  test('wizard should display 5-step progress indicator', async ({ page }) => {
    await page.goto('/settings/connections');

    // Open wizard if not auto-opened
    const setupButton = page.locator('button:has-text("Setup Guide")');
    if (await setupButton.isVisible()) {
      await setupButton.click();
    }

    // Wait for modal to be visible
    await page.waitForSelector('#onboardingWizard.show, #onboardingWizard:visible', { timeout: 5000 }).catch(() => {});

    // Check for progress steps
    const progressSteps = page.locator('.progress-step, .onboarding-progress .step-circle');
    const stepCount = await progressSteps.count();

    // Should have 5 steps
    expect(stepCount).toBe(5);
  });

  test('wizard step 1 should be Connect GitHub', async ({ page }) => {
    await page.goto('/settings/connections');

    // Open wizard
    const setupButton = page.locator('button:has-text("Setup Guide")');
    if (await setupButton.isVisible()) {
      await setupButton.click();
    }

    await page.waitForSelector('#onboardingWizard.show', { timeout: 5000 }).catch(() => {});

    // Check step 1 content
    const step1 = page.locator('#wizardStep1');
    const githubText = step1.locator('text=/GitHub/i');
    await expect(githubText.first()).toBeVisible();
  });

  test('wizard should have GitHub connect button', async ({ page }) => {
    await page.goto('/settings/connections');

    // Open wizard
    const setupButton = page.locator('button:has-text("Setup Guide")');
    if (await setupButton.isVisible()) {
      await setupButton.click();
    }

    await page.waitForSelector('#onboardingWizard.show', { timeout: 5000 }).catch(() => {});

    // Check for GitHub connect action
    const githubBtn = page.locator('#onboardingWizard button:has-text("Connect GitHub"), #onboardingWizard a[href*="github"]');
    await expect(githubBtn.first()).toBeVisible();
  });

  test('wizard should be dismissible', async ({ page }) => {
    await page.goto('/settings/connections');

    // Open wizard
    const setupButton = page.locator('button:has-text("Setup Guide")');
    if (await setupButton.isVisible()) {
      await setupButton.click();
    }

    await page.waitForSelector('#onboardingWizard.show', { timeout: 5000 }).catch(() => {});

    // Find close button or "I'll finish later" link
    const closeBtn = page.locator('#onboardingWizard .btn-close, #onboardingWizard button:has-text("later")');
    await closeBtn.first().click();

    // Modal should be hidden
    await expect(page.locator('#onboardingWizard.show')).not.toBeVisible();
  });

  test('wizard should show security reassurance messages', async ({ page }) => {
    await page.goto('/settings/connections');

    // Open wizard
    const setupButton = page.locator('button:has-text("Setup Guide")');
    if (await setupButton.isVisible()) {
      await setupButton.click();
    }

    await page.waitForSelector('#onboardingWizard.show', { timeout: 5000 }).catch(() => {});

    // Check for security/trust messaging
    const securityText = page.locator('#onboardingWizard text=/OAuth|permissions|secure|password/i');
    await expect(securityText.first()).toBeVisible();
  });

});

/**
 * Dashboard Post-Login Tests
 *
 * Tests the dashboard experience after login.
 */
test.describe('Dashboard', () => {

  test.skip(!process.env.TEST_USER_EMAIL, 'Skipping - TEST_USER_EMAIL not set');

  test.beforeEach(async ({ page }) => {
    await page.goto('/auth/login');
    await page.fill('input[name="email"], input[name="username"]', process.env.TEST_USER_EMAIL || '');
    await page.fill('input[type="password"]', process.env.TEST_USER_PASSWORD || '');
    await page.click('button[type="submit"]');
    await page.waitForURL(/settings|dashboard/, { timeout: 10000 });
  });

  test('should display user profile information', async ({ page }) => {
    await page.goto('/settings/connections');

    // Check for profile section
    const profileCard = page.locator('.card:has-text("Profile")');
    await expect(profileCard).toBeVisible();
  });

  test('should display subscription status', async ({ page }) => {
    await page.goto('/settings/connections');

    // Check for subscription section
    const subscriptionCard = page.locator('.card:has-text("Subscription"), .card:has-text("Plan")');
    await expect(subscriptionCard).toBeVisible();
  });

  test('should display connected services section', async ({ page }) => {
    await page.goto('/settings/connections');

    // Check for connected services
    const servicesSection = page.locator('text=/Connected Services/i');
    await expect(servicesSection).toBeVisible();
  });

  test('should display quick links', async ({ page }) => {
    await page.goto('/settings/connections');

    // Check for quick links section
    const quickLinks = page.locator('.card:has-text("Quick Links")');
    await expect(quickLinks).toBeVisible();
  });

  test('should display statistics', async ({ page }) => {
    await page.goto('/settings/connections');

    // Check for statistics
    const stats = page.locator('.card:has-text("Statistics"), .card:has-text("Stats")');
    await expect(stats).toBeVisible();
  });

  test('navigation should show Dashboard link', async ({ page }) => {
    await page.goto('/settings/connections');

    // Check navbar has Dashboard
    const dashboardLink = page.locator('nav a:has-text("Dashboard")');
    await expect(dashboardLink.first()).toBeVisible();
  });

  test('should have logout option', async ({ page }) => {
    await page.goto('/settings/connections');

    // Look for logout link
    const logoutLink = page.locator('a[href*="logout"]');
    await expect(logoutLink.first()).toBeVisible();
  });

});
