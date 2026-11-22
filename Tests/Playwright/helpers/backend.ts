import { Page, expect } from '@playwright/test';

export const OAUTH2_LOGIN_PROVIDER = '1616569531';

export async function startBackendOAuth2Login(page: Page, providerId: string): Promise<void> {
    await page.goto(`/typo3/login?loginProvider=${OAUTH2_LOGIN_PROVIDER}`);
    await page.click(`button[name="oauth2-provider"][value="${providerId}"]`);
}

export async function expectBackendLoggedIn(page: Page, realName: string): Promise<void> {
    await expect(page.locator('.scaffold-header')).toBeVisible();
    await expect(page.locator('.scaffold-toolbar')).toContainText(realName);
}

export async function expectBackendLoginFailed(page: Page): Promise<void> {
    await expect(page).toHaveURL(/\/typo3\/login/);
    await expect(page.locator('.scaffold-header')).toHaveCount(0);
}
