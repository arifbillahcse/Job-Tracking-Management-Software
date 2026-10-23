<?php

declare(strict_types=1);

/**
 * Job statuses and the allowed transitions (assumptions A5/A6). This is the single place
 * the workflow rules live; JobWorkflowService enforces them on every status change.
 *
 * Per transition:
 *   label       button / history text
 *   permission  permission key the user needs for this particular change
 *   style       Bootstrap button style
 *   form        extra data collected: 'dispatch' (carrier, driver, phone), 'deliver' (delivery date) or 'note' (just a note)
 *   note        'required' | 'optional' - whether a reason must be given
 */
return [
    'statuses' => [
        'booked'     => ['label' => 'Booked',     'badge' => 'booked'],
        'in_transit' => ['label' => 'In Transit', 'badge' => 'transit'],
        'delivered'  => ['label' => 'Delivered',  'badge' => 'delivered'],
        'on_hold'    => ['label' => 'On Hold',    'badge' => 'hold'],
        'cancelled'  => ['label' => 'Cancelled',  'badge' => 'cancelled'],
    ],

    'transitions' => [
        'booked' => [
            'in_transit' => ['label' => 'Dispatch', 'permission' => 'jobs.status', 'style' => 'primary', 'form' => 'dispatch', 'note' => 'optional'],
            'on_hold'    => ['label' => 'Put on hold', 'permission' => 'jobs.status', 'style' => 'outline-secondary', 'form' => 'note', 'note' => 'required'],
            'cancelled'  => ['label' => 'Cancel job', 'permission' => 'jobs.status', 'style' => 'outline-danger', 'form' => 'note', 'note' => 'required'],
        ],
        'in_transit' => [
            'delivered'  => ['label' => 'Mark delivered', 'permission' => 'jobs.status', 'style' => 'primary', 'form' => 'deliver', 'note' => 'optional'],
            'on_hold'    => ['label' => 'Put on hold', 'permission' => 'jobs.status', 'style' => 'outline-secondary', 'form' => 'note', 'note' => 'required'],
            'cancelled'  => ['label' => 'Cancel job', 'permission' => 'jobs.status', 'style' => 'outline-danger', 'form' => 'note', 'note' => 'required'],
        ],
        // Resuming returns the job to Booked; it has to be dispatched again (carrier details are cleared, but kept in the history).
        'on_hold' => [
            'booked'     => ['label' => 'Resume', 'permission' => 'jobs.status', 'style' => 'primary', 'form' => 'note', 'note' => 'optional'],
            'cancelled'  => ['label' => 'Cancel job', 'permission' => 'jobs.status', 'style' => 'outline-danger', 'form' => 'note', 'note' => 'required'],
        ],
        'delivered' => [
            'in_transit' => ['label' => 'Reopen (back in transit)', 'permission' => 'jobs.reopen', 'style' => 'outline-secondary', 'form' => 'note', 'note' => 'required'],
        ],
        'cancelled' => [
            'booked'     => ['label' => 'Reopen as booked', 'permission' => 'jobs.reopen', 'style' => 'outline-secondary', 'form' => 'note', 'note' => 'required'],
        ],
    ],
];
