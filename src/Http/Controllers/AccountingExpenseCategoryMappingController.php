<?php

namespace Jtech\Accounting\Http\Controllers;

use Jtech\Accounting\Contracts\AccountingContextInterface;

use Jtech\Accounting\Http\Controllers\BaseController;
use Jtech\Accounting\Models\AccountingExpenseCategoryMapping;
use Jtech\Accounting\Services\AccountingMappingResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountingExpenseCategoryMappingController extends BaseController
{
    protected $model = AccountingExpenseCategoryMapping::class;

    protected $ownershipEnabled = false;

    protected $searchableFields = [];

    protected $sortableFields = ['id', 'expense_category_id', 'created_at'];

    protected $relationships = [
        'expenseCategory:id,name,status',
        'debitAccount:id,name,number',
        'creditAccount:id,name,number',
    ];

    public function upsert(Request $request)
    {
        $orgId = app(AccountingContextInterface::class)->getOrganizationId();
        $branchId = app(AccountingContextInterface::class)->getBranchId();

        $accountRule = Rule::exists('accounting_accounts', 'id')->where(function ($q) use ($orgId) {
            $q->whereNull('deleted_at');
            if ($orgId) {
                $q->where('organization_id', $orgId);
            }
        });

        $validated = $request->validate([
            'mappings' => 'required|array|min:1',
            'mappings.*.expense_category_id' => [
                'required',
                'integer',
                Rule::exists('expense_categories', 'id')->where(function ($q) use ($orgId) {
                    $q->whereNull('deleted_at');
                    if ($orgId) {
                        $q->where('organization_id', $orgId);
                    }
                }),
            ],
            'mappings.*.debit_account_id' => ['required', 'integer', $accountRule],
            'mappings.*.credit_account_id' => ['required', 'integer', $accountRule],
            'mappings.*.is_enabled' => 'sometimes|boolean',
        ]);

        $rows = DB::transaction(function () use ($validated, $orgId, $branchId) {
            $saved = [];
            foreach ($validated['mappings'] as $row) {
                $saved[] = AccountingExpenseCategoryMapping::query()->updateOrCreate(
                    [
                        'branch_id' => $branchId,
                        'expense_category_id' => $row['expense_category_id'],
                    ],
                    [
                        'organization_id' => $orgId,
                        'debit_account_id' => $row['debit_account_id'],
                        'credit_account_id' => $row['credit_account_id'],
                        'is_enabled' => $row['is_enabled'] ?? true,
                    ]
                );
            }

            return $saved;
        });

        app(AccountingMappingResolver::class)->forgetBranch($branchId);

        return $this->successResponse(
            AccountingExpenseCategoryMapping::query()->with($this->relationships)->whereIn('id', collect($rows)->pluck('id'))->get(),
            'Expense category mappings saved'
        );
    }
}
