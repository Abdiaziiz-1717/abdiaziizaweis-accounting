<?php

namespace Abdiaziizaweis\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Abdiaziizaweis\Accounting\Enums\AccountingTransactionStatus;
use Abdiaziizaweis\Accounting\Models\Concerns\BelongsToAccountingContext;
use LogicException;

class AccountingTransaction extends Model
{
    use BelongsToAccountingContext;

    protected $table = 'accounting_transactions';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'owner_user_id',
        'transactionable_type',
        'transactionable_id',
        'type',
        'status',
        'amount',
        'tax_amount',
        'discount_amount',
        'transaction_date',
        'description',
        'reference',
        'journal_entry_id',
        'voided_at',
        'voided_by_user_id',
        'void_reason',
        'superseded_by_transaction_id',
    ];

    protected $casts = [
        'status' => AccountingTransactionStatus::class,
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'transaction_date' => 'date',
        'voided_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LogicException(
                'Accounting transactions cannot be deleted. Void them instead.'
            );
        });
    }

    public function owner(): BelongsTo
    {
        $model = config('accounting.models.user', \App\Models\User::class);

        return $this->belongsTo($model, 'owner_user_id');
    }

    public function voidedBy(): BelongsTo
    {
        $model = config('accounting.models.user', \App\Models\User::class);

        return $this->belongsTo($model, 'voided_by_user_id');
    }

    public function transactionable(): MorphTo
    {
        return $this->morphTo();
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(AccountingJournalEntry::class, 'journal_entry_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_transaction_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            AccountingTransactionStatus::Mapped->value,
            AccountingTransactionStatus::NotMapped->value,
        ]);
    }

    public function scopeVoided(Builder $query): Builder
    {
        return $query->where('status', AccountingTransactionStatus::Voided->value);
    }

    public function isVoided(): bool
    {
        return $this->status === AccountingTransactionStatus::Voided;
    }

    public function isActive(): bool
    {
        return ! $this->isVoided();
    }
}