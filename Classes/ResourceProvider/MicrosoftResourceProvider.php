<?php

namespace Xima\XimaOauth2Extended\ResourceProvider;

use League\OAuth2\Client\Provider\GenericProvider;

class MicrosoftResourceProvider extends GenericProvider
{
    use SubResourceOwnerIdTrait;
}
