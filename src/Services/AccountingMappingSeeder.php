<?php

namespace Jtech\Accounting\Services;

use Jtech\Accounting\Enums\AccountingModule;
use Jtech\Accounting\Models\AccountingDocumentMapping;
use Jtech\Accounting\Models\AccountingExpenseCategoryMapping;
use Jtech\Accounting\Models\AccountingPaymentMethodMapping;
use Jtech\Accounting\Registry\DocumentRegistry;
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
            $this->coa->seedDefaultsForBranch($organizationId, $branchId, $ownerUserId);

            $paymentMethodClass = config('accounting.models.payment_method', \App\Models\PaymentMethod::class);
            if (is_string($paymentMethodClass) && class_exists($paymentMethodClass) && method_exists($paymentMethodClass, 'seedDefaultsForOrganization')) {
                $paymentMethodClass::seedDefaultsForOrganization($organizationId, $ownerUserId);
            }

            $expenseCategoryClass = config('accounting.models.expense_category', \App\Models\ExpenseCategory::class);
            if (is_string($expenseCategoryClass) && class_exists($expenseCategoryClass) && method_exists($expenseCategoryClass, 'seedDefaultsForOrganization')) {
                $expenseCategoryClass::seedDefaultsForOrganization($organizationId, $ownerUserId);
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
            ->where('branch_id', $branchId)
            ->where('module', $module)
            ->where('document_type', $documentType)
            ->first();

        if ($existing && ! $force) {
            if ($existing->is_enabled && $existing->debit_account_id && $existing->credit_account_id) {
                return;
            }
        }

        AccountingDocumentMapping::withoutGlobalScopes()->updateOrCreate(
            [
                'branch_id' => $branchId,
                'module' => $module,
                'document_type' => $documentType,
            ],
            [
                'organization_id' => $organizationId,
                'debit_account_id' => $debitAccountId,
                'credit_account_id' => $creditAccountId,
                'is_enabled' => true,
            ]
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
            ->where('organization_id', $organizationId)
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
                ->where('branch_id', $branchId)
                ->where('payment_method_id', $method->id)
                ->first();

            if ($existing && ! $force
                && $existing->is_enabled
                && $existing->sales_debit_account_id
                && $existing->sales_credit_account_id
            ) {
                continue;
            }

            AccountingPaymentMethodMapping::withoutGlobalScopes()->updateOrCreate(
                [
                    'branch_id' => $branchId,
                    'payment_method_id' => $method->id,
                ],
                [
                    'organization_id' => $organizationId,
                    'sales_debit_account_id' => $moneyIn,
                    'sales_credit_account_id' => $arId,
                    'purchase_debit_account_id' => $apId,
                    'purchase_credit_account_id' => $moneyIn,
                    'is_enabled' => true,
                ]
            );
        }
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
            ->where('organization_id', $organizationId)
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
                ->where('branch_id', $branchId)
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
                [
                    'branch_id' => $branchId,
                    'expense_category_id' => $category->id,
                ],
                [
                    'organization_id' => $organizationId,
                    'debit_account_id' => $debitId,
                    'credit_account_id' => $cashId,
                    'is_enabled' => true,
                ]
            );
        }
    }
}