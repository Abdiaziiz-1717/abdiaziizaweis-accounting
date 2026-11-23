<?php

namespace Abdiaziizaweis\Accounting\Http\Controllers;

use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;
use Abdiaziizaweis\Accounting\Models\AccountingPaymentMethodMapping;
use Abdiaziizaweis\Accounting\Models\AccountingPaymentMethodMappingSide;
use Abdiaziizaweis\Accounting\Services\AccountingMappingResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountingPaymentMethodMappingSideController extends BaseController
{
    public function index(int $id)
    {
        $mapping = $this->findMapping($id);
        if (! $mapping) {
            return $this->notFoundResponse('Payment method mapping not found.');
        }

        return $this->successResponse(
            $mapping->sides()->with(['debitAccount:id,name,number', 'creditAccount:id,name,number'])->get()
        );
    }

    public function store(Request $request, int $id)
    {
        $mapping = $this->findMapping($id);
        if (! $mapping) {
            return $this->notFoundResponse('Payment method mapping not found.');
        }

        $validated = $this->validateSide($request, $mapping);

        $side = AccountingPaymentMethodMappingSide::query()->updateOrCreate(
            [
                'payment_method_mapping_id' => $mapping->id,
                'module' => $validated['module'],
            ],
            [
                'organization_id' => $mapping->organization_id,
                'branch_id' => $mapping->branch_id,
                'debit_account_id' => $validated['debit_account_id'],
                'credit_account_id' => $validated['credit_account_id'],
                'is_enabled' => $validated['is_enabled'] ?? true,
            ]
        );

        app(AccountingMappingResolver::class)->forgetBranch($mapping->branch_id);

        return $this->createdResponse(
            $side->load(['debitAccount:id,name,number', 'creditAccount:id,name,number']),
            'Payment method mapping side saved'
        );
    }

    public function update(Request $request, int $id, string $module)
    {
        $mapping = $this->findMapping($id);
        if (! $mapping) {
            return $this->notFoundResponse('Payment method mapping not found.');
        }

        $validated = $this->validateSide($request, $mapping, $module);

        $side = AccountingPaymentMethodMappingSide::query()->updateOrCreate(
            [
                'payment_method_mapping_id' => $mapping->id,
                'module' => $validated['module'],
            ],
            [
                'organization_id' => $mapping->organization_id,
                'branch_id' => $mapping->branch_id,
                'debit_account_id' => $validated['debit_account_id'],
                'credit_account_id' => $validated['credit_account_id'],
                'is_enabled' => $validated['is_enabled'] ?? true,
            ]
        );

        app(AccountingMappingResolver::class)->forgetBranch($mapping->branch_id);

        return $this->successResponse(
            $side->load(['debitAccount:id,name,number', 'creditAccount:id,name,number']),
            'Payment method mapping side updated'
        );
    }

    public function destroy(int $id, string $module)
    {
        $mapping = $this->findMapping($id);
        if (! $mapping) {
            return $this->notFoundResponse('Payment method mapping not found.');
        }

        $module = strtolower($module);
        $side = $mapping->sides()->where('module', $module)->first();
        if (! $side) {
            return $this->notFoundResponse("No mapping side found for module [{$module}].");
        }

        $side->delete();
        app(AccountingMappingResolver::class)->forgetBranch($mapping->branch_id);

        return $this->noContentResponse('Payment method mapping side removed');
    }

    protected function findMapping(int $id): ?AccountingPaymentMethodMapping
    {
        return AccountingPaymentMethodMapping::query()->find($id);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateSide(Request $request, AccountingPaymentMethodMapping $mapping, ?string $routeModule = null): array
    {
        $orgId = app(AccountingContextInterface::class)->getOrganizationId();
        $accountRule = Rule::exists('accounting_accounts', 'id')->where(function ($q) use ($orgId) {
            $q->whereNull('deleted_at');
            if ($orgId) {
                $q->where('organization_id', $orgId);
            }
        });

        $rules = [
            'module' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_-]*$/'],
            'debit_account_id' => ['required', 'integer', $accountRule],
            'credit_account_id' => ['required', 'integer', $accountRule],
            'is_enabled' => ['sometimes', 'boolean'],
        ];

        if ($routeModule !== null) {
            $rules['module'] = ['sometimes', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_-]*$/'];
            $request->merge(['module' => strtolower($routeModule)]);
        }

        return $request->validate($rules);
    }
}