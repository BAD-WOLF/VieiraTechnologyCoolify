import { defineConfig } from '@playwright/test';

/**
 * Browser journeys of a customer and of the owner against the local environment (infra/local).
 *
 *   HOSTREMA_URL   dashboard address (default http://localhost:8000)
 *   MAILPIT_URL    Mailpit, to read the sign-up e-mail (default http://localhost:8025)
 *   E2E_NODE=1     a working node exists: also deploy and create resources
 *   PW_CHANNEL     browser channel (e.g. "chrome" to use an installed Google Chrome)
 */
export default defineConfig({
    testDir: './tests',
    timeout: 15 * 60 * 1000,
    expect: { timeout: 20 * 1000 },
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
    use: {
        baseURL: process.env.HOSTREMA_URL ?? 'http://localhost:8000',
        channel: process.env.PW_CHANNEL || undefined,
        locale: 'pt-BR',
        actionTimeout: 20 * 1000,
        navigationTimeout: 60 * 1000,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: process.env.CI ? 'retain-on-failure' : 'off',
    },
});
