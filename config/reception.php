<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Clinic timezone
    |--------------------------------------------------------------------------
    |
    | The app itself runs on UTC, but appointments are stored as the clinic's
    | local wall-clock time, and "today", the financial-year boundary (1 April)
    | and the queue's automatic flags all have to follow the clinic's clock.
    | One value for every clinic for now; per-clinic timezones come later.
    |
    */

    'timezone' => env('CLINIC_TIMEZONE', 'Asia/Kolkata'),

    /*
    |--------------------------------------------------------------------------
    | Live queue thresholds (minutes)
    |--------------------------------------------------------------------------
    |
    | When a queue item is raised into "Needs attention". Per-clinic values
    | come later; these are the defaults every clinic uses today.
    |
    */

    'thresholds' => [
        'long_wait_minutes' => (int) env('QUEUE_LONG_WAIT_MINUTES', 20),
        'called_not_seen_minutes' => (int) env('QUEUE_CALLED_NOT_SEEN_MINUTES', 5),
        'late_arrival_minutes' => (int) env('QUEUE_LATE_ARRIVAL_MINUTES', 15),
    ],

];
