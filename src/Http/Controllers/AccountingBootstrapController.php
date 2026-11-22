<?php

namespace Jtech\Accounting\Http\Controllers;

use Jtech\Accounting\Contracts\AccountingAuthInterface;
use Jtech\Accounting\Contracts\AccountingContextInterface;
use Jtech\Accounting\Models\AccountingAccount;
use Jtech\Accounting\Models\AccountingDocumentMapping;
use Jtech\Accounting\Services\AccountingMappingResolver;
use Jtech\Accounting\Services\AccountingMappingSeeder;
use Illuminate\Http\Request;

class AccountingBootstrapController extends BaseController
{
    public function __construct(
        protected AccountingMappingSeeder $mappingSeeder,
        protected AccountingMappingResolver $mappingResolver,
    ) {}

    /**
     * Seed default CoA + mappings + branch settings for the current branch.
     */
    public function seed(Request $request)
    {
        $branchId = (int) app(AccountingContextInterface::class)->getBranchId();
        $orgId = (int) app(AccountingContextInterface::class)->getOrganizationId();

        if (! $branchId || ! $orgId) {
            return $this->errorResponse('Branch and organization context are required.', [], 422);
        }

        $beforeAccounts = AccountingAccount::query()->count();
        $beforeMaps = AccountingDocumentMapping::query()->count();

        $this->mappingSeeder->seedDefaultsForBranch(
            $orgId,
            $branchId,
            app(AccountingAuthInterface::class)->user()?->id,
            (bool) $request->boolean('force')
        );

        $this->mappingResolver->forgetBranch($branchId);

        $afterAccounts = AccountingAccount::query()->count();
        $afterMaps = AccountingDocumentMapping::query()->count();

        return $this->successResponse([
            'accounts_count' => $afterAccounts,
            'document_mappings_count' => $afterMaps,
            'accounts_added' => max(0, $afterAccounts - $beforeAccounts),
            'mappings_added' => max(0, $afterMaps - $beforeMaps),
        ], 'Default chart of accounts and mappings seeded');
    }
}
