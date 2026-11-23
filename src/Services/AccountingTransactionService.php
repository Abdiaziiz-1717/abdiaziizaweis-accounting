<?php

namespace Abdiaziizaweis\Accounting\Services;

use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;

use Abdiaziizaweis\Accounting\Enums\AccountingJournalEntryStatus;
use Abdiaziizaweis\Accounting\Enums\AccountingJournalSource;
use Abdiaziizaweis\Accounting\Enums\AccountingModule;
use Abdiaziizaweis\Accounting\Enums\AccountingTransactionStatus;
use Abdiaziizaweis\Accounting\Contracts\AccountingDocumentInterface;
use Abdiaziizaweis\Accounting\Models\AccountingJournalEntry;
use Abdiaziizaweis\Accounting\Models\AccountingTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingTransactionService
{
    public function __construct(
        protected AccountingJournalService $journals,
        protected AccountingMappingResolver $resolver,
        protected AccountingPeriodGuard $periodGuard,
    ) {}

    /**
     * Record an accounting transaction for a source document.
     *
     * Economic edit (amount, date, tax, discount/coupon change on an existing
     * active tx for same morph+type): void old tx (+ JE) and create a new tx,
     * linking superseded_by.
     *
     * Non-economic (description/reference only): update in place.
     * Remap of accounts is handled separately (same tx id, void JE + new JE).
     *
     * @param  array{
     *     type: string,
     *     amount: float|string,
     *     transaction_date: string|\DateTimeInterface,
     *     tax_amount?: float|string,
     *     discount_amount?: float|string,
     *     description?: ?string,
     *     reference?: ?string,
     *     owner_user_id?: ?int,
     *     organization_id?: ?int,
     *     branch_id?: ?int,
     *     auto_map?: bool,
     *     module?: AccountingModule|string|null,
     *     document_type?: ?string,
     *     payment_method_id?: ?int,
     *     expense_category_id?: ?int,
     * }  $attributes
     */
    public function recordTransaction(?AccountingDocumentInterface $transactionable, array $attributes): AccountingTransaction
    {
        $this->periodGuard->assertOpen($attributes['transaction_date'] ?? now());

        return DB::transaction(function () use ($transactionable, $attributes) {
            $orgId = (int) ($attributes['organization_id'] ?? app(AccountingContextInterface::class)->getOrganizationId());
            $branchId = (int) ($attributes['branch_id'] ?? app(AccountingContextInterface::class)->getBranchId() ?? 0);

            $query = AccountingTransaction::withoutGlobalScopes()
                ->active()
                ->where('type', $attributes['type']);

            if ($branchId > 0) {
                $query->where('branch_id', $branchId);
            }

            if ($transactionable !== null) {
                $query->where('transactionable_type', $transactionable->getMorphClass())
                    ->where('transactionable_id', $transactionable->getDocumentId());
            } else {
                $query->whereNull('transactionable_type')->whereNull('transactionable_id');
            }

            /** @var AccountingTransaction|null $existing */
            $existing = $query->orderByDesc('id')->first();

            $newAmount = round((float) $attributes['amount'], 2);
            $newTax = round((float) ($attributes['tax_amount'] ?? 0), 2);
            $newDiscount = round((float) ($attributes['discount_amount'] ?? 0), 2);
            $newDate = $attributes['transaction_date'];

            if ($existing) {
                $amountChanged = abs((float) $existing->amount - $newAmount) >= 0.005;
                $taxChanged = abs((float) ($existing->tax_amount ?? 0) - $newTax) >= 0.005;
                $discountChanged = abs((float) ($existing->discount_amount ?? 0) - $newDiscount) >= 0.005;
                $dateChanged = $existing->transaction_date?->toDateString()
                    !== (is_string($newDate)
                        ? $newDate
                        : (method_exists($newDate, 'toDateString')
                            ? $newDate->toDateString()
                            : (string) $newDate));

                // Economic edit → void + new tx (source of truth).
                if ($amountChanged || $dateChanged || $taxChanged || $discountChanged) {
                    $payload = $this->buildPayload($orgId, $branchId, $transactionable, $attributes, $existing);
                    $payload['status'] = AccountingTransactionStatus::NotMapped;
                    $tx = AccountingTransaction::withoutGlobalScopes()->create($payload);

                    $this->voidTransaction(
                        $existing,
                        'Superseded by economic edit',
                        Auth::id(),
                        $tx
                    );

                    if ($attributes['auto_map'] ?? true) {
                        $this->tryAutoMap($tx, $attributes);
                    }

                    return $tx->fresh(['journalEntry.lines']);
                }

                // Non-economic: description / reference / owner only.
                $existing->fill([
                    'description' => $attributes['description'] ?? $existing->description,
                    'reference' => $attributes['reference'] ?? $existing->reference,
                    'owner_user_id' => $attributes['owner_user_id'] ?? $existing->owner_user_id,
                ]);
                $existing->save();
                $tx = $existing;

                if (($attributes['auto_map'] ?? true) && $tx->status === AccountingTransactionStatus::NotMapped) {
                    $this->tryAutoMap($tx, $attributes);
                }

                return $tx->fresh(['journalEntry.lines']);
            }

            $payload = $this->buildPayload($orgId, $branchId, $transactionable, $attributes, null);
            $payload['status'] = AccountingTransactionStatus::NotMapped;
            $tx = AccountingTransaction::withoutGlobalScopes()->create($payload);

            if ($attributes['auto_map'] ?? true) {
                $this->tryAutoMap($tx, $attributes);
            }

            return $tx->fresh(['journalEntry.lines']);
        });
    }

    /**
     * Void a transaction permanently (and void its JE). Never delete the row.
     */
    public function voidTransaction(
        AccountingTransaction $tx,
        ?string $reason = null,
        ?int $userId = null,
        ?AccountingTransaction $supersededBy = null
    ): AccountingTransaction {
        $this->periodGuard->assertOpen(now());

        return DB::transaction(function () use ($tx, $reason, $userId, $supersededBy) {
            $tx = AccountingTransaction::withoutGlobalScopes()->findOrFail($tx->id);

            if ($tx->status === AccountingTransactionStatus::Voided) {
                if ($supersededBy && ! $tx->superseded_by_transaction_id) {
                    $tx->forceFill([
                        'superseded_by_transaction_id' => $supersededBy->id,
                    ])->save();
                }

                return $tx->fresh();
            }

            if ($tx->journal_entry_id) {
                $entry = AccountingJournalEntry::withoutGlobalScopes()->find($tx->journal_entry_id);
                if ($entry && $entry->status !== AccountingJournalEntryStatus::Voided) {
                    $this->journals->voidEntry(
                        $entry,
                        $reason ?: 'Transaction voided',
                        $userId
                    );
                }
            }

            $tx->forceFill([
                'status' => AccountingTransactionStatus::Voided,
                'voided_at' => now(),
                'voided_by_user_id' => $userId ?? Auth::id(),
                'void_reason' => $reason ?: 'Voided',
                'superseded_by_transaction_id' => $supersededBy?->id ?? $tx->superseded_by_transaction_id,
            ])->save();

            return $tx->fresh(['journalEntry.lines']);
        });
    }

    /**
     * Attempt to auto-map a transaction using document / payment / expense mappings.
     *
     * @param  array{
     *     module?: AccountingModule|string|null,
     *     document_type?: ?string,
     *     payment_method_id?: ?int,
     *     expense_category_id?: ?int,
     *     debit_account_id?: ?int,
     *     credit_account_id?: ?int,
     * }  $hints
     */
    public function tryAutoMap(AccountingTransaction $tx, array $hints = []): AccountingTransaction
    {
        if ($tx->isVoided()) {
            return $tx;
        }

        $hasExplicitAccounts = ! empty($hints['debit_account_id']) && ! empty($hints['credit_account_id']);
        if (! $hasExplicitAccounts
            && in_array((string) $tx->type, [
                'hotel_invoice',
                'restaurant_invoice',
                'event_invoice',
                'purchase_invoice',
                'saas_invoice',
            ], true)
            && app(AccountingDocumentBridge::class)->tryRemapInvoiceSplit($tx)
        ) {
            return $tx->fresh(['journalEntry.lines']);
        }

        $module = $hints['module'] ?? null;
        $documentType = $hints['document_type'] ?? null;
        if ((! $module || ! $documentType) && ($inferred = $this->inferModuleAndDocumentType((string) $tx->type))) {
            $module = $module ?: $inferred['module'];
            $documentType = $documentType ?: $inferred['document_type'];
        }

        $debitId = isset($hints['debit_account_id']) ? (int) $hints['debit_account_id'] : null;
        $creditId = isset($hints['credit_account_id']) ? (int) $hints['credit_account_id'] : null;

        if (! $debitId || ! $creditId) {
            $resolved = $this->resolver->resolve(
                (int) $tx->organization_id,
                (int) $tx->branch_id,
                $module,
                $documentType,
                isset($hints['payment_method_id']) ? (int) $hints['payment_method_id'] : null,
                isset($hints['expense_category_id']) ? (int) $hints['expense_category_id'] : null,
                $hints['payment_side'] ?? 'sales',
            );
            if ($resolved) {
                $debitId = $resolved['debit_account_id'];
                $creditId = $resolved['credit_account_id'];
            }
        }

        if (! $debitId || ! $creditId) {
            return $tx;
        }

        return $this->mapWithAccounts($tx, $debitId, $creditId);
    }

    /**
     * @param  list<int>  $ids
     * @return array{mapped: int, failed: list<array{id: int, message: string}>}
     */
    public function bulkMap(array $ids, array $hints = []): array
    {
        $mapped = 0;
        $failed = [];

        foreach ($ids as $id) {
            try {
                $tx = AccountingTransaction::query()->active()->findOrFail($id);
                $this->tryAutoMap($tx, $hints);
                if ($tx->fresh()->status === AccountingTransactionStatus::Mapped) {
                    $mapped++;
                } else {
                    $failed[] = ['id' => (int) $id, 'message' => 'No enabled mapping found.'];
                }
            } catch (\Throwable $e) {
                $failed[] = ['id' => (int) $id, 'message' => $e->getMessage()];
            }
        }

        return ['mapped' => $mapped, 'failed' => $failed];
    }

    /**
     * Unmap: void linked JE, clear link, set not_mapped (tx stays active).
     */
    public function unmap(AccountingTransaction $tx): AccountingTransaction
    {
        return DB::transaction(function () use ($tx) {
            if ($tx->isVoided()) {
                throw ValidationException::withMessages([
                    'transaction' => ['Cannot unmap a voided transaction.'],
                ]);
            }

            $this->detachJournalByVoid($tx, 'Unmapped');
            $tx->status = AccountingTransactionStatus::NotMapped;
            $tx->journal_entry_id = null;
            $tx->save();

            return $tx->fresh();
        });
    }

    /**
     * @deprecated Use detachJournalByVoid / voidTransaction.
     */
    public function wipeConvert(AccountingTransaction $tx): void
    {
        $this->detachJournalByVoid($tx, 'Remap wipe');
        $tx->journal_entry_id = null;
        $tx->save();
    }

    public function mapWithAccounts(
        AccountingTransaction $tx,
        int $debitAccountId,
        int $creditAccountId
    ): AccountingTransaction {
        return DB::transaction(function () use ($tx, $debitAccountId, $creditAccountId) {
            if ($tx->isVoided()) {
                throw ValidationException::withMessages([
                    'transaction' => ['Cannot map a voided transaction.'],
                ]);
            }

            $existing = $this->activeJournalFor($tx);

            $lines = $this->journals->twoLineMapping(
                $debitAccountId,
                $creditAccountId,
                (float) $tx->amount,
                $tx->description
            );

            $entry = $this->journals->reverseOrReplace($existing, [
                'organization_id' => $tx->organization_id,
                'branch_id' => $tx->branch_id,
                'owner_user_id' => $tx->owner_user_id,
                'journal_date' => $tx->transaction_date,
                'description' => $tx->description,
                'reference' => $tx->reference,
                'source' => AccountingJournalSource::Mapping,
            ], $lines);

            $tx->journal_entry_id = $entry->id;
            $tx->status = AccountingTransactionStatus::Mapped;
            $tx->save();

            return $tx->fresh(['journalEntry.lines']);
        });
    }

    /**
     * @param  list<array{account_id: int, debit?: float|string, credit?: float|string, memo?: ?string}>  $lines
     */
    public function mapWithLines(AccountingTransaction $tx, array $lines): AccountingTransaction
    {
        return DB::transaction(function () use ($tx, $lines) {
            if ($tx->isVoided()) {
                throw ValidationException::withMessages([
                    'transaction' => ['Cannot map a voided transaction.'],
                ]);
            }

            $existing = $this->activeJournalFor($tx);

            $entry = $this->journals->reverseOrReplace($existing, [
                'organization_id' => $tx->organization_id,
                'branch_id' => $tx->branch_id,
                'owner_user_id' => $tx->owner_user_id,
                'journal_date' => $tx->transaction_date,
                'description' => $tx->description,
                'reference' => $tx->reference,
                'source' => AccountingJournalSource::Mapping,
            ], $lines);

            $tx->journal_entry_id = $entry->id;
            $tx->status = AccountingTransactionStatus::Mapped;
            $tx->save();

            return $tx->fresh(['journalEntry.lines']);
        });
    }

    protected function detachJournalByVoid(AccountingTransaction $tx, string $reason): void
    {
        if (! $tx->journal_entry_id) {
            return;
        }

        $entry = AccountingJournalEntry::withoutGlobalScopes()->find($tx->journal_entry_id);
        if ($entry && $entry->status !== AccountingJournalEntryStatus::Voided) {
            $this->journals->voidEntry($entry, $reason);
        }
    }

    protected function activeJournalFor(AccountingTransaction $tx): ?AccountingJournalEntry
    {
        if (! $tx->journal_entry_id) {
            return null;
        }

        $existing = AccountingJournalEntry::withoutGlobalScopes()
            ->with('lines')
            ->find($tx->journal_entry_id);

        if ($existing && $existing->status === AccountingJournalEntryStatus::Voided) {
            return null;
        }

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function buildPayload(
        int $orgId,
        int $branchId,
        ?AccountingDocumentInterface $transactionable,
        array $attributes,
        ?AccountingTransaction $existing
    ): array {
        $morphClass = null;
        $morphId = null;
        if ($transactionable !== null) {
            $morphClass = $transactionable->getMorphClass();
            $morphId = $transactionable->getDocumentId();
        }

        return [
            'organization_id' => $orgId,
            'branch_id' => $branchId > 0 ? $branchId : ($existing?->branch_id),
            'owner_user_id' => $attributes['owner_user_id'] ?? $existing?->owner_user_id,
            'transactionable_type' => $morphClass,
            'transactionable_id' => $morphId,
            'type' => $attributes['type'],
            'amount' => round((float) $attributes['amount'], 2),
            'tax_amount' => round((float) ($attributes['tax_amount'] ?? 0), 2),
            'discount_amount' => round((float) ($attributes['discount_amount'] ?? 0), 2),
            'transaction_date' => $attributes['transaction_date'],
            'description' => $attributes['description'] ?? null,
            'reference' => $attributes['reference'] ?? null,
        ];
    }

    /**
     * @return array{module: string, document_type: string}|null
     */
    public function inferModuleAndDocumentType(string $type): ?array
    {
        $map = [
            'hotel_invoice' => ['module' => AccountingModule::Hotel->value, 'document_type' => 'invoice'],
            'hotel_payment' => ['module' => AccountingModule::Hotel->value, 'document_type' => 'payment'],
            'restaurant_invoice' => ['module' => AccountingModule::Restaurant->value, 'document_type' => 'invoice'],
            'restaurant_payment' => ['module' => AccountingModule::Restaurant->value, 'document_type' => 'payment'],
            'event_invoice' => ['module' => AccountingModule::Events->value, 'document_type' => 'invoice'],
            'event_payment' => ['module' => AccountingModule::Events->value, 'document_type' => 'payment'],
            'purchase_invoice' => ['module' => AccountingModule::Purchase->value, 'document_type' => 'invoice'],
            'purchase_payment' => ['module' => AccountingModule::Purchase->value, 'document_type' => 'payment'],
            'expense' => ['module' => AccountingModule::Expenses->value, 'document_type' => 'expense'],
            'customer_deposit' => ['module' => AccountingModule::Crm->value, 'document_type' => 'deposit'],
            'customer_deposit_refund' => ['module' => AccountingModule::Crm->value, 'document_type' => 'deposit_refund'],
            'payroll' => ['module' => AccountingModule::Payroll->value, 'document_type' => 'payroll'],
            'inventory_transfer_out' => ['module' => AccountingModule::Inventory->value, 'document_type' => 'transfer_out'],
            'inventory_transfer_in' => ['module' => AccountingModule::Inventory->value, 'document_type' => 'transfer_in'],
            'inventory_purchase_return' => ['module' => AccountingModule::Inventory->value, 'document_type' => 'purchase_return'],
            'inventory_purchase_return_void' => ['module' => AccountingModule::Inventory->value, 'document_type' => 'purchase_return_void'],
            'saas_invoice' => ['module' => AccountingModule::Saas->value, 'document_type' => 'invoice'],
            'saas_payment' => ['module' => AccountingModule::Saas->value, 'document_type' => 'payment'],
        ];

        return $map[$type] ?? null;
    }
}
