<?php

namespace Xima\XimaOauth2Extended\ResourceResolver;

/**
 * A remote (Microsoft Entra) group with the information needed to create a
 * readable, hierarchical TYPO3 group: the directory object id (matched against
 * the `oauth2_id` column), the display name and the ids of the groups this group
 * is a direct member of (its parents in the Entra nesting).
 */
final class RemoteGroup
{
    /**
     * @param string[] $parentIds directory object ids of the parent groups
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly array $parentIds = [],
    ) {
    }
}
