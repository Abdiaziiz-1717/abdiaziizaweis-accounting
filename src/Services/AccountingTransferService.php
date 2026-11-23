<?php

namespace Abdiaziizaweis\Accounting\Services;

use Abdiaziizaweis\Accounting\Contracts\AccountingAuditInterface;
use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;

use Abdiaziizaweis\Accounting\Enums\AccountingJournalSource;
use Abdiaziizaweis\Accounting\Models\AccountingTransfer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingTransferService
{
    public function __construct(
        protected AccountingJournalService $journals,
        protected AccountingPeriodGuard $periodGuard,
    ) {}

    /**
     * Create a transfer between two accounts and a 2-line journal entry.
     * Debit to_account (deposit_to), credit from_account (payment_account).
     *
     * @param  array{
     *     from_account_id: int,
     *     to_account_id: int,
     *     amount: float|string,
     *     transfer_date: string|\DateTimeInterface,
     *     notes?: ?string,
     *     owner_user_id?: ?int,
     *     organization_id?: ?int,
     *     branch_id?: ?int,
     * }  $data
     */
    public function create(array $data): AccountingTransfer
    {
        if ((int) $data['from_account_id'] === (int) $data['to_account_id']) {
            throw ValidationException::withMessages([
                'to_account_id' => ['Transfer accounts must be different.'],
            ]);
        }

        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Transfer amount must be greater than zero.'],
            ]);
        }

        $this->periodGuard->assertOpen($data['transfer_date'] ?? now());

        return DB::transaction(function () use ($data, $amount) {
            $orgId = (int) ($data['organization_id'] ?? app(AccountingContextInterface::class)->getOrganizationId());
            $branchId = (int) ($data['branch_id'] ?? app(AccountingContextInterface::class)->getBranchId());

            $transfer = AccountingTransfer::withoutGlobalScopes()->create([
                'organization_id' => $orgId,
                'branch_id' => $branchId,
                'owner_user_id' => $data['owner_user_id'] ?? null,
                'from_account_id' => (int) $data['from_account_id'],
                'to_account_id' => (int) $data['to_account_id'],
                'amount' => $amount,
                'transfer_date' => $data['transfer_date'],
                'notes' => $data['notes'] ?? null,
            ]);

            $lines = $this->journals->twoLineMapping(
                (int) $data['to_account_id'],
                (int) $data['from_account_id'],
                $amount,
                $data['notes'] ?? 'Transfer'
            );

            $entry = $this->journals->createFromMapping([
                'organization_id' => $orgId,
                'branch_id' => $branchId,
                'owner_user_id' => $data['owner_user_id'] ?? null,
                'journal_date' => $data['transfer_date'],
                'description' => $data['notes'] ?? 'Account transfer',
                'reference' => 'TR-'.$transfer->id,
                'source' => AccountingJournalSource::Transfer,
                'accounting_transfer_id' => $transfer->id,
            ], $lines);

            $transfer->update(['journal_entry_id' => $entry->id]);

            $transfer = $transfer->fresh(['fromAccount', 'toAccount', 'journalEntry.lines']);

            app(AccountingAuditInterface::class)->log(
                'transfer.created',
                'AccountingTransfer',
                (int) $transfer->id,
                [
                    'amount' => (float) $transfer->amount,
                    'from_account_id' => (int) $transfer->from_account_id,
                    'to_account_id' => (int) $transfer->to_account_id,
                    'journal_entry_id' => $transfer->journal_entry_id ? (int) $transfer->journal_entry_id : null,
                ],
                Auth::id() ? (int) Auth::id() : null,
            );

            return $transfer;
        });
    }

    public function delete(AccountingTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer) {
            $auditContext = [
                'amount' => (float) $transfer->amount,
                'from_account_id' => (int) $transfer->from_account_id,
                'to_account_id' => (int) $transfer->to_account_id,
                'journal_entry_id' => $transfer->journal_entry_id ? (int) $transfer->journal_entry_id : null,
            ];
            $transferId = (int) $transfer->id;

            if ($transfer->journal_entry_id && $transfer->journalEntry) {
                $this->journals->deleteEntryIfNotReconciled($transfer->journalEntry);
            }
            $transfer->delete();

            app(AccountingAuditInterface::class)->log(
                'transfer.deleted',
                'AccountingTransfer',
                $transferId,
                $auditContext,
                Auth::id() ? (int) Auth::id() : null,
            );
        });
    }
}
