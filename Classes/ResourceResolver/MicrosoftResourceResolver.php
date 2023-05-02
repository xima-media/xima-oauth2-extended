<?php

namespace Xima\XimaOauth2Extended\ResourceResolver;

use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class MicrosoftResourceResolver extends GenericResourceResolver
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

        //if ($remoteUser['picture'] ?? '') {
        //    $this->resolveRemotePicture();
        //}
    }

    protected function resolveRemotePicture()
    {
        $remoteUser = $this->getRemoteUser()->toArray();
        $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
        $image = $requestFactory->request(
            $remoteUser['picture'],
            'GET',
            [
                'Authorization' => 'Bearer ' . $this->userLookupEvent->getAccessToken(),
            ]
        );
        $b = $image->getBody();
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
