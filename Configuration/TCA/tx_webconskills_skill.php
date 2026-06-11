<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:tx_webconskills_skill',
        'label' => 'title',
        'label_alt' => 'identifier',
        'descriptionColumn' => 'description',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'rootLevel' => -1,
        'versioningWS' => false,
        'versioningWS_alwaysAllowLiveEdit' => true,
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'typeicon_classes' => [
            'default' => 'webconskills-skill',
        ],
        'searchFields' => 'title,identifier,description,body',
    ],
    'columns' => [
        'title' => [
            'label' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:skill.title',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'required' => true,
                'eval' => 'trim',
            ],
        ],
        'identifier' => [
            'label' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:skill.identifier',
            'description' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:skill.identifier.description',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'required' => true,
                'eval' => 'trim,unique',
            ],
        ],
        'description' => [
            'label' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:skill.description',
            'description' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:skill.description.description',
            'config' => [
                'type' => 'text',
                'rows' => 3,
                'required' => true,
            ],
        ],
        'body' => [
            'label' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:skill.body',
            'description' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:skill.body.description',
            'config' => [
                'type' => 'text',
                'renderType' => 'codeEditor',
                'rows' => 30,
            ],
        ],
        'allowed_tools' => [
            'label' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:skill.allowed_tools',
            'description' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:skill.allowed_tools.description',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 1024,
                'eval' => 'trim',
            ],
        ],
        'metadata' => [
            'label' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:skill.metadata',
            'config' => [
                'type' => 'json',
            ],
        ],
        'source_type' => [
            'label' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:skill.source_type',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:skill.source_type.manual', 'value' => 'manual'],
                    ['label' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:skill.source_type.folder', 'value' => 'folder'],
                    ['label' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:skill.source_type.repository', 'value' => 'repository'],
                ],
                'default' => 'manual',
            ],
        ],
        'repository' => [
            'label' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:skill.repository',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_webconskills_repository',
                'items' => [
                    ['label' => '', 'value' => 0],
                ],
                'default' => 0,
                'readOnly' => true,
            ],
        ],
        'relative_path' => [
            'label' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:skill.relative_path',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 1024,
                'readOnly' => true,
            ],
        ],
        'content_hash' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'last_synced' => [
            'label' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:skill.last_synced',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:tab.general,
                    title, identifier, description, body,
                --div--;LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:tab.options,
                    allowed_tools, metadata,
                --div--;LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:tab.source,
                    source_type, repository, relative_path, last_synced,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    hidden,
            ',
        ],
    ],
];
