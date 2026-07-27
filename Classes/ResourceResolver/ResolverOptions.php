<?php

namespace Xima\XimaOauth2Extended\ResourceResolver;

use Xima\XimaOauth2Extended\Enum\OrphanedUserAction;

final class ResolverOptions
{
    /** @var class-string */
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

    public string $defaultBackendLanguage = '';

    public string $defaultBackendAdminGroups = '';

    /**
     * How to reconcile users that are still linked to the client but no longer
     * present in Entra. Applies to the app-only Graph sync only.
     */
    public OrphanedUserAction $orphanedUserAction = OrphanedUserAction::None;

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
        $conf->defaultBackendLanguage = $extConf['defaultBackendLanguage'] ?? 'default';
        $conf->defaultBackendAdminGroups = $extConf['defaultBackendAdminGroups'] ?? '';
        $conf->orphanedUserAction = OrphanedUserAction::fromConfig($extConf['orphanedUserAction'] ?? null);

        return $conf;
    }
}
