<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jtech\Accounting\Database\Migration\TenancySchemaHelper;
use Jtech\Accounting\Models\AccountingAccount;

/**
 * Backfills the new default accounts (added to AccountingCoaSeeder) onto branches
 * that already had their Chart of Accounts seeded before this change, so the new
 * mapping types (tax, discounts, inventory adjustments/waste, salary advances)
 * have sensible accounts to pick from without manual setup.
 */
return new class extends Migration
{
    /** @var list<array{name:string,key_name:string,type:string,parent_key:string,number:string,description:?string}> */
    private array $accounts = [
        ['name' => 'Salary Advances', 'key_name' => 'salary_advances', 'type' => 'current_assets', 'parent_key' => 'current_assets', 'number' => '1160', 'description' => 'Staff advances/loans recoverable via payroll'],
        ['name' => 'Tax Receivable', 'key_name' => 'tax_receivable', 'type' => 'current_assets', 'parent_key' => 'current_assets', 'number' => '1170', 'description' => 'Recoverable input tax paid on purchases'],
        ['name' => 'Tax Payable', 'key_name' => 'tax_payable', 'type' => 'current_liabilities', 'parent_key' => 'current_liabilities', 'number' => '2130', 'description' => 'Output tax (VAT/GST) collected on sales, owed to the tax authority'],
        ['name' => 'Sales Discounts', 'key_name' => 'sales_discounts', 'type' => 'income', 'parent_key' => 'income_parent', 'number' => '4200', 'description' => 'Contra-revenue: discounts given to customers'],
        ['name' => 'Purchase Discounts', 'key_name' => 'purchase_discounts', 'type' => 'cost_of_sales', 'parent_key' => 'cost_of_sales_parent', 'number' => '5200', 'description' => 'Contra-COGS: discounts/allowances received from suppliers'],
        ['name' => 'Inventory Adjustments', 'key_name' => 'inventory_adjustments', 'type' => 'cost_of_sales', 'parent_key' => 'cost_of_sales_parent', 'number' => '5300', 'description' => 'Stock count variances (shrinkage/found stock)'],
        ['name' => 'Waste and Spoilage', 'key_name' => 'waste_and_spoilage', 'type' => 'cost_of_sales', 'parent_key' => 'cost_of_sales_parent', 'number' => '5400', 'description' => null],
    ];

    public function up(): void
    {
        if (TenancySchemaHelper::usesBranchId()) {
            $branchClass = config('accounting.models.branch', \App\Models\Branch::class);
            if (! is_string($branchClass) || ! class_exists($branchClass)) {
                return;
            }

            $branchClass::query()->withoutGlobalScopes()
                ->when(
                    Schema::hasColumn((new $branchClass)->getTable(), 'deleted_at'),
                    fn ($q) => $q->whereNull('deleted_at')
                )
                ->orderBy('id')
                ->each(function ($branch) {
                    $this->backfillForScope([
                        'branch_id' => (int) $branch->id,
                        'organization_id' => TenancySchemaHelper::usesOrganizationId()
                            ? (int) $branch->organization_id
                            : null,
                        'owner_user_id' => $branch->owner_user_id ? (int) $branch->owner_user_id : null,
                    ]);
                });

            return;
        }

        if (TenancySchemaHelper::usesOrganizationId()) {
            $orgClass = config('accounting.models.organization', \App\Models\Organization::class);
            if (! is_string($orgClass) || ! class_exists($orgClass)) {
                return;
            }

            $orgClass::query()->orderBy('id')->each(function ($organization) {
                $this->backfillForScope([
                    'organization_id' => (int) $organization->id,
                    'owner_user_id' => $organization->owner_user_id ?? null,
                ]);
            });

            return;
        }

        $this->backfillForScope([]);
    }

    /**
     * @param  array{branch_id?: int, organization_id?: int|null, owner_user_id?: int|null}  $scope
     */
    private function backfillForScope(array $scope): void
    {
        $query = AccountingAccount::withoutGlobalScopes()->whereNull('deleted_at');

        if (isset($scope['branch_id'])) {
            $query->where('branch_id', $scope['branch_id']);
        } elseif (isset($scope['organization_id'])) {
            $query->where('organization_id', $scope['organization_id']);
        }

        if (! $query->exists()) {
            return;
        }

        $parentQuery = AccountingAccount::withoutGlobalScopes()->whereNull('deleted_at');
        if (isset($scope['branch_id'])) {
            $parentQuery->where('branch_id', $scope['branch_id']);
        } elseif (isset($scope['organization_id'])) {
            $parentQuery->where('organization_id', $scope['organization_id']);
        }

        $parents = $parentQuery
            ->whereIn('key_name', ['current_assets', 'current_liabilities', 'income_parent', 'cost_of_sales_parent'])
            ->pluck('id', 'key_name');

        foreach ($this->accounts as $def) {
            $existsQuery = AccountingAccount::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('key_name', $def['key_name']);

            if (isset($scope['branch_id'])) {
                $existsQuery->where('branch_id', $scope['branch_id']);
            } elseif (isset($scope['organization_id'])) {
                $existsQuery->where('organization_id', $scope['organization_id']);
            }

            if ($existsQuery->exists()) {
                continue;
            }

            $parentId = $parents[$def['parent_key']] ?? null;

            $payload = [
                'owner_user_id' => $scope['owner_user_id'] ?? null,
                'parent_id' => $parentId,
                'name' => $def['name'],
                'number' => $def['number'],
                'key_name' => $def['key_name'],
                'account_type' => $def['type'],
                'detail_type' => null,
                'description' => $def['description'],
                'is_active' => true,
                'is_default' => true,
            ];

            if (isset($scope['branch_id'])) {
                $payload['branch_id'] = $scope['branch_id'];
            }
            if (isset($scope['organization_id'])) {
                $payload['organization_id'] = $scope['organization_id'];
            } elseif (TenancySchemaHelper::usesOrganizationId()) {
                $payload['organization_id'] = $scope['organization_id'] ?? 1;
            }

            AccountingAccount::withoutGlobalScopes()->create($payload);
        }
    }

    public function down(): void
    {
        $keyNames = array_column($this->accounts, 'key_name');

        DB::table('accounting_accounts')
            ->whereIn('key_name', $keyNames)
            ->where('is_default', true)
            ->delete();
    }
};