# XIMA OAuth2 Extended

TYPO3 extension that extends the functionality
of [waldhacker/ext-oauth2-client](https://packagist.org/packages/co-stack/typo3-oauth2-client) for on-the-fly user creation.

## New resource provider

* `MicrosoftResourceProvider`
* `AuthentikResourceProvider`

## TYPO3 user creation

To create frontend or backend users from OAuth2 authentication, you can create
your own ResourceResolver by implementing the `ResourceResolverInterface` and
register it in the extension configuration:

```php

'EXTENSIONS' => [
    // your existing configuration of waldhacker/ext-oauth2-client
    'oauth2_client' => [
        'providers' => [
            'yourProviderId' => [
                'description' => 'Your provider',
                'implementationClassName' => 'Xima\XimaOauth2Extended\ResourceProvider\MicrosoftResourceProvider',
                ...
            ],
            'secondProviderId' => [
                'description' => 'Another provider'
                ...
            ]
        ]
    ],

    'xima_oauth2_extended' => [
        'oauth2_client_providers' => [
            // provider of waldhacker/ext-oauth2-client you want to extend
            'yourProviderId' => [
                'resolverClassName' => \Xima\XimaOauth2Extended\ResourceResolver\MicrosoftResourceResolver::class,
                'createBackendUser' => true,
                'createFrontendUser' => false,
                'defaultBackendUsergroup' => '1,3',
                'defaultFrontendUsergroup' => '',
                'imageStorageBackendIdentifier' => '1:/user_upload/oauth',
            ],
            'secondProviderId' => [
                'resolverClassName' => \Xima\XimaOauth2Extended\ResourceResolver\GenericResourceResolver::class,
                'createBackendUser' => true,
                'createFrontendUser' => true,
                'defaultBackendUsergroup' => '',
                'defaultFrontendUsergroup' => '',
            ],
        ],
    ],
]
```

## Available resource resolver

This TYPO3 extension provides a resource resolver to facilitate the creation and
updating of TYPO3 users through OAuth2 login. The resource resolver serves as a
mapping tool for data retrieval from various OAuth resources. While the default
resolver, GenericResolver, covers most OAuth endpoints, each endpoint's unique
API for extended user information might require specific handling, leading to
variations in features.

| Resolver                  | User Creation | Profile picture | Group Creation |
|---------------------------|:-------------:|:---------------:|:--------------:|
| GenericResourceResolver   |       ✅       |       🚫        |       🚫       |
| MicrosoftResourceResolver |       ✅       |   ✅ (BE only)   |  ✅ (BE only)   |
| AuthentikResourceResolver |       ✅       |   ✅ (BE only)   |       🚫       |
| GitlabResourceResolver    |       ✅       |       🚫        |       🚫       |

## Extended resource resolver options

The extension provides customizable options to tailor the resolver's behavior:

| Option                           | Description                                                                                           | Default                          |
|----------------------------------|-------------------------------------------------------------------------------------------------------|----------------------------------|
| `resolverClassName`              | Class name of the resource resolver. See above for list of available resolver                         | `GenericResourceResolver::class` |
| `createBackendUser`              | If set, create a new TYPO3 backend user if no existing user is authenticated                          | `false`                          |
| `createFrontendUser`             | If set, create a new TYPO3 frontend user if no existing user is authenticated                         | `false`                          |
| `defaultBackendUsergroup`        | List of be_group UIDs the created user will be assigned to                                            | ` `                              |
| `defaultFrontendUsergroup`       | List of fe_group UIDs the created user will be assigned to                                            | ` `                              |
| `createBackendUsergroups`        | If set, create backend user groups based on those of the remote user                                  | `false`                          |
| `createFrontendUsergroups`       | If set, create frontend user groups based on those of the remote user                                 | `false`                          |
| `requireBackendUsergroup`        | If set, require the remote user to be in at least one user group with matching `oauth2_id`            | `false`                          |
| `requireFrontendUsergroup`       | If set, require the remote user to be in at least one user group with matching `oauth2_id`            | `false`                          |
| `imageStorageBackendIdentifier`  | Storage identifier for downloaded backend user profile images                                         | `1:/user_upload/oauth`           |
| `imageStorageFrontendIdentifier` | Storage identifier for downloaded frontend user profile images                                        | `1:/user_upload/oauth`           |
| `defaultBackendLanguage`         | Language identifier for created backend users                                                         | `default`                        |
| `defaultBackendAdminGroups`      | Comma separated list of remote `oauth2_id`s that will become Admin during login. Special value `all`. | ` `                              |

## User sync via Microsoft Graph (app-only)

While the OAuth2 login provisions a TYPO3 user *reactively* when that user logs
in, the Graph sync provisions users *proactively* — it pulls every user the
registered Azure application can see and creates/updates the matching TYPO3
users, reusing the same factory pipeline (username/email mapping, group mapping
via `oauth2_id`, optional profile image, identity link).

Both backend and frontend users can be synced:

* `xima:oauth2:sync-backend-users` → `be_users` (+ `be_groups`)
* `xima:oauth2:sync-frontend-users` → `fe_users` (+ `fe_groups`)

The sync supports **multiple clients/tenants**: `graphSync` is a map of
independent client configurations, each keyed by an arbitrary client id. Every
client is **fully self-contained** — credentials, identity-link key and sync
options all live in the client entry and are **not** derived from
`oauth2_client_providers`.

### Azure app registration

Register an application in **each** Microsoft tenant you want to sync and grant
it the following **Application** permissions (admin consent required):

* `User.Read.All` — read all users
* `GroupMember.Read.All` — read group membership (only needed for group sync)

Create a client secret for each application.

### Extension configuration

Configure the clients under *Settings → Extension Configuration →
xima_oauth2_extended* or in `settings.php`. `graphSync` is a map keyed by client
id (`customerA`, `customerB`, … — pick any stable key):

```php
'EXTENSIONS' => [
    'xima_oauth2_extended' => [
        'graphSync' => [
            'customerA' => [
                // --- Azure app credentials ---
                'tenantId' => '<directory (tenant) id>',
                'clientId' => '<application (client) id>',
                'clientSecret' => '<client secret>',
                // Identity-link key written to the provider column of
                // tx_oauth2_beuser/feuser_provider_configuration.
                // Optional; defaults to the client key ('customerA').
                'provider' => 'customerA',
                // Storage page for created fe_users (and, on the frontend sync,
                // for auto-created fe_groups).
                'frontendUserPid' => 0,
                // --- Sync options (self-contained, per client) ---
                'createBackendUser' => true,
                'createBackendUsergroups' => true,
                'defaultBackendUsergroup' => '1,3',
                'defaultBackendAdminGroups' => '',
                'defaultBackendLanguage' => 'default',
                'imageStorageBackendIdentifier' => '1:/user_upload/oauth',
                'createFrontendUser' => true,
                'createFrontendUsergroups' => true,
                'defaultFrontendUsergroup' => '',
            ],
            'customerB' => [
                'tenantId' => '...',
                'clientId' => '...',
                'clientSecret' => '...',
                'frontendUserPid' => 42,
                'createFrontendUser' => true,
            ],
        ],
    ],
],
```

Whether users are actually created and which groups they receive is governed by
each client's own options (same keys as the
[resolver options](#extended-resource-resolver-options)):

* Backend: `createBackendUser`, `defaultBackendUsergroup`,
`createBackendUsergroups`, `defaultBackendAdminGroups`
* Frontend: `createFrontendUser`, `defaultFrontendUsergroup`,
`createFrontendUsergroups`

Frontend users **and** auto-created frontend groups are stored on the page
configured via the client's `frontendUserPid`.

### Running the sync

```bash
# all configured clients
vendor/bin/typo3 xima:oauth2:sync-backend-users
vendor/bin/typo3 xima:oauth2:sync-frontend-users

# a single client (by its graphSync key)
vendor/bin/typo3 xima:oauth2:sync-backend-users customerA
vendor/bin/typo3 xima:oauth2:sync-frontend-users customerA
```

Without an argument every configured client is synced in turn, with a separate
result line per client; one client's failure does not abort the others. Both
commands are also schedulable: in the *Scheduler* backend module add an *Execute
console commands* task for the respective command (optionally with a client key
as argument).

The application access token is acquired via the client-credentials grant and
cached per client in `sys_registry` (`xima_oauth2_extended` /
`graphAppToken_<clientId>_<tenantId>`). The grant issues no refresh token, so the
token is simply re-acquired once it expires — there is no separate token-refresh
task.

> **Note on identity matching:** the login flow links identities using the
> id_token `sub` claim, while the app-only `/users` endpoint only exposes the
> directory object id. Users matched purely by sync therefore use the object id
> as identifier. The underlying `be_users` record is still matched by
> username/email, so a synced user that later logs in resolves to the same user.

### Backend module (debugging)

An admin-only backend module **Admin Tools → Entra / Graph Debug** helps explore
and debug the configured Graph endpoints. It is read-only (it never creates or
changes users) and provides, per `graphSync` client:

* **Configuration overview** — credentials (secret masked), identity provider,
frontend PID, all sync options and the concrete Graph endpoints used.
* **Test connection** — acquires an app-only token and reads a few sample users
to confirm the credentials and permissions work.
* **User search** — search the tenant by name / UPN / mail.
* **User detail & mapping** — the raw Graph user next to the resolved TYPO3
mapping (intended username/email, `be_users`/`fe_users` fields, and each
group membership matched against the `oauth2_id` column).

## FAQ

<details>
<summary>
Register Return-URLs
</summary>

For the backend login the return url looks like this:

```
https://domain.de/typo3/login?loginProvider=1616569531&oauth2-provider=yourProviderId&login_status=login&commandLI=attempt
```

Replace `domain.de` and `yourProviderId` with your data!
</details>

<details>
<summary>
Login not working
</summary>

Make sure `cookieSameSite` is set to `lax`.

```php
$GLOBALS['TYPO3_CONF_VARS']['BE']['cookieSameSite'] = 'lax';
$GLOBALS['TYPO3_CONF_VARS']['FE']['cookieSameSite'] = 'lax';
```

</details>

<details>
<summary>
Order of login provider
</summary>

To change the order of provider displayed at the `/typo3` login page (OAuth
login over classic username/password), use the following snippet:

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['backend']['loginProviders']['1616569531']['sorting'] = 75;
```

</details>
