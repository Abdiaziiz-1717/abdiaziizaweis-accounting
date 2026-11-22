<?php

namespace Jtech\Accounting\Http\Controllers;

use Jtech\Accounting\Contracts\AccountingContextInterface;

use Jtech\Accounting\Enums\AccountingAccountType;
use Jtech\Accounting\Http\Controllers\BaseController;
use Jtech\Accounting\Models\AccountingAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountingAccountController extends BaseController
{
    protected $model = AccountingAccount::class;

    protected $searchableFields = ['name', 'number', 'key_name', 'description'];

    protected $sortableFields = [
        'id', 'name', 'number', 'account_type', 'is_active', 'created_at', 'updated_at',
    ];

    protected $defaultSortField = 'number';

    protected $defaultSortDirection = 'asc';

    protected $relationships = ['parent:id,name,number', 'children:id,name,number,parent_id'];

    public function index(Request $request)
    {
        $query = $this->applyOwnershipScope($this->model::query());

        $query = $this->applyApiFilters(
            $query,
            $request,
            $this->searchableFields,
            $this->sortableFields,
            $this->defaultSortField,
            $this->defaultSortDirection
        );

        if (! empty($this->relationships)) {
            $query->with($this->relationships);
        }

        // Append current balance as a computed sub-select column.
        // Only active JEs count toward balances (voided kept for audit, excluded here).
        $balanceSub = DB::raw("(
            SELECT COALESCE(SUM(jel.debit), 0) - COALESCE(SUM(jel.credit), 0)
            FROM accounting_journal_entry_lines jel
            INNER JOIN accounting_journal_entries je ON jel.journal_entry_id = je.id
            WHERE jel.account_id = accounting_accounts.id
              AND (je.status = 'active' OR je.status IS NULL)
        ) AS current_balance");

        $query->addSelect(DB::raw('accounting_accounts.*'))->addSelect($balanceSub);

        if ($request->filled('account_type')) {
            $query->where('account_type', $request->input('account_type'));
        }
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('parent_id')) {
            $query->where('parent_id', (int) $request->input('parent_id'));
        }
        if ($request->boolean('roots_only')) {
            $query->whereNull('parent_id');
        }
        if ($request->boolean('non_zero_balance')) {
            $query->whereRaw("(
                SELECT COALESCE(SUM(jel.debit), 0) - COALESCE(SUM(jel.credit), 0)
                FROM accounting_journal_entry_lines jel
                INNER JOIN accounting_journal_entries je ON jel.journal_entry_id = je.id
                WHERE jel.account_id = accounting_accounts.id
                  AND (je.status = 'active' OR je.status IS NULL)
            ) <> 0");
        }

        return $this->paginateResponse($query, $request);
    }

    public function store(Request $request)
    {
        $this->validationRules['store'] = $this->rules();

        // Auto-assign account number when not supplied
        if (! $request->filled('number')) {
            $request->merge([
                'number' => $this->nextAccountNumber(
                    $request->input('account_type'),
                    $request->input('parent_id') ? (int) $request->input('parent_id') : null
                ),
            ]);
        }

        return parent::store($request);
    }

    public function update(Request $request, $id)
    {
        $this->validationRules['update'] = $this->rules(true);

        return parent::update($request, $id);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes|required' : 'required';
        $orgId = app(AccountingContextInterface::class)->getOrganizationId();

        return [
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('accounting_accounts', 'id')->where(function ($q) use ($orgId) {
                    $q->whereNull('deleted_at');
                    if ($orgId) {
                        $q->where('organization_id', $orgId);
                    }
                }),
            ],
            'name' => [$required, 'string', 'max:255'],
            // Number is optional on input; auto-assigned in store() when omitted
            'number' => 'nullable|string|max:40',
            'key_name' => 'nullable|string|max:80',
            'account_type' => [$required, Rule::enum(AccountingAccountType::class)],
            'detail_type' => 'nullable|string|max:80',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
            'is_default' => 'sometimes|boolean',
        ];
    }

    /**
     * Auto-assign the next sequential account number within the correct range.
     *
     * Range by account type:
     *   1xxx = asset / cash / bank / current_assets / fixed_assets / accounts_receivable
     *   2xxx = liability / accounts_payable / credit_card / current_liabilities / noncurrent_liabilities
     *   3xxx = equity / owner_equity
     *   4xxx = income / other_income
     *   5xxx = cost_of_sales
     *   6xxx = expenses / other_expense / expense
     *
     * When a parent account is given, the child number stays within the parent
     * account's block (parentNumber * 10 … parentNumber * 10 + 9).
     */
    protected function nextAccountNumber(string $accountType, ?int $parentId): string
    {
        // Determine the numeric range start from account type
        $assetTypes = ['asset', 'cash', 'bank', 'current_assets', 'fixed_assets', 'accounts_receivable'];
        $liabilityTypes = ['liability', 'accounts_payable', 'credit_card', 'current_liabilities', 'noncurrent_liabilities'];
        $equityTypes = ['equity', 'owner_equity'];
        $incomeTypes = ['income', 'other_income'];
        $cosTypes = ['cost_of_sales'];
        $expenseTypes = ['expenses', 'other_expense', 'expense'];

        if (in_array($accountType, $assetTypes, true)) {
            $rangeStart = 1000;
            $rangeEnd   = 1999;
        } elseif (in_array($accountType, $liabilityTypes, true)) {
            $rangeStart = 2000;
            $rangeEnd   = 2999;
        } elseif (in_array($accountType, $equityTypes, true)) {
            $rangeStart = 3000;
            $rangeEnd   = 3999;
        } elseif (in_array($accountType, $incomeTypes, true)) {
            $rangeStart = 4000;
            $rangeEnd   = 4999;
        } elseif (in_array($accountType, $cosTypes, true)) {
            $rangeStart = 5000;
            $rangeEnd   = 5999;
        } elseif (in_array($accountType, $expenseTypes, true)) {
            $rangeStart = 6000;
            $rangeEnd   = 6999;
        } else {
            $rangeStart = 9000;
            $rangeEnd   = 9999;
        }

        $query = AccountingAccount::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereNotNull('number')
            ->whereRaw("number REGEXP '^[0-9]+$'")
            ->where('number', '>=', (string) $rangeStart)
            ->where('number', '<=', (string) $rangeEnd);

        // Scope to the current org's branch (active through the standard scope)
        $orgId = app(AccountingContextInterface::class)->getOrganizationId();
        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        // Narrow further: if a parent is given, stay within the parent's sub-block
        if ($parentId) {
            $parent = AccountingAccount::withoutGlobalScopes()
                ->whereKey($parentId)
                ->whereNull('deleted_at')
                ->select('number')
                ->first();

            if ($parent && $parent->number && ctype_digit((string) $parent->number)) {
                $pNum = (int) $parent->number;
                // e.g. parent 1100 → children 1100–1199
                $blockSize = 100;
                $blockStart = $pNum;
                $blockEnd   = $pNum + $blockSize - 1;
                $query->where('number', '>=', (string) $blockStart)
                      ->where('number', '<=', (string) $blockEnd);
                $rangeStart = $blockStart;
                $rangeEnd   = $blockEnd;
            }
        }

        $max = (int) ($query->max(DB::raw('CAST(number AS UNSIGNED)')) ?? ($rangeStart - 1));
        $next = max($rangeStart, $max + 1);

        return (string) min($next, $rangeEnd);
    }
}
