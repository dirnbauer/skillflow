<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:tx_skillflow_file',
        'label' => 'relative_path',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'rootLevel' => -1,
        'hideTable' => true,
        'versioningWS' => false,
        'default_sortby' => 'relative_path',
        'typeicon_classes' => [
            'default' => 'skillflow-skill',
        ],
        'searchFields' => 'relative_path,content',
    ],
    'columns' => [
        'skill' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'relative_path' => [
            'label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:file.relative_path',
            'description' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:file.relative_path.description',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 1024,
                'required' => true,
                'eval' => 'trim',
            ],
        ],
        'content' => [
            'label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:file.content',
            'config' => [
                'type' => 'text',
                'renderType' => 'codeEditor',
                'rows' => 20,
            ],
        ],
        'size' => [
            'label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:file.size',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'content_hash' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'relative_path, content, size',
        ],
    ],
];
