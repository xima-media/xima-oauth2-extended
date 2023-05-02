<?php

namespace Xima\XimaOauth2Extended\ResourceResolver;

class GitlabResourceResolver extends GenericResourceResolver
{


    public function updateBackendUser(array &$beUser): void
    {
        $remoteUser = $this->getRemoteUser()->toArray();

        if (!$beUser['username'] && $remoteUser['username']) {
            $beUser['username'] = $remoteUser['username'];
        }

        if ($remoteUser['email']) {
            $beUser['email'] = $remoteUser['email'];
        }

        if ($remoteUser['state'] === 'active') {
            $beUser['disable'] =  0;
        }

        if (!$beUser['realName']) {
            $beUser['realName'] = $remoteUser['name'];
        }

        if (!$beUser['admin'] && isset($remoteUser['is_admin']) && $remoteUser['is_admin']) {
            $beUser['admin'] = 1;
        }
    }

    public function updateFrontendUser(array &$feUser): void
    {
        $remoteUser = $this->getRemoteUser()->toArray();

        if (!$feUser['username'] && $remoteUser['username']) {
            $feUser['username'] = $remoteUser['username'];
        }

        if ($remoteUser['email']) {
            $feUser['email'] = $remoteUser['email'];
        }

        if ($remoteUser['state'] === 'active') {
            $feUser['disable'] =  0;
        }

        if (!$feUser['name']) {
            $feUser['name'] = $remoteUser['name'];
        }

        // @TODO: usergroups
    }
}
