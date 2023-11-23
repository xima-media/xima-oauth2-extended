<?php

namespace Xima\XimaOauth2Extended\ResourceResolver;

use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class MicrosoftResourceResolver extends GenericResourceResolver implements ProfileImageResolverInterface, UserGroupResolverInterface
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

    public function resolveUserGroups(): array
    {
        $ownerUrl = 'https://graph.microsoft.com/v1.0/me/memberOf';

        $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
        try {
            $groupResource = $requestFactory->request(
                $ownerUrl,
                'GET',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->userLookupEvent->getAccessToken(),
                    ],
                ]
            );
            $body = $groupResource->getBody()->getContents();
            $groupSettings = json_decode($body);

            return array_map(function ($group) {
                return $group->id;
            }, $groupSettings?->value ?? []);
        } catch (\Exception) {
        }
        return [];
    }
}
