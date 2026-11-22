import { test, expect } from '@playwright/test';
import { startBackendOAuth2Login, expectBackendLoggedIn, expectBackendLoginFailed } from './helpers/backend';
import { mockLogin } from './helpers/mockLogin';
import { query, queryOne, countBackendIdentities } from './helpers/database';

test.describe('Backend linking of existing accounts', () => {
    test.describe.configure({ mode: 'serial' });

    test.beforeEach(() => {
        query('DELETE FROM be_sessions');
    });

    function loggedInBackendUserIds(): number[] {
        return query('SELECT DISTINCT ses_userid FROM be_sessions WHERE ses_userid > 0').map(Number);
    }

    test('A2: an unverified email does not link the identity to the existing editor', async ({ page }) => {
        query("DELETE FROM tx_oauth2_beuser_provider_configuration WHERE identifier = 'be-unlinked'");

        await startBackendOAuth2Login(page, 'mock-link');
        await mockLogin(page, 'be-unlinked');

        await expectBackendLoginFailed(page);
        expect(loggedInBackendUserIds()).toEqual([]);
        expect(countBackendIdentities('mock-link', 'be-unlinked')).toBe(0);
    });

    test('A2: a verified email links the identity to the existing editor once', async ({ page }) => {
        query("DELETE FROM tx_oauth2_beuser_provider_configuration WHERE identifier = 'be-verified'");

        for (let login = 0; login < 2; login++) {
            await page.context().clearCookies();
            await startBackendOAuth2Login(page, 'mock-link');
            await mockLogin(page, 'be-verified');
            await expectBackendLoggedIn(page, 'Editor verified');
        }

        expect(loggedInBackendUserIds()).toEqual([12]);
        expect(countBackendIdentities('mock-link', 'be-verified')).toBe(1);
    });

    test('A2b: the username and email of an admin do not log the identity in as admin', async ({ page }) => {
        query("DELETE FROM tx_oauth2_beuser_provider_configuration WHERE identifier = 'be-admin-email'");

        await startBackendOAuth2Login(page, 'mock-link');
        await mockLogin(page, 'be-admin-email');

        await expectBackendLoginFailed(page);
        expect(loggedInBackendUserIds()).toEqual([]);
        expect(queryOne("SELECT realName FROM be_users WHERE username = 'admin'")).not.toBe('Mallory');
        expect(countBackendIdentities('mock-link', 'be-admin-email')).toBe(0);
    });

    test('A3: a linked user disabled in TYPO3 stays disabled and is not logged in', async ({ page }) => {
        query('UPDATE be_users SET disable = 1 WHERE uid = 14');

        await startBackendOAuth2Login(page, 'mock-link');
        await mockLogin(page, 'be-disabled');

        await expectBackendLoginFailed(page);
        expect(loggedInBackendUserIds()).toEqual([]);
        expect(queryOne('SELECT disable FROM be_users WHERE uid = 14')).toBe('1');
        expect(countBackendIdentities('mock-link', 'be-disabled')).toBe(1);
    });

    test('A3: duplicate identity rows of one user are merged at login', async ({ page }) => {
        await startBackendOAuth2Login(page, 'mock-link');
        await mockLogin(page, 'be-duplicate');

        await expectBackendLoggedIn(page, 'BE duplicate');
        expect(loggedInBackendUserIds()).toEqual([15]);
        expect(countBackendIdentities('mock-link', 'be-duplicate')).toBe(1);
    });
});
