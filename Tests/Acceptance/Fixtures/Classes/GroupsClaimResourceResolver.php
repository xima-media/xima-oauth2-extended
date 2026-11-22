<?php

declare(strict_types=1);

namespace Xima\XimaOauth2Extended\Tests\Acceptance\Fixtures\Classes;

use Xima\XimaOauth2Extended\ResourceResolver\GenericResourceResolver;
use Xima\XimaOauth2Extended\ResourceResolver\UserGroupResolverInterface;

/**
 * Test-only resolver: no stock resolver reads a `groups` claim, so group mapping is exercised through this one.
 */
final class GroupsClaimResourceResolver extends GenericResourceResolver implements UserGroupResolverInterface
{
    public function resolveUserGroups(): ?array
    {
        $groups = $this->getRemoteUser()->toArray()['groups'] ?? null;
        return is_array($groups) ? array_values(array_map('strval', $groups)) : null;
    }
}
