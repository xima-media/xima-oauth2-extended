<?php

namespace Xima\XimaOauth2Extended\ResourceResolver;

interface ProfileImageResolverInterface extends ResourceResolverInterface
{
    public function resolveProfileImage(): ?string;
}
