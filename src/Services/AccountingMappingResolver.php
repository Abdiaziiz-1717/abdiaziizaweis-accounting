<?php

namespace Jtech\Accounting\Services;

use Jtech\Accounting\Contracts\AccountingContextInterface;

use Jtech\Accounting\Enums\AccountingModule;
use Jtech\Accounting\Models\AccountingDocumentMapping;
use Jtech\Accounting\Models\AccountingExpenseCategoryMapping;
use Jtech\Accounting\Models\AccountingPaymentMethodMapping;
use Illuminate\Support\Facades\Cache;

class AccountingMappingResolver
{
    public const CACHE_TTL_SECONDS = 300;

    public function forgetBranch(?int $branchId = null): void
    {
        $branchId = $branchId ?? app(AccountingContextInterface::class)->getBranchId();
        if (! $branchId) {
            return;
        }

        $key = $this->versionKey($branchId);
        Cache::forever($key, $this->branchVersion($branchId) + 1);
    }

    /**
     * Resolve document mapping accounts for the current (or given) branch.
     * debit_account_id = Perfex deposit_to style; credit_account_id = payment_account style.
     *
     * @return array{debit_account_id: int, credit_account_id: int, mapping: AccountingDocumentMapping}|null
     */
    public function resolveDocument(
        AccountingModule|string $module,
        string $documentType,
        ?int $branchId = null
    ): ?array {
        $branchId = $branchId ?? app(AccountingContextInterface::class)->getBranchId();
        if (! $branchId) {
            return null;
        }

        $moduleValue = $module instanceof AccountingModule ? $module->value : $module;
        $cacheKey = $this->entryKey($branchId, 'doc', $moduleValue, $documentType);

        $payload = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($branchId, $moduleValue, $documentType) {
            $mapping = AccountingDocumentMapping::withoutGlobalScopes()
                ->where('branch_id', $branchId)
                ->where('module', $moduleValue)
                ->where('document_type', $documentType)
                ->where('is_enabled', true)
                ->first();

            if (! $mapping) {
                return null;
            }

            return [
                'debit_account_id' => (int) $mapping->debit_account_id,
                'credit_account_id' => (int) $mapping->credit_account_id,
                'mapping_id' => (int) $mapping->id,
            ];
        });

        if (! $payload) {
            return null;
        }

        // Callers use account ids only; avoid a second PK round-trip on cache hits.
        $mapping = new AccountingDocumentMapping();
        $mapping->forceFill(['id' => $payload['mapping_id']]);
        $mapping->exists = true;

