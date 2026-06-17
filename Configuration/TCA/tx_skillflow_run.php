<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:tx_skillflow_run',
        'label' => 'target_table',
        'label_alt' => 'status',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'rootLevel' => 1,
        'hideTable' => true,
        'versioningWS' => false,
        'default_sortby' => 'crdate DESC',
        'typeicon_classes' => [
            'default' => 'skillflow-run',
        ],
    ],
    'columns' => [
        'skill' => [
            'label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:run.skill',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_skillflow_skill',
                'items' => [['label' => '', 'value' => 0]],
                'default' => 0,
                'readOnly' => true,
            ],
        ],
        'target_table' => [
            'label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:run.target_table',
            'config' => ['type' => 'input', 'max' => 255, 'readOnly' => true],
        ],
        'target_uid' => [
            'label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:run.target_uid',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
        'workspace_uid' => [
            'label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:run.workspace_uid',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
        'stage_uid' => [
            'label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:run.stage_uid',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
        'status' => [
            'label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:run.status',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:run.status.success', 'value' => 'success'],
                    ['label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:run.status.failed', 'value' => 'failed'],
                    ['label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:run.status.blocked', 'value' => 'blocked'],
                ],
                'default' => 'success',
                'readOnly' => true,
            ],
        ],
        'runner' => [
            'label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:run.runner',
            'config' => ['type' => 'input', 'max' => 64, 'readOnly' => true],
        ],
        'instructions' => [
            'label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:run.instructions',
            'config' => ['type' => 'text', 'rows' => 4, 'readOnly' => true],
        ],
        'output' => [
            'label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:run.output',
            'config' => ['type' => 'text', 'rows' => 20, 'readOnly' => true],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'skill, status, runner, target_table, target_uid, workspace_uid, stage_uid, instructions, output',
        ],
    ],
];
