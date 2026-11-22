import { expect, test } from '@playwright/test';
import { expectBranded, screenshot, verificationLink } from './support';

/**
 * A customer on the cheapest plan brings a Docker Compose project as it is in its repository
 * (a database volume, the folder of the project mounted and host ports), changes the compose
 * file location and deploys it. Needs a node (the local environment of infra/local, E2E_NODE=1).
 */
const withNode = Boolean(process.env.E2E_NODE);
const repository = 'https://github.com/clemdesign/docker-symfony-demo';

test('a customer on the cheapest plan deploys a Docker Compose project as it is in the repository', async ({ browser, request }, testInfo) => {
    test.skip(!withNode, 'Needs a node: run it in the local environment (infra/local) with E2E_NODE=1.');

    const email = `basico-${Date.now()}@example.com`;
    const password = 'Basico-E2e-2026!';
    const context = await browser.newContext({ baseURL: testInfo.project.use.baseURL, locale: 'pt-BR' });
    const page = await context.newPage();
    const planErrors = page.getByText(/Não incluído no seu plano|Not included in your plan/);
    // Errors shown as toasts disappear after a few seconds: keep them for the failure message.
    const toasts: string[] = [];
    page.on('response', async (response) => {
        if (!response.url().includes('/livewire/update')) {
            return;
        }
        try {
            const body = (await response.json()) as { components?: Array<{ effects?: { dispatches?: Array<{ name: string; params?: unknown }> } }> };
            for (const component of body.components ?? []) {
                for (const dispatch of component.effects?.dispatches ?? []) {
                    if (['error', 'warning'].includes(dispatch.name)) {
                        toasts.push(`${dispatch.name}: ${JSON.stringify(dispatch.params)}`);
                    }
                }
            }
        } catch {
            // Not JSON (a redirect or an error page).
        }
    });
    const withToasts = async (step: () => Promise<void>) => {
        try {
            await step();
        } catch (error) {
            throw new Error(`${(error as Error).message}\n\nToasts shown so far:\n${toasts.join('\n') || '(none)'}`);
        }
    };

    try {
        await test.step('signs up and pays the cheapest plan', async () => {
            await page.goto('/register');
            await page.locator('#name').fill('Cliente Básico');
            await page.locator('#email').fill(email);
            await page.locator('#password').fill(password);
            await page.locator('#password_confirmation').fill(password);
            await page.getByRole('button', { name: 'Register' }).click();
            await page.waitForURL(/\/verify/);
            await page.goto(await verificationLink(request, email));
            await page.waitForURL((url) => !url.pathname.startsWith('/email/verify'));

            await page.goto('/subscription');
            await page.locator('[wire\\:key="plan-basic"]').getByRole('button', { name: 'Assinar' }).click();
            await page.locator('#profile\\.name').fill('Cliente Básico');
            await page.locator('#profile\\.document').fill('529.982.247-25');
            await page.getByRole('button', { name: 'Salvar e continuar' }).click();
            await page.getByRole('button', { name: 'Simular pagamento (testes)' }).click();
            await expect(page.getByText('Pagamento confirmado')).toBeVisible();
        });

        await test.step('adds the repository with the Docker Compose build pack', async () => {
            await page.goto('/platform/onboarding');
            await page.locator('#projectName').fill('Symfony');
            await page.getByRole('button', { name: 'Criar e publicar minha primeira aplicação' }).click();
            await page.waitForURL(/\/new/, { timeout: 120_000 });
            await page.getByText('Public Repository', { exact: true }).click();
            await page.locator('#repository_url').fill(repository);
            await page.getByRole('button', { name: 'Check repository' }).click();
            await page.locator('select[wire\\:model\\.live="build_pack"]').selectOption('dockercompose');
            await expect(page.getByText('Docker Compose Location')).toBeVisible();
            await page.getByRole('button', { name: 'Continue' }).click();
            await page.waitForURL(/\/application\//, { timeout: 60_000 });
        });

        await test.step('changes the compose file from .yaml to .yml and the services load', () => withToasts(async () => {
            // The repository has docker-compose.yml; the default location is /docker-compose.yaml,
            // which the page tries to read first (and reports as not found).
            const location = page.locator('#application\\.docker_compose_location');
            await expect(location).toBeEnabled({ timeout: 3 * 60_000 });
            await location.fill('/docker-compose.yml');
            await page.locator('form').filter({ has: location }).getByRole('button', { name: 'Save' }).click();
            await expect(page.getByText('Domains for phpmyadmin')).toBeVisible({ timeout: 3 * 60_000 });
            await expect(page.getByText('Domains for nginx')).toBeVisible();
            await expect(location).toHaveValue('/docker-compose.yml');
            await expect(planErrors).toHaveCount(0);
            await expectBranded(page);
            await screenshot(page, 'cheapest-plan-compose-loaded');
        }));

        await test.step('deploys: the project folder is kept and the host ports become internal', () => withToasts(async () => {
            // The checkbox of the form components has no id: it is found by its binding.
            const preserve = page.locator('input[type="checkbox"][wire\\:model="application.settings.is_preserve_repository_enabled"]');
            await preserve.check();
            await expect(preserve).toBeChecked();
            await page.getByRole('button', { name: 'Deploy' }).first().click();
            await expect(page.getByText(/Service "php-fpm": host ports .* are not published on the platform/).first()).toBeVisible({ timeout: 5 * 60_000 });
            await expect(page.getByText('Pulling & building required images.').first()).toBeVisible({ timeout: 5 * 60_000 });
            await expect(page.getByText(/mounts a directory of the project/)).toHaveCount(0);
            await expect(planErrors).toHaveCount(0);
            await screenshot(page, 'cheapest-plan-compose-deploying');
        }));
    } finally {
        await context.close();
    }
});
