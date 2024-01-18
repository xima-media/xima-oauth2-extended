<?php

namespace Xima\XimaOauth2Extended\ResourceProvider;

use League\OAuth2\Client\Token\AccessToken;

trait TokenBasedResourceOwnerDetailsTrait
{
    /**
     * @return string[]
     */
    protected function getRequiredOptions(): array
    {
        return [
            'urlAuthorize',
            'urlAccessToken',
        ];
    }

    /**
     * @param AccessToken $token
     * @return array
     */
    protected function fetchResourceOwnerDetails(AccessToken $token): array
    {
        $tokenValues = $token->getValues();
        return (array)json_decode(base64_decode(str_replace('_', '/', str_replace('-', '+', explode('.', $tokenValues['id_token'])[1]))));
    }
}
