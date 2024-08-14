<?php
return [
    'BE' => [
        'debug' => false,
        'explicitADmode' => 'explicitAllow',
        'installToolPassword' => '$argon2i$v=19$m=65536,t=16,p=1$T3hMTnBwNTYxc1dnc3pqaw$GWgKTpVT7pp1m5BvDB/2ueStB9lPZ9VDo7u8AfHiTBg',
        'passwordHashing' => [
            'className' => 'TYPO3\\CMS\\Core\\Crypto\\PasswordHashing\\Argon2iPasswordHash',
            'options' => [],
        ],
    ],
    'DB' => [
        'Connections' => [
            'Default' => [
                'charset' => 'utf8',
                'driver' => 'mysqli',
            ],
        ],
    ],
    'EXTENSIONS' => [
        'backend' => [
            'backendFavicon' => '',
            'backendLogo' => '',
            'loginBackgroundImage' => '',
            'loginFootnote' => '',
            'loginHighlightColor' => '',
            'loginLogo' => '',
            'loginLogoAlt' => '',
        ],
        'extensionmanager' => [
            'automaticInstallation' => '1',
            'offlineMode' => '0',
        ],
        'oauth2_client' => [
            'providers' => [
                // http://xima-oauth2-extended.ddev.site:4011/.well-known/openid-configuration
                'authentik' => [
                    'description' => 'Login with Soluto/oidc-server-mock',
                    'iconIdentifier' => '',
                    'implementationClassName' => 'Xima\\XimaOauth2Extended\\ResourceProvider\\AuthentikResourceProvider',
                    'label' => 'oidc-server-mock',
                    'options' => [
                        'clientId' => 'authentik-mock-client',
                        'clientSecret' => 'authentik-mock-client-secret',
                        'scopeSeparator' => ' ',
                        'scopes' => [
                            'openid',
                            'profile',
                            'email',
                            'avatar',
                        ],
                        'urlAccessToken' => 'http://xima-oauth2-extended.ddev.site:4011/connect/token',
                        'urlAuthorize' => 'http://xima-oauth2-extended.ddev.site:4011/connect/authorize',
                        'urlResourceOwnerDetails' => 'http://xima-oauth2-extended.ddev.site:4011/connect/userinfo',
                    ],
                    'scopes' => [
                        'backend',
                    ],
                ],
            ],
        ],
        'xima_oauth2_extended' => [
            'oauth2_client_providers' => [
                'authentik' => [
                    'createBackendUser' => true,
                    'defaultBackendAdminGroups' => 'all',
                    'defaultBackendLanguage' => 'de',
                    'imageStorageBackendIdentifier' => '1:/user_upload/oauth',
                    'resolverClassName' => 'Xima\\XimaOauth2Extended\\ResourceResolver\\AuthentikResourceResolver',
                ],
            ],
        ],
    ],
    'FE' => [
        'cacheHash' => [
            'enforceValidation' => true,
        ],
        'debug' => false,
        'disableNoCacheParameter' => true,
        'passwordHashing' => [
            'className' => 'TYPO3\\CMS\\Core\\Crypto\\PasswordHashing\\Argon2iPasswordHash',
            'options' => [],
        ],
    ],
    'GFX' => [
        'processor' => 'GraphicsMagick',
        'processor_allowTemporaryMasksAsPng' => false,
        'processor_colorspace' => 'RGB',
        'processor_effects' => false,
        'processor_enabled' => true,
        'processor_path' => '/usr/bin/',
        'processor_path_lzw' => '/usr/bin/',
    ],
    'LOG' => [
        'TYPO3' => [
            'CMS' => [
                'deprecations' => [
                    'writerConfiguration' => [
                        'notice' => [
                            'TYPO3\CMS\Core\Log\Writer\FileWriter' => [
                                'disabled' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'MAIL' => [
        'transport' => 'sendmail',
        'transport_sendmail_command' => '/usr/local/bin/mailpit sendmail -t --smtp-addr 127.0.0.1:1025',
        'transport_smtp_encrypt' => '',
        'transport_smtp_password' => '',
        'transport_smtp_server' => '',
        'transport_smtp_username' => '',
    ],
    'SYS' => [
        'caching' => [
            'cacheConfigurations' => [
                'hash' => [
                    'backend' => 'TYPO3\\CMS\\Core\\Cache\\Backend\\Typo3DatabaseBackend',
                ],
                'imagesizes' => [
                    'backend' => 'TYPO3\\CMS\\Core\\Cache\\Backend\\Typo3DatabaseBackend',
                    'options' => [
                        'compression' => true,
                    ],
                ],
                'pages' => [
                    'backend' => 'TYPO3\\CMS\\Core\\Cache\\Backend\\Typo3DatabaseBackend',
                    'options' => [
                        'compression' => true,
                    ],
                ],
                'pagesection' => [
                    'backend' => 'TYPO3\\CMS\\Core\\Cache\\Backend\\Typo3DatabaseBackend',
                    'options' => [
                        'compression' => true,
                    ],
                ],
                'rootline' => [
                    'backend' => 'TYPO3\\CMS\\Core\\Cache\\Backend\\Typo3DatabaseBackend',
                    'options' => [
                        'compression' => true,
                    ],
                ],
            ],
        ],
        'devIPmask' => '',
        'displayErrors' => 0,
        'encryptionKey' => 'e4c055214372ed39d01b5b8770ebf99c6aed18064ba1eb2838456e75ad842db40ee6da6ad1a3e145fdc366f80bca3a47',
        'exceptionalErrors' => 4096,
        'features' => [
            'yamlImportsFollowDeclarationOrder' => true,
        ],
        'sitename' => 'New TYPO3 Console site',
        'systemMaintainers' => [
            1,
        ],
    ],
];
