<?php

declare(strict_types=1);

/**
 * Sidebar items. An item is shown only when the user holds its permission
 * (null = any signed-in user). Later phases add their entries here.
 */
return [
    ['label' => 'Dashboard',  'path' => '/dashboard', 'icon' => 'home', 'permission' => 'dashboard.view'],
    ['label' => 'All Jobs',            'path' => '/jobs',              'icon' => 'briefcase',    'permission' => 'jobs.view', 'match' => '#^/jobs(/(create|trash|\d+(/.*)?))?$#'],
    ['label' => 'Ready for Delivery',  'path' => '/jobs/ready',        'icon' => 'clock',        'permission' => 'jobs.view'],
    ['label' => 'More Than 30 Days',   'path' => '/jobs/more-than-30', 'icon' => 'calendar',     'permission' => 'jobs.view'],
    ['label' => 'In Transit',          'path' => '/jobs/in-transit',   'icon' => 'truck',        'permission' => 'jobs.view'],
    ['label' => 'Delivered',           'path' => '/jobs/delivered',    'icon' => 'check-circle', 'permission' => 'jobs.view'],
    ['label' => 'My Account', 'path' => '/account',   'icon' => 'user', 'permission' => null],
];
