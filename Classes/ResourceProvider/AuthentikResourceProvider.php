<?php

namespace Xima\XimaOauth2Extended\ResourceProvider;

use League\OAuth2\Client\Provider\GenericProvider;

class AuthentikResourceProvider extends GenericProvider
{
    use SubResourceOwnerIdTrait;

    use TokenBasedResourceOwnerDetailsTrait;
}
