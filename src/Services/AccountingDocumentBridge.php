<?php

namespace Abdiaziizaweis\Accounting\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;
use Abdiaziizaweis\Accounting\Contracts\AccountingDocumentInterface;
use Abdiaziizaweis\Accounting\Enums\AccountingModule;
use Abdiaziizaweis\Accounting\Models\AccountingBranchSetting;
use Abdiaziizaweis\Accounting\Models\AccountingTransaction;

/**
 * Map host documents (AccountingDocumentInterface) → AccountingTransactionService.
 * Failures are logged and never rethrown unless strict void (POS / bookings must not break).
 */
class AccountingDocumentBridge
{
    /** @var list<string> */
    protected const SALES_INVOICE_TX_TYPES = [
        'hotel_invoice',
        'restaurant_invoice',
        'event_invoice',
    ];

    public function __construct(
        protected AccountingTransactionService $transactions,
        protected AccountingMappingResolver $resolver,
    ) {}

    public function record(AccountingDocumentInterface $document): void
    {
        $this->safe(function () use ($document) {
            $context = app(AccountingContextInterface::class);
            $branchId = $context->getBranchId();
            if ($branchId !== null && $branchId < 1) {
                return;
            }

            $amount = round($document->getAmount(), 2);
            $type = $document->getDocumentType();

            if ($amount <= 0.009) {
                $this->voidFor($document, false, 'Zero amount document');

                return;
            }

            $inferred = $this->transactions->inferModuleAndDocumentType($type);
            $module = $document->getDocumentModule() ?: ($inferred['module'] ?? null);
            $mappingDocumentType = $inferred['document_type'] ?? null;

            $attrs = [
                'organization_id' => $context->getOrganizationId(),
                'branch_id' => $branchId,
                'owner_user_id' => $this->ownerUserId($document, $context),
                'type' => $type,
                'amount' => $amount,
                'tax_amount' => $document->getTaxAmount(),
                'discount_amount' => $document->getDiscountAmount(),
                'transaction_date' => $document->getDate()->toDateString(),
                'description' => $document->getDescription(),
                'reference' => $document->getReference(),
                'module' => $module,
                'document_type' => $mappingDocumentType,
            ];

            $invoiceSplitTypes = [...self::SALES_INVOICE_TX_TYPES, 'purchase_invoice'];
            $attrs['auto_map'] = ! in_array($type, $invoiceSplitTypes, true);

            if ($type === 'purchase_payment') {
                $attrs['payment_side'] = 'purchase';
            }

            if (method_exists($document, 'getPaymentMethodId')) {
                $paymentMethodId = $document->getPaymentMethodId();
                if ($paymentMethodId) {
                    $attrs['payment_method_id'] = (int) $paymentMethodId;
                }
            }

            if (method_exists($document, 'getExpenseCategoryId')) {
                $expenseCategoryId = $document->getExpenseCategoryId();
                if ($expenseCategoryId) {
                    $attrs['expense_category_id'] = (int) $expenseCategoryId;
                }
            }

            if ($type === 'inventory_adjustment') {
                $tx = $this->transactions->recordTransaction($document, $attrs + ['auto_map' => false]);
                $resolved = $this->resolver->resolveDocument(
                    AccountingModule::Inventory->value,
                    'adjustment',
                    $branchId
                );
                if (! $resolved) {
                    return;
                }
                $increase = $amount >= 0;
                $this->transactions->mapWithAccounts(
                    $tx,
                    $increase ? (int) $resolved['debit_account_id'] : (int) $resolved['credit_account_id'],
                    $increase ? (int) $resolved['credit_account_id'] : (int) $resolved['debit_account_id']
                );

                return;
            }

            $tx = $this->transactions->recordTransaction($document, $attrs);

            if (in_array($type, self::SALES_INVOICE_TX_TYPES, true)) {
                $this->applySalesInvoiceSplit(
                    $tx,
                    (string) $module,
                    (float) $document->getTaxAmount(),
                    (float) $document->getDiscountAmount()
                );
            } elseif ($type === 'purchase_invoice') {
                $this->applyPurchaseInvoiceSplit(
                    $tx,
                    (float) $document->getTaxAmount(),
                    (float) $document->getDiscountAmount()
                );
            }
        }, 'record:'.$document->getDocumentType(), $document);
    }

    /**
     * @param  bool  $strict  When true the method throws on accounting failure instead of silently logging.
     */
    public function voidFor(AccountingDocumentInterface $document, bool $strict = false, ?string $reason = null): void
    {
        $execute = function () use ($document, $reason): void {
            $type = $document->getDocumentType();
            $rows = AccountingTransaction::withoutGlobalScopes()
                ->active()
                ->where('transactionable_type', $document->getMorphClass())
                ->where('transactionable_id', $document->getDocumentId())
                ->where('type', $type)
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
            $this->safe($execute, 'voidFor:'.$document->getDocumentType(), $document);
        }
    }

    /**
     * Re-apply tax/discount N-line split for invoice-type accounting transactions.
     */
    public function tryRemapInvoiceSplit(AccountingTransaction $tx): bool
    {
        $type = (string) $tx->type;

        return match ($type) {
            'hotel_invoice' => $this->remapSalesSplit(
                $tx,
                AccountingModule::Hotel->value,
                (float) ($tx->tax_amount ?? 0),
                (float) ($tx->discount_amount ?? 0)
            ),
            'restaurant_invoice' => $this->remapSalesSplit(
                $tx,
                AccountingModule::Restaurant->value,
                (float) ($tx->tax_amount ?? 0),
                (float) ($tx->discount_amount ?? 0)
            ),
            'event_invoice' => $this->remapSalesSplit(
                $tx,
                AccountingModule::Events->value,
                (float) ($tx->tax_amount ?? 0),
                0.0
            ),
            'purchase_invoice' => $this->remapPurchaseSplit(
                $tx,
                (float) ($tx->tax_amount ?? 0),
                (float) ($tx->discount_amount ?? 0)
            ),
            default => false,
        };
    }

    protected function remapSalesSplit(
        AccountingTransaction $tx,
        string $module,
        float $taxAmount,
        float $discountAmount
    ): bool {
        $this->applySalesInvoiceSplit($tx, $module, $taxAmount, $discountAmount);

        return true;
    }

    protected function remapPurchaseSplit(
        AccountingTransaction $tx,
        float $taxAmount,
        float $discountAmount
    ): bool {
        $this->applyPurchaseInvoiceSplit($tx, $taxAmount, $discountAmount);

        return true;
    }

    protected function applySalesInvoiceSplit(
        AccountingTransaction $tx,
        string $module,
        float $taxAmount,
        float $discountAmount
    ): void {
        $branchId = (int) $tx->branch_id;
        $resolvedDoc = $this->resolver->resolveDocument($module, 'invoice', $branchId);
        if (! $resolvedDoc) {
            return;
        }

        $arAccountId = (int) $resolvedDoc['debit_account_id'];
        $salesAccountId = (int) $resolvedDoc['credit_account_id'];
        $total = round((float) $tx->amount, 2);

        $taxAmount = round(max(0, $taxAmount), 2);
        $discountAmount = round(max(0, $discountAmount), 2);
        $settings = $this->branchSettings($branchId);
        $taxAccountId = $taxAmount > 0.009 ? $settings?->tax_payable_account_id : null;
        $discountAccountId = $discountAmount > 0.009 ? $settings?->sales_discount_account_id : null;

        if (! $taxAccountId && ! $discountAccountId) {
            $this->transactions->mapWithAccounts($tx, $arAccountId, $salesAccountId);

            return;
        }

        $salesCredit = $total;
        $lines = [];

        if ($taxAccountId) {
            $salesCredit = round($salesCredit - $taxAmount, 2);
            $lines[] = ['account_id' => (int) $taxAccountId, 'debit' => 0, 'credit' => $taxAmount];
        }
        if ($discountAccountId) {
            $salesCredit = round($salesCredit + $discountAmount, 2);
            $lines[] = ['account_id' => (int) $discountAccountId, 'debit' => $discountAmount, 'credit' => 0];
        }

        array_unshift($lines, ['account_id' => $arAccountId, 'debit' => $total, 'credit' => 0]);
        $lines[] = ['account_id' => $salesAccountId, 'debit' => 0, 'credit' => $salesCredit];

        $this->transactions->mapWithLines($tx, $lines);
    }

    protected function applyPurchaseInvoiceSplit(
        AccountingTransaction $tx,
        float $taxAmount,
        float $discountAmount
    ): void {
        $branchId = (int) $tx->branch_id;
        $resolvedDoc = $this->resolver->resolveDocument(AccountingModule::Purchase->value, 'invoice', $branchId);
        if (! $resolvedDoc) {
            return;
        }

        $inventoryAccountId = (int) $resolvedDoc['debit_account_id'];
        $apAccountId = (int) $resolvedDoc['credit_account_id'];
        $total = round((float) $tx->amount, 2);

        $taxAmount = round(max(0, $taxAmount), 2);
        $discountAmount = round(max(0, $discountAmount), 2);
        $settings = $this->branchSettings($branchId);
        $taxAccountId = $taxAmount > 0.009 ? $settings?->tax_receivable_account_id : null;
        $discountAccountId = $discountAmount > 0.009 ? $settings?->purchase_discount_account_id : null;

        if (! $taxAccountId && ! $discountAccountId) {
            $this->transactions->mapWithAccounts($tx, $inventoryAccountId, $apAccountId);

            return;
        }

        $inventoryDebit = $total;
        $lines = [];

        if ($taxAccountId) {
            $inventoryDebit = round($inventoryDebit - $taxAmount, 2);
            $lines[] = ['account_id' => (int) $taxAccountId, 'debit' => $taxAmount, 'credit' => 0];
        }
        if ($discountAccountId) {
            $inventoryDebit = round($inventoryDebit + $discountAmount, 2);
            $lines[] = ['account_id' => (int) $discountAccountId, 'debit' => 0, 'credit' => $discountAmount];
        }

        array_unshift($lines, ['account_id' => $inventoryAccountId, 'debit' => $inventoryDebit, 'credit' => 0]);
        $lines[] = ['account_id' => $apAccountId, 'debit' => 0, 'credit' => $total];

        $this->transactions->mapWithLines($tx, $lines);
    }

    protected function branchSettings(int $branchId): ?AccountingBranchSetting
    {
        return AccountingBranchSetting::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->first();
    }

    protected function ownerUserId(AccountingDocumentInterface $document, AccountingContextInterface $context): ?int
    {
        if (method_exists($document, 'getOwnerUserId')) {
            $owner = $document->getOwnerUserId();

            return $owner !== null ? (int) $owner : null;
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