<?php

namespace Abdiaziizaweis\Accounting\Database\Migration;

use Illuminate\Database\Schema\Blueprint;

class TenancySchemaHelper
{
    public static function mode(): string
    {
        return (string) config('accounting.tenancy_mode', 'multi_tenant_multi_branch');
    }

    public static function usesOrganizationId(): bool
    {
        return in_array(self::mode(), ['multi_tenant', 'multi_tenant_multi_branch'], true);
    }

    public static function usesBranchId(): bool
    {
        return in_array(self::mode(), ['multi_branch', 'multi_tenant_multi_branch'], true);
    }

    public static function organizationTable(): string
    {
        return (string) config('accounting.tables.organizations', 'organizations');
    }

    public static function branchTable(): string
    {
        return (string) config('accounting.tables.branches', 'branches');
    }

    public static function usersTable(): string
    {
        return (string) config('accounting.tables.users', 'users');
    }

    public static function paymentMethodsTable(): string
    {
        return (string) config('accounting.tables.payment_methods', 'payment_methods');
    }

    public static function expenseCategoriesTable(): string
    {
        return (string) config('accounting.tables.expense_categories', 'expense_categories');
    }

    public static function addOrganizationColumn(Blueprint $table): void
    {
        if (! self::usesOrganizationId()) {
            return;
        }

        $table->foreignId('organization_id')->constrained(self::organizationTable())->cascadeOnDelete();
    }

    public static function addBranchColumn(Blueprint $table, bool $unique = false): void
    {
        if (! self::usesBranchId()) {
            return;
        }

        if ($unique) {
            $table->foreignId('branch_id')
                ->unique()
                ->constrained(self::branchTable())
                ->cascadeOnDelete();

            return;
        }

        $table->foreignId('branch_id')
            ->constrained(self::branchTable())
            ->cascadeOnDelete();
    }

    public static function addOrganizationIndex(Blueprint $table, string $name = 'organization_id'): void
    {
        if (self::usesOrganizationId()) {
            $table->index('organization_id', $name);
        }
    }

    public static function addOrgBranchIndex(Blueprint $table, string $indexName): void
    {
        if (self::usesOrganizationId() && self::usesBranchId()) {
            $table->index(['organization_id', 'branch_id'], $indexName);
        }
    }
}