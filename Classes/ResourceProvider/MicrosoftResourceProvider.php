<?php

namespace Xima\XimaOauth2Extended\ResourceProvider;

use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Provider\GenericResourceOwner;
use League\OAuth2\Client\Token\AccessToken;

class MicrosoftResourceProvider extends GenericProvider
{
    protected function createResourceOwner(array $response, AccessToken $token)
    {
        return new GenericResourceOwner($response, 'sub');
    }
}
