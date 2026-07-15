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

#### Group names & hierarchy

Synced groups are created with their **Entra display name** as the TYPO3 group
title (the object id is kept in `oauth2_id` for matching, and titles are
refreshed when they change in Entra). Group membership is resolved via
`transitiveMemberOf`, so users are also assigned to the **nested parent groups**
they inherit through Entra group nesting — not just their direct groups. That
nesting is reconstructed into the TYPO3 `subgroup` field (a child group lists its
parents as subgroups). Reading nested membership uses the existing
`GroupMember.Read.All` permission.

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

### Backend module

An admin-only backend module **Admin Tools → Microsoft Entra** helps browse,
inspect and manually import the configured tenants' users. It is only registered
when at least one `graphSync` client is configured, and a doc-header dropdown
switches between the configured clients (shown when more than one exists). Per
client it provides:

* **Users** — a searchable list of remote users showing, for each, whether it is
  already imported as a `be_user` / `fe_user` (identity link) or merely exists
  (matched by username/email), with **Create BE user** / **Create FE user**
  buttons. Import is idempotent — an existing user is linked/updated, never
  duplicated — and the buttons force creation regardless of the client's
  `createBackendUser` / `createFrontendUser` option (explicit manual action).
* **User detail & mapping** — the raw Graph user next to the resolved TYPO3
  mapping (intended username/email, `be_users`/`fe_users` fields, and each group
  membership — with names and Entra hierarchy — matched against `oauth2_id`).
* **Configuration** — credentials (secret masked), identity provider, frontend
  PID, all sync options and the concrete Graph endpoints used.
* **Test connection** — acquires an app-only token and reads a few sample users
  to confirm the credentials and permissions work.

## Reacting to sync (PSR-14 events)

Third-party extensions often need to run their own logic when a user or group is
synced — create a profile record on user creation, reconcile memberships when a
group's hierarchy changes, notify another system, and so on. The extension
dispatches PSR-14 events for these moments. Because they fire from the shared
`UserFactory` / `RemoteGroupWriter` code paths, **user events fire for both the
bulk Graph sync and interactive OAuth2 login**; group events fire from the Graph
sync (rich group path).

| Event | Fired when |
|-------|------------|
| `Event\BackendUserCreatedEvent` | a new backend user was created and linked |
| `Event\BackendUserUpdatedEvent` | an existing linked backend user was updated |
| `Event\FrontendUserCreatedEvent` | a new frontend user was created and linked |
| `Event\FrontendUserUpdatedEvent` | an existing linked frontend user was updated |
| `Event\UserGroupCreatedEvent` | a new TYPO3 group was created from a remote group |
| `Event\UserGroupUpdatedEvent` | a synced group's title and/or hierarchy changed |

All events are dispatched **after** the record has been persisted, so the payload
always carries the final `uid`. The four user events implement
`Event\UserSyncEventInterface` (`getProviderId()`, `getTypo3User()`,
`getUserId()`, `getResolver()`, `getRemoteUser()`); the two group events
implement `Event\GroupSyncEventInterface` (`getTable()`, `getGroupUid()`,
`getRemoteGroup()`, `getOauth2Id()`). TYPO3's listener provider matches parent
types, so you can bind a listener to a concrete event class, to the shared
interface (react to *any* user or group change), or to the abstract base class.

```php
// EventListener/CreateUserProfile.php
namespace Vendor\MyExt\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use Xima\XimaOauth2Extended\Event\FrontendUserCreatedEvent;

final class CreateUserProfile
{
    #[AsEventListener(identifier: 'my-ext/create-profile')]
    public function __invoke(FrontendUserCreatedEvent $event): void
    {
        $userId = $event->getUserId();
        $email = $event->getResolver()->getIntendedEmail();
        // create your profile record for $userId ...
    }
}
```

> The sync never *deletes* TYPO3 users or groups, so there is no deletion event.
> To reconcile after removals, compare the synced set (surfaced via the group
> events) against your own records.

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