        return [
            'debit_account_id' => (int) $payload['debit_account_id'],
            'credit_account_id' => (int) $payload['credit_account_id'],
            'mapping' => $mapping,
        ];
    }

    /**
     * `sales` = customer money-in (hotel/restaurant/event payments, deposits).
     * `purchase` = money-out to suppliers (purchase payments only). Company
     * expenses use expense-category maps and never reach this resolver.
     *
     * @return array{debit_account_id: int, credit_account_id: int, mapping: AccountingPaymentMethodMapping}|null
     */
    public function resolvePaymentMethod(
        int $paymentMethodId,
        string $side = 'sales',
        ?int $branchId = null
    ): ?array {
        $branchId = $branchId ?? app(AccountingContextInterface::class)->getBranchId();
        if (! $branchId) {
            return null;
        }

        if (! in_array($side, ['sales', 'purchase'], true)) {
            return null;
        }

        $cacheKey = $this->entryKey($branchId, 'pm', (string) $paymentMethodId, $side);

        $payload = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($branchId, $paymentMethodId, $side) {
            $mapping = AccountingPaymentMethodMapping::withoutGlobalScopes()
                ->where('branch_id', $branchId)
                ->where('payment_method_id', $paymentMethodId)
                ->where('is_enabled', true)
                ->first();

            if (! $mapping) {
                return null;
            }

            if ($side === 'purchase') {
                if (! $mapping->purchase_debit_account_id || ! $mapping->purchase_credit_account_id) {
                    return null;
                }

                return [
                    'debit_account_id' => (int) $mapping->purchase_debit_account_id,
                    'credit_account_id' => (int) $mapping->purchase_credit_account_id,
                    'mapping_id' => (int) $mapping->id,
                ];
            }

            return [
                'debit_account_id' => (int) $mapping->sales_debit_account_id,
                'credit_account_id' => (int) $mapping->sales_credit_account_id,
                'mapping_id' => (int) $mapping->id,
            ];
        });

        if (! $payload) {
            return null;
        }

        $mapping = new AccountingPaymentMethodMapping();
        $mapping->forceFill(['id' => $payload['mapping_id']]);
        $mapping->exists = true;

        return [
            'debit_account_id' => (int) $payload['debit_account_id'],
            'credit_account_id' => (int) $payload['credit_account_id'],
            'mapping' => $mapping,
        ];
    }

    /**
     * Composite resolver used by AccountingTransactionService::tryAutoMap.
     *
     * Priority order:
     *  1. Explicit debit/credit accounts passed via hints (caller handles that).
     *  2. Expense category map (when expenseCategoryId is set).
     *  3. Payment method map (when paymentMethodId is set).
     *  4. Document map (module + documentType fallback).
     *
     * Returns null when no matching mapping is found.
     *
     * @return array{debit_account_id: int, credit_account_id: int}|null
     */
    public function resolve(
        int $organizationId,
        int $branchId,
        AccountingModule|string|null $module,
        ?string $documentType,
        ?int $paymentMethodId,
        ?int $expenseCategoryId,
        string $paymentSide = 'sales',
    ): ?array {
        // 1. Expense category map takes precedence for expense-type transactions.
        if ($expenseCategoryId) {
            $result = $this->resolveExpenseCategory($expenseCategoryId, $branchId);
            if ($result) {
                return ['debit_account_id' => $result['debit_account_id'], 'credit_account_id' => $result['credit_account_id']];
            }
        }

        // 2. Payment method map.
        if ($paymentMethodId) {
            $result = $this->resolvePaymentMethod($paymentMethodId, $paymentSide, $branchId);
            if ($result) {
                return ['debit_account_id' => $result['debit_account_id'], 'credit_account_id' => $result['credit_account_id']];
            }
        }

        // 3. Document type map.
        if ($module && $documentType) {
            $result = $this->resolveDocument($module, $documentType, $branchId);
            if ($result) {
                return ['debit_account_id' => $result['debit_account_id'], 'credit_account_id' => $result['credit_account_id']];
            }
        }

        return null;
    }

    /**
     * @return array{debit_account_id: int, credit_account_id: int, mapping: AccountingExpenseCategoryMapping}|null
     */
    public function resolveExpenseCategory(int $expenseCategoryId, ?int $branchId = null): ?array
    {
        $branchId = $branchId ?? app(AccountingContextInterface::class)->getBranchId();
        if (! $branchId) {
            return null;
        }

        $cacheKey = $this->entryKey($branchId, 'exp', (string) $expenseCategoryId, '-');

        $payload = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($branchId, $expenseCategoryId) {
            $mapping = AccountingExpenseCategoryMapping::withoutGlobalScopes()
                ->where('branch_id', $branchId)
                ->where('expense_category_id', $expenseCategoryId)
                ->where('is_enabled', true)
                ->first();

            if (! $mapping) {
                return null;
            }

            return [
                'debit_account_id' => (int) $mapping->debit_account_id,
                'credit_account_id' => (int) $mapping->credit_account_id,
                'mapping_id' => (int) $mapping->id,
            ];
        });

        if (! $payload) {
            return null;
        }

        $mapping = new AccountingExpenseCategoryMapping();
        $mapping->forceFill(['id' => $payload['mapping_id']]);
        $mapping->exists = true;

        return [
            'debit_account_id' => (int) $payload['debit_account_id'],
            'credit_account_id' => (int) $payload['credit_account_id'],
            'mapping' => $mapping,
        ];
    }

    protected function versionKey(int $branchId): string
    {
        return "acct.mapping.v.{$branchId}";
    }

    protected function branchVersion(int $branchId): int
    {
        return (int) Cache::get($this->versionKey($branchId), 1);
    }

    protected function entryKey(int $branchId, string $kind, string $a, string $b): string
    {
        $ver = $this->branchVersion($branchId);

        return "acct.map.{$ver}.{$branchId}.{$kind}.{$a}.{$b}";
    }
}
