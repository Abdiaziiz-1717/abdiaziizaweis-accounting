<?php

namespace Abdiaziizaweis\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Abdiaziizaweis\Accounting\Models\Concerns\BelongsToAccountingContext;

class AccountingPaymentMethodMappingSide extends Model
{
    use BelongsToAccountingContext;

    protected $table = 'accounting_payment_method_mapping_sides';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'payment_method_mapping_id',
        'module',
        'debit_account_id',
        'credit_account_id',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function paymentMethodMapping(): BelongsTo
    {
        return $this->belongsTo(AccountingPaymentMethodMapping::class, 'payment_method_mapping_id');
    }

    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'debit_account_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'credit_account_id');
    }
}