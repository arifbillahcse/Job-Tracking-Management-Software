<?php

declare(strict_types=1);

/**
 * Master list of permission keys and the default roles. The seeder syncs this
 * into the database. Code checks permission keys only, never role names.
 *
 * 'Admin' always receives every permission (re-synced on each seed run).
 * Other roles receive their defaults only when first created, so later edits
 * made in the permission matrix (Phase 4) are never overwritten.
 */
return [
    'permissions' => [
        'dashboard.view'        => ['Dashboard', 'View the dashboard'],
        'jobs.view'             => ['Jobs', 'View jobs and job lists'],
        'jobs.create'           => ['Jobs', 'Create jobs'],
        'jobs.edit'             => ['Jobs', 'Edit jobs'],
        'jobs.delete'           => ['Jobs', 'Delete (soft-delete) and restore jobs'],
        'jobs.status'           => ['Jobs', 'Change job status (dispatch, deliver, hold, cancel)'],
        'jobs.reopen'           => ['Jobs', 'Reopen delivered or cancelled jobs'],
        'jobs.view_financials'  => ['Jobs', 'See balance and storage fee'],
        'jobs.edit_financials'  => ['Jobs', 'Edit balance and storage fee (also lets the user see them)'],
        'jobs.import'           => ['Import / Export', 'Import jobs from CSV/Excel'],
        'jobs.export'           => ['Import / Export', 'Export job lists'],
        'users.manage'          => ['Administration', 'Manage users'],
        'roles.manage'          => ['Administration', 'Manage roles and permissions'],
        'audit.view'            => ['Administration', 'View the audit log'],
        'trips.view'            => ['Trips', 'View foreman trips'],
        'trips.manage'          => ['Trips', 'Create, edit and delete foreman trips'],
        'trips.financials'      => ['Trips', 'See and edit trip expenses (diesel, hotels, helpers, ...)'],
        'trips.export'          => ['Trips', 'Export trips and the foreman report to CSV'],
        'foremen.manage'        => ['Trips', 'Manage foremen'],
        'settings.manage'       => ['Administration', 'Change application settings'],
        'settings.storage_fee'  => ['Administration', 'Change the storage fee rate and rules'],
    ],

    'roles' => [
        'Admin' => [
            'description' => 'Full access to everything, including users, roles and settings.',
            'system' => true,
            'permissions' => '*',
        ],
        'Manager' => [
            'description' => 'Runs day-to-day operations, including financials, imports and exports.',
            'system' => false,
            'permissions' => [
                'dashboard.view', 'jobs.view', 'jobs.create', 'jobs.edit', 'jobs.delete', 'jobs.status',
                'jobs.reopen', 'jobs.view_financials', 'jobs.edit_financials', 'jobs.import', 'jobs.export',
                'settings.storage_fee', 'trips.view', 'trips.manage', 'trips.financials', 'trips.export', 'foremen.manage',
            ],
        ],
        'Staff' => [
            'description' => 'Enters and updates jobs. No financial figures, deletion or imports.',
            'system' => false,
            'permissions' => [
                'dashboard.view', 'jobs.view', 'jobs.create', 'jobs.edit', 'jobs.status',
            ],
        ],
    ],
];
