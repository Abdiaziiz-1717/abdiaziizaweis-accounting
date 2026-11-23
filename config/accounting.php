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
    */
    'tenancy_mode' => 'multi_tenant_multi_branch',

    'currency' => 'USD',

    /* First month of fiscal year (1–12). */
    'fy_start' => 1,

    'route_prefix' => 'api/v1/accounting',

    'middleware' => [
        'auth:sanctum',
        ResolveAccountingContext::class,
    ],

    'features' => [
        'tax_splitting' => true,
        'discount_splitting' => true,
    ],

    'auth' => [
        'guard' => DefaultAccountingAuth::class,
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
        'payment_method' => \App\Models\PaymentMethod::class,
        'expense_category' => \App\Models\ExpenseCategory::class,
    ],

    'tables' => [
        'organizations' => 'organizations',
        'branches' => 'branches',
        'users' => 'users',
        'payment_methods' => 'payment_methods',
        'expense_categories' => 'expense_categories',
    ],
];