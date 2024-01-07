<?php

namespace Xima\XimaOauth2Extended\ResourceResolver;

use TYPO3\CMS\Core\Utility\GeneralUtility;

class AuthentikResourceResolver extends GenericResourceResolver implements ProfileImageResolverInterface
{
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

    public function resolveProfileImage(): ?string
    {
        $remoteUser = $this->getRemoteUser()->toArray();

        if (!isset($remoteUser['avatar']) || !$remoteUser['avatar']) {
            return null;
        }

        $base64Parts = GeneralUtility::trimExplode(',', $remoteUser['avatar']);

        return base64_decode($base64Parts[1]) ?: null;
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
