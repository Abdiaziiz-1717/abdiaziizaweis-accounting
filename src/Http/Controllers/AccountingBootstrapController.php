<?php

namespace Abdiaziizaweis\Accounting\Http\Controllers;

use Abdiaziizaweis\Accounting\Contracts\AccountingAuthInterface;
use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;
use Abdiaziizaweis\Accounting\Database\Migration\TenancySchemaHelper;
use Abdiaziizaweis\Accounting\Models\AccountingAccount;
use Abdiaziizaweis\Accounting\Services\AccountingMappingSeeder;
use Illuminate\Http\Request;

class AccountingBootstrapController extends BaseController
{
    public function __construct(
        protected AccountingMappingSeeder $mappingSeeder,
    ) {}

    public function seed(Request $request)
    {
        $context = app(AccountingContextInterface::class);
        $branchId = (int) ($context->getBranchId() ?? 0);
        $orgId = (int) $context->getOrganizationId();

        if (! TenancySchemaHelper::isSingleTenant()) {
            if (TenancySchemaHelper::usesOrganizationId() && $orgId < 1) {
                return $this->errorResponse('Organization context is required.', [], 422);
            }
            if (TenancySchemaHelper::usesBranchId() && $branchId < 1) {
                return $this->errorResponse('Branch context is required.', [], 422);
            }
        }

        $beforeAccounts = AccountingAccount::query()->count();

        $this->mappingSeeder->seedDefaultsForBranch(
            TenancySchemaHelper::isSingleTenant() ? 0 : $orgId,
            TenancySchemaHelper::isSingleTenant() ? 0 : $branchId,
            app(AccountingAuthInterface::class)->userOrNull()?->id,
            (bool) $request->boolean('force')
        );

        $afterAccounts = AccountingAccount::query()->count();

        return $this->successResponse([
            'accounts_count' => $afterAccounts,
            'accounts_added' => max(0, $afterAccounts - $beforeAccounts),
        ], 'Chart of accounts seeded');
    }
}
