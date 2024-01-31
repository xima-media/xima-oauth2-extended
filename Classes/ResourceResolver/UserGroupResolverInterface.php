<?php

namespace Xima\XimaOauth2Extended\ResourceResolver;

interface UserGroupResolverInterface extends ResourceResolverInterface
{
    /**
     * Returns list of `oauth2_id`s
     *
     * @return string[]|null
     */
    public function resolveUserGroups(): ?array;
}
