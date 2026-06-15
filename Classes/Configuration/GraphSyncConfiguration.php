<?php

namespace Xima\XimaOauth2Extended\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Typed accessor for the `graphSync` extension configuration used by the
 * app-only Microsoft Graph user sync. Mirrors the pattern of ResolverOptions.
 */
final class GraphSyncConfiguration
{
    public string $tenantId = '';

    public string $clientId = '';

    public string $clientSecret = '';

    public string $providerId = '';

    public int $frontendUserPid = 0;

    public static function create(): self
    {
        $extConf = GeneralUtility::makeInstance(ExtensionConfiguration::class)
            ->get('xima_oauth2_extended', 'graphSync') ?? [];

        $config = new self();
        $config->tenantId = trim((string)($extConf['tenantId'] ?? ''));
        $config->clientId = trim((string)($extConf['clientId'] ?? ''));
        $config->clientSecret = (string)($extConf['clientSecret'] ?? '');
        $config->providerId = trim((string)($extConf['providerId'] ?? ''));
        $config->frontendUserPid = (int)($extConf['frontendUserPid'] ?? 0);

        return $config;
    }

    public function isComplete(): bool
    {
        return $this->tenantId !== ''
            && $this->clientId !== ''
            && $this->clientSecret !== ''
            && $this->providerId !== '';
    }
}
