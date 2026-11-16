import { expect, test } from '@playwright/test';

/**
 * The owner signs in and opens the operator console (E2E_OWNER_EMAIL / E2E_OWNER_PASSWORD).
 */
const ownerEmail = process.env.E2E_OWNER_EMAIL;
const ownerPassword = process.env.E2E_OWNER_PASSWORD;

test('the owner opens every tab of the operator console', async ({ page }) => {
    test.skip(!ownerEmail || !ownerPassword, 'E2E_OWNER_EMAIL and E2E_OWNER_PASSWORD are not set.');

    await page.goto('/login');
    await page.locator('input[name="email"]').fill(ownerEmail!);
    await page.locator('input[name="password"]').fill(ownerPassword!);
    await page.locator('input[name="password"]').press('Enter');
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));

    for (const tab of ['tenants', 'billing', 'nodes', 'settings', 'audit']) {
        const response = await page.goto(`/admin/platform?tab=${tab}`);
        expect(response?.status(), `tab ${tab}`).toBe(200);
        await expect(page.getByRole('heading', { name: 'Plataforma', exact: true })).toBeVisible();
    }
});
