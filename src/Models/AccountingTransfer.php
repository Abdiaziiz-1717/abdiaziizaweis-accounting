<?php

namespace Abdiaziizaweis\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Abdiaziizaweis\Accounting\Models\Concerns\BelongsToAccountingContext;

class AccountingTransfer extends Model
{
    use BelongsToAccountingContext;
    use SoftDeletes;

    protected $table = 'accounting_transfers';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'owner_user_id',
        'from_account_id',
        'to_account_id',
        'amount',
        'transfer_date',
        'notes',
        'journal_entry_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transfer_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        $model = config('accounting.models.user', \App\Models\User::class);

        return $this->belongsTo($model, 'owner_user_id');
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'to_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(AccountingJournalEntry::class, 'journal_entry_id');
    }
}