<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Plan → allowed module keys
    | plan_key => list of module keys that can be enabled for that plan.
    | Null/empty plan_key means no restriction (all non-core optional modules allowed).
    |--------------------------------------------------------------------------
    */
    'plans' => [
        'starter' => [
            'accounting_core',
            'projects_crop_cycles',
            'land',
            'treasury_payments',
            'reports',
        ],
        'growth' => [
            'accounting_core',
            'projects_crop_cycles',
            'land',
            'treasury_payments',
            'treasury_advances',
            'ar_sales',
            'settlements',
            'reports',
            'inventory',
            'labour',
        ],
        'enterprise' => [
            'accounting_core',
            'projects_crop_cycles',
            'land',
            'land_leases',
            'treasury_payments',
            'treasury_advances',
            'ar_sales',
            'settlements',
            'reports',
            'inventory',
            'labour',
            'crop_ops',
            'machinery',
            'loans',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default plan when plan_key is null (all optional modules allowed)
    |--------------------------------------------------------------------------
    */
    'default_allow_all' => true,
];
