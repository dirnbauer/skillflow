<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:tx_skillflow_repository',
        'label' => 'title',
        'label_alt' => 'url',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'rootLevel' => -1,
        'adminOnly' => true,
        'versioningWS' => false,
        'versioningWS_alwaysAllowLiveEdit' => true,
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'typeicon_classes' => [
            'default' => 'skillflow-repository',
        ],
        'searchFields' => 'title,url',
    ],
    'columns' => [
        'title' => [
            'label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:repository.title',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'required' => true,
                'eval' => 'trim',
            ],
        ],
        'url' => [
            'label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:repository.url',
            'description' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:repository.url.description',
            'config' => [
                'type' => 'link',
                'allowedTypes' => ['url'],
                'required' => true,
            ],
        ],
        'branch' => [
            'label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:repository.branch',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 255,
                'default' => 'main',
                'eval' => 'trim',
            ],
        ],
        'subfolder' => [
            'label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:repository.subfolder',
            'description' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:repository.subfolder.description',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'token_env_var' => [
            'label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:repository.token_env_var',
            'description' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:repository.token_env_var.description',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'last_synced' => [
            'label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:repository.last_synced',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],
        'last_error' => [
            'label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:repository.last_error',
            'config' => [
                'type' => 'text',
                'rows' => 3,
                'readOnly' => true,
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:tab.general,
                    title, url, branch, subfolder,
                --div--;LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:tab.credentials,
                    token_env_var,
                --div--;LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:tab.status,
                    last_synced, last_error,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    hidden,
            ',
        ],
    ],
];
