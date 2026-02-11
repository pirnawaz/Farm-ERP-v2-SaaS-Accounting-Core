<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Slow SQL monitoring
    |--------------------------------------------------------------------------
    */

    'slow_sql' => [
        'enabled' => env('PERFORMANCE_SLOW_SQL_ENABLED', false),
        'threshold_ms' => (int) env('PERFORMANCE_SLOW_SQL_THRESHOLD_MS', 1000),
        'bindings_max_length' => (int) env('PERFORMANCE_BINDINGS_MAX_LENGTH', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Slow request monitoring
    |--------------------------------------------------------------------------
    */

    'slow_request' => [
        'enabled' => env('PERFORMANCE_SLOW_REQUEST_ENABLED', false),
        'threshold_ms' => (int) env('PERFORMANCE_SLOW_REQUEST_THRESHOLD_MS', 3000),
        'query_params_max_length' => (int) env('PERFORMANCE_QUERY_PARAMS_MAX_LENGTH', 200),
    ],

];
