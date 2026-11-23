<?php

namespace Abdiaziizaweis\Accounting\Services;

use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;
use Abdiaziizaweis\Accounting\Database\Migration\TenancySchemaHelper;
use Abdiaziizaweis\Accounting\Models\AccountingAccount;
use Abdiaziizaweis\Accounting\Models\AccountingOperationMapping;
use Illuminate\Support\Facades\Log;

/**
 * Turns a host operation key plus a numeric payload into balanced journal lines.
 */
class OperationMappingCompiler
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     status: string,
     *     lines: list<array{account_id: int, debit: float, credit: float, memo: ?string}>,
     *     total_debits: float,
     *     total_credits: float,
     *     message: ?string
     * }
     */
    public function compile(string $operationKey, array $payload): array
    {
        $empty = [
            'status' => 'missing',
            'lines' => [],
            'total_debits' => 0.0,
            'total_credits' => 0.0,
            'message' => null,
        ];

        $mapping = $this->findEnabled($operationKey);
        if (! $mapping) {
            $empty['message'] = "No enabled mapping for operation '{$operationKey}'.";

            return $empty;
        }

        $active = [];
        foreach ($mapping->lines as $line) {
            $source = (string) $line->amount_source;
            $raw = $payload[$source] ?? 0;
            $amount = round(abs((float) $raw), 2);

            if ($line->skip_when_zero && abs($amount) < 0.005) {
                continue;
            }

            $active[] = ['line' => $line, 'amount' => $amount];
        }

        if (count($active) < 2) {
            return [
                'status' => 'too_few',
                'lines' => [],
                'total_debits' => 0.0,
                'total_credits' => 0.0,
                'message' => "Operation '{$operationKey}' needs at least 2 posting lines after skip-when-zero.",
            ];
        }

        $resolved = [];
        foreach ($active as $row) {
            $line = $row['line'];
            $amount = $row['amount'];
            $accountId = $this->resolveAccountId($line->account_id ? (int) $line->account_id : null, $line->account_key);
            if (! $accountId) {
                return [
                    'status' => 'missing_account',
                    'lines' => [],
                    'total_debits' => 0.0,
                    'total_credits' => 0.0,
                    'message' => "Account for operation '{$operationKey}' was not found (key {$line->account_key}).",
                ];
            }

            $debit = $line->side === 'debit' ? $amount : 0.0;
            $credit = $line->side === 'credit' ? $amount : 0.0;
            $resolved[] = [
                'account_id' => $accountId,
                'debit' => round(max(0, $debit), 2),
                'credit' => round(max(0, $credit), 2),
                'memo' => null,
            ];
        }

        $totalDebits = round(array_sum(array_column($resolved, 'debit')), 4);
        $totalCredits = round(array_sum(array_column($resolved, 'credit')), 4);

        if (abs($totalDebits - $totalCredits) > 0.005) {
            $difference = abs($totalDebits - $totalCredits);
            $message = "Operation '{$operationKey}' is unbalanced. Debits {$totalDebits}, credits {$totalCredits}, difference {$difference}.";
            Log::warning($message, [
                'operation_key' => $operationKey,
                'total_debits' => $totalDebits,
                'total_credits' => $totalCredits,
                'difference' => $difference,
            ]);

            return [
                'status' => 'unbalanced',
                'lines' => [],
                'total_debits' => $totalDebits,
                'total_credits' => $totalCredits,
                'message' => $message,
            ];
        }

        return [
            'status' => 'ready',
            'lines' => $resolved,
            'total_debits' => $totalDebits,
            'total_credits' => $totalCredits,
            'message' => null,
        ];
    }

    /**
     * Structural check: each amount source must have the same number of debit and credit lines.
     * A unit sample (every source = 1) then balances.
     *
     * @param  list<array{side: string, amount_source: string}>  $lines
     */
    public function structuralBalanceMessage(array $lines): ?string
    {
        $counts = [];
        foreach ($lines as $line) {
            $source = (string) $line['amount_source'];
            $side = (string) $line['side'];
            $counts[$source][$side] = ($counts[$source][$side] ?? 0) + 1;
        }

        foreach ($counts as $source => $sides) {
            $debits = $sides['debit'] ?? 0;
            $credits = $sides['credit'] ?? 0;
            if ($debits !== $credits) {
                return "Amount source '{$source}' has {$debits} debit line(s) and {$credits} credit line(s). They must match so the entry balances for any payload.";
            }
        }

        return null;
    }

    public function findEnabled(string $operationKey): ?AccountingOperationMapping
    {
        $query = AccountingOperationMapping::withoutGlobalScopes()
            ->with('lines')
            ->where('operation_key', $operationKey)
            ->where('is_enabled', true);

        $this->scopeTenant($query);

        return $query->first();
    }

    public function resolveAccountId(?int $accountId, ?string $accountKey): ?int
    {
        if ($accountId) {
            $exists = AccountingAccount::withoutGlobalScopes()->whereKey($accountId)->whereNull('deleted_at')->exists();

            return $exists ? $accountId : null;
        }

        if ($accountKey === null || $accountKey === '') {
            return null;
        }

        $query = AccountingAccount::withoutGlobalScopes()
            ->where('key_name', $accountKey)
            ->whereNull('deleted_at');

        $context = app(AccountingContextInterface::class);
        if (TenancySchemaHelper::usesOrganizationId()) {
            $query->where('organization_id', $context->getOrganizationId());
        }
        if (TenancySchemaHelper::usesBranchId()) {
            $branchId = $context->getBranchId();
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }
        }

        $id = $query->value('id');

        return $id ? (int) $id : null;
    }

    protected function scopeTenant($query): void
    {
        $context = app(AccountingContextInterface::class);
        if (TenancySchemaHelper::usesOrganizationId()) {
            $query->where('organization_id', $context->getOrganizationId());
        }
        if (TenancySchemaHelper::usesBranchId()) {
            $branchId = $context->getBranchId();
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }
        }
    }
}
