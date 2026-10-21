<?php

use Xima\XimaOauth2Extended\Controller\GraphDebugController;

/**
 * Backend module (admin only) for the configured Microsoft Entra (Graph)
 * clients: browse and manually import remote users as be_users / fe_users,
 * inspect the configuration / field mapping and test the connection.
 *
 * The module is only registered when at least one graphSync client is
 * configured. Read straight from TYPO3_CONF_VARS so no service container is
 * required at module-registration time.
 */
$graphSync = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['xima_oauth2_extended']['graphSync'] ?? null;
if (!is_array($graphSync) || $graphSync === []) {
    return [];
}

return [
    'tools_ximaoauth2graph' => [
        'parent' => 'tools',
        'position' => ['after' => 'tools_toolsmaintenance'],
        'access' => 'admin',
        'iconIdentifier' => 'module-xima-oauth2-graph',
        'labels' => 'LLL:EXT:xima_oauth2_extended/Resources/Private/Language/locallang_module.xlf',
        'routes' => [
            '_default' => [
                'target' => GraphDebugController::class . '::handleRequest',
            ],
        ],
    ],
];
