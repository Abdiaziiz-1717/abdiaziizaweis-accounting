<?php

namespace Abdiaziizaweis\Accounting\Http\Controllers;

use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;

use Abdiaziizaweis\Accounting\Http\Controllers\BaseController;
use Abdiaziizaweis\Accounting\Models\AccountingBranchSetting;
use Illuminate\Http\Request;

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
        $validated = $request->validate([
            'first_month_financial_year' => 'sometimes|required|integer|min:1|max:12',
            'accounting_method' => 'sometimes|required|in:accrual,cash',
            'close_the_books' => 'sometimes|boolean',
            'closing_date' => 'nullable|date',
            'enable_account_numbers' => 'sometimes|boolean',
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

    public function closePeriod(Request $request)
    {
        $validated = $request->validate([
            'closing_date' => 'required|date',
        ]);

        $branchId = app(AccountingContextInterface::class)->getBranchId();
        $orgId = app(AccountingContextInterface::class)->getOrganizationId();

        $settings = AccountingBranchSetting::query()->firstOrCreate(
            ['branch_id' => $branchId],
            [
                'organization_id' => $orgId,
                'first_month_financial_year' => 1,
                'accounting_method' => 'accrual',
            ]
        );

        $settings->update([
            'close_the_books' => true,
            'closing_date' => $validated['closing_date'],
        ]);

        return $this->successResponse($settings->fresh(), 'Accounting period closed');
    }

    public function reopenPeriod()
    {
        $branchId = app(AccountingContextInterface::class)->getBranchId();
        $orgId = app(AccountingContextInterface::class)->getOrganizationId();

        $settings = AccountingBranchSetting::query()->firstOrCreate(
            ['branch_id' => $branchId],
            ['organization_id' => $orgId]
        );

        $settings->update([
            'close_the_books' => false,
        ]);

        return $this->successResponse($settings->fresh(), 'Accounting period reopened');
    }
}
