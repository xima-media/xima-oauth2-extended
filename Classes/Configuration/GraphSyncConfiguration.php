<?php

namespace Xima\XimaOauth2Extended\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Xima\XimaOauth2Extended\ResourceResolver\ResolverOptions;

/**
 * Typed accessor for a single `graphSync` client used by the app-only Microsoft
 * Graph user sync.
 *
 * The `graphSync` extension configuration is a map of independent clients, each
 * keyed by an arbitrary client id:
 *
 *   graphSync[<clientKey>] = {
 *       tenantId, clientId, clientSecret,   // Azure app credentials
 *       provider,                           // identity-link key (defaults to <clientKey>)
 *       frontendUserPid,                    // storage page for synced fe_users
 *       ...ResolverOptions...               // createBackendUser, defaultBackendUsergroup, ...
 *   }
 *
 * Each client is fully self-contained: its sync behaviour (create flags, default
 * groups, admin groups, image storage, ...) is configured here and is *not*
 * derived from `oauth2_client_providers`.
 */
final class GraphSyncConfiguration
{
    public string $key = '';

    public string $tenantId = '';

    public string $clientId = '';

    public string $clientSecret = '';

    /**
     * Identifier stored in the `provider` column of the identity link tables.
     * Kept separate from `oauth2_client_providers`; defaults to the client key.
     */
    public string $provider = '';

    public int $frontendUserPid = 0;

    public ResolverOptions $options;

    /**
     * @param array<string, mixed> $conf
     */
    public static function create(string $key, array $conf): self
    {
        $config = new self();
        $config->key = $key;
        $config->tenantId = trim((string)($conf['tenantId'] ?? ''));
        $config->clientId = trim((string)($conf['clientId'] ?? ''));
        $config->clientSecret = (string)($conf['clientSecret'] ?? '');
        $config->provider = trim((string)($conf['provider'] ?? '')) ?: $key;
        $config->frontendUserPid = (int)($conf['frontendUserPid'] ?? 0);
        $config->options = ResolverOptions::createFromExtensionConfiguration($conf);

        return $config;
    }

    /**
     * Loads every configured graphSync client, keyed by its client key.
     *
     * @return array<string, self>
     */
    public static function loadAll(): array
    {
        $graphSync = GeneralUtility::makeInstance(ExtensionConfiguration::class)
            ->get('xima_oauth2_extended', 'graphSync') ?? [];

        if (!is_array($graphSync)) {
            return [];
        }

        $configs = [];
        foreach ($graphSync as $key => $conf) {
            if (!is_array($conf)) {
                continue;
            }
            $configs[(string)$key] = self::create((string)$key, $conf);
        }

        return $configs;
    }

    public function isComplete(): bool
    {
        return $this->tenantId !== ''
            && $this->clientId !== ''
            && $this->clientSecret !== '';
    }
}
