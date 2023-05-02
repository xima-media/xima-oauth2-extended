<?php

namespace Xima\XimaOauth2Extended\ResourceResolver;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;

interface ResourceResolverInterface
{
    public function updateBackendUser(array &$beUser): void;

    public function updateFrontendUser(array &$beUser): void;

    public function getIntendedUsername(): ?string;

    public function getIntendedEmail(): ?string;

    public function getRemoteUser(): ResourceOwnerInterface;

}
