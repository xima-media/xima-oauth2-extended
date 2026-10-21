<?php

namespace Xima\XimaOauth2Extended\ResourceResolver;

/**
 * Optional extension of {@see UserGroupResolverInterface} for resolvers that can
 * provide rich group information (display name + hierarchy), not just ids.
 *
 * Factories feature-detect this with `instanceof`: when present, synced groups
 * are created with their real names and the Entra nesting is reconstructed into
 * the TYPO3 `subgroup` field. Resolvers that only implement
 * {@see UserGroupResolverInterface} keep the id-only behaviour.
 */
interface UserGroupDetailsResolverInterface
{
    /**
     * @return RemoteGroup[]|null
     */
    public function resolveUserGroupDetails(): ?array;
}
