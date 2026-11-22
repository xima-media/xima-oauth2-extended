import { test, expect, Page } from '@playwright/test';
import { startBackendOAuth2Login } from './helpers/backend';
import { mockLogin } from './helpers/mockLogin';
import { deleteBackendUserByEmail, findBackendUserByEmail, countBackendIdentities } from './helpers/database';

async function expectBackendLoggedIn(page: Page, realName: string): Promise<void> {
    await expect(page.locator('.scaffold-header')).toBeVisible();
    await expect(page.locator('.scaffold-toolbar')).toContainText(realName);
}

async function expectBackendLoginFailed(page: Page): Promise<void> {
    await expect(page).toHaveURL(/\/typo3\/login/);
    await expect(page.locator('.scaffold-header')).toHaveCount(0);
}

test.describe('Backend user provisioning', () => {
    test('A1: an unknown identity creates a backend user with the default groups', async ({ page }) => {
        deleteBackendUserByEmail('new-user@example.org');

        await startBackendOAuth2Login(page, 'mock-create');
        await mockLogin(page, 'new-user');

        await expectBackendLoggedIn(page, 'New user');
        const user = findBackendUserByEmail('new-user@example.org');
        expect(user).toMatchObject({ username: 'new-user@example.org', admin: 0, disable: 0, usergroup: '1' });
        expect(countBackendIdentities('mock-create', 'new-user')).toBe(1);
    });

    test('A5: a remote group with a matching oauth2_id is assigned to the new user', async ({ page }) => {
        deleteBackendUserByEmail('new-grouped@example.org');

        await startBackendOAuth2Login(page, 'mock-groups');
        await mockLogin(page, 'new-grouped');

        await expectBackendLoggedIn(page, 'New grouped');
        expect(findBackendUserByEmail('new-grouped@example.org')).toMatchObject({ admin: 0, usergroup: '2' });
    });

    test('A5: requireBackendUsergroup creates no user without a mapped remote group', async ({ page }) => {
        deleteBackendUserByEmail('new-ungrouped@example.org');

        await startBackendOAuth2Login(page, 'mock-groups');
        await mockLogin(page, 'new-ungrouped');

        await expectBackendLoginFailed(page);
        expect(findBackendUserByEmail('new-ungrouped@example.org')).toBeUndefined();
        expect(countBackendIdentities('mock-groups', 'new-ungrouped')).toBe(0);
    });

    test('A6: defaultBackendAdminGroups "all" makes every new user an admin', async ({ page }) => {
        deleteBackendUserByEmail('new-admin-all@example.org');

        await startBackendOAuth2Login(page, 'mock-admin-all');
        await mockLogin(page, 'new-admin-all');

        await expectBackendLoggedIn(page, 'New admin all');
        expect(findBackendUserByEmail('new-admin-all@example.org')).toMatchObject({ admin: 1, usergroup: '1' });
    });

    test('A6: defaultBackendAdminGroups makes members of a listed remote group admins', async ({ page }) => {
        deleteBackendUserByEmail('new-admin-group@example.org');

        await startBackendOAuth2Login(page, 'mock-admin-group');
        await mockLogin(page, 'new-admin-group');

        await expectBackendLoggedIn(page, 'New admin group');
        expect(findBackendUserByEmail('new-admin-group@example.org')).toMatchObject({ admin: 1, usergroup: '3' });
    });

    test('A6: members of other remote groups do not become admins', async ({ page }) => {
        deleteBackendUserByEmail('new-nonadmin-group@example.org');

        await startBackendOAuth2Login(page, 'mock-admin-group');
        await mockLogin(page, 'new-nonadmin-group');

        await expectBackendLoggedIn(page, 'New non-admin group');
        expect(findBackendUserByEmail('new-nonadmin-group@example.org')).toMatchObject({ admin: 0, usergroup: '2' });
    });
});
