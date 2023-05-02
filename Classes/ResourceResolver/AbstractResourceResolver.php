<?php

namespace Xima\XimaOauth2Extended\ResourceResolver;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use Waldhacker\Oauth2Client\Events\BackendUserLookupEvent;
use Waldhacker\Oauth2Client\Events\FrontendUserLookupEvent;

abstract class AbstractResourceResolver implements ResourceResolverInterface
{
    public function getRemoteUser(): ResourceOwnerInterface
    {
        return $this->userLookupEvent->getRemoteUser();
    }

    public function __construct(
        protected readonly FrontendUserLookupEvent|BackendUserLookupEvent $userLookupEvent
    ) {
    }
}
