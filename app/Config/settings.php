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
    // Which date the Ready / More Than 30 Days rule counts from: 'first_available' (default, matches the client's sheet) or 'pickup' (falls back to the 1st available date). Assumption L1.
    'section_basis' => 'first_available',
    'currency_symbol' => '$',
    'timezone' => 'UTC',
    'page_size' => '25',
    // Automatic storage fee = CF x rate, charged once a job has waited more than N days (assumption J1).
    'storage_fee_rate' => '0.6',
    'storage_fee_after_days' => '30',
    'storage_fee_basis' => 'pickup', // or 'first_available' or 'loaded' (assumption N1)
    // 'monthly' = CF x rate x months stayed, no prorating (the client's sheet); 'once' = CF x rate a single time (assumption N2).
    'storage_fee_mode' => 'monthly',
];
