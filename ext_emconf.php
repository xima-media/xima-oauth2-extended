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
    'version' => '2.0.3',
    'autoload' => [
        'psr-4' => ['Xima\\XimaOauth2Extended\\' => 'Classes'],
    ],
    'constraints' => [
        'depends' => [
            'typo3' => '11.5.99-12.4.99',
        ],
    ],
];
