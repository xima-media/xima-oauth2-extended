import { execFileSync } from 'child_process';

// Run SQL against the DDEV database from the Playwright container (it ships the mysql client).
// Returns the rows tab-separated, one string per row.
export function query(sql: string): string[] {
    const output = execFileSync('mysql', ['-hdb', '-udb', '-pdb', 'db', '-Nse', sql], { encoding: 'utf8' });
    return output.split('\n').filter((line) => line !== '');
}

export function queryOne(sql: string): string | undefined {
    return query(sql)[0];
}

export function quote(value: string): string {
    return `'${value.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
}

export function deleteBackendUserByEmail(email: string): void {
    query(`DELETE FROM tx_oauth2_beuser_provider_configuration WHERE parentid IN (SELECT uid FROM be_users WHERE email = ${quote(email)})`);
    query(`DELETE FROM be_users WHERE email = ${quote(email)}`);
}

export interface BackendUserRow {
    uid: number;
    username: string;
    admin: number;
    disable: number;
    usergroup: string;
}

export function findBackendUserByEmail(email: string): BackendUserRow | undefined {
    const row = queryOne(`SELECT uid, username, admin, disable, usergroup FROM be_users WHERE deleted = 0 AND email = ${quote(email)}`);
    if (row === undefined) {
        return undefined;
    }
    const [uid, username, admin, disable, usergroup] = row.split('\t');
    return { uid: Number(uid), username, admin: Number(admin), disable: Number(disable), usergroup };
}

export function countBackendIdentities(provider: string, identifier: string): number {
    return Number(queryOne(`SELECT COUNT(*) FROM tx_oauth2_beuser_provider_configuration WHERE provider = ${quote(provider)} AND identifier = ${quote(identifier)}`));
}
