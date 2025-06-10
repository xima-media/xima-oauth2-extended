<?php

namespace Xima\XimaOauth2Extended\EventListener;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Security\SudoMode\Event\SudoModeRequiredEvent;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

class SudoModeRequiredEventListener
{
    public function __invoke(SudoModeRequiredEvent $event): void
    {
        // Check if sudo mode is required
        $isVerificationRequired = $event->isVerificationRequired();
        if (!$isVerificationRequired) {
            return;
        }

        // Check if the user has an OAuth2 client configuration
        $hasOauth2ClientConfig = $this->getCurrentUser()->user['tx_oauth2_client_configs'] ?? false;
        if (!$hasOauth2ClientConfig) {
            return;
        }

        // Admins can bypass sudo mode
        if ($this->getCurrentUser()->isAdmin()) {
            $event->setVerificationRequired(false);
        }
    }

    private function getCurrentUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
