<?php

namespace Abdiaziizaweis\Accounting\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;
use Abdiaziizaweis\Accounting\Models\AccountingDocumentMapping;
use Abdiaziizaweis\Accounting\Models\AccountingPaymentMethodMappingSide;
use Abdiaziizaweis\Accounting\Registry\DocumentRegistry;

class DocumentRegistryController extends Controller
{
    public function index(): JsonResponse
    {
        /** @var AccountingContextInterface $context */
        $context = app(AccountingContextInterface::class);

        $organizationId = $context->getOrganizationId();
        $branchId = $context->getBranchId();

        $mappings = $this->branchMappingsKeyed($organizationId, $branchId);
        $paymentMethodModules = $this->paymentMethodModulesWithSides($organizationId, $branchId);

        $modules = [];

        foreach (DocumentRegistry::allGroupedByModule() as $module => $documents) {
            $enriched = [];

            foreach ($documents as $document) {
                $mapping = $mappings[self::mappingKey($module, $document['document_type'])] ?? null;

                $enriched[] = array_merge($document, [
                    'is_mapped' => $mapping !== null,
                    'debit_account_id' => $mapping?->debit_account_id,
                    'credit_account_id' => $mapping?->credit_account_id,
                    'is_enabled' => $mapping !== null ? (bool) $mapping->is_enabled : false,
                    'has_payment_method_mapping_side' => isset($paymentMethodModules[strtolower($module)]),
                ]);
            }

            $modules[] = [
                'module' => $module,
                'documents' => $enriched,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'modules' => $modules,
            ],
        ]);
    }

    /**
     * @return array<string, AccountingDocumentMapping>
     */
    protected function branchMappingsKeyed(int $organizationId, ?int $branchId): array
    {
        $query = AccountingDocumentMapping::query()
            ->where('organization_id', $organizationId);

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $keyed = [];

        foreach ($query->get() as $mapping) {
            $module = $mapping->module instanceof \BackedEnum
                ? $mapping->module->value
                : (string) $mapping->module;

            $keyed[self::mappingKey($module, (string) $mapping->document_type)] = $mapping;
        }

        return $keyed;
    }

    protected static function mappingKey(string $module, string $documentType): string
    {
        return strtolower($module).':'.strtolower($documentType);
    }

    /**
     * @return array<string, true>
     */
    protected function paymentMethodModulesWithSides(int $organizationId, ?int $branchId): array
    {
        $query = AccountingPaymentMethodMappingSide::query()
            ->where('is_enabled', true)
            ->whereNotNull('debit_account_id')
            ->whereNotNull('credit_account_id');

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }
        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $modules = [];

        foreach ($query->distinct()->pluck('module') as $module) {
            $modules[strtolower((string) $module)] = true;
        }

        return $modules;
    }
}