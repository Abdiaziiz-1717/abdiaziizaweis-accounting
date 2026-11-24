<?php

namespace Abdiaziizaweis\Accounting\Console;

use Illuminate\Console\Command;
use Abdiaziizaweis\Accounting\Contracts\AccountingAuthInterface;
use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;
use Abdiaziizaweis\Accounting\Database\Migration\TenancySchemaHelper;
use Abdiaziizaweis\Accounting\Models\AccountingAccount;
use Abdiaziizaweis\Accounting\Services\AccountingMappingSeeder;

class BootstrapCommand extends Command
{
    protected $signature = 'accounting:bootstrap
                            {--force : Kept for compatibility; bootstrap only seeds accounts}
                            {--organization-id= : Organization ID (CLI when HTTP context is not resolved)}
                            {--branch-id= : Branch ID (CLI when HTTP context is not resolved)}
                            {--user-id= : Owner user ID for seeded rows (optional; no login required in CLI)}';

    protected $description = 'Seed the chart of accounts. Does not create operation mappings.';

    public function handle(AccountingMappingSeeder $mappingSeeder): int {
        if (TenancySchemaHelper::isSingleTenant()) {
            if ($this->option('organization-id') !== null || $this->option('branch-id') !== null) {
                $this->warn('Single tenant mode ignores --organization-id and --branch-id.');
            }

            $this->info('Single tenant mode: seeding global chart of accounts');

            $ownerUserId = $this->optionalOwnerUserId();
            $beforeAccounts = $this->accountCount(0, 0);

            $mappingSeeder->seedDefaultsForBranch(
                0,
                0,
                $ownerUserId ? (int) $ownerUserId : null,
                (bool) $this->option('force'),
            );

            $afterAccounts = $this->accountCount(0, 0);

            $this->newLine();
            $this->line(sprintf('  Accounts: %d total (%d added this run)', $afterAccounts, max(0, $afterAccounts - $beforeAccounts)));
            $this->line('  Operation mappings are not seeded. Save them in your mapping screen.');

            return self::SUCCESS;
        }

        $context = app(AccountingContextInterface::class);

        $orgId = $this->option('organization-id') !== null
            ? (int) $this->option('organization-id')
            : (int) $context->getOrganizationId();

        $branchOption = $this->option('branch-id');
        $branchId = $branchOption !== null
            ? (int) $branchOption
            : $context->getBranchId();

        if (TenancySchemaHelper::usesOrganizationId() && $orgId < 1) {
            $this->error('Organization ID is required. Pass --organization-id= or bind a resolved AccountingContextInterface.');

            return self::FAILURE;
        }

        if (! TenancySchemaHelper::usesOrganizationId()) {
            $orgId = max(1, $orgId);
        }

        if (TenancySchemaHelper::usesBranchId()) {
            if ($branchId === null || (int) $branchId < 1) {
                $this->error('Branch ID is required. Pass --branch-id= or resolve branch context (e.g. X-Branch-Id).');

                return self::FAILURE;
            }
            $branchId = (int) $branchId;
        } else {
            $branchId = $branchId !== null ? (int) $branchId : 0;
        }

        $ownerUserId = $this->optionalOwnerUserId();

        $beforeAccounts = $this->accountCount($orgId, $branchId);

        $mappingSeeder->seedDefaultsForBranch(
            $orgId,
            $branchId,
            $ownerUserId ? (int) $ownerUserId : null,
            (bool) $this->option('force'),
        );

        $afterAccounts = $this->accountCount($orgId, $branchId);

        $this->newLine();
        $this->info(sprintf(
            'Seeded chart of accounts for organization %d%s.',
            $orgId,
            TenancySchemaHelper::usesBranchId() ? ", branch {$branchId}" : ''
        ));
        $this->line(sprintf('  Accounts: %d total (%d added this run)', $afterAccounts, max(0, $afterAccounts - $beforeAccounts)));
        $this->line('  Operation mappings are not seeded. Save them in your mapping screen.');

        return self::SUCCESS;
    }

    protected function accountCount(int $organizationId, int $branchId): int
    {
        $query = AccountingAccount::withoutGlobalScopes()->whereNull('deleted_at');

        if (TenancySchemaHelper::usesOrganizationId()) {
            $query->where('organization_id', $organizationId);
        }

        if (TenancySchemaHelper::usesBranchId() && $branchId > 0) {
            $query->where('branch_id', $branchId);
        }

        return (int) $query->count();
    }

    protected function optionalOwnerUserId(): ?int
    {
        $userIdOption = $this->option('user-id');
        if ($userIdOption !== null && $userIdOption !== '') {
            return (int) $userIdOption;
        }

        $id = app(AccountingAuthInterface::class)->userOrNull()?->id;

        return $id !== null ? (int) $id : null;
    }
}