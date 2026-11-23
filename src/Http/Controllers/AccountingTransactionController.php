<?php

namespace Abdiaziizaweis\Accounting\Http\Controllers;

use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;

use Abdiaziizaweis\Accounting\Http\Controllers\BaseController;
use Abdiaziizaweis\Accounting\Enums\AccountingTransactionStatus;
use Abdiaziizaweis\Accounting\Models\AccountingTransaction;
use Abdiaziizaweis\Accounting\Services\AccountingTransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class AccountingTransactionController extends BaseController
{
    protected $model = AccountingTransaction::class;

    protected $searchableFields = ['type', 'description', 'reference'];

    protected $sortableFields = [
        'id', 'type', 'status', 'amount', 'transaction_date', 'created_at', 'updated_at',
    ];

    protected $defaultSortField = 'transaction_date';

    protected $relationships = [
        'journalEntry.lines.account:id,name,number',
        'transactionable',
        'voidedBy:id,name',
        'supersededBy:id,type,status,amount,transaction_date',
    ];

    public function __construct(
        protected AccountingTransactionService $transactions,
    ) {}

    public function index(Request $request)
    {
        $query = $this->applyOwnershipScope($this->model::query());

        // Default: exclude voided transactions unless audit mode or include_voided requested.
        $includeVoided = $request->boolean('include_voided')
            || $request->input('scope') === 'audit';

        if (! $includeVoided) {
            $query->active();
        }

        $query = $this->applyApiFilters(
            $query,
            $request,
            $this->searchableFields,
            $this->sortableFields,
            $this->defaultSortField,
            $this->defaultSortDirection
        );

        // Index: thin payload (JE number for column). Show/map keep full $relationships.
        $query->with(['journalEntry:id,number']);

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('module')) {
            $module = (string) $request->input('module');
            $query->where(function ($q) use ($module) {
                match ($module) {
                    'sales' => $q->where(function ($inner) {
                        $inner->where('type', 'like', 'hotel_%')
                            ->orWhere('type', 'like', 'restaurant_%')
                            ->orWhere('type', 'like', 'event_%')
                            ->orWhere('type', 'like', 'sales_%')
                            ->orWhere('type', 'like', 'sale_%');
                    }),
                    'service' => $q->where('type', 'like', 'service_%'),
                    'parts' => $q->where('type', 'like', 'parts_%'),
                    'events', 'event' => $q->where('type', 'like', 'event_%'),
                    'expenses', 'expense' => $q->where('type', 'expense'),
                    'crm' => $q->where(function ($inner) {
                        $inner->where('type', 'customer_deposit')
                            ->orWhere('type', 'customer_deposit_refund');
                    }),
                    'payroll' => $q->where('type', 'payroll'),
                    'inventory' => $q->where(function ($inner) {
                        $inner->where('type', 'like', 'inventory_%')
                            ->orWhere('type', 'like', 'purchase_%');
                    }),
                    default => $q->where('type', 'like', $module.'_%'),
                };
            });
        }
        if ($request->filled('date_from')) {
            $query->whereDate('transaction_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('transaction_date', '<=', $request->input('date_to'));
        }

        return $this->paginateResponse($query, $request);
    }

    /**
     * Convenience listing for monitoring: active transactions stuck in not_mapped.
     */
    public function unmapped(Request $request)
    {
        $query = $this->applyOwnershipScope($this->model::query())
            ->active()
            ->where('status', AccountingTransactionStatus::NotMapped)
            ->with([
                'transactionable',
                'journalEntry:id,number',
            ])
            ->orderByDesc('created_at');

        $query = $this->applyApiFilters(
            $query,
            $request,
            $this->searchableFields,
            $this->sortableFields,
            'created_at',
            'desc'
        );

        $perPage = min(max((int) $request->input('per_page', 50), 1), 100);
        $page = max((int) $request->input('page', 1), 1);
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $items = collect($paginator->items())->map(function (AccountingTransaction $tx) {
            $createdAt = $tx->created_at ? Carbon::parse($tx->created_at) : now();

            return [
                'id' => (int) $tx->id,
                'type' => (string) $tx->type,
                'amount' => (float) $tx->amount,
                'reference' => $tx->reference,
                'description' => $tx->description,
                'transaction_date' => $tx->transaction_date?->toDateString(),
                'created_at' => $createdAt->toIso8601String(),
                'unmapped_for' => $createdAt->diffForHumans(now(), true),
                'unmapped_days' => (int) $createdAt->diffInDays(now()),
                'transactionable_type' => $tx->transactionable_type,
                'transactionable_id' => $tx->transactionable_id ? (int) $tx->transactionable_id : null,
            ];
        });

        return $this->successResponse([
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'Unmapped transactions');
    }

    public function bulkMap(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer',
            'module' => 'nullable|string',
            'document_type' => 'nullable|string',
            'payment_method_id' => 'nullable|integer',
            'expense_category_id' => 'nullable|integer',
            'payment_side' => ['nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_-]*$/'],
            'debit_account_id' => 'nullable|integer',
            'credit_account_id' => 'nullable|integer',
        ]);

        $result = $this->transactions->bulkMap(
            $validated['ids'],
            collect($validated)->except('ids')->all()
        );

        return $this->successResponse($result, 'Bulk map completed');
    }

    public function map(Request $request, $id)
    {
        $tx = $this->applyOwnershipScope($this->model::query())->find($id);
        if (! $tx) {
            return $this->notFoundResponse();
        }

        $orgId = app(AccountingContextInterface::class)->getOrganizationId();
        $validated = $request->validate([
            'debit_account_id' => [
                'required',
                'integer',
                Rule::exists('accounting_accounts', 'id')->where(function ($q) use ($orgId) {
                    $q->whereNull('deleted_at');
                    if ($orgId) {
                        $q->where('organization_id', $orgId);
                    }
                }),
            ],
            'credit_account_id' => [
                'required',
                'integer',
                Rule::exists('accounting_accounts', 'id')->where(function ($q) use ($orgId) {
                    $q->whereNull('deleted_at');
                    if ($orgId) {
                        $q->where('organization_id', $orgId);
                    }
                }),
            ],
        ]);

        try {
            $tx = $this->transactions->mapWithAccounts(
                $tx,
                (int) $validated['debit_account_id'],
                (int) $validated['credit_account_id']
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse(
                collect($e->errors())->flatten()->first() ?: 'Map failed.',
                $e->errors(),
                422
            );
        }

        return $this->successResponse($tx->load($this->relationships), 'Transaction mapped');
    }

    public function unmap($id)
    {
        $tx = $this->applyOwnershipScope($this->model::query())->find($id);
        if (! $tx) {
            return $this->notFoundResponse();
        }

        try {
            $tx = $this->transactions->unmap($tx);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse(
                collect($e->errors())->flatten()->first() ?: 'Unmap failed.',
                $e->errors(),
                422
            );
        }

        return $this->successResponse($tx, 'Transaction unmapped');
    }
}
