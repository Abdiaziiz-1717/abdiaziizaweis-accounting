<?php

namespace Abdiaziizaweis\Accounting\Http\Controllers;

use Abdiaziizaweis\Accounting\Http\Traits\ApiResponseTrait;
use Abdiaziizaweis\Accounting\Registry\DocumentRegistry;
use Illuminate\Routing\Controller;

/**
 * Host-declared operation catalog. Does not create mapping rows.
 */
class DocumentRegistryController extends Controller
{
    use ApiResponseTrait;

    public function index()
    {
        return $this->successResponse(DocumentRegistry::all());
    }
}
