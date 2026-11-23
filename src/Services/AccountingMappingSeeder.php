<?php

namespace Abdiaziizaweis\Accounting\Services;

use Abdiaziizaweis\Accounting\Enums\AccountingModule;
use Abdiaziizaweis\Accounting\Models\AccountingDocumentMapping;
use Abdiaziizaweis\Accounting\Models\AccountingExpenseCategoryMapping;
use Abdiaziizaweis\Accounting\Models\AccountingPaymentMethodMapping;
use Abdiaziizaweis\Accounting\Models\AccountingPaymentMethodMappingSide;
use Abdiaziizaweis\Accounting\Database\Migration\TenancySchemaHelper;
use Abdiaziizaweis\Accounting\Registry\BuiltinDocumentRegistry;
use Abdiaziizaweis\Accounting\Registry\DocumentRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Seeds default accounting document / payment-method / expense-category maps
 * for a branch so new tenants can post without manual Mapping setup.
 *
 * Idempotent: creates missing maps; leaves already-configured maps alone
 * unless $force is true.
 */
class AccountingMappingSeeder
{
    public function __construct(
        protected AccountingCoaSeeder $coa,
    ) {}

    public function seedDefaultsForBranch(
        int $organizationId,
        int $branchId,
        ?int $ownerUserId = null,
        bool $force = false,
    ): void {
        DB::transaction(function () use ($organizationId, $branchId, $ownerUserId, $force) {
            BuiltinDocumentRegistry::register();

            $this->coa->seedDefaultsForBranch($organizationId, $branchId, $ownerUserId);

            if (TenancySchemaHelper::usesOrganizationId()) {
                $paymentMethodClass = config('accounting.models.payment_method', \App\Models\PaymentMethod::class);
                if (is_string($paymentMethodClass) && class_exists($paymentMethodClass) && method_exists($paymentMethodClass, 'seedDefaultsForOrganization')) {
                    $paymentMethodClass::seedDefaultsForOrganization($organizationId, $ownerUserId);
                }

                $expenseCategoryClass = config('accounting.models.expense_category', \App\Models\ExpenseCategory::class);
                if (is_string($expenseCategoryClass) && class_exists($expenseCategoryClass) && method_exists($expenseCategoryClass, 'seedDefaultsForOrganization')) {
                    $expenseCategoryClass::seedDefaultsForOrganization($organizationId, $ownerUserId);
                }
            }

            $id = fn (string $key): ?int => $this->coa->accountIdByKey($branchId, $key);

            $require = function (string $key) use ($id): int {
                $accountId = $id($key);
                if (! $accountId) {
                    throw new \RuntimeException("Missing CoA account key_name={$key} for branch.");
                }

                return $accountId;
            };

            $includeSaas = $this->organizationIncludesSaasMaps($organizationId);

            foreach (DocumentRegistry::all() as $row) {
                if ($row['module'] === AccountingModule::Saas->value && ! $includeSaas) {
                    continue;
                }

                $debit = $require($row['default_debit']);
                $credit = $require($row['default_credit']);
                $this->upsertDocument(
                    $organizationId,
                    $branchId,
                    $row['module'],
                    $row['document_type'],
                    $debit,
                    $credit,
                    $force,
                );
            }

            $this->seedPaymentMethodMaps($organizationId, $branchId, $require, $force);
            $this->seedExpenseCategoryMaps($organizationId, $branchId, $require, $force);
        });
    }

    protected function organizationIncludesSaasMaps(int $organizationId): bool
    {
        if (! TenancySchemaHelper::usesOrganizationId()) {
            return false;
        }

        $orgClass = config('accounting.models.organization', \App\Models\Organization::class);
        if (! is_string($orgClass) || ! class_exists($orgClass)) {
            return false;
        }

        $org = $orgClass::withoutGlobalScopes()->find($organizationId);
        if (! $org) {
            return false;
        }

        if (method_exists($org, 'isMaster')) {
            return (bool) $org->isMaster();
        }

        return false;
    }

