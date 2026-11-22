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

    for (const tab of ['tenants', 'plans', 'billing', 'nodes', 'settings', 'audit']) {
        const response = await page.goto(`/admin/platform?tab=${tab}`);
        expect(response?.status(), `tab ${tab}`).toBe(200);
        await expect(page.getByRole('heading', { name: 'Plataforma', exact: true })).toBeVisible();
    }

    // The plans tab edits every plan; the billing tab asks for the Efí credentials and certificate.
    await page.goto('/admin/platform?tab=plans');
    await expect(page.getByRole('button', { name: 'Novo plano' })).toBeVisible();
    await expect(page.getByText('Básico', { exact: true }).first()).toBeVisible();
    await page.getByRole('button', { name: 'Editar' }).first().click();
    await expect(page.getByText('Limites', { exact: true }).first()).toBeVisible();
    await page.getByRole('button', { name: 'Cancelar' }).first().click();

    await page.goto('/admin/platform?tab=billing');
    await expect(page.getByText('Client ID', { exact: true })).toBeVisible();
    await expect(page.getByText('Certificado da aplicação (.p12 ou .pem)')).toBeVisible();
    await expect(page.locator('body')).not.toContainText(/Banco Inter|Inter Empresas/);
});
