import {chromium, expect} from '@playwright/test';
import {writeFile} from 'node:fs/promises';

const origin = process.env.SIMPLEMAPPR_TEST_URL;
const address = process.env.SIMPLEMAPPR_FIXTURE_ADDRESS;
let stage = 'launch';
let activeProvider = '';
let browser;
try {
  browser = await chromium.launch({
    headless: true,
    args: ['--no-proxy-server', `--host-resolver-rules=MAP accounts.google.com ${address}, MAP login.microsoftonline.com ${address}`],
  });
  for (const provider of ['Google', 'Microsoft']) {
    activeProvider = provider;
    const context = await browser.newContext({ignoreHTTPSErrors: true, serviceWorkers: 'block'});
    try {
      await context.route('**/*', route => {
        const url = new URL(route.request().url());
        return url.origin === origin || ['accounts.google.com', 'login.microsoftonline.com'].includes(url.hostname)
          ? route.continue() : route.abort();
      });
      const page = await context.newPage();
      page.setDefaultTimeout(20000);
      stage = 'open-login';
      await page.goto(origin + '/login');
      stage = 'click-provider';
      await page.getByRole('button', {name: `Continue with ${provider}`, exact: true}).click();
      stage = 'return-to-editor';
      await page.waitForURL(origin + '/');
      stage = 'signed-in-control';
      await expect(page.getByRole('button', {name: 'Sign Out', exact: true})).toBeVisible();
      stage = 'open-my-maps';
      await page.locator('a[href="#map-mymaps"]').click();
      await expect(page.locator('#map-mymaps')).toBeVisible();
      stage = 'private-maps-request';
      const privateMaps = await context.request.get(origin + '/usermap');
      expect(privateMaps.status()).toBe(200);
      stage = 'sign-out';
      await page.getByRole('button', {name: 'Sign Out', exact: true}).click();
      stage = 'signed-out-control';
      await expect(page.getByRole('link', {name: 'Sign In', exact: true})).toBeVisible();
    } finally {
      await context.close();
    }
  }
} catch (error) {
  const category = ['TimeoutError', 'Error', 'AssertionError', 'TypeError'].includes(error.name) ? error.name : 'Error';
  process.stderr.write(JSON.stringify({stage, provider: activeProvider, category}) + '\n');
  if (process.env.SIMPLEMAPPR_BROWSER_DIAGNOSTICS) {
    await writeFile('/diagnostics/browser-error.json', JSON.stringify({stage, provider: activeProvider, name: error.name, message: error.message, stack: error.stack}), {mode: 0o600});
  }
  process.exitCode = 1;
} finally {
  await browser?.close();
}
