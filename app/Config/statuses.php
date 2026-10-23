<?php

declare(strict_types=1);

/**
 * Job statuses and the allowed transitions (assumptions A5/A6). Declared in
 * Phase 1 so the rules live in one place; enforced by JobService in Phase 3.
 */
return [
    'statuses' => [
        'booked'     => ['label' => 'Booked',     'badge' => 'booked'],
        'in_transit' => ['label' => 'In Transit', 'badge' => 'transit'],
        'delivered'  => ['label' => 'Delivered',  'badge' => 'delivered'],
        'on_hold'    => ['label' => 'On Hold',    'badge' => 'hold'],
        'cancelled'  => ['label' => 'Cancelled',  'badge' => 'cancelled'],
    ],

    // from => [to => permission required]
    'transitions' => [
        'booked'     => ['in_transit' => 'jobs.status', 'on_hold' => 'jobs.status', 'cancelled' => 'jobs.status'],
        'in_transit' => ['delivered' => 'jobs.status', 'on_hold' => 'jobs.status', 'cancelled' => 'jobs.status'],
        'on_hold'    => ['booked' => 'jobs.status', 'cancelled' => 'jobs.status'],
        'delivered'  => ['in_transit' => 'jobs.reopen'],
        'cancelled'  => ['booked' => 'jobs.reopen'],
    ],
];
