<?php

declare(strict_types=1);

/**
 * Default values for the editable `settings` table. Seeded only when a key is
 * missing, so changes made in the database (or the Phase 7 settings page) survive re-seeding.
 */
return [
    'company_name' => 'Job Tracker',
    // A job is "ready for delivery" when its 1st available date is within this many days (assumption A2).
    'ready_threshold_days' => '30',
    'currency_symbol' => '$',
    'timezone' => 'UTC',
    'page_size' => '25',
];
