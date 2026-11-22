<?php

namespace Jtech\Accounting\Http\Controllers;

use Jtech\Accounting\Contracts\AccountingContextInterface;

use Jtech\Accounting\Enums\AccountingModule;
use Jtech\Accounting\Http\Controllers\BaseController;
use Jtech\Accounting\Models\AccountingDocumentMapping;
use Jtech\Accounting\Services\AccountingMappingResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountingDocumentMappingController extends BaseController
{
    protected $model = AccountingDocumentMapping::class;

    protected $ownershipEnabled = false;

    protected $searchableFields = ['module', 'document_type'];

    protected $sortableFields = ['id', 'module', 'document_type', 'created_at'];

    protected $relationships = [
        'debitAccount:id,name,number',
        'creditAccount:id,name,number',
    ];

    public function upsert(Request $request)
    {
        $orgId = app(AccountingContextInterface::class)->getOrganizationId();
        $branchId = app(AccountingContextInterface::class)->getBranchId();

        $validated = $request->validate([
            'mappings' => 'required|array|min:1',
            'mappings.*.module' => ['required', Rule::enum(AccountingModule::class)],
            'mappings.*.document_type' => 'required|string|max:40',
            'mappings.*.debit_account_id' => [
                'required',
                'integer',
                Rule::exists('accounting_accounts', 'id')->where(function ($q) use ($orgId) {
                    $q->whereNull('deleted_at');
                    if ($orgId) {
                        $q->where('organization_id', $orgId);
                    }
                }),
            ],
            'mappings.*.credit_account_id' => [
                'required',
                'integer',
                Rule::exists('accounting_accounts', 'id')->where(function ($q) use ($orgId) {
                    $q->whereNull('deleted_at');
                    if ($orgId) {
                        $q->where('organization_id', $orgId);
                    }
                }),
            ],
            'mappings.*.is_enabled' => 'sometimes|boolean',
        ]);

        $rows = DB::transaction(function () use ($validated, $orgId, $branchId) {
            $saved = [];
            foreach ($validated['mappings'] as $row) {
                $module = $row['module'] instanceof AccountingModule
                    ? $row['module']->value
                    : (string) $row['module'];

                $saved[] = AccountingDocumentMapping::query()->updateOrCreate(
                    [
                        'branch_id' => $branchId,
                        'module' => $module,
                        'document_type' => $row['document_type'],
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
            AccountingDocumentMapping::query()->with($this->relationships)->whereIn('id', collect($rows)->pluck('id'))->get(),
            'Document mappings saved'
        );
    }
}
