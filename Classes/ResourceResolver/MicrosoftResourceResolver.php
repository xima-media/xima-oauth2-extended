<?php

namespace Xima\XimaOauth2Extended\ResourceResolver;

use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class MicrosoftResourceResolver extends GenericResourceResolver implements ProfileImageResolverInterface
{
    public function updateBackendUser(array &$beUser): void
    {
        $remoteUser = $this->getRemoteUser()->toArray();

        if (!$beUser['username'] && $remoteUser['email']) {
            $beUser['username'] = $remoteUser['email'];
        }

        if ($remoteUser['email']) {
            $beUser['email'] = $remoteUser['email'];
        }

        $beUser['disable'] = 0;
        $beUser['admin'] = 1;

        if (!$beUser['realName']) {
            $beUser['realName'] = $remoteUser['name'];
        }
    }

    public function resolveProfileImage(): ?string
    {
        $remoteUser = $this->getRemoteUser()->toArray();

        if (!isset($remoteUser['picture']) || !$remoteUser['picture']) {
            return null;
        }

        $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
        try {
            $imageResponse = $requestFactory->request(
                $remoteUser['picture'],
                'GET',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->userLookupEvent->getAccessToken(),
                    ],
                ]
            );
            return $imageResponse->getBody()->getContents();
        } catch (\Exception) {
        }
        return null;
    }

    public function updateFrontendUser(array &$feUser): void
    {
        $remoteUser = $this->getRemoteUser()->toArray();

        if (!$feUser['username'] && $remoteUser['email']) {
            $feUser['username'] = $remoteUser['email'];
        }

        if ($remoteUser['email']) {
            $feUser['email'] = $remoteUser['email'];
        }

        $feUser['disable'] = 0;

        if (!$feUser['name']) {
            $feUser['name'] = $remoteUser['name'];
        }
    }
}
