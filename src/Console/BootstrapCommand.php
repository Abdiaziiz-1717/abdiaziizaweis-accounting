<?php

namespace Abdiaziizaweis\Accounting\Console;

use Illuminate\Console\Command;
use Abdiaziizaweis\Accounting\Contracts\AccountingAuthInterface;
use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;
use Abdiaziizaweis\Accounting\Database\Migration\TenancySchemaHelper;
use Abdiaziizaweis\Accounting\Models\AccountingAccount;
use Abdiaziizaweis\Accounting\Models\AccountingDocumentMapping;
use Abdiaziizaweis\Accounting\Services\AccountingMappingResolver;
use Abdiaziizaweis\Accounting\Services\AccountingMappingSeeder;

class BootstrapCommand extends Command
{
    protected $signature = 'accounting:bootstrap
                            {--force : Re-apply default document mappings when they already exist}
                            {--organization-id= : Organization ID (CLI when HTTP context is not resolved)}
                            {--branch-id= : Branch ID (CLI when HTTP context is not resolved)}';

    protected $description = 'Bootstrap chart of accounts and default accounting mappings for the current context';

    public function handle(
        AccountingMappingSeeder $mappingSeeder,
        AccountingMappingResolver $mappingResolver,
    ): int {
        if (TenancySchemaHelper::isSingleTenant()) {
            if ($this->option('organization-id') !== null || $this->option('branch-id') !== null) {
                $this->warn('Single tenant mode ignores --organization-id and --branch-id.');
            }

            $this->info('Single tenant mode: seeding global chart of accounts');

            $ownerUserId = app(AccountingAuthInterface::class)->user()?->id;
            $beforeAccounts = $this->accountCount(0, 0);
            $beforeMaps = $this->mappingCount(0, 0);

            $mappingSeeder->seedDefaultsForBranch(
                0,
                0,
                $ownerUserId ? (int) $ownerUserId : null,
                (bool) $this->option('force'),
            );

            $mappingResolver->forgetBranch(0);

            $afterAccounts = $this->accountCount(0, 0);
            $afterMaps = $this->mappingCount(0, 0);

            $this->newLine();
            $this->line(sprintf('  Accounts: %d total (%d added this run)', $afterAccounts, max(0, $afterAccounts - $beforeAccounts)));
            $this->line(sprintf('  Document mappings: %d total (%d added this run)', $afterMaps, max(0, $afterMaps - $beforeMaps)));

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

        $ownerUserId = app(AccountingAuthInterface::class)->user()?->id;

        $beforeAccounts = $this->accountCount($orgId, $branchId);
        $beforeMaps = $this->mappingCount($orgId, $branchId);

        $mappingSeeder->seedDefaultsForBranch(
            $orgId,
            $branchId,
            $ownerUserId ? (int) $ownerUserId : null,
            (bool) $this->option('force'),
        );

        if (TenancySchemaHelper::usesBranchId() && $branchId > 0) {
            $mappingResolver->forgetBranch($branchId);
        }

        $afterAccounts = $this->accountCount($orgId, $branchId);
        $afterMaps = $this->mappingCount($orgId, $branchId);

        $this->newLine();
        $this->info(sprintf(
            'Seeded chart of accounts for organization %d%s.',
            $orgId,
            TenancySchemaHelper::usesBranchId() ? ", branch {$branchId}" : ''
        ));
        $this->line(sprintf('  Accounts: %d total (%d added this run)', $afterAccounts, max(0, $afterAccounts - $beforeAccounts)));
        $this->line(sprintf('  Document mappings: %d total (%d added this run)', $afterMaps, max(0, $afterMaps - $beforeMaps)));

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

    protected function mappingCount(int $organizationId, int $branchId): int
    {
        $query = AccountingDocumentMapping::withoutGlobalScopes();

        if (TenancySchemaHelper::usesOrganizationId()) {
            $query->where('organization_id', $organizationId);
        }

        if (TenancySchemaHelper::usesBranchId() && $branchId > 0) {
            $query->where('branch_id', $branchId);
        }

        return (int) $query->count();
    }
}