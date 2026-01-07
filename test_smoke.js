const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    viewport: { width: 1920, height: 1080 }
  });
  const page = await context.newPage();

  // Collect console messages and errors
  const consoleMessages = [];
  const errors = [];

  page.on('console', msg => {
    const type = msg.type();
    const text = msg.text();
    consoleMessages.push({ type, text });
    if (type === 'error') {
      console.log(`❌ Console Error: ${text}`);
    }
  });

  page.on('pageerror', error => {
    errors.push(error.message);
    console.log(`❌ Page Error: ${error.message}`);
  });

  const testPages = [
    { name: 'Homepage', url: 'https://myctobot.ai/' },
    { name: 'Dashboard', url: 'https://myctobot.ai/dashboard' },
    { name: 'Boards', url: 'https://myctobot.ai/boards' },
    { name: 'Settings - Connections', url: 'https://myctobot.ai/settings/connections' }
  ];

  const results = [];

  for (const testPage of testPages) {
    console.log(`\n=== Testing: ${testPage.name} (${testPage.url}) ===`);

    try {
      // Clear previous messages
      consoleMessages.length = 0;
      errors.length = 0;

      // Navigate to page
      const response = await page.goto(testPage.url, {
        waitUntil: 'domcontentloaded',
        timeout: 30000
      });

      const status = response.status();
      const finalUrl = page.url();

      // Wait a bit for any dynamic content
      await page.waitForTimeout(2000);

      // Check for PHP errors in page content
      const bodyText = await page.textContent('body');
      const hasPhpError = bodyText.includes('Fatal error') ||
                         bodyText.includes('Parse error') ||
                         bodyText.includes('Warning:') ||
                         bodyText.includes('Notice:');

      // Take screenshot
      const screenshotPath = `/home/mfrederico/development/myctobot/screenshot_${testPage.name.replace(/\s+/g, '_').toLowerCase()}.png`;
      await page.screenshot({ path: screenshotPath, fullPage: true });

      // Get page title
      const title = await page.title();

      const result = {
        name: testPage.name,
        url: testPage.url,
        finalUrl: finalUrl,
        status: status,
        title: title,
        redirected: finalUrl !== testPage.url,
        hasPhpError: hasPhpError,
        consoleErrors: consoleMessages.filter(m => m.type === 'error').length,
        pageErrors: errors.length,
        screenshot: screenshotPath
      };

      results.push(result);

      console.log(`Status: ${status}`);
      console.log(`Title: ${title}`);
      console.log(`Final URL: ${finalUrl}`);
      console.log(`Redirected: ${result.redirected}`);
      console.log(`PHP Errors: ${hasPhpError ? 'YES ⚠️' : 'NO ✓'}`);
      console.log(`Console Errors: ${result.consoleErrors}`);
      console.log(`Page Errors: ${result.pageErrors}`);
      console.log(`Screenshot: ${screenshotPath}`);

    } catch (error) {
      console.log(`❌ Failed to test ${testPage.name}: ${error.message}`);
      results.push({
        name: testPage.name,
        url: testPage.url,
        error: error.message
      });
    }
  }

  // Save results to JSON
  const fs = require('fs');
  fs.writeFileSync(
    '/home/mfrederico/development/myctobot/test_results.json',
    JSON.stringify(results, null, 2)
  );

  console.log('\n=== Test Complete ===');
  console.log('Results saved to test_results.json');

  await browser.close();
})();
