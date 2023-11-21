<?php

namespace Xima\XimaOauth2Extended\UserFactory;

use Xima\XimaOauth2Extended\ResourceResolver\ResourceResolverInterface;
use Xima\XimaOauth2Extended\ResourceResolver\UserGroupResolverInterface;

abstract class AbstractUserFactory
{

    /** @var string[]|null */
    protected ?array $remoteGroupIds = null;

    public function __construct(
        protected ResourceResolverInterface $resolver,
        protected string $providerId
    ) {
    }

    /** @return string[] */
    public function getRemoteGroupIdsCached(): array
    {
        if (!$this->resolver instanceof UserGroupResolverInterface) {
            return [];
        }
        if ($this->remoteGroupIds === null) {
            $this->remoteGroupIds = $this->resolver->resolveUserGroups();
        }
        return $this->remoteGroupIds;
    }
}
