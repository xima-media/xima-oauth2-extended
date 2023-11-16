<?php

if (!defined('TYPO3')) {
    die('Access denied.');
}

$tempFields = [
    'oauth2_id' => [
        'exclude' => true,
        'label' => 'OAuth2 ID',
        'config' => [
            'type' => 'input',
            'eval' => 'trim',
        ],
    ],
];

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('fe_groups', $tempFields);

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'fe_groups',
    'oauth2_id',
    '',
    'after:TSconfig'
);
