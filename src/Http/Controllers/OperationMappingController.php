<?php

namespace Abdiaziizaweis\Accounting\Http\Controllers;

use Abdiaziizaweis\Accounting\Http\Traits\ApiResponseTrait;
use Abdiaziizaweis\Accounting\Models\AccountingOperationMapping;
use Abdiaziizaweis\Accounting\Registry\DocumentRegistry;
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
        $saved = AccountingOperationMapping::query()
            ->with(['lines.account:id,name,number,key_name'])
            ->orderBy('operation_key')
            ->get()
            ->keyBy('operation_key');

        $rows = [];
        $seen = [];

        foreach (DocumentRegistry::all() as $declared) {
            $key = $declared['operation_key'];
            $seen[$key] = true;
            $mapping = $saved->get($key);
            $rows[] = [
                'operation_key' => $key,
                'label' => $mapping?->label ?: $declared['label'],
                'amount_sources' => $declared['amount_sources'],
                'is_enabled' => (bool) ($mapping?->is_enabled),
                'line_count' => $mapping ? $mapping->lines->count() : 0,
                'lines' => $mapping?->lines ?? [],
                'declared' => true,
            ];
        }

        foreach ($saved as $key => $mapping) {
            if (isset($seen[$key])) {
                continue;
            }
            $rows[] = [
                'operation_key' => $mapping->operation_key,
                'label' => $mapping->label,
                'amount_sources' => $mapping->lines->pluck('amount_source')->unique()->values(),
                'is_enabled' => (bool) $mapping->is_enabled,
                'line_count' => $mapping->lines->count(),
                'lines' => $mapping->lines,
                'declared' => false,
            ];
        }

        return $this->successResponse($rows);
    }

    public function show(string $operationKey)
    {
        $mapping = AccountingOperationMapping::query()
            ->with(['lines.account:id,name,number,key_name'])
            ->where('operation_key', $operationKey)
            ->first();

        $declared = collect(DocumentRegistry::all())->firstWhere('operation_key', $operationKey);

        return $this->successResponse([
            'operation_key' => $operationKey,
            'label' => $mapping?->label ?: ($declared['label'] ?? $operationKey),
            'amount_sources' => $declared['amount_sources'] ?? ($mapping?->lines->pluck('amount_source')->unique()->values() ?? []),
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
