<?php

namespace Abdiaziizaweis\Accounting\Http\Controllers;

use Abdiaziizaweis\Accounting\Contracts\AccountingReportInterface;
use Abdiaziizaweis\Accounting\Http\Traits\ApiResponseTrait;
use Abdiaziizaweis\Accounting\Registry\AccountingReportRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class CustomReportController extends Controller
{
    use ApiResponseTrait;

    public function index(): JsonResponse
    {
        return $this->successResponse(
            AccountingReportRegistry::allCustom(),
            'Custom reports retrieved successfully',
        );
    }

    public function show(Request $request, string $name): JsonResponse
    {
        $entry = AccountingReportRegistry::findCustom($name);
        if ($entry === null) {
            return $this->notFoundResponse("Custom report [{$name}] is not registered.");
        }

        /** @var AccountingReportInterface $handler */
        $handler = app($entry['handler']);

        $validator = Validator::make($request->all(), $handler->rules());
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->toArray());
        }

        return $this->successResponse(
            $handler->handle($validator->validated()),
            'Report generated successfully',
        );
    }
}