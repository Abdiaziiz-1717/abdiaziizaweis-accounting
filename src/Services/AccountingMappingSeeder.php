<?php

namespace Abdiaziizaweis\Accounting\Services;

use Illuminate\Support\Facades\DB;

/**
 * Bootstrap seeds the chart of accounts only.
 * Document, payment-method, and expense-category mappings are host configuration.
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
        DB::transaction(function () use ($organizationId, $branchId, $ownerUserId) {
            $this->coa->seedDefaultsForBranch($organizationId, $branchId, $ownerUserId);
        });
    }
}
