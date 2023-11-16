<?php

if (!defined('TYPO3')) {
    die('Access denied.');
}

$tempFields = [
    'oauth2_id' => [
        'label' => 'OAuth2 ID',
        'config' => [
            'type' => 'input',
            'eval' => 'trim',
        ],
    ],
];

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('be_groups', $tempFields);

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'be_groups',
    'oauth2_id',
    '',
    'after:TSconfig'
);
