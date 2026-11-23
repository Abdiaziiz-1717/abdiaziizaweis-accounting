<?php

namespace Abdiaziizaweis\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Abdiaziizaweis\Accounting\Enums\AccountingJournalEntryStatus;
use Abdiaziizaweis\Accounting\Enums\AccountingJournalSource;
use Abdiaziizaweis\Accounting\Models\Concerns\BelongsToAccountingContext;
use LogicException;

class AccountingJournalEntry extends Model
{
    use BelongsToAccountingContext;

    protected $table = 'accounting_journal_entries';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'owner_user_id',
        'number',
        'journal_date',
        'description',
        'reference',
        'source',
        'amount',
        'status',
        'voided_at',
        'voided_by_user_id',
        'void_reason',
        'accounting_transfer_id',
    ];

    protected $casts = [
        'source' => AccountingJournalSource::class,
        'status' => AccountingJournalEntryStatus::class,
        'amount' => 'decimal:2',
        'journal_date' => 'date',
        'voided_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $entry) {
            if (! ($entry->forceDeletingJournal ?? false)) {
                throw new LogicException(
                    'Accounting journal entries cannot be deleted. Void them instead.'
                );
            }
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

    public function lines(): HasMany
    {
        return $this->hasMany(AccountingJournalEntryLine::class, 'journal_entry_id');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(AccountingTransfer::class, 'accounting_transfer_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AccountingJournalEntryStatus::Active->value);
    }

    public function scopeVoided(Builder $query): Builder
    {
        return $query->where('status', AccountingJournalEntryStatus::Voided->value);
    }

    public function isVoided(): bool
    {
        return $this->status === AccountingJournalEntryStatus::Voided;
    }

    public function isActive(): bool
    {
        return $this->status === AccountingJournalEntryStatus::Active
            || $this->status === null;
    }
}