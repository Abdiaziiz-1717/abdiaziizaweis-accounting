<?php

namespace Abdiaziizaweis\Accounting\Http\Controllers;

use Abdiaziizaweis\Accounting\Contracts\AccountingAuthInterface;

use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;

use Abdiaziizaweis\Accounting\Http\Controllers\BaseController;
use Abdiaziizaweis\Accounting\Models\AccountingTransfer;
use Abdiaziizaweis\Accounting\Services\AccountingTransferService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountingTransferController extends BaseController
{
    protected $model = AccountingTransfer::class;

    protected $searchableFields = ['notes'];

    protected $sortableFields = [
        'id', 'amount', 'transfer_date', 'created_at', 'updated_at',
    ];

    protected $defaultSortField = 'transfer_date';

    protected $relationships = [
        'fromAccount:id,name,number',
        'toAccount:id,name,number',
        'journalEntry.lines',
        'owner:id,name',
    ];

    public function __construct(
        protected AccountingTransferService $transfers,
    ) {}

    public function store(Request $request)
    {
        $orgId = app(AccountingContextInterface::class)->getOrganizationId();
        $accountRule = Rule::exists('accounting_accounts', 'id')->where(function ($q) use ($orgId) {
            $q->whereNull('deleted_at');
            if ($orgId) {
                $q->where('organization_id', $orgId);
            }
        });

        $validated = $request->validate([
            'from_account_id' => ['required', 'integer', $accountRule],
            'to_account_id' => ['required', 'integer', $accountRule],
            'amount' => 'required|numeric|min:0.01|max:999999999.99',
            'transfer_date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $transfer = $this->transfers->create([
            ...$validated,
            'owner_user_id' => app(AccountingAuthInterface::class)->user()?->id,
        ]);

        $this->logActivity('Accounting transfer was created', $transfer, [], 'created');

        return $this->createdResponse($transfer);
    }

    public function destroy($id)
    {
        $transfer = $this->applyOwnershipScope($this->model::query())->with('journalEntry.lines')->find($id);
        if (! $transfer) {
            return $this->notFoundResponse();
        }

        try {
            $this->transfers->delete($transfer);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse(
                collect($e->errors())->flatten()->first() ?: 'Cannot delete transfer.',
                $e->errors(),
                422
            );
        }

        $this->logActivity('Accounting transfer was deleted', $transfer, [], 'deleted');

        return $this->noContentResponse('Resource deleted successfully');
    }
}
