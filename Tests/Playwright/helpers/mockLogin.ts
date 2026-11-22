import { Page, expect } from '@playwright/test';

// Sign in on the login page of navikt/mock-oauth2-server.
// The username selects the persona (claims) from Tests/Fixtures/MockOAuth2/config.json.
export async function mockLogin(page: Page, username: string): Promise<void> {
    await expect(page).toHaveURL(/\/\/oidc\.[^/]+\/default\/authorize/);
    await page.fill('input[name="username"]', username);
    await page.click('input[type="submit"]');
}
