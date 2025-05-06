<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'OAuth2 Extended',
    'description' => 'Provides additional OAuth2 provider + on-the-fly user creation',
    'category' => 'auth',
    'author' => 'Maik Schneider',
    'author_email' => 'maik.schneider@xima.de',
    'author_company' => 'XIMA Media GmbH',
    'state' => 'stable',
    'uploadfolder' => 0,
    'clearCacheOnLoad' => 1,
    'version' => '3.0.0',
    'autoload' => [
        'psr-4' => ['Xima\\XimaOauth2Extended\\' => 'Classes'],
    ],
    'constraints' => [
        'depends' => [
            'typo3' => '12.0.0-13.99.99',
        ],
    ],
];
