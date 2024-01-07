<?php

namespace Xima\XimaOauth2Extended\ResourceProvider;

use League\OAuth2\Client\Provider\GenericResourceOwner;
use League\OAuth2\Client\Token\AccessToken;

trait SubResourceOwnerIdTrait
{
    protected function createResourceOwner(array $response, AccessToken $token): GenericResourceOwner
    {
        return new GenericResourceOwner($response, 'sub');
    }
}
