/**
 * E2E demo of the BeaverMind "Clone from URL" flow.
 *
 * Authentication: bypasses the UI login flow entirely. A wp-cli-minted auth
 * cookie is pre-loaded via Playwright's storageState (see scripts/generate-
 * auth-state.sh and .auth/state.json). The test can navigate straight into
 * wp-admin as the test user.
 *
 * Why bypass the UI login? WPS Hide Login + Beaver Themer + maintenance mode
 * make the login page brittle to automate, and the login itself isn't what
 * we're demoing. Use a real logged-in session, test the real feature.
 *
 * Steps:
 *   1. Open BeaverMind → Clone from URL (already authed).
 *   2. Paste a reference URL + a design hint.
 *   3. Submit and wait for Claude + the LayoutWriter (~15-30s).
 *   4. Assert the success notice points at a new draft page + plan summary.
 *   5. Visit the generated page and screenshot the Beaver Builder render.
 *
 * Playwright records a full video of the run under test-results/.
 */
import { test, expect } from '@playwright/test';

const REFERENCE_URL = process.env.TEST_REFERENCE_URL
  ?? 'https://testbeavermind.dependentmedia.com/beavermind-fixtures/example-landing.html';
const DESIGN_HINT = 'modern SaaS, calm neutrals, dark CTA banner';

test.describe('BeaverMind clone-from-URL', () => {
  test.setTimeout(180_000);

  test('clones a reference page into a BB layout', async ({ page }) => {
    await test.step('Open the Clone from URL admin page', async () => {
      await page.goto('/wp-admin/admin.php?page=beavermind-clone');
      await expect(page.locator('#wpadminbar')).toBeVisible({ timeout: 15_000 });
      await expect(page.getByRole('heading', { name: /BeaverMind.*Clone from URL/ })).toBeVisible();
      await expect(page.getByTestId('bm-clone-form')).toBeVisible();
      await page.screenshot({ path: 'test-results/01-clone-page-loaded.png', fullPage: true });
    });

    await test.step('Fill and submit the form', async () => {
      await page.getByTestId('bm-url-input').fill(REFERENCE_URL);
      await page.getByTestId('bm-hint-input').fill(DESIGN_HINT);
      await page.getByTestId('bm-status-select').selectOption('publish');
      // Force 1 variant so we exercise the single-page render path with
      // its plan summary (`bm-plan-summary`). The form's default is now 3
      // variants (matches the Elementor "always show 3" UX) which renders
      // a gallery instead of the summary; that's tested separately.
      await page.locator('#bm_variants').selectOption('1');
      await page.screenshot({ path: 'test-results/02-form-filled.png', fullPage: true });
      // `noWaitAfter: true` because the submit triggers admin-post.php which
      // calls Claude (10-40s) before redirecting back. The default click
      // behaviour waits for navigation within actionTimeout (15s) and fails.
      // We let the explicit waitForURL below handle the round-trip.
      await page.getByTestId('bm-clone-submit').click({ noWaitAfter: true });
    });

    let postId = '';

    await test.step('Wait for Claude + writer to finish', async () => {
      // admin-post handler fetches, plans, writes, then redirects back here.
      // Plan time varies 10-45s; allow up to 2 minutes.
      await page.waitForURL(/page=beavermind-clone/, { timeout: 120_000 });
      await expect(page.getByTestId('bm-success')).toBeVisible({ timeout: 120_000 });
      await expect(page.getByTestId('bm-plan-summary')).toBeVisible();
      await page.screenshot({ path: 'test-results/03-clone-success.png', fullPage: true });

      const editLink = page.getByTestId('bm-edit-link');
      const editHref = await editLink.getAttribute('href');
      const match = /[?&]post=(\d+)/.exec(editHref ?? '');
      expect(match, 'Expected post ID in edit link').not.toBeNull();
      postId = match![1];
      console.log(`Generated page #${postId}`);
    });

    await test.step('Visit the generated page and screenshot the BB render', async () => {
      await page.goto(`/?page_id=${postId}`);
      // BB wraps its layout in .fl-builder-content — wait for it as proof-of-render.
      await expect(page.locator('.fl-builder-content').first()).toBeVisible({ timeout: 30_000 });
      await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {});
      await page.screenshot({ path: `test-results/04-rendered-page-${postId}.png`, fullPage: true });
    });
  });
});
