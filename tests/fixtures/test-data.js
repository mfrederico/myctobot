/**
 * Test Data Fixtures
 *
 * Shared test data for Playwright tests.
 * Sensitive data should be passed via environment variables.
 */

const testData = {
  // Valid signup data
  signup: {
    business_name: 'Test Company Inc',
    email: 'test@example.com',
    password: 'testPassword123!',
  },

  // Invalid data for validation testing
  invalid: {
    email: 'not-an-email',
    shortPassword: '123',
    mismatchedPasswords: {
      password: 'password123',
      confirm: 'differentPassword',
    },
  },

  // URLs
  urls: {
    home: '/',
    login: '/auth/login',
    signup: '/signup',
    dashboard: '/settings/connections',
    logout: '/auth/logout',
    docs: '/docs',
    help: '/help',
  },

  // Selectors for common elements
  selectors: {
    navbar: 'nav.navbar',
    footer: 'footer',
    loginForm: 'form[action*="login"]',
    signupForm: 'form[action*="signup"]',
    flashMessage: '.alert, .toast',
    spinner: '.spinner-border',
    modal: '.modal.show',
  },

  // Wait times
  timeouts: {
    short: 1000,
    medium: 5000,
    long: 10000,
  },
};

module.exports = { testData };
