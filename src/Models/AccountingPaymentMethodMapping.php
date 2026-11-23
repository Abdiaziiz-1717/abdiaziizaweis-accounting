<?php

namespace Abdiaziizaweis\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Abdiaziizaweis\Accounting\Models\Concerns\BelongsToAccountingContext;

class AccountingPaymentMethodMapping extends Model
{
    use BelongsToAccountingContext;

    protected $table = 'accounting_payment_method_mappings';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'payment_method_id',
        'sales_debit_account_id',
        'sales_credit_account_id',
        'purchase_debit_account_id',
        'purchase_credit_account_id',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function paymentMethod(): BelongsTo
    {
        $model = config('accounting.models.payment_method', \App\Models\PaymentMethod::class);

        return $this->belongsTo($model, 'payment_method_id');
    }

    public function salesDebitAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'sales_debit_account_id');
    }

    public function salesCreditAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'sales_credit_account_id');
    }

    public function purchaseDebitAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'purchase_debit_account_id');
    }

    public function purchaseCreditAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'purchase_credit_account_id');
    }
}