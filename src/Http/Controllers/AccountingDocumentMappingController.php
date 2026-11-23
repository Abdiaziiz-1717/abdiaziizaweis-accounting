<?php

namespace Abdiaziizaweis\Accounting\Http\Controllers;

use Abdiaziizaweis\Accounting\Models\AccountingDocumentMapping;
use Illuminate\Http\Request;

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
        return $this->errorResponse(
            'Two-account document mappings are removed in v2. Save N lines with PUT /operations/{operationKey}.',
            [],
            410
        );
    }
}
