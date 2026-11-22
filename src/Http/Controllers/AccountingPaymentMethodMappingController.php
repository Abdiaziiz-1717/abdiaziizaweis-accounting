<?php

namespace Jtech\Accounting\Http\Controllers;

use Jtech\Accounting\Contracts\AccountingContextInterface;

use Jtech\Accounting\Http\Controllers\BaseController;
use Jtech\Accounting\Models\AccountingPaymentMethodMapping;
use Jtech\Accounting\Services\AccountingMappingResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountingPaymentMethodMappingController extends BaseController
{
    protected $model = AccountingPaymentMethodMapping::class;

    protected $ownershipEnabled = false;

    protected $searchableFields = [];

    protected $sortableFields = ['id', 'payment_method_id', 'created_at'];

    protected $relationships = [
        'paymentMethod:id,name,is_active',
        'salesDebitAccount:id,name,number',
        'salesCreditAccount:id,name,number',
        'purchaseDebitAccount:id,name,number',
        'purchaseCreditAccount:id,name,number',
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
            'mappings.*.payment_method_id' => [
                'required',
                'integer',
                Rule::exists('payment_methods', 'id')->where(function ($q) use ($orgId) {
                    $q->whereNull('deleted_at');
                    if ($orgId) {
                        $q->where('organization_id', $orgId);
                    }
                }),
            ],
            'mappings.*.sales_debit_account_id' => ['required', 'integer', $accountRule],
            'mappings.*.sales_credit_account_id' => ['required', 'integer', $accountRule],
            'mappings.*.purchase_debit_account_id' => [
                'nullable', 'integer', 'required_with:mappings.*.purchase_credit_account_id', $accountRule,
            ],
            'mappings.*.purchase_credit_account_id' => [
                'nullable', 'integer', 'required_with:mappings.*.purchase_debit_account_id', $accountRule,
            ],
            'mappings.*.is_enabled' => 'sometimes|boolean',
        ]);

        $rows = DB::transaction(function () use ($validated, $orgId, $branchId) {
            $saved = [];
            foreach ($validated['mappings'] as $row) {
                $saved[] = AccountingPaymentMethodMapping::query()->updateOrCreate(
                    [
                        'branch_id' => $branchId,
                        'payment_method_id' => $row['payment_method_id'],
                    ],
                    [
                        'organization_id' => $orgId,
                        'sales_debit_account_id' => $row['sales_debit_account_id'],
                        'sales_credit_account_id' => $row['sales_credit_account_id'],
                        // Purchase (money out to suppliers) pair is optional — falls
                        // back to the purchase-payment document map when unset.
                        'purchase_debit_account_id' => $row['purchase_debit_account_id'] ?? null,
                        'purchase_credit_account_id' => $row['purchase_credit_account_id'] ?? null,
                        'is_enabled' => $row['is_enabled'] ?? true,
                    ]
                );
            }

            return $saved;
        });

        app(AccountingMappingResolver::class)->forgetBranch($branchId);

        return $this->successResponse(
            AccountingPaymentMethodMapping::query()->with($this->relationships)->whereIn('id', collect($rows)->pluck('id'))->get(),
            'Payment method mappings saved'
        );
    }
}
