<?php

namespace Jtech\Accounting\Http\Controllers;

use Jtech\Accounting\Contracts\AccountingContextInterface;

use Jtech\Accounting\Http\Controllers\BaseController;
use Jtech\Accounting\Models\AccountingBranchSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountingSettingsController extends BaseController
{
    protected $model = AccountingBranchSetting::class;

    protected $ownershipEnabled = false;

    public function show($id = null)
    {
        $branchId = app(AccountingContextInterface::class)->getBranchId();
        $orgId = app(AccountingContextInterface::class)->getOrganizationId();

        $settings = AccountingBranchSetting::query()->firstOrCreate(
            ['branch_id' => $branchId],
            [
                'organization_id' => $orgId,
                'first_month_financial_year' => 1,
                'accounting_method' => 'accrual',
                'close_the_books' => false,
                'enable_account_numbers' => false,
            ]
        );

        return $this->successResponse($settings);
    }

    public function update(Request $request, $id = null)
    {
        $orgId = app(AccountingContextInterface::class)->getOrganizationId();
        $accountRule = Rule::exists('accounting_accounts', 'id')->where(function ($q) use ($orgId) {
            $q->whereNull('deleted_at');
            if ($orgId) {
                $q->where('organization_id', $orgId);
            }
        });

        $validated = $request->validate([
            'first_month_financial_year' => 'sometimes|required|integer|min:1|max:12',
            'accounting_method' => 'sometimes|required|in:accrual,cash',
            'close_the_books' => 'sometimes|boolean',
            'closing_date' => 'nullable|date',
            'enable_account_numbers' => 'sometimes|boolean',
            // Optional — when unset, invoices post the plain 2-line JE (unchanged behavior).
            'tax_payable_account_id' => ['nullable', 'integer', $accountRule],
            'tax_receivable_account_id' => ['nullable', 'integer', $accountRule],
            'sales_discount_account_id' => ['nullable', 'integer', $accountRule],
            'purchase_discount_account_id' => ['nullable', 'integer', $accountRule],
        ]);

        $branchId = app(AccountingContextInterface::class)->getBranchId();
        $orgId = app(AccountingContextInterface::class)->getOrganizationId();

        $settings = AccountingBranchSetting::query()->firstOrCreate(
            ['branch_id' => $branchId],
            ['organization_id' => $orgId]
        );

        $settings->update($validated);

        return $this->successResponse($settings->fresh(), 'Accounting settings updated');
    }
}
