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
use Xima\XimaOauth2Extended\ResourceResolver\ResolverOptions;
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
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     * @throws IdentityResolverException
     */
    public function __invoke(BackendUserLookupEvent $event): void
    {
        if (!($event->getRemoteUser() instanceof ResourceOwnerInterface)) {
            return;
        }

        $providerId = $event->getProviderId();
        $extendedProviderConfiguration = $this->extensionConfiguration->get('xima_oauth2_extended', 'oauth2_client_providers') ?? [];
        $resolverOptions = ResolverOptions::createFromExtensionConfiguration($extendedProviderConfiguration[$providerId] ?? []);
        if (!$resolverOptions->resolverClassName) {
            return;
        }

        // create resolver
        $resolver = GeneralUtility::makeInstance($resolverOptions->resolverClassName, $event, $resolverOptions);
        if (!$resolver instanceof ResourceResolverInterface) {
            $message = 'Class ' . $resolverOptions->resolverClassName . ' musst implement interface ' . ResourceResolverInterface::class;
            throw new IdentityResolverException($message, 1683016777);
        }

        // create/link user or update
        $userFactory = new BackendUserFactory($resolver, $providerId);
        $typo3User = $event->getTypo3User();
        if ($typo3User === null) {
            $this->logger->info('Register remote user from provider "' . $event->getProviderId() . '" (remote id: ' . $event->getRemoteUser()->getId() . ')');
            $typo3User = $userFactory->registerRemoteUser();
        } else {
            $this->logger->info('Update TYPO3 user from provider "' . $event->getProviderId() . '" (remote id: ' . $event->getRemoteUser()->getId() . ')');
            $typo3User = $userFactory->updateTypo3User($typo3User);
        }

        // add user to event
        if ($typo3User) {
            $event->setTypo3User($typo3User);
        }
    }
}
