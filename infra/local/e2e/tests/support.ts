import { APIRequestContext, Page, expect, test } from '@playwright/test';
import { existsSync, readFileSync } from 'node:fs';

/**
 * Link of the sign-up confirmation e-mail: read from Mailpit (local environment) or from a file
 * of raw messages (MAIL_FILE, e.g. a Laravel log with MAIL_MAILER=log or a local SMTP sink).
 */
export async function verificationLink(request: APIRequestContext, email: string): Promise<string> {
    const mailpit = process.env.MAILPIT_URL ?? 'http://localhost:8025';
    const deadline = Date.now() + 90_000;
    while (Date.now() < deadline) {
        const link = process.env.MAIL_FILE ? fromFile(process.env.MAIL_FILE, email) : await fromMailpit(request, mailpit, email);
        if (link) {
            return link;
        }
        await new Promise((resolve) => setTimeout(resolve, 1500));
    }
    throw new Error(`No confirmation e-mail arrived for ${email}`);
}

async function fromMailpit(request: APIRequestContext, mailpit: string, email: string): Promise<string | null> {
    const search = await request.get(`${mailpit}/api/v1/search?query=${encodeURIComponent(`to:"${email}"`)}`);
    if (!search.ok()) {
        return null;
    }
    const { messages = [] } = (await search.json()) as { messages?: Array<{ ID: string }> };
    for (const message of messages) {
        const response = await request.get(`${mailpit}/api/v1/message/${message.ID}`);
        if (!response.ok()) {
            continue;
        }
        const body = (await response.json()) as { HTML?: string; Text?: string };
        const link = findLink(`${body.HTML ?? ''}\n${body.Text ?? ''}`);
        if (link) {
            return link;
        }
    }
    return null;
}

function fromFile(file: string, email: string): string | null {
    if (!existsSync(file)) {
        return null;
    }
    // Raw messages: quoted-printable bodies.
    const raw = readFileSync(file, 'utf8').replace(/=\r?\n/g, '').replace(/=3D/g, '=');
    const at = raw.lastIndexOf(`To: ${email}`);
    return at < 0 ? null : findLink(raw.slice(at));
}

function findLink(text: string): string | null {
    const match = text.replace(/&amp;/g, '&').match(/https?:\/\/[^\s"'<>]+\/email\/verify\/[^\s"'<>]+/);
    return match ? match[0] : null;
}

/**
 * A page of the dashboard opened without errors and without the Coolify name.
 */
export async function expectBranded(page: Page): Promise<void> {
    await expect(page.locator('body')).not.toContainText('Coolify', { useInnerText: true });
    expect(await page.title()).not.toContain('Coolify');
}

/**
 * Full-page screenshot attached to the report.
 */
export async function screenshot(page: Page, name: string): Promise<void> {
    const path = test.info().outputPath(`${name}.png`);
    await page.screenshot({ path, fullPage: true });
    await test.info().attach(name, { path, contentType: 'image/png' });
}
