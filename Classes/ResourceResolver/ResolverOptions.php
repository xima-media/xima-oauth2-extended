<?php

namespace Xima\XimaOauth2Extended\ResourceResolver;

final class ResolverOptions
{
    /** @var class-string  */
    public string $resolverClassName;

    public bool $createBackendUser = false;

    public bool $createFrontendUser = false;

    public string $defaultBackendUsergroup = '';

    public string $defaultFrontendUsergroup = '';

    public bool $createBackendUsergroups = false;

    public bool $createFrontendUsergroups = false;

    public bool $requireBackendUsergroup = false;

    public bool $requireFrontendUsergroup = false;

    public string $imageStorageBackendIdentifier = '';

    public string $imageStorageFrontendIdentifier = '';

    /**
     * @param array<string, mixed> $extConf
     */
    public static function createFromExtensionConfiguration(array $extConf): self
    {
        $conf = new self();
        $conf->resolverClassName = $extConf['resolverClassName'] ?? '';
        $conf->createBackendUser = $extConf['createBackendUser'] ?? false;
        $conf->createFrontendUser = $extConf['createFrontendUser'] ?? false;
        $conf->defaultBackendUsergroup = $extConf['defaultBackendUsergroup'] ?? '';
        $conf->defaultFrontendUsergroup = $extConf['defaultFrontendUsergroup'] ?? '';
        $conf->createBackendUsergroups = $extConf['createBackendUsergroups'] ?? false;
        $conf->createFrontendUsergroups = $extConf['createFrontendUsergroups'] ?? false;
        $conf->requireBackendUsergroup = $extConf['requireBackendUsergroup'] ?? false;
        $conf->requireFrontendUsergroup = $extConf['requireFrontendUsergroup'] ?? false;
        $conf->imageStorageBackendIdentifier = $extConf['imageStorageBackendIdentifier'] ?? '1:/user_upload/oauth';
        $conf->imageStorageFrontendIdentifier = $extConf['imageStorageFrontendIdentifier'] ?? '1:/user_upload/oauth';

        return $conf;
    }
}
