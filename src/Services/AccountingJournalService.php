<?php

namespace Abdiaziizaweis\Accounting\Services;

use Abdiaziizaweis\Accounting\Contracts\AccountingAuditInterface;
use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;
use Abdiaziizaweis\Accounting\Database\Migration\TenancySchemaHelper;

use Abdiaziizaweis\Accounting\Enums\AccountingJournalEntryStatus;
use Abdiaziizaweis\Accounting\Enums\AccountingJournalSource;
use Abdiaziizaweis\Accounting\Models\AccountingJournalEntry;
use Abdiaziizaweis\Accounting\Models\AccountingJournalEntryLine;
use Abdiaziizaweis\Accounting\Models\AccountingTransaction;
use Abdiaziizaweis\Accounting\Models\AccountingTransfer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AccountingJournalService
{
    public function __construct(
        protected AccountingPeriodGuard $periodGuard,
    ) {}

    /**
     * @param  array{
     *     journal_date: string|\DateTimeInterface,
     *     description?: ?string,
     *     reference?: ?string,
     *     source?: AccountingJournalSource|string,
     *     accounting_transfer_id?: ?int,
     *     owner_user_id?: ?int,
     *     organization_id?: ?int,
     *     branch_id?: ?int,
     *     number?: ?string,
     * }  $header
     * @param  list<array{account_id: int, debit?: float|string, credit?: float|string, memo?: ?string}>  $lines
     */
    public function createManualEntry(array $header, array $lines): AccountingJournalEntry
    {
        $this->periodGuard->assertOpen($header['journal_date'] ?? now());
        $header['source'] = $header['source'] ?? AccountingJournalSource::Manual;

        return $this->createFromMapping($header, $lines);
    }

    /**
     * Create a balanced journal entry header + lines.
     *
     * @param  array{
     *     journal_date: string|\DateTimeInterface,
     *     description?: ?string,
     *     reference?: ?string,
     *     source?: AccountingJournalSource|string,
     *     accounting_transfer_id?: ?int,
     *     owner_user_id?: ?int,
     *     organization_id?: ?int,
     *     branch_id?: ?int,
     *     number?: ?string,
     * }  $header
     * @param  list<array{account_id: int, debit?: float|string, credit?: float|string, memo?: ?string}>  $lines
     */
    public function createFromMapping(array $header, array $lines): AccountingJournalEntry
    {
        $this->periodGuard->assertOpen($header['journal_date'] ?? now());
        $this->assertBalanced($lines);

        return DB::transaction(function () use ($header, $lines) {
            $orgId = (int) ($header['organization_id'] ?? app(AccountingContextInterface::class)->getOrganizationId());
            $branchId = (int) ($header['branch_id'] ?? app(AccountingContextInterface::class)->getBranchId() ?? 0);

            if (TenancySchemaHelper::usesOrganizationId() && $orgId < 1) {
                throw new RuntimeException('Organization is required to create a journal entry.');
            }

            if (TenancySchemaHelper::usesBranchId() && $branchId < 1) {
                throw new RuntimeException('Branch is required to create a journal entry.');
            }

            $totalDebit = $this->sumSide($lines, 'debit');
            $source = $header['source'] ?? AccountingJournalSource::Mapping;
            if ($source instanceof AccountingJournalSource) {
                $source = $source->value;
            }

            $entry = AccountingJournalEntry::withoutGlobalScopes()->create(
                TenancySchemaHelper::filterTenantAttributes([
                    'organization_id' => $orgId,
                    'branch_id' => $branchId,
                    'owner_user_id' => $header['owner_user_id'] ?? null,
                    'number' => $header['number'] ?? $this->nextNumber($orgId, $branchId),
                    'journal_date' => $header['journal_date'],
                    'description' => $header['description'] ?? null,
                    'reference' => $header['reference'] ?? null,
                    'source' => $source,
                    'amount' => $totalDebit,
                    'status' => AccountingJournalEntryStatus::Active,
                    'accounting_transfer_id' => $header['accounting_transfer_id'] ?? null,
                ])
            );

            foreach ($lines as $line) {
                AccountingJournalEntryLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => (int) $line['account_id'],
                    'debit' => (float) ($line['debit'] ?? 0),
                    'credit' => (float) ($line['credit'] ?? 0),
                    'memo' => $line['memo'] ?? null,
                ]);
            }

            $entry = $entry->load('lines');

            app(AccountingAuditInterface::class)->log(
                'journal.created',
                'AccountingJournalEntry',
                (int) $entry->id,
                [
                    'journal_entry_id' => (int) $entry->id,
                    'amount' => (float) $entry->amount,
                    'source' => $source,
                ],
                Auth::id() ? (int) Auth::id() : null,
            );

            return $entry;
        });
    }

    /**
     * Permanently deactivate a JE (keep header + lines). Never hard-delete.
     */
    public function voidEntry(
        AccountingJournalEntry $entry,
        ?string $reason = null,
        ?int $userId = null
    ): AccountingJournalEntry {
        $this->periodGuard->assertOpen(now());

        return DB::transaction(function () use ($entry, $reason, $userId) {
            $entry = AccountingJournalEntry::withoutGlobalScopes()->findOrFail($entry->id);

            if ($entry->status === AccountingJournalEntryStatus::Voided) {
                return $entry;
            }

            $voidReason = $reason ?: 'Voided';

            $entry->forceFill([
                'status' => AccountingJournalEntryStatus::Voided,
                'voided_at' => now(),
                'voided_by_user_id' => $userId ?? Auth::id(),
                'void_reason' => $voidReason,
            ])->save();

            $entry = $entry->fresh(['lines']);

            app(AccountingAuditInterface::class)->log(
                'journal.voided',
                'AccountingJournalEntry',
                (int) $entry->id,
                [
                    'journal_entry_id' => (int) $entry->id,
                    'void_reason' => $voidReason,
                    'amount' => (float) $entry->amount,
                ],
                ($userId ?? Auth::id()) ? (int) ($userId ?? Auth::id()) : null,
            );

            return $entry;
        });
    }

    /**
     * @deprecated Use voidEntry — kept as alias for call sites mid-migration.
     */
    public function deleteEntry(AccountingJournalEntry $entry): void
    {
        $this->voidEntry($entry, 'Voided (legacy deleteEntry)');
    }

    /**
     * Transfer undo: void JE if present (lines kept for audit).
     */
    public function deleteEntryIfNotReconciled(?AccountingJournalEntry $entry): void
    {
        if (! $entry) {
            return;
        }

        $this->voidEntry($entry, 'Transfer removed');
    }

    /**
     * Remap: void old JE (keep rows) then create a replacement active JE.
     *
     * @param  array{
     *     journal_date: string|\DateTimeInterface,
     *     description?: ?string,
     *     reference?: ?string,
     *     source?: AccountingJournalSource|string,
     *     accounting_transfer_id?: ?int,
     *     owner_user_id?: ?int,
     *     organization_id?: ?int,
     *     branch_id?: ?int,
     *     number?: ?string,
     * }  $header
     * @param  list<array{account_id: int, debit?: float|string, credit?: float|string, memo?: ?string}>  $lines
     */
    public function reverseOrReplace(?AccountingJournalEntry $existing, array $header, array $lines): AccountingJournalEntry
    {
        return DB::transaction(function () use ($existing, $header, $lines) {
            if ($existing) {
                // Preserve transfer link on the replacement JE when callers omit it.
                if (! array_key_exists('accounting_transfer_id', $header)) {
                    $header['accounting_transfer_id'] = $existing->accounting_transfer_id;
                }

                if ($existing->status !== AccountingJournalEntryStatus::Voided) {
                    $this->voidEntry($existing, 'Remapped / replaced');
                }
            }

            $replacement = $this->createFromMapping($header, $lines);

            // Remap callers (tx map, manual JE edit) must not leave txs/transfers
            // pointing at the voided predecessor id.
            if ($existing) {
                AccountingTransaction::withoutGlobalScopes()
                    ->where('journal_entry_id', $existing->id)
                    ->update(['journal_entry_id' => $replacement->id]);

                AccountingTransfer::withoutGlobalScopes()
                    ->where('journal_entry_id', $existing->id)
                    ->update(['journal_entry_id' => $replacement->id]);

                app(AccountingAuditInterface::class)->log(
                    'journal.replaced',
                    'AccountingJournalEntry',
                    (int) $replacement->id,
                    [
                        'journal_entry_id' => (int) $replacement->id,
                        'prior_journal_entry_id' => (int) $existing->id,
                        'amount' => (float) $replacement->amount,
                    ],
                    Auth::id() ? (int) Auth::id() : null,
                );
            }

            return $replacement;
        });
    }

    /**
     * @param  list<array{account_id: int, debit?: float|string, credit?: float|string, memo?: ?string}>  $lines
     */
    public function assertBalanced(array $lines): void
    {
        if (count($lines) < 2) {
            throw ValidationException::withMessages([
                'lines' => ['A journal entry requires at least two lines.'],
            ]);
        }

        $debit = $this->sumSide($lines, 'debit');
        $credit = $this->sumSide($lines, 'credit');

        if (round($debit, 2) !== round($credit, 2)) {
            throw ValidationException::withMessages([
                'lines' => ["Journal entry is not balanced (debit {$debit} vs credit {$credit})."],
            ]);
        }

        if ($debit <= 0) {
            throw ValidationException::withMessages([
                'lines' => ['Journal entry amount must be greater than zero.'],
            ]);
        }

        foreach ($lines as $i => $line) {
            $d = (float) ($line['debit'] ?? 0);
            $c = (float) ($line['credit'] ?? 0);
            if (($d > 0 && $c > 0) || ($d <= 0 && $c <= 0)) {
                throw ValidationException::withMessages([
                    "lines.{$i}" => ['Each line must have either a debit or a credit (not both or neither).'],
                ]);
            }
            if (empty($line['account_id'])) {
                throw ValidationException::withMessages([
                    "lines.{$i}.account_id" => ['Account is required.'],
                ]);
            }
        }
    }

    /**
     * @return list<array{account_id: int, debit: float, credit: float, memo?: ?string}>
     */
    public function twoLineMapping(int $debitAccountId, int $creditAccountId, float $amount, ?string $memo = null): array
    {
        $amount = round($amount, 2);

        return [
            [
                'account_id' => $debitAccountId,
                'debit' => $amount,
                'credit' => 0,
                'memo' => $memo,
            ],
            [
                'account_id' => $creditAccountId,
                'debit' => 0,
                'credit' => $amount,
                'memo' => $memo,
            ],
        ];
    }

    public function nextNumber(int $organizationId, int $branchId): string
    {
        $prefix = 'JE-';
        $existing = AccountingJournalEntry::withoutGlobalScopes()
            ->when(TenancySchemaHelper::usesOrganizationId(), fn ($q) => $q->where('organization_id', $organizationId))
            ->when(TenancySchemaHelper::usesBranchId(), fn ($q) => $q->where('branch_id', $branchId))
            ->where('number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->pluck('number');

        $max = 0;
        foreach ($existing as $value) {
            if (! is_string($value) || ! str_starts_with($value, $prefix)) {
                continue;
            }
            $suffix = substr($value, strlen($prefix));
            if ($suffix !== '' && ctype_digit($suffix)) {
                $max = max($max, (int) $suffix);
            }
        }

        return $prefix.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    /**
     * @param  list<array{debit?: float|string, credit?: float|string}>  $lines
     */
    protected function sumSide(array $lines, string $side): float
    {
        $sum = 0.0;
        foreach ($lines as $line) {
            $sum += (float) ($line[$side] ?? 0);
        }

        return round($sum, 2);
    }
}
