<?php

namespace Abdiaziizaweis\Accounting\Services;

use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;
use Abdiaziizaweis\Accounting\Database\Migration\TenancySchemaHelper;
use Abdiaziizaweis\Accounting\Enums\AccountingModule;
use Abdiaziizaweis\Accounting\Models\AccountingDocumentMapping;
use Abdiaziizaweis\Accounting\Models\AccountingExpenseCategoryMapping;
use Abdiaziizaweis\Accounting\Models\AccountingPaymentMethodMapping;
use Abdiaziizaweis\Accounting\Models\AccountingPaymentMethodMappingSide;
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
        if (TenancySchemaHelper::usesBranchId() && ! $branchId) {
            return null;
        }

        $moduleValue = $module instanceof AccountingModule ? $module->value : $module;
        $cacheKey = $this->entryKey((int) ($branchId ?? 0), 'doc', $moduleValue, $documentType);

        $payload = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($branchId, $moduleValue, $documentType) {
            $mapping = AccountingDocumentMapping::withoutGlobalScopes()
                ->when(TenancySchemaHelper::usesBranchId(), fn ($q) => $q->where('branch_id', $branchId))
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
     * Resolve debit/credit for a payment method in a given module context (`sales`, `purchase`, `expenses`, custom, …).
     * Reads `accounting_payment_method_mapping_sides` first; falls back to legacy sales/purchase columns.
     *
     * @return array{debit_account_id: int, credit_account_id: int, mapping: AccountingPaymentMethodMapping}|null
     */
    public function resolvePaymentMethod(
        int $paymentMethodId,
        string $module = 'sales',
        ?int $branchId = null
    ): ?array {
        $branchId = $branchId ?? app(AccountingContextInterface::class)->getBranchId();
        if (TenancySchemaHelper::usesBranchId() && ! $branchId) {
            return null;
        }

        $module = $this->normalizePaymentMethodModule($module);
        $cacheKey = $this->entryKey((int) ($branchId ?? 0), 'pm', (string) $paymentMethodId, $module);

        $payload = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($branchId, $paymentMethodId, $module) {
            $mapping = AccountingPaymentMethodMapping::withoutGlobalScopes()
                ->when(TenancySchemaHelper::usesBranchId(), fn ($q) => $q->where('branch_id', $branchId))
                ->where('payment_method_id', $paymentMethodId)
                ->where('is_enabled', true)
                ->first();

            if (! $mapping) {
                return null;
            }

            $side = AccountingPaymentMethodMappingSide::withoutGlobalScopes()
                ->where('payment_method_mapping_id', $mapping->id)
                ->where('module', $module)
                ->where('is_enabled', true)
                ->first();

            if ($side && $side->debit_account_id && $side->credit_account_id) {
                return [
                    'debit_account_id' => (int) $side->debit_account_id,
                    'credit_account_id' => (int) $side->credit_account_id,
                    'mapping_id' => (int) $mapping->id,
                ];
            }

            return $this->resolveLegacyPaymentMethodColumns($mapping, $module);
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
        string $paymentModule = 'sales',
    ): ?array {
        // 1. Expense category map takes precedence for expense-type transactions.
        if ($expenseCategoryId) {
            $result = $this->resolveExpenseCategory($expenseCategoryId, $branchId);
            if ($result) {
                return ['debit_account_id' => $result['debit_account_id'], 'credit_account_id' => $result['credit_account_id']];
            }
        }

        // 2. Payment method map (`payment_side` hint, else document module, else sales).
        if ($paymentMethodId) {
            $pmModule = $paymentModule;
            if (in_array($pmModule, ['', 'sales'], true) && $module !== null) {
                $pmModule = $module instanceof AccountingModule ? $module->value : (string) $module;
            }
            if ($pmModule === '') {
                $pmModule = 'sales';
            }

            $result = $this->resolvePaymentMethod(
                $paymentMethodId,
                $this->normalizePaymentMethodModule($pmModule),
                $branchId
            );
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
        if (TenancySchemaHelper::usesBranchId() && ! $branchId) {
            return null;
        }

        $cacheKey = $this->entryKey((int) ($branchId ?? 0), 'exp', (string) $expenseCategoryId, '-');

        $payload = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($branchId, $expenseCategoryId) {
            $mapping = AccountingExpenseCategoryMapping::withoutGlobalScopes()
                ->when(TenancySchemaHelper::usesBranchId(), fn ($q) => $q->where('branch_id', $branchId))
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

    protected function normalizePaymentMethodModule(string $module): string
    {
        $module = strtolower(trim($module));

        return match ($module) {
            'purchases' => 'purchase',
            default => $module,
        };
    }

    /**
     * @return array{debit_account_id: int, credit_account_id: int, mapping_id: int}|null
     */
    protected function resolveLegacyPaymentMethodColumns(
        AccountingPaymentMethodMapping $mapping,
        string $module
    ): ?array {
        if ($module === 'purchase') {
            if (! $mapping->purchase_debit_account_id || ! $mapping->purchase_credit_account_id) {
                return null;
            }

            return [
                'debit_account_id' => (int) $mapping->purchase_debit_account_id,
                'credit_account_id' => (int) $mapping->purchase_credit_account_id,
                'mapping_id' => (int) $mapping->id,
            ];
        }

        if ($module === 'sales') {
            if (! $mapping->sales_debit_account_id || ! $mapping->sales_credit_account_id) {
                return null;
            }

            return [
                'debit_account_id' => (int) $mapping->sales_debit_account_id,
                'credit_account_id' => (int) $mapping->sales_credit_account_id,
                'mapping_id' => (int) $mapping->id,
            ];
        }

        return null;
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
