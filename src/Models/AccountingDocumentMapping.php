<?php

namespace Jtech\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Jtech\Accounting\Enums\AccountingModule;
use Jtech\Accounting\Models\Concerns\BelongsToAccountingContext;

class AccountingDocumentMapping extends Model
{
    use BelongsToAccountingContext;

    protected $table = 'accounting_document_mappings';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'module',
        'document_type',
        'debit_account_id',
        'credit_account_id',
        'is_enabled',
    ];

    protected $casts = [
        'module' => AccountingModule::class,
        'is_enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'debit_account_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'credit_account_id');
    }
}