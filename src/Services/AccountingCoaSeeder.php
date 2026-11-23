<?php

namespace Abdiaziizaweis\Accounting\Services;

use Abdiaziizaweis\Accounting\Enums\AccountingAccountType;
use Abdiaziizaweis\Accounting\Models\AccountingAccount;
use Abdiaziizaweis\Accounting\Models\AccountingBranchSetting;
use Illuminate\Support\Facades\DB;
use Abdiaziizaweis\Accounting\Database\Migration\TenancySchemaHelper;

class AccountingCoaSeeder
{
    /**
     * Seed / backfill default chart of accounts + branch settings for a branch.
     * Idempotent: creates missing key_name accounts without duplicating existing ones.
     */
    public function seedDefaultsForBranch(
        int $organizationId,
        int $branchId,
        ?int $ownerUserId = null
    ): void {
        DB::transaction(function () use ($organizationId, $branchId, $ownerUserId) {
            $settingsKey = match (true) {
                TenancySchemaHelper::usesBranchId() => ['branch_id' => $branchId],
                TenancySchemaHelper::usesOrganizationId() => ['organization_id' => $organizationId],
                default => [],
            };

            $settingDefaults = [
                'first_month_financial_year' => max(1, min(12, (int) config('accounting.fy_start', 1))),
                'accounting_method' => 'accrual',
                'close_the_books' => false,
                'closing_date' => null,
                'enable_account_numbers' => true,
            ];
            if (TenancySchemaHelper::usesOrganizationId()) {
                $settingDefaults['organization_id'] = $organizationId;
            }
            if (TenancySchemaHelper::usesBranchId()) {
                $settingDefaults['branch_id'] = $branchId;
            }

            AccountingBranchSetting::withoutGlobalScopes()->firstOrCreate(
                $settingsKey,
                $settingDefaults
            );

            $accountQuery = AccountingAccount::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->whereNotNull('key_name');

            if (TenancySchemaHelper::usesBranchId()) {
                $accountQuery->where('branch_id', $branchId);
            } elseif (TenancySchemaHelper::usesOrganizationId()) {
                $accountQuery->where('organization_id', $organizationId);
            }

            $byKey = $accountQuery->get()->keyBy('key_name');

            $create = function (
                string $name,
                AccountingAccountType $type,
                string $keyName,
                ?string $parentKey = null,
                ?string $number = null,
                ?string $description = null,
            ) use (&$byKey, $organizationId, $branchId, $ownerUserId): AccountingAccount {
                if (isset($byKey[$keyName])) {
                    return $byKey[$keyName];
                }

                $parentId = null;
                if ($parentKey !== null && isset($byKey[$parentKey])) {
                    $parentId = $byKey[$parentKey]->id;
                }

                $accountPayload = [
                    'owner_user_id' => $ownerUserId,
                    'parent_id' => $parentId,
                    'name' => $name,
                    'number' => $number,
                    'key_name' => $keyName,
                    'account_type' => $type,
                    'detail_type' => null,
                    'description' => $description,
                    'is_active' => true,
                    'is_default' => true,
                ];
                if (TenancySchemaHelper::usesOrganizationId()) {
                    $accountPayload['organization_id'] = $organizationId;
                }
                if (TenancySchemaHelper::usesBranchId()) {
                    $accountPayload['branch_id'] = $branchId;
                }

                $account = AccountingAccount::withoutGlobalScopes()->create($accountPayload);

                $byKey[$keyName] = $account;

                return $account;
            };

            // ── Parents ──────────────────────────────────────────────────────
            $create('Assets', AccountingAccountType::Asset, 'assets', null, '1000');
            $create('Current Assets', AccountingAccountType::CurrentAssets, 'current_assets', 'assets', '1100');
            $create('Fixed Assets', AccountingAccountType::FixedAssets, 'fixed_assets', 'assets', '1500');

            $create('Liabilities', AccountingAccountType::Liability, 'liabilities', null, '2000');
            $create('Current Liabilities', AccountingAccountType::CurrentLiabilities, 'current_liabilities', 'liabilities', '2100');

            $create('Equity', AccountingAccountType::Equity, 'equity', null, '3000');

            $create('Income', AccountingAccountType::Income, 'income_parent', null, '4000');
            $create('Cost of Sales', AccountingAccountType::CostOfSales, 'cost_of_sales_parent', null, '5000');
            $create('Expenses', AccountingAccountType::Expenses, 'expenses_parent', null, '6000');

            // ── Assets ───────────────────────────────────────────────────────
            $create('Cash and Cash Equivalents', AccountingAccountType::Cash, 'cash_and_cash_equivalents', 'current_assets', '1110');
            $create('Bank', AccountingAccountType::Bank, 'bank', 'current_assets', '1120');
            $create('Undeposited Funds', AccountingAccountType::CurrentAssets, 'undeposited_funds', 'current_assets', '1130', 'Holding account for payments before bank deposit');
            $create('Accounts Receivable', AccountingAccountType::AccountsReceivable, 'accounts_receivable', 'current_assets', '1140');
            $create('Inventory Asset', AccountingAccountType::CurrentAssets, 'inventory_asset', 'current_assets', '1150');
            $create('Salary Advances', AccountingAccountType::CurrentAssets, 'salary_advances', 'current_assets', '1160', 'Staff advances recoverable via payroll');
            $create('Tax Receivable', AccountingAccountType::CurrentAssets, 'tax_receivable', 'current_assets', '1170', 'Recoverable input tax on purchases');
            $create('Prepaid Expenses', AccountingAccountType::CurrentAssets, 'prepaid_expenses', 'current_assets', '1180');

            $create('Furniture and Equipment', AccountingAccountType::FixedAssets, 'furniture_and_equipment', 'fixed_assets', '1510');

            // ── Liabilities ──────────────────────────────────────────────────
            $create('Accounts Payable', AccountingAccountType::AccountsPayable, 'accounts_payable', 'current_liabilities', '2110');
            $create('Credit Card', AccountingAccountType::CreditCard, 'credit_card', 'current_liabilities', '2120');
            $create('Tax Payable', AccountingAccountType::CurrentLiabilities, 'tax_payable', 'current_liabilities', '2130', 'Output tax collected on sales');
            $create('Customer Deposits', AccountingAccountType::CurrentLiabilities, 'customer_deposits', 'current_liabilities', '2140', 'Customer prepaid credit / deposits held');
            $create('Wages Payable', AccountingAccountType::CurrentLiabilities, 'wages_payable', 'current_liabilities', '2150', 'Accrued payroll not yet paid');
            $create('Inter-branch due to/from', AccountingAccountType::CurrentLiabilities, 'inter_branch_due', 'current_liabilities', '2190', 'Clearing for inter-branch stock/cash');

            // ── Equity ───────────────────────────────────────────────────────
            $create('Opening Balance Equity', AccountingAccountType::OwnerEquity, 'opening_balance_equity', 'equity', '3100');
            $create('Retained Earnings', AccountingAccountType::OwnerEquity, 'retained_earnings', 'equity', '3200');
            $create("Owner's Equity", AccountingAccountType::OwnerEquity, 'owner_equity', 'equity', '3300');

            // ── Income (per revenue stream) ──────────────────────────────────
            $create('Sales', AccountingAccountType::Income, 'sales', 'income_parent', '4100', 'Generic sales revenue');
            $create('Hotel Revenue', AccountingAccountType::Income, 'hotel_revenue', 'income_parent', '4110', 'Room / hotel stay invoices');
            $create('Restaurant Revenue', AccountingAccountType::Income, 'restaurant_revenue', 'income_parent', '4120', 'Restaurant / POS / order invoices');
            $create('Event Revenue', AccountingAccountType::Income, 'event_revenue', 'income_parent', '4130', 'Event hall booking invoices');
            $create('SaaS Revenue', AccountingAccountType::Income, 'saas_revenue', 'income_parent', '4150', 'SaaS subscription billing (master org)');
            $create('Sales Revenue', AccountingAccountType::Income, 'sales_revenue', 'income_parent', '4140', 'Product / vehicle sales revenue');
            $create('Service Revenue', AccountingAccountType::Income, 'service_revenue', 'income_parent', '4145', 'Service department revenue');
            $create('Parts Revenue', AccountingAccountType::Income, 'parts_revenue', 'income_parent', '4160', 'Parts counter revenue');
            $create('Sales Discounts', AccountingAccountType::Income, 'sales_discounts', 'income_parent', '4200', 'Contra-revenue: discounts given to customers');
            $create('Other Income', AccountingAccountType::OtherIncome, 'other_income', 'income_parent', '4800');
            $create('Uncategorised Income', AccountingAccountType::OtherIncome, 'uncategorised_income', 'income_parent', '4900');

            // ── Cost of sales / inventory variance ───────────────────────────
            $create('Cost of Goods Sold', AccountingAccountType::CostOfSales, 'cost_of_sales', 'cost_of_sales_parent', '5100');
            $create('Purchase Discounts', AccountingAccountType::CostOfSales, 'purchase_discounts', 'cost_of_sales_parent', '5200', 'Contra-COGS: supplier discounts');
            $create('Inventory Adjustments', AccountingAccountType::CostOfSales, 'inventory_adjustments', 'cost_of_sales_parent', '5300', 'Stock count variances');
            $create('Waste and Spoilage', AccountingAccountType::CostOfSales, 'waste_and_spoilage', 'cost_of_sales_parent', '5400');
            $create('Purchase Returns', AccountingAccountType::CostOfSales, 'purchase_returns', 'cost_of_sales_parent', '5500', 'Clearing for goods returned to suppliers');

            // ── Operating expenses (aligned with expense categories) ─────────
            $create('Office Expenses', AccountingAccountType::Expenses, 'office_expenses', 'expenses_parent', '6100');
            $create('Payroll Expenses', AccountingAccountType::Expenses, 'payroll_expenses', 'expenses_parent', '6200');
            $create('Utilities', AccountingAccountType::Expenses, 'utilities', 'expenses_parent', '6300');
            $create('Bad Debt Expense', AccountingAccountType::Expenses, 'bad_debt_expense', 'expenses_parent', '6400', 'Written-off salary advances / uncollectible amounts');
            $create('Rent Expense', AccountingAccountType::Expenses, 'rent_expense', 'expenses_parent', '6500');
            $create('Marketing Expense', AccountingAccountType::Expenses, 'marketing_expense', 'expenses_parent', '6600');
            $create('Maintenance and Repairs', AccountingAccountType::Expenses, 'maintenance_expense', 'expenses_parent', '6700');
            $create('Transport Expense', AccountingAccountType::Expenses, 'transport_expense', 'expenses_parent', '6800');
            $create('Bank Charges', AccountingAccountType::Expenses, 'bank_charges', 'expenses_parent', '6850');
            $create('Uncategorised Expense', AccountingAccountType::OtherExpense, 'uncategorised_expense', 'expenses_parent', '6900');

            // Wire tax / discount split settings when unset
            $settingsQuery = AccountingBranchSetting::withoutGlobalScopes();
            if (TenancySchemaHelper::usesBranchId()) {
                $settingsQuery->where('branch_id', $branchId);
            } elseif (TenancySchemaHelper::usesOrganizationId()) {
                $settingsQuery->where('organization_id', $organizationId);
            }
            $settings = $settingsQuery->first();

            if ($settings) {
                $patch = [];
                $taxSplitting = (bool) config('accounting.features.tax_splitting', true);
                $discountSplitting = (bool) config('accounting.features.discount_splitting', true);

                if ($taxSplitting) {
                    if (! $settings->tax_payable_account_id && isset($byKey['tax_payable'])) {
                        $patch['tax_payable_account_id'] = $byKey['tax_payable']->id;
                    }
                    if (! $settings->tax_receivable_account_id && isset($byKey['tax_receivable'])) {
                        $patch['tax_receivable_account_id'] = $byKey['tax_receivable']->id;
                    }
                }
                if ($discountSplitting) {
                    if (! $settings->sales_discount_account_id && isset($byKey['sales_discounts'])) {
                        $patch['sales_discount_account_id'] = $byKey['sales_discounts']->id;
                    }
                    if (! $settings->purchase_discount_account_id && isset($byKey['purchase_discounts'])) {
                        $patch['purchase_discount_account_id'] = $byKey['purchase_discounts']->id;
                    }
                }
                if ($patch !== []) {
                    $settings->forceFill($patch)->save();
                }
            }
        });
    }

    /**
     * Resolve an account id by stable key_name for a branch.
     */
    public function accountIdByKey(int $branchId, string $keyName): ?int
    {
        $query = AccountingAccount::withoutGlobalScopes()
            ->where('key_name', $keyName)
            ->whereNull('deleted_at');

        if (TenancySchemaHelper::usesBranchId()) {
            $query->where('branch_id', $branchId);
        }

        $id = $query->value('id');

        return $id ? (int) $id : null;
    }
}
