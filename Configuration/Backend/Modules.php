<?php

use Xima\XimaOauth2Extended\Controller\GraphDebugController;

/**
 * Backend module for exploring / debugging the configured Microsoft Entra
 * (Graph) endpoints: test the connection, search users and inspect the
 * configuration / field mapping per graphSync client. Admin only.
 */
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
