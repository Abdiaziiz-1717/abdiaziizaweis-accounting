<?php

namespace Jtech\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Jtech\Accounting\Models\Concerns\BelongsToAccountingContext;

class AccountingExpenseCategoryMapping extends Model
{
    use BelongsToAccountingContext;

    protected $table = 'accounting_expense_category_mappings';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'expense_category_id',
        'debit_account_id',
        'credit_account_id',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function expenseCategory(): BelongsTo
    {
        $model = config('accounting.models.expense_category', \App\Models\ExpenseCategory::class);

        return $this->belongsTo($model, 'expense_category_id');
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