<?php

namespace Abdiaziizaweis\Accounting\Http\Controllers;

use Abdiaziizaweis\Accounting\Http\Traits\ApiResponseTrait;
use Abdiaziizaweis\Accounting\Models\AccountingOperationMapping;
use Abdiaziizaweis\Accounting\Services\OperationMappingService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class OperationMappingController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        protected OperationMappingService $mappings,
    ) {}

    public function index()
    {
        $rows = AccountingOperationMapping::query()
            ->with(['lines.account:id,name,number,key_name'])
            ->orderBy('operation_key')
            ->get()
            ->map(fn (AccountingOperationMapping $mapping) => [
                'operation_key' => $mapping->operation_key,
                'label' => $mapping->label,
                'amount_sources' => $mapping->lines->pluck('amount_source')->unique()->values(),
                'is_enabled' => (bool) $mapping->is_enabled,
                'line_count' => $mapping->lines->count(),
                'lines' => $mapping->lines,
            ]);

        return $this->successResponse($rows);
    }

    public function show(string $operationKey)
    {
        $mapping = AccountingOperationMapping::query()
            ->with(['lines.account:id,name,number,key_name'])
            ->where('operation_key', $operationKey)
            ->first();

        return $this->successResponse([
            'operation_key' => $operationKey,
            'label' => $mapping?->label ?: $operationKey,
            'amount_sources' => $mapping?->lines->pluck('amount_source')->unique()->values() ?? [],
            'is_enabled' => (bool) ($mapping?->is_enabled),
            'lines' => $mapping?->lines ?? [],
        ]);
    }

    public function replace(Request $request, string $operationKey)
    {
        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'is_enabled' => ['sometimes', 'boolean'],
            'lines' => ['present', 'array'],
            'lines.*.account_key' => ['nullable', 'string', 'max:80'],
            'lines.*.account_id' => ['nullable', 'integer'],
            'lines.*.side' => ['required', 'in:debit,credit'],
            'lines.*.amount_source' => ['required', 'string', 'max:64'],
            'lines.*.skip_when_zero' => ['sometimes', 'boolean'],
        ]);

        $mapping = $this->mappings->replace(
            $operationKey,
            $validated['lines'],
            $validated['label'] ?? null,
            array_key_exists('is_enabled', $validated) ? (bool) $validated['is_enabled'] : null,
        );

        return $this->successResponse($mapping, 'Operation mapping saved');
    }
}
