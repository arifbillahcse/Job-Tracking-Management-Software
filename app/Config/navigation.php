<?php

declare(strict_types=1);

/**
 * Sidebar items. An item is shown only when the user holds its permission
 * (null = any signed-in user). Later phases add their entries here.
 */
return [
    ['label' => 'Dashboard',  'path' => '/dashboard', 'icon' => 'home', 'permission' => 'dashboard.view'],
    ['label' => 'My Account', 'path' => '/account',   'icon' => 'user', 'permission' => null],
];
