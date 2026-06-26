const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { expect, test } = require('@playwright/test');

const fixturePath = path.join(__dirname, 'fixtures', 'render-admin-smoke.php');
const repoRoot = path.resolve(__dirname, '..', '..');
const adminUrl = 'https://example.org/wp-admin/admin.php?page=onesmtp';

function renderAdminFixture() {
  return execFileSync('php', [fixturePath], {
    cwd: repoRoot,
    encoding: 'utf8',
    env: {
      ...process.env,
      ONESMTP_PLAYWRIGHT_SMOKE: '1',
    },
  });
}

test.describe('OneSMTP admin browser smoke', () => {
  test.beforeEach(async ({ page }) => {
    const html = renderAdminFixture();

    await page.route('https://example.org/wp-admin/admin.php**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'text/html; charset=utf-8',
        body: html,
      });
    });

    await page.goto(adminUrl);
  });

  test('renders the admin shell and primary section navigation', async ({ page }) => {
    await expect(page.getByRole('heading', { name: 'OneSMTP', exact: true })).toBeVisible();
    const primaryNav = page.locator('.nav-tab-wrapper');

    for (const section of ['Dashboard', 'Setup', 'Providers', 'Logs', 'Diagnostics', 'Settings']) {
      await expect(primaryNav.getByRole('link', { name: section, exact: true })).toHaveAttribute('href', new RegExp(`#onesmtp-${section.toLowerCase()}`));
      await expect(page.getByRole('heading', { name: section, exact: true })).toBeVisible();
    }

    await primaryNav.getByRole('link', { name: 'Setup', exact: true }).click();
    await expect(page).toHaveURL(/#onesmtp-setup$/);
    await expect(page.locator('#onesmtp-setup')).toContainText('Test email');
  });

  test('submits provider settings with safe local fixture values', async ({ page }) => {
    await captureFormSubmissions(page);

    const providerForm = page.locator('form.onesmtp-provider-form');
    await providerForm.locator('input[name="name"]').fill('Browser Smoke SMTP');
    await providerForm.locator('select[name="adapter_type"]').selectOption('smtp');
    await providerForm.locator('input[name="priority"]').fill('10');
    await providerForm.locator('input[name="weight"]').fill('1');
    await providerForm.locator('input[name="is_active"]').check();
    await providerForm.locator('input[name="config[host]"]').fill('smtp.local.test');
    await providerForm.locator('input[name="config[port]"]').fill('2525');
    await providerForm.locator('input[name="config[username]"]').fill('browser-smoke');
    await providerForm.getByRole('button', { name: 'Save provider' }).click();

    await expect.poll(() => latestSubmission(page)).toMatchObject({
      onesmtp_provider_action: 'save',
      name: 'Browser Smoke SMTP',
      adapter_type: 'smtp',
      priority: '10',
      weight: '1',
      is_active: '1',
      'config[host]': 'smtp.local.test',
      'config[port]': '2525',
      'config[username]': 'browser-smoke',
    });
  });

  test('exercises setup wizard and test email form state without external sends', async ({ page }) => {
    await captureFormSubmissions(page);

    await expect(page.locator('#onesmtp-setup')).toContainText('Complete');
    await expect(page.locator('#onesmtp-setup')).toContainText('Send test email');

    const setupTestForm = page
      .locator('#onesmtp-setup form')
      .filter({ has: page.locator('input[name="onesmtp_setup_action"][value="send_test"]') });

    await setupTestForm.locator('select[name="provider_id"]').selectOption('7');
    await setupTestForm.locator('input[name="test_to"]').fill('recipient@example.test');
    await setupTestForm.getByRole('button', { name: 'Send test email' }).click();

    await expect.poll(() => latestSubmission(page)).toMatchObject({
      onesmtp_setup_action: 'send_test',
      provider_id: '7',
      test_to: 'recipient@example.test',
    });
  });

  test('renders log redaction and privacy-safe diagnostic state', async ({ page }) => {
    await expect(page.locator('#onesmtp-logs')).toContainText('Recent messages');
    await expect(page.locator('#onesmtp-logs')).toContainText('Message detail');
    await expect(page.locator('#onesmtp-logs')).toContainText('Manual resend');
    await expect(page.locator('#onesmtp-logs')).toContainText('1 recipients across example.test');
    await expect(page.locator('#onesmtp-logs')).toContainText('transient timeout');

    await expect(page.locator('#onesmtp-logs')).not.toContainText('recipient@example.test');
    await expect(page.locator('#onesmtp-logs')).not.toContainText('Internal smoke body');
    await expect(page.locator('#onesmtp-logs')).not.toContainText('/var/www/private/invoice.pdf');

    await expect(page.locator('#onesmtp-diagnostics')).toContainText('Scheduler availability');
    await expect(page.locator('#onesmtp-diagnostics')).toContainText('Unavailable');
    await expect(page.locator('#onesmtp-diagnostics')).toContainText('Overdue retries');
    await expect(page.locator('#onesmtp-diagnostics')).toContainText('Action Scheduler');
    await expect(page.locator('#onesmtp-diagnostic-preview')).not.toContainText('customer body');
    await expect(page.locator('#onesmtp-diagnostic-preview')).not.toContainText('secret-token');
    await expect(page.locator('#onesmtp-diagnostic-preview')).not.toContainText('smtp.local.test');
  });
});

async function captureFormSubmissions(page) {
  await page.addInitScript(() => {
    window.__onesmtpSubmittedForms = [];
    document.addEventListener(
      'submit',
      (event) => {
        event.preventDefault();
        const formData = new FormData(event.target);
        window.__onesmtpSubmittedForms.push(Object.fromEntries(formData.entries()));
      },
      true
    );
  });

  await page.evaluate(() => {
    window.__onesmtpSubmittedForms = [];
    document.addEventListener(
      'submit',
      (event) => {
        event.preventDefault();
        const formData = new FormData(event.target);
        window.__onesmtpSubmittedForms.push(Object.fromEntries(formData.entries()));
      },
      true
    );
  });
}

async function latestSubmission(page) {
  return page.evaluate(() => {
    const submissions = window.__onesmtpSubmittedForms || [];

    return submissions[submissions.length - 1] || {};
  });
}
