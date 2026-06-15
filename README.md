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
in, the Graph sync provisions backend users *proactively* — it pulls every user
the registered Azure application can see and creates/updates the matching
`be_users`, reusing the same factory pipeline (username/email mapping, group
mapping via `oauth2_id`, optional profile image, identity link).

### Azure app registration

Register an application in your Microsoft tenant and grant it the following
**Application** permissions (admin consent required):

* `User.Read.All` — read all users
* `GroupMember.Read.All` — read group membership (only needed for group sync)

Create a client secret for the application.

### Extension configuration

Configure the credentials under *Settings → Extension Configuration →
xima_oauth2_extended* (category *graphSync*) or in `settings.php`:

```php
'EXTENSIONS' => [
    'xima_oauth2_extended' => [
        'graphSync' => [
            'tenantId' => '<directory (tenant) id>',
            'clientId' => '<application (client) id>',
            'clientSecret' => '<client secret>',
            // ID of an existing oauth2_client_providers entry. Its ResolverOptions
            // (createBackendUser, default groups, admin groups, image storage, ...)
            // and identity link are reused for the synced users.
            'providerId' => 'yourProviderId',
            // Frontend sync only: storage page for created fe_users.
            'frontendUserPid' => 0,
        ],
    ],
],
```

Whether users are actually created and which groups they receive is governed by
the [resolver options](#extended-resource-resolver-options) of the referenced
`providerId` (e.g. `createBackendUser`, `defaultBackendUsergroup`,
`createBackendUsergroups`, `defaultBackendAdminGroups`).

### Running the sync

```bash
vendor/bin/typo3 xima:oauth2:sync-backend-users
```

The command is also schedulable: in the *Scheduler* backend module add an
*Execute console commands* task for `xima:oauth2:sync-backend-users`.

The application access token is acquired via the client-credentials grant and
cached in `sys_registry` (`xima_oauth2_extended` / `graphAppToken`). The grant
issues no refresh token, so the token is simply re-acquired once it expires —
there is no separate token-refresh task.

> **Note on identity matching:** the login flow links identities using the
> id_token `sub` claim, while the app-only `/users` endpoint only exposes the
> directory object id. Users matched purely by sync therefore use the object id
> as identifier. The underlying `be_users` record is still matched by
> username/email, so a synced user that later logs in resolves to the same user.

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
