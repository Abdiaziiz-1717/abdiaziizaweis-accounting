<?php

namespace Jtech\Accounting\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Jtech\Accounting\Contracts\AccountingContextInterface;

class AccountingContextScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(AccountingContextInterface::class);
        $table = $model->getTable();

        $builder->where("{$table}.organization_id", $context->getOrganizationId());

        $branchId = $context->getBranchId();
        if ($branchId !== null) {
            $builder->where("{$table}.branch_id", $branchId);
        }
    }
}