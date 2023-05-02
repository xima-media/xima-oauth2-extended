<?php

namespace Xima\XimaOauth2Extended\EventListener;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Waldhacker\Oauth2Client\Events\BackendUserLookupEvent;
use Xima\XimaOauth2Extended\Exception\IdentityResolverException;
use Xima\XimaOauth2Extended\ResourceResolver\ResourceResolverInterface;
use Xima\XimaOauth2Extended\UserFactory\BackendUserFactory;

class BackendUserLookup
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ExtensionConfiguration $extensionConfiguration
    ) {
    }

    /**
     * @throws ExtensionConfigurationPathDoesNotExistException
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws IdentityResolverException
     */
    public function __invoke(BackendUserLookupEvent $event): void
    {
        if ($event->getTypo3User() !== null || !($event->getRemoteUser() instanceof ResourceOwnerInterface)) {
            return;
        }

        $providerId = $event->getProviderId();
        $extendedProviderConfiguration = $this->extensionConfiguration->get('xima-oauth2-extended', $providerId) ?? [];
        $resolverClass = $extendedProviderConfiguration['resolverClassName'] ?? '';

        if (!$resolverClass) {
            return;
        }

        // create resolver
        $resolver = GeneralUtility::makeInstance($resolverClass, $event);
        if (!$resolver instanceof ResourceResolverInterface) {
            $message = 'Class ' . $resolverClass . ' musst implement interface ' . ResourceResolverInterface::class;
            throw new IdentityResolverException($message, 1683016777);
        }

        // log info
        $this->logger->info('Register remote user from provider "' . $event->getProviderId() . '" (remote id: ' . $event->getRemoteUser()->getId() . ')');

        // create/link user
        $userFactory = new BackendUserFactory($resolver, $providerId, $extendedProviderConfiguration);
        $typo3User = $userFactory->registerRemoteUser();

        // add user to event
        if ($typo3User) {
            $event->setTypo3User($typo3User);
        }
    }
}
