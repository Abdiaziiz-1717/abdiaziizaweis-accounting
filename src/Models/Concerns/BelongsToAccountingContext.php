<?php

namespace Abdiaziizaweis\Accounting\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;
use Abdiaziizaweis\Accounting\Database\Migration\TenancySchemaHelper;
use Abdiaziizaweis\Accounting\Models\Scopes\AccountingContextScope;
use RuntimeException;

/**
 * Stamp + globally filter by organization_id and branch_id (MAAMUUS BelongsToOrganization + BelongsToBranch).
 */
trait BelongsToAccountingContext
{
    public static function bootBelongsToAccountingContext(): void
    {
        static::addGlobalScope(new AccountingContextScope);

        static::creating(function (Model $model) {
            if (TenancySchemaHelper::isSingleTenant()) {
                return;
            }

            $context = app(AccountingContextInterface::class);
            $branchId = $context->getBranchId();

            if ($branchId !== null && empty($model->getAttribute('branch_id'))) {
                $model->setAttribute('branch_id', $branchId);
            }

            if (
                empty($model->getAttribute('organization_id'))
                && ! empty($model->getAttribute('branch_id'))
            ) {
                $branchClass = config('accounting.models.branch');
                if (is_string($branchClass) && class_exists($branchClass)) {
                    $orgId = $branchClass::query()
                        ->withoutGlobalScopes()
                        ->whereKey($model->getAttribute('branch_id'))
                        ->value('organization_id');
                    if ($orgId) {
                        $model->setAttribute('organization_id', (int) $orgId);
                    }
                }
            }

            if (empty($model->getAttribute('organization_id'))) {
                $model->setAttribute('organization_id', $context->getOrganizationId());
            }
        });

        static::saving(function (Model $model) {
            if (TenancySchemaHelper::isSingleTenant()) {
                return;
            }

            $orgId = $model->getAttribute('organization_id');
            $branchId = $model->getAttribute('branch_id');

            if (! $orgId || ! $branchId) {
                return;
            }

            $branchClass = config('accounting.models.branch');
            if (! is_string($branchClass) || ! class_exists($branchClass)) {
                return;
            }

            $branchOrgId = $branchClass::query()
                ->withoutGlobalScopes()
                ->whereKey($branchId)
                ->value('organization_id');

            if ($branchOrgId !== null && (int) $branchOrgId !== (int) $orgId) {
                throw new RuntimeException(
                    'Branch does not belong to the organization on this record.'
                );
            }
        });
    }

    public function branch(): BelongsTo
    {
        $model = config('accounting.models.branch', \App\Models\Branch::class);

        return $this->belongsTo($model);
    }

    public function organization(): BelongsTo
    {
        $model = config('accounting.models.organization', \App\Models\Organization::class);

        return $this->belongsTo($model);
    }
}