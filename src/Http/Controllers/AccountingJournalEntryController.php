<?php

namespace Abdiaziizaweis\Accounting\Http\Controllers;

use Abdiaziizaweis\Accounting\Contracts\AccountingAuthInterface;

use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;

use Abdiaziizaweis\Accounting\Http\Controllers\BaseController;
use Abdiaziizaweis\Accounting\Models\AccountingJournalEntry;
use Abdiaziizaweis\Accounting\Services\AccountingJournalService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountingJournalEntryController extends BaseController
{
    protected $model = AccountingJournalEntry::class;

    protected $searchableFields = ['number', 'description', 'reference'];

    protected $sortableFields = [
        'id', 'number', 'journal_date', 'amount', 'source', 'created_at', 'updated_at',
    ];

    protected $defaultSortField = 'journal_date';

    protected $relationships = ['lines.account:id,name,number', 'owner:id,name'];

    public function __construct(
        protected AccountingJournalService $journals,
    ) {}

    public function index(Request $request)
    {
        $query = $this->applyOwnershipScope($this->model::query());

        // Default: show active entries only. Pass include_voided=1 or scope=audit to see all.
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

        if (! empty($this->relationships)) {
            $query->with($this->relationships);
        }

        if ($request->filled('source')) {
            $query->where('source', $request->input('source'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('journal_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('journal_date', '<=', $request->input('date_to'));
        }

        return $this->paginateResponse($query, $request);
    }

    public function store(Request $request)
    {
        $orgId = app(AccountingContextInterface::class)->getOrganizationId();
        $validated = $request->validate([
            'journal_date' => 'required|date',
            'description' => 'nullable|string',
            'reference' => 'nullable|string|max:120',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => [
                'required',
                'integer',
                Rule::exists('accounting_accounts', 'id')->where(function ($q) use ($orgId) {
                    $q->whereNull('deleted_at');
                    if ($orgId) {
                        $q->where('organization_id', $orgId);
                    }
                }),
            ],
            'lines.*.debit' => 'nullable|numeric|min:0',
            'lines.*.credit' => 'nullable|numeric|min:0',
            'lines.*.memo' => 'nullable|string|max:255',
        ]);

        $entry = $this->journals->createManualEntry([
            'journal_date' => $validated['journal_date'],
            'description' => $validated['description'] ?? null,
            'reference' => $validated['reference'] ?? null,
            'owner_user_id' => app(AccountingAuthInterface::class)->user()?->id,
        ], $validated['lines']);

        $this->logActivity('Accounting journal entry was created', $entry, [], 'created');

        return $this->createdResponse($entry->load($this->relationships));
    }

    public function update(Request $request, $id)
    {
        $entry = $this->applyOwnershipScope($this->model::query())->with('lines')->find($id);
        if (! $entry) {
            return $this->notFoundResponse();
        }

        $orgId = app(AccountingContextInterface::class)->getOrganizationId();
        $validated = $request->validate([
            'journal_date' => 'sometimes|required|date',
            'description' => 'nullable|string',
            'reference' => 'nullable|string|max:120',
            'lines' => 'sometimes|required|array|min:2',
            'lines.*.account_id' => [
                'required',
                'integer',
                Rule::exists('accounting_accounts', 'id')->where(function ($q) use ($orgId) {
                    $q->whereNull('deleted_at');
                    if ($orgId) {
                        $q->where('organization_id', $orgId);
                    }
                }),
            ],
            'lines.*.debit' => 'nullable|numeric|min:0',
            'lines.*.credit' => 'nullable|numeric|min:0',
            'lines.*.memo' => 'nullable|string|max:255',
        ]);

        if (isset($validated['lines'])) {
            $replacement = $this->journals->reverseOrReplace($entry, [
                'journal_date' => $validated['journal_date'] ?? $entry->journal_date,
                'description' => array_key_exists('description', $validated)
                    ? $validated['description']
                    : $entry->description,
                'reference' => array_key_exists('reference', $validated)
                    ? $validated['reference']
                    : $entry->reference,
                'owner_user_id' => $entry->owner_user_id,
                'organization_id' => $entry->organization_id,
                'branch_id' => $entry->branch_id,
                'source' => $entry->source,
                'accounting_transfer_id' => $entry->accounting_transfer_id,
            ], $validated['lines']);

            $this->logActivity('Accounting journal entry was replaced', $replacement, [], 'updated');

            return $this->successResponse(
                $replacement->load($this->relationships),
                'Resource updated successfully'
            );
        }

        $entry->update(collect($validated)->only(['journal_date', 'description', 'reference'])->all());
        $this->logActivity('Accounting journal entry was updated', $entry, [], 'updated');

        return $this->successResponse($entry->load($this->relationships), 'Resource updated successfully');
    }

    public function destroy($id)
    {
        $entry = $this->applyOwnershipScope($this->model::query())->with('lines')->find($id);
        if (! $entry) {
            return $this->notFoundResponse();
        }

        $this->journals->voidEntry($entry, 'Voided via API');

        $this->logActivity('Accounting journal entry was voided', $entry, [], 'voided');

        return $this->successResponse(null, 'Journal entry voided successfully');
    }
}
