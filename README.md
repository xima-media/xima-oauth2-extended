# XIMA OAuth2 Extended

This repository contains additional provider
for [league/oauth2-client](https://github.com/thephpleague/oauth2-client). When
installed as TYPO3 extension, it is possible to extend
the [waldhacker/ext-oauth2-client](https://github.com/waldhacker/ext-oauth2-client)
for on-the-fly user creation.

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

<details>
<summary>
Usage in TYPO3v12
</summary>

The TYPO3
extension [waldhacker/ext-oauth2-client](https://github.com/waldhacker/ext-oauth2-client)
is not yet ready for v12. However, there is a feature branch that is almost
working - [this fork](https://github.com/maikschneider/ext-oauth2-client/tree/feature/v12-compatibility-1)
makes the trick. To use it, adjust your `composer.json`:

```json
{
  "repositories": [
    {
      "url": "https://github.com/maikschneider/ext-oauth2-client.git",
      "type": "git"
    }
  ],
  "require": {
    "waldhacker/typo3-oauth2-client": "dev-feature/v12-compatibility-1"
  }
}
```

</details>
