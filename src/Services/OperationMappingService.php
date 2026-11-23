<?php

namespace Abdiaziizaweis\Accounting\Services;

use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;
use Abdiaziizaweis\Accounting\Database\Migration\TenancySchemaHelper;
use Abdiaziizaweis\Accounting\Models\AccountingOperationMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationMappingService
{
    public function __construct(
        protected OperationMappingCompiler $compiler,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function replace(string $operationKey, array $lines, ?string $label = null, ?bool $isEnabled = null): AccountingOperationMapping
    {
        $normalized = [];
        foreach (array_values($lines) as $index => $line) {
            $accountId = isset($line['account_id']) ? (int) $line['account_id'] : null;
            $accountKey = isset($line['account_key']) ? (string) $line['account_key'] : null;
            if (! $accountId && ($accountKey === null || $accountKey === '')) {
                throw ValidationException::withMessages([
                    "lines.{$index}.account_key" => ['Each line needs account_key or account_id.'],
                ]);
            }

            $side = strtolower((string) ($line['side'] ?? ''));
            if (! in_array($side, ['debit', 'credit'], true)) {
                throw ValidationException::withMessages([
                    "lines.{$index}.side" => ['Side must be debit or credit.'],
                ]);
            }

            $source = trim((string) ($line['amount_source'] ?? ''));
            if ($source === '') {
                throw ValidationException::withMessages([
                    "lines.{$index}.amount_source" => ['Amount source is required (amount, tax_amount, cost, …).'],
                ]);
            }

            if ($accountKey) {
                $resolved = $this->compiler->resolveAccountId(null, $accountKey);
                if (! $resolved) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.account_key" => ["Unknown chart of accounts key_name '{$accountKey}'."],
                    ]);
                }
                $accountId = $resolved;
            } elseif (! $this->compiler->resolveAccountId($accountId, null)) {
                throw ValidationException::withMessages([
                    "lines.{$index}.account_id" => ['Account id was not found.'],
                ]);
            }

            $normalized[] = [
                'sort_order' => $index,
                'account_id' => $accountId,
                'account_key' => $accountKey,
                'side' => $side,
                'amount_source' => $source,
                'skip_when_zero' => (bool) ($line['skip_when_zero'] ?? false),
            ];
        }

        $enable = $isEnabled ?? true;
        if (count($normalized) < 2) {
            $enable = false;
        }

        if ($enable) {
            $problem = $this->compiler->structuralBalanceMessage($normalized);
            if ($problem !== null) {
                throw ValidationException::withMessages([
                    'lines' => [$problem],
                ]);
            }
        }

        $context = app(AccountingContextInterface::class);

        return DB::transaction(function () use ($operationKey, $label, $enable, $normalized, $context) {
            $query = AccountingOperationMapping::withoutGlobalScopes()->where('operation_key', $operationKey);
            if (TenancySchemaHelper::usesOrganizationId()) {
                $query->where('organization_id', $context->getOrganizationId());
            }
            if (TenancySchemaHelper::usesBranchId() && $context->getBranchId()) {
                $query->where('branch_id', $context->getBranchId());
            }

            /** @var AccountingOperationMapping $mapping */
            $mapping = $query->first() ?? new AccountingOperationMapping;

            $mapping->fill(TenancySchemaHelper::filterTenantAttributes([
                'organization_id' => $context->getOrganizationId(),
                'branch_id' => $context->getBranchId(),
                'operation_key' => $operationKey,
                'label' => $label ?? $mapping->label ?? $operationKey,
                'is_enabled' => $enable,
            ]));
            $mapping->save();

            $mapping->lines()->delete();
            foreach ($normalized as $row) {
                $mapping->lines()->create($row);
            }

            return $mapping->fresh('lines.account');
        });
    }
}
