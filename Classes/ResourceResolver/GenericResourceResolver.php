<?php

namespace Xima\XimaOauth2Extended\ResourceResolver;

class GenericResourceResolver extends AbstractResourceResolver
{
    public function getIntendedEmail(): ?string
    {
        if ($this->getRemoteUser()->toArray()['email'] ?? false) {
            return strtolower($this->getRemoteUser()->toArray()['email']);
        }
        return null;
    }

    public function getIntendedUsername(): ?string
    {
        return $this->getRemoteUser()->toArray()['username'] ?? null;
    }

    public function updateBackendUser(array &$beUser): void
    {
        $remoteUser = $this->getRemoteUser()->toArray();

        if (!$beUser['username'] && $remoteUser['email']) {
            $beUser['username'] = strtolower($remoteUser['email']);
        }

        if ($remoteUser['email']) {
            $beUser['email'] = strtolower($remoteUser['email']);
        }

        $beUser['disable'] = 0;

        if (!$beUser['realName']) {
            $beUser['realName'] = $remoteUser['name'];
        }
    }

    public function updateFrontendUser(array &$feUser): void
    {
        $remoteUser = $this->getRemoteUser()->toArray();

        if (!$feUser['username'] && $remoteUser['email']) {
            $feUser['username'] = strtolower($remoteUser['email']);
        }

        if ($remoteUser['email']) {
            $feUser['email'] = strtolower($remoteUser['email']);
        }

        $feUser['disable'] = 0;

        if (!$feUser['name']) {
            $feUser['name'] = $remoteUser['name'];
        }
    }
}