    protected function upsertDocument(
        int $organizationId,
        int $branchId,
        string $module,
        string $documentType,
        int $debitAccountId,
        int $creditAccountId,
        bool $force,
    ): void {
        $existing = AccountingDocumentMapping::withoutGlobalScopes()
            ->when(TenancySchemaHelper::usesBranchId(), fn ($q) => $q->where('branch_id', $branchId))
            ->when(
                ! TenancySchemaHelper::usesBranchId() && TenancySchemaHelper::usesOrganizationId(),
                fn ($q) => $q->where('organization_id', $organizationId)
            )
            ->where('module', $module)
            ->where('document_type', $documentType)
            ->first();

        if ($existing && ! $force) {
            if ($existing->is_enabled && $existing->debit_account_id && $existing->credit_account_id) {
                return;
            }
        }

        AccountingDocumentMapping::withoutGlobalScopes()->updateOrCreate(
            $this->scopedUniqueKeys($organizationId, $branchId, [
                'module' => $module,
                'document_type' => $documentType,
            ]),
            $this->scopedAttributes($organizationId, $branchId, [
                'debit_account_id' => $debitAccountId,
                'credit_account_id' => $creditAccountId,
                'is_enabled' => true,
            ])
        );
    }

    /**
     * @param  callable(string): int  $require
     */
    protected function seedPaymentMethodMaps(
        int $organizationId,
        int $branchId,
        callable $require,
        bool $force,
    ): void {
        $cashId = $require('cash_and_cash_equivalents');
        $bankId = $require('bank');
        $arId = $require('accounts_receivable');
        $apId = $require('accounts_payable');

        $paymentMethodClass = config('accounting.models.payment_method', \App\Models\PaymentMethod::class);
        if (! is_string($paymentMethodClass) || ! class_exists($paymentMethodClass)) {
            return;
        }

        $methods = $paymentMethodClass::withoutGlobalScopes()
            ->when(
                TenancySchemaHelper::usesOrganizationId(),
                fn ($q) => $q->where('organization_id', $organizationId)
            )
            ->when(
                method_exists(new $paymentMethodClass, 'getDeletedAtColumn'),
                fn ($q) => $q->whereNull('deleted_at')
            )
            ->where('is_active', true)
            ->get();

        foreach ($methods as $method) {
            $name = strtolower((string) $method->name);
            $moneyIn = (str_contains($name, 'bank') || str_contains($name, 'card') || str_contains($name, 'transfer'))
                ? $bankId
                : $cashId;

            $existing = AccountingPaymentMethodMapping::withoutGlobalScopes()
                ->when(TenancySchemaHelper::usesBranchId(), fn ($q) => $q->where('branch_id', $branchId))
                ->when(
                    ! TenancySchemaHelper::usesBranchId() && TenancySchemaHelper::usesOrganizationId(),
                    fn ($q) => $q->where('organization_id', $organizationId)
                )
                ->where('payment_method_id', $method->id)
                ->first();

            if ($existing && ! $force
                && $existing->is_enabled
                && $existing->sales_debit_account_id
                && $existing->sales_credit_account_id
            ) {
                continue;
            }

            $mapping = AccountingPaymentMethodMapping::withoutGlobalScopes()->updateOrCreate(
                $this->scopedUniqueKeys($organizationId, $branchId, [
                    'payment_method_id' => $method->id,
                ]),
                $this->scopedAttributes($organizationId, $branchId, [
                    'sales_debit_account_id' => $moneyIn,
                    'sales_credit_account_id' => $arId,
                    'purchase_debit_account_id' => $apId,
                    'purchase_credit_account_id' => $moneyIn,
                    'is_enabled' => true,
                ])
            );

            $this->upsertPaymentMethodSide($mapping, 'sales', $moneyIn, $arId);
            $this->upsertPaymentMethodSide($mapping, 'purchase', $apId, $moneyIn);
        }
    }

    protected function upsertPaymentMethodSide(
        AccountingPaymentMethodMapping $mapping,
        string $module,
        ?int $debitId,
        ?int $creditId,
    ): void {
        if (! $debitId || ! $creditId) {
            return;
        }

        $attrs = [
            'debit_account_id' => $debitId,
            'credit_account_id' => $creditId,
            'is_enabled' => (bool) $mapping->is_enabled,
        ];
        if (TenancySchemaHelper::usesOrganizationId()) {
            $attrs['organization_id'] = $mapping->organization_id;
        }
        if (TenancySchemaHelper::usesBranchId()) {
            $attrs['branch_id'] = $mapping->branch_id;
        }

        AccountingPaymentMethodMappingSide::withoutGlobalScopes()->updateOrCreate(
            [
                'payment_method_mapping_id' => $mapping->id,
                'module' => $module,
            ],
            $attrs
        );
    }

    /**
     * @param  callable(string): int  $require
     */
    protected function seedExpenseCategoryMaps(
        int $organizationId,
        int $branchId,
        callable $require,
        bool $force,
    ): void {
        $cashId = $require('cash_and_cash_equivalents');

        /** @var array<string, string> code => CoA key */
        $byCode = [
            'rent' => 'rent_expense',
            'utilities' => 'utilities',
            'salaries' => 'payroll_expenses',
            'marketing' => 'marketing_expense',
            'maintenance' => 'maintenance_expense',
            'office_supplies' => 'office_expenses',
            'transport' => 'transport_expense',
            'other' => 'uncategorised_expense',
        ];

        $expenseCategoryClass = config('accounting.models.expense_category', \App\Models\ExpenseCategory::class);
        if (! is_string($expenseCategoryClass) || ! class_exists($expenseCategoryClass)) {
            return;
        }

        $categories = $expenseCategoryClass::withoutGlobalScopes()
            ->when(
                TenancySchemaHelper::usesOrganizationId(),
                fn ($q) => $q->where('organization_id', $organizationId)
            )
            ->when(
                method_exists(new $expenseCategoryClass, 'getDeletedAtColumn'),
                fn ($q) => $q->whereNull('deleted_at')
            )
            ->get();

        foreach ($categories as $category) {
            $code = strtolower((string) ($category->code ?: ''));
            $expenseKey = $byCode[$code] ?? 'uncategorised_expense';
            $debitId = $require($expenseKey);

            $existing = AccountingExpenseCategoryMapping::withoutGlobalScopes()
                ->when(TenancySchemaHelper::usesBranchId(), fn ($q) => $q->where('branch_id', $branchId))
                ->when(
                    ! TenancySchemaHelper::usesBranchId() && TenancySchemaHelper::usesOrganizationId(),
                    fn ($q) => $q->where('organization_id', $organizationId)
                )
                ->where('expense_category_id', $category->id)
                ->first();

            if ($existing && ! $force
                && $existing->is_enabled
                && $existing->debit_account_id
                && $existing->credit_account_id
            ) {
                continue;
            }

            AccountingExpenseCategoryMapping::withoutGlobalScopes()->updateOrCreate(
                $this->scopedUniqueKeys($organizationId, $branchId, [
                    'expense_category_id' => $category->id,
                ]),
                $this->scopedAttributes($organizationId, $branchId, [
                    'debit_account_id' => $debitId,
                    'credit_account_id' => $cashId,
                    'is_enabled' => true,
                ])
            );
        }
    }

    /**
     * @param  array<string, mixed>  $keys
     * @return array<string, mixed>
     */
    protected function scopedUniqueKeys(int $organizationId, int $branchId, array $keys): array
    {
        if (TenancySchemaHelper::usesBranchId()) {
            $keys['branch_id'] = $branchId;
        } elseif (TenancySchemaHelper::usesOrganizationId()) {
            $keys['organization_id'] = $organizationId;
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function scopedAttributes(int $organizationId, int $branchId, array $attributes): array
    {
        if (TenancySchemaHelper::usesOrganizationId()) {
            $attributes['organization_id'] = $organizationId;
        }
        if (TenancySchemaHelper::usesBranchId()) {
            $attributes['branch_id'] = $branchId;
        }

        return $attributes;
    }
}