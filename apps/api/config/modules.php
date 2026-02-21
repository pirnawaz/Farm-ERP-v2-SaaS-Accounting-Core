<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Module hard dependencies (module_key => list of required module keys)
    | Enabling a module auto-enables all dependencies (transitive).
    | Disabling is blocked if any enabled module depends on it.
    |--------------------------------------------------------------------------
    */
    'dependencies' => [
        'projects_crop_cycles' => ['land'],
        'settlements' => ['projects_crop_cycles'],
        'inventory' => ['projects_crop_cycles'],
        'labour' => ['projects_crop_cycles'],
        'machinery' => ['projects_crop_cycles'],
        'crop_ops' => ['projects_crop_cycles', 'inventory', 'labour'],
        'treasury_advances' => ['treasury_payments'],
        'ar_sales' => [],
        'accounting_core' => [],
        'land' => [],
        'land_leases' => ['land'],
        'treasury_payments' => [],
        'reports' => [],
        'loans' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Module tier: CORE (cannot disable), CORE_ADJUNCT, OPTIONAL
    | Used for display and validation. Core modules cannot be disabled.
    |--------------------------------------------------------------------------
    */
    'tiers' => [
        'accounting_core' => 'CORE',
        'projects_crop_cycles' => 'CORE',
        'treasury_payments' => 'CORE',
        'reports' => 'CORE',
        'land' => 'CORE_ADJUNCT',
        'land_leases' => 'OPTIONAL',
        'treasury_advances' => 'OPTIONAL',
        'ar_sales' => 'OPTIONAL',
        'settlements' => 'OPTIONAL',
        'inventory' => 'OPTIONAL',
        'labour' => 'OPTIONAL',
        'machinery' => 'OPTIONAL',
        'loans' => 'OPTIONAL',
        'crop_ops' => 'OPTIONAL',
    ],
];
