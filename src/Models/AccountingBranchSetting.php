<?php

namespace Abdiaziizaweis\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Abdiaziizaweis\Accounting\Models\Concerns\BelongsToAccountingContext;

class AccountingBranchSetting extends Model
{
    use BelongsToAccountingContext;

    protected $table = 'accounting_branch_settings';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'first_month_financial_year',
        'accounting_method',
        'close_the_books',
        'closing_date',
        'enable_account_numbers',
    ];

    protected $casts = [
        'first_month_financial_year' => 'integer',
        'close_the_books' => 'boolean',
        'closing_date' => 'date',
        'enable_account_numbers' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}