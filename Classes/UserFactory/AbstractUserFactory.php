<?php

namespace Xima\XimaOauth2Extended\UserFactory;

use Psr\Log\LoggerInterface;
use Xima\XimaOauth2Extended\ResourceResolver\ResourceResolverInterface;
use Xima\XimaOauth2Extended\ResourceResolver\UserGroupResolverInterface;

abstract class AbstractUserFactory
{
    /** @var string[]|null */
    protected ?array $remoteGroupIds = null;

    public function __construct(
        protected ResourceResolverInterface $resolver,
        protected string $providerId,
        protected LoggerInterface $logger
    ) {
    }

    /** @return string[]|null */
    public function getRemoteGroupIdsCached(): ?array
    {
        if (!$this->resolver instanceof UserGroupResolverInterface) {
            return null;
        }
        if ($this->remoteGroupIds === null) {
            $this->remoteGroupIds = $this->resolver->resolveUserGroups();
        }
        return $this->remoteGroupIds;
    }
}
