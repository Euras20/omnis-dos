<?php

declare(strict_types=1);

/**
 * OMNIS application configuration.
 *
 * Architecture source: 04-folder-structure.md, 02-database.md, 03-api.md
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Money
    |--------------------------------------------------------------------------
    | All monetary amounts are stored and transferred as BIGINT minor units
    | (pesewas). 1 GHS = 100 pesewas.
    */
    'money' => [
        'scale' => 2,                    // decimal places
        'currency_default' => 'GHS',
        'minor_unit_suffix' => 'Minor',   // API field naming convention
    ],

    /*
    |--------------------------------------------------------------------------
    | Company / multi-tenancy
    |--------------------------------------------------------------------------
    */
    'company' => [
        'header' => 'X-Company-Id',       // not used in production (derived from auth)
        'context_key' => 'omnis.company',
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    */
    'idempotency' => [
        'header' => 'Idempotency-Key',
        'min_length' => 8,
        'ttl_hours' => 48,
    ],

    /*
    |--------------------------------------------------------------------------
    | Period lock
    |--------------------------------------------------------------------------
    */
    'period_lock' => [
        'enforced' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication (production uses external IdP)
    |--------------------------------------------------------------------------
    */
    'auth' => [
        'driver' => env('OMNIS_AUTH_DRIVER', 'cloudflare'), // cloudflare | entra | dev
        'dev_header' => 'X-Dev-User',
        'cloudflare_header' => 'Cf-Access-Jwt-Assertion',
    ],

    /*
    |--------------------------------------------------------------------------
    | API
    |--------------------------------------------------------------------------
    */
    'api' => [
        'prefix' => 'api/v1',
        'pagination_default' => 50,
        'pagination_max' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature flags (only flags already implied by the frontend)
    |--------------------------------------------------------------------------
    */
    'features' => [
        // Add only flags that exist in the review build HTML
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbox / worker
    |--------------------------------------------------------------------------
    | Source: 09-events-jobs-queues.md
    */
    'outbox' => [
        'queue' => env('OMNIS_OUTBOX_QUEUE', 'outbox'),
        'claim_limit' => (int) env('OMNIS_OUTBOX_CLAIM_LIMIT', 50),
        'max_attempts' => (int) env('OMNIS_OUTBOX_MAX_ATTEMPTS', 8),
        'stale_lock_minutes' => (int) env('OMNIS_OUTBOX_STALE_LOCK_MINUTES', 15),
        'retention_days' => (int) env('OMNIS_OUTBOX_RETENTION_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email side-effects
    |--------------------------------------------------------------------------
    | EMAIL_MODE: log (dev/CI) | smtp | ses
    */
    'email' => [
        'mode' => env('EMAIL_MODE', 'log'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Object storage for generated documents
    |--------------------------------------------------------------------------
    */
    'storage' => [
        'documents_disk' => env('OMNIS_DOCUMENTS_DISK', 'local'),
    ],

];
