<?php

namespace Abdiaziizaweis\Accounting\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;
use Abdiaziizaweis\Accounting\Database\Migration\TenancySchemaHelper;

class AccountingContextScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (TenancySchemaHelper::isSingleTenant()) {
            return;
        }

        $context = app(AccountingContextInterface::class);
        $table = $model->getTable();

        $builder->where("{$table}.organization_id", $context->getOrganizationId());

        $branchId = $context->getBranchId();
        if ($branchId !== null) {
            $builder->where("{$table}.branch_id", $branchId);
        }
    }
}