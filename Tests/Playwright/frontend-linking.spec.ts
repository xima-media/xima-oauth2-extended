import { test, expect, Page } from '@playwright/test';
import { mockLogin } from './helpers/mockLogin';
import { query, queryOne, quote } from './helpers/database';

function countFrontendIdentities(identifier: string): number {
    return Number(queryOne(`SELECT COUNT(*) FROM tx_oauth2_feuser_provider_configuration WHERE provider = 'mock-fe' AND identifier = ${quote(identifier)}`));
}

async function loginAndReadUserId(page: Page, persona: string): Promise<string> {
    await page.context().clearCookies();
    await page.goto('/?logintype=login&oauth2-provider=mock-fe');
    await mockLogin(page, persona);
    await page.goto('/');
    return (await page.locator('#fe-user-id').textContent()) ?? '';
}

// The site's storage folder is pid 2; pid 3 belongs to another site.
test.describe('Frontend linking within the storage folder', () => {
    test('A4: a user of another storage folder is neither logged in nor linked', async ({ page }) => {
        query("DELETE FROM tx_oauth2_feuser_provider_configuration WHERE identifier = 'fe-otherpid'");

        expect(await loginAndReadUserId(page, 'fe-otherpid')).toMatch(/^0?$/);
        expect(countFrontendIdentities('fe-otherpid')).toBe(0);
    });

    test('A4: a user of the storage folder is linked once', async ({ page }) => {
        query("DELETE FROM tx_oauth2_feuser_provider_configuration WHERE identifier = 'fe-member'");

        expect(await loginAndReadUserId(page, 'fe-member')).toBe('22');
        expect(await loginAndReadUserId(page, 'fe-member')).toBe('22');
        expect(countFrontendIdentities('fe-member')).toBe(1);
    });
});
