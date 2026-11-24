<?php

namespace Abdiaziizaweis\Accounting\Services;

use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;
use Abdiaziizaweis\Accounting\Contracts\AccountingDocumentInterface;
use Abdiaziizaweis\Accounting\Database\Migration\TenancySchemaHelper;
use Abdiaziizaweis\Accounting\Models\AccountingTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Host document → accounting transaction.
 * Posts only when the host saved a balanced operation mapping.
 */
class AccountingDocumentBridge
{
    public function __construct(
        protected AccountingTransactionService $transactions,
    ) {}

    public function record(AccountingDocumentInterface $document): void
    {
        $this->safe(function () use ($document) {
            $context = app(AccountingContextInterface::class);
            $branchId = $context->getBranchId();
            if (TenancySchemaHelper::usesBranchId() && ($branchId === null || $branchId < 1)) {
                return;
            }

            $payload = $document->getAccountingPayload();
            $amount = round((float) ($payload['amount'] ?? 0), 2);
            $operationKey = $document->getOperationKey();

            if ($amount <= 0.009) {
                $this->voidFor($document, false, 'Zero amount document');

                return;
            }

            $this->transactions->recordTransaction($document, TenancySchemaHelper::filterTenantAttributes([
                'organization_id' => $context->getOrganizationId(),
                'branch_id' => $branchId,
                'owner_user_id' => $this->ownerUserId($document, $context),
                'type' => $operationKey,
                'amount' => $amount,
                'tax_amount' => (float) ($payload['tax_amount'] ?? 0),
                'discount_amount' => (float) ($payload['discount_amount'] ?? 0),
                'payload' => $payload,
                'transaction_date' => $document->getDate()->toDateString(),
                'description' => $document->getDescription(),
                'reference' => $document->getReference(),
                'auto_map' => true,
            ]));
        }, 'record:'.$document->getOperationKey(), $document);
    }

    public function voidFor(AccountingDocumentInterface $document, bool $strict = false, ?string $reason = null): void
    {
        $execute = function () use ($document, $reason): void {
            $rows = AccountingTransaction::withoutGlobalScopes()
                ->active()
                ->where('transactionable_type', $document->getMorphClass())
                ->where('transactionable_id', $document->getDocumentId())
                ->where('type', $document->getOperationKey())
                ->get();

            foreach ($rows as $tx) {
                $this->transactions->voidTransaction(
                    $tx,
                    $reason ?? 'Operational document removed/voided',
                    null
                );
            }
        };

        if ($strict) {
            $execute();
        } else {
            $this->safe($execute, 'voidFor:'.$document->getOperationKey(), $document);
        }
    }

    protected function ownerUserId(AccountingDocumentInterface $document, AccountingContextInterface $context): ?int
    {
        $owner = $document->getOwnerUserId();
        if ($owner !== null) {
            return (int) $owner;
        }

        $userId = $context->getUserId();

        return $userId > 0 ? $userId : null;
    }

    protected function safe(callable $fn, string $label, AccountingDocumentInterface|Model $subject): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('AccountingDocumentBridge failed', [
                'label' => $label,
                'subject' => $subject instanceof Model ? $subject::class : get_class($subject),
                'id' => $subject instanceof AccountingDocumentInterface
                    ? $subject->getDocumentId()
                    : $subject->getKey(),
                'message' => $e->getMessage(),
            ]);
        }
    }
}
