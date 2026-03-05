<?php

use Monolog\Level;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\NullHandler;
use Monolog\Formatter\JsonFormatter;

return [

    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => false,
    ],

    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => [env('APP_ENV') === 'production' ? 'single_json' : 'single'],
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        /** JSON-formatted log for production; machine-parsable. */
        'single_json' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'with' => ['stream' => storage_path('logs/laravel.log')],
            'formatter' => JsonFormatter::class,
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        /** Request-timing: one JSON event per request (request_id, latency_ms, etc.). */
        'request' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'with' => ['stream' => storage_path('logs/request.log')],
            'formatter' => JsonFormatter::class,
            'level' => 'debug',
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],
    ],

];
