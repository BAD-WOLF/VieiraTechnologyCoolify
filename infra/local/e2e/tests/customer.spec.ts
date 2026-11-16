import { Page, expect, test } from '@playwright/test';
import { expectBranded, screenshot, verificationLink } from './support';

/**
 * A new customer, from the sign-up to applications running at http://<name>.localhost. The second
 * part needs a node (the local environment of infra/local, E2E_NODE=1).
 */
const withNode = Boolean(process.env.E2E_NODE);
const email = `cliente-${Date.now()}@example.com`;
const password = 'Cliente-E2e-2026!';

test.describe.configure({ mode: 'serial' });

let page: Page;
let newResource = '';

test.beforeAll(async ({ browser }, testInfo) => {
    page = await browser.newPage({ baseURL: testInfo.project.use.baseURL, locale: 'pt-BR' });
});

test.afterAll(async () => {
    await page?.close();
});

test('a customer signs up, confirms the e-mail and pays with only name and CPF', async ({ request }) => {
    await test.step('signs up and confirms the e-mail', async () => {
        await page.goto('/register');
        await page.locator('#name').fill('Cliente E2E');
        await page.locator('#email').fill(email);
        await page.locator('#password').fill(password);
        await page.locator('#password_confirmation').fill(password);
        await page.getByRole('button', { name: 'Register' }).click();
        await page.waitForURL(/\/verify/);

        await page.goto(await verificationLink(request, email));
        await page.waitForURL((url) => !url.pathname.startsWith('/email/verify'));
    });

    await test.step('opens the dashboard before paying and finds the plans', async () => {
        await page.goto('/');
        await expect(page.getByText('Seu plano ainda não está ativo')).toBeVisible();
        await expectBranded(page);
        await screenshot(page, 'dashboard-before-paying');
        await page.getByRole('link', { name: 'Planos e pagamento' }).click();
        await page.waitForURL(/\/subscription/);
    });

    await test.step('chooses a plan', async () => {
        await page.locator('[wire\\:key="plan-pro"]').getByRole('button', { name: 'Assinar' }).click();
        await page.locator('#profile\\.name').fill('Cliente E2E');
        await page.locator('#profile\\.document').fill('529.982.247-25');
        await expect(page.locator('#profile\\.email')).toHaveValue(email);
        await expect(page.locator('#profile\\.zip')).toHaveCount(0);
        await page.getByRole('button', { name: 'Salvar e continuar' }).click();

        await expect(page.getByText('Pagamento pendente')).toBeVisible();
        await expect(page.getByRole('link', { name: /Voltar ao início/ })).toBeVisible();
        await expectBranded(page);
        await screenshot(page, 'payment-pending');
    });

    await test.step('pays and goes to the dashboard', async () => {
        await page.getByRole('button', { name: 'Simular pagamento (testes)' }).click();
        await expect(page.getByText('Pagamento confirmado')).toBeVisible();
        await screenshot(page, 'payment-confirmed');
        await page.getByRole('link', { name: 'Ir para o painel' }).click();
        await page.waitForURL((url) => url.pathname === '/' || url.pathname.startsWith('/platform/onboarding'));
        await expectBranded(page);
    });
});

test('the customer deploys and creates resources', async () => {
    test.skip(!withNode, 'Needs a node: run it in the local environment (infra/local) with E2E_NODE=1.');

    await test.step('creates the first project', async () => {
        await page.goto('/platform/onboarding');
        await page.locator('#projectName').fill('Loja');
        await page.getByRole('button', { name: 'Criar e publicar minha primeira aplicação' }).click();
        await page.waitForURL(/\/new/, { timeout: 120_000 });
        newResource = page.url();
    });

    await test.step('adds a public GitHub repository', async () => {
        await page.getByText('Public Repository', { exact: true }).click();
        await page.locator('#repository_url').fill('https://github.com/BAD-WOLF/StreamVibe.git');
        await page.getByRole('button', { name: 'Check repository' }).click();
        await page.getByRole('button', { name: 'Continue' }).click();
        await page.waitForURL(/\/application\//, { timeout: 60_000 });
        await expectBranded(page);
    });

    await test.step('deploys a Docker image and opens it at its domain', async () => {
        await page.goto(newResource);
        await page.getByText('Docker Image', { exact: true }).click();
        await page.locator('#dockerImage').fill('nginx:alpine');
        await page.getByRole('button', { name: 'Save' }).click();
        await page.waitForURL(/\/application\//, { timeout: 60_000 });
        const domain = (await page.locator('input[wire\\:model\\.blur="application.fqdn"]').inputValue()).split(',')[0];
        expect(domain).toMatch(/^https?:\/\/[\w.-]+\.localhost/);

        await page.getByRole('button', { name: 'Deploy' }).first().click();
        await expect(page.getByText('Running', { exact: true }).first()).toBeVisible({ timeout: 8 * 60_000 });

        const app = await page.context().newPage();
        await expect(async () => {
            await app.goto(domain);
            await expect(app.getByText('Welcome to nginx!')).toBeVisible({ timeout: 5_000 });
        }).toPass({ timeout: 2 * 60_000 });
        await app.close();
    });

    await test.step('creates a PostgreSQL database', async () => {
        await page.goto(newResource);
        await page.getByText('PostgreSQL', { exact: true }).first().click();
        await page.getByText('PostgreSQL 17 (default)').click();
        await page.waitForURL(/\/database\//, { timeout: 60_000 });
        await expectBranded(page);
    });

    await test.step('creates a one-click service', async () => {
        await page.goto(newResource);
        await page.getByPlaceholder('Type / to search...').fill('uptime kuma');
        await page.getByText('Uptime Kuma', { exact: true }).first().click();
        await page.waitForURL(/\/service\//, { timeout: 60_000 });
        await expectBranded(page);
    });
});
