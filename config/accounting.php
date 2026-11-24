<?php

use Abdiaziizaweis\Accounting\Auth\DefaultAccountingAuth;
use Abdiaziizaweis\Accounting\Http\Middleware\ResolveAccountingContext;

return [
    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    |
    | single_tenant | multi_tenant | multi_branch | multi_tenant_multi_branch
    |
    | Start with single_tenant for simple apps.
    | Change to multi_branch or multi_tenant_multi_branch only when you need
    | multiple organizations or branches.
    */
    'tenancy_mode' => 'single_tenant',

    'currency' => 'USD',

    /* First month of fiscal year (1–12). */
    'fy_start' => 1,

    'route_prefix' => 'api/v1/accounting',

    /*
    | Set to whatever auth middleware your app uses.
    | Examples:
    | 'auth' — Laravel session auth (default)
    | 'auth:sanctum' — Laravel Sanctum
    | 'auth:api' — Laravel Passport
    | 'auth:jwt' — JWT auth
    | Any custom middleware your app registers
    */
    'middleware' => [
        'auth',
        ResolveAccountingContext::class,
    ],

    'auth' => [
        'guard' => DefaultAccountingAuth::class,
    ],

    'audit' => [
        'driver' => \Abdiaziizaweis\Accounting\Audit\NullAccountingAudit::class,
        // Options:
        // NullAccountingAudit::class — no audit logging (default)
        // SpatieAccountingAudit::class — log via Spatie activitylog
        // Or provide your own class implementing AccountingAuditInterface
    ],

    /*
    |--------------------------------------------------------------------------
    | Host model class map (MAAMUUS-style defaults)
    |--------------------------------------------------------------------------
    */
    'models' => [
        'user' => \App\Models\User::class,
        'organization' => \App\Models\Organization::class,
        'branch' => \App\Models\Branch::class,
    ],

    'tables' => [
        'organizations' => 'organizations',
        'branches' => 'branches',
        'users' => 'users',
    ],
];