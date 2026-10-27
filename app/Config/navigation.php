<?php

declare(strict_types=1);

/**
 * Sidebar items. An item is shown only when the user holds its permission (null = any signed-in user).
 * "group" prints a small heading when it changes; "match" is an optional regex for items whose path is a
 * prefix of siblings (/jobs vs /jobs/ready). Later phases add their entries here.
 */
return [
    ['label' => 'Dashboard', 'path' => '/dashboard', 'icon' => 'home', 'permission' => 'dashboard.view'],

    ['group' => 'Jobs', 'label' => 'All Jobs',           'path' => '/jobs',              'icon' => 'briefcase',    'permission' => 'jobs.view', 'match' => '#^/jobs(/(create|trash|\d+(/.*)?))?$#'],
    ['group' => 'Jobs', 'label' => 'Ready for Delivery', 'path' => '/jobs/ready',        'icon' => 'clock',        'permission' => 'jobs.view'],
    ['group' => 'Jobs', 'label' => 'More Than 30 Days',  'path' => '/jobs/more-than-30', 'icon' => 'calendar',     'permission' => 'jobs.view'],
    ['group' => 'Jobs', 'label' => 'In Transit',         'path' => '/jobs/in-transit',   'icon' => 'truck',        'permission' => 'jobs.view'],
    ['group' => 'Jobs', 'label' => 'Delivered',          'path' => '/jobs/delivered',    'icon' => 'check-circle', 'permission' => 'jobs.view'],

    ['group' => 'Jobs', 'label' => 'Import Jobs',        'path' => '/jobs/import',       'icon' => 'upload',       'permission' => 'jobs.import'],

    ['group' => 'Foremen & Trips', 'label' => 'Trips',   'path' => '/trips',   'icon' => 'truck', 'permission' => 'trips.view', 'match' => '#^/trips(/(create|\\d+(/.*)?))?$#'],
    ['group' => 'Foremen & Trips', 'label' => 'Foremen', 'path' => '/foremen', 'icon' => 'users', 'permission' => 'foremen.manage', 'match' => '#^/foremen(/(create|\\d+(/.*)?))?$#'],

    ['group' => 'Administration', 'label' => 'Users',             'path' => '/users', 'icon' => 'users',  'permission' => 'users.manage'],
    ['group' => 'Administration', 'label' => 'Roles & Permissions', 'path' => '/roles', 'icon' => 'shield', 'permission' => 'roles.manage'],

    ['group' => 'Administration', 'label' => 'Audit Log',          'path' => '/audit',    'icon' => 'file-text', 'permission' => 'audit.view', 'match' => '#^/audit(/\d+)?$#'],
    ['group' => 'Administration', 'label' => 'Settings',           'path' => '/settings', 'icon' => 'sliders',   'permission' => 'settings.manage', 'match' => '#^/settings$#'],
    ['group' => 'Administration', 'label' => 'Storage Fee',        'path' => '/settings/storage-fee', 'icon' => 'dollar', 'permission' => 'settings.storage_fee'],

    ['group' => 'Account', 'label' => 'My Account', 'path' => '/account', 'icon' => 'user', 'permission' => null],
];
