import { Page } from '@playwright/test';

export const OAUTH2_LOGIN_PROVIDER = '1616569531';

export async function startBackendOAuth2Login(page: Page, providerId: string): Promise<void> {
    await page.goto(`/typo3/login?loginProvider=${OAUTH2_LOGIN_PROVIDER}`);
    await page.click(`button[name="oauth2-provider"][value="${providerId}"]`);
}
