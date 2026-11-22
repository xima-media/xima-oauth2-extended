<?php

declare(strict_types=1);

use Waldhacker\Oauth2Client\Service\Oauth2ProviderManager;
use Xima\XimaOauth2Extended\ResourceProvider\AuthentikResourceProvider;
use Xima\XimaOauth2Extended\ResourceResolver\GenericResourceResolver;
use Xima\XimaOauth2Extended\Tests\Acceptance\Fixtures\Classes\GroupsClaimResourceResolver;

defined('TYPO3') or die();

$GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = '.*';
$GLOBALS['TYPO3_CONF_VARS']['BE']['loginRateLimit'] = 0;
$GLOBALS['TYPO3_CONF_VARS']['FE']['loginRateLimit'] = 0;

// Plain HTTP through the DDEV router: the mock derives `iss` from the request, so browser and web container see the same issuer
$mockIssuer = sprintf('http://oidc.%s.%s/default', getenv('DDEV_SITENAME'), getenv('DDEV_TLD') ?: 'ddev.site');
$mockProvider = static fn (string $label, string $scope): array => [
    'label' => $label,
    'iconIdentifier' => 'actions-key',
    'description' => 'Login with ' . $label,
    'implementationClassName' => AuthentikResourceProvider::class,
    'scopes' => [$scope],
    'options' => [
        'clientId' => 'typo3',
        'clientSecret' => 'secret',
        'urlAuthorize' => $mockIssuer . '/authorize',
        'urlAccessToken' => $mockIssuer . '/token',
        'scopes' => ['openid', 'email', 'profile'],
        'scopeSeparator' => ' ',
    ],
];

$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['oauth2_client']['providers'] = [
    'mock-create' => $mockProvider('Mock create', Oauth2ProviderManager::SCOPE_BACKEND),
    'mock-groups' => $mockProvider('Mock groups', Oauth2ProviderManager::SCOPE_BACKEND),
    'mock-admin-all' => $mockProvider('Mock admin all', Oauth2ProviderManager::SCOPE_BACKEND),
    'mock-admin-group' => $mockProvider('Mock admin group', Oauth2ProviderManager::SCOPE_BACKEND),
];

$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['xima_oauth2_extended']['oauth2_client_providers'] = [
    'mock-create' => [
        'resolverClassName' => GenericResourceResolver::class,
        'createBackendUser' => true,
        'defaultBackendUsergroup' => '1',
    ],
    'mock-groups' => [
        'resolverClassName' => GroupsClaimResourceResolver::class,
        'createBackendUser' => true,
        'requireBackendUsergroup' => true,
    ],
    'mock-admin-all' => [
        'resolverClassName' => GenericResourceResolver::class,
        'createBackendUser' => true,
        'defaultBackendUsergroup' => '1',
        'defaultBackendAdminGroups' => 'all',
    ],
    'mock-admin-group' => [
        'resolverClassName' => GroupsClaimResourceResolver::class,
        'createBackendUser' => true,
        'defaultBackendAdminGroups' => 'typo3-admins',
    ],
];
