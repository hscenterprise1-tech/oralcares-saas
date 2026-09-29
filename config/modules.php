<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Module Categories
    |--------------------------------------------------------------------------
    |
    | Define the logical grouping of modules for display in the UI.
    |
    */

    'categories' => [
        'core' => ['patients', 'doctors', 'reception', 'billing'],
        'clinical' => ['laboratory', 'inventory', 'queue'],
        'business' => ['crm', 'reports', 'finance'],
        'future' => ['ai', 'telemedicine', 'marketplace'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Module Definitions & Dependencies
    |--------------------------------------------------------------------------
    |
    | Define each module and its required dependencies. A module cannot be
    | enabled unless all of its dependencies are also enabled.
    |
    */

    'modules' => [
        'patients' => [
            'dependencies' => [],
        ],
        'doctors' => [
            'dependencies' => [],
        ],
        'reception' => [
            'dependencies' => ['patients'],
        ],
        'billing' => [
            'dependencies' => ['patients', 'treatments'],
        ],
        'treatments' => [
            'dependencies' => ['patients'],
        ],
        'laboratory' => [
            'dependencies' => ['patients', 'treatments'],
        ],
        'inventory' => [
            'dependencies' => ['treatments', 'billing'],
        ],
        'queue' => [
            'dependencies' => ['reception', 'treatments'],
        ],
        'crm' => [
            'dependencies' => [],
        ],
        'reports' => [
            'dependencies' => [],
        ],
        'finance' => [
            'dependencies' => ['billing'],
        ],
        'ai' => [
            'dependencies' => [],
        ],
        'telemedicine' => [
            'dependencies' => ['patients', 'doctors', 'billing'],
        ],
        'marketplace' => [
            'dependencies' => [],
        ],
    ],
];
