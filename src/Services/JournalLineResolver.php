<?php

namespace Abdiaziizaweis\Accounting\Services;

use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;
use Abdiaziizaweis\Accounting\Database\Migration\TenancySchemaHelper;
use Abdiaziizaweis\Accounting\Exceptions\UnbalancedJournalLinesException;
use Abdiaziizaweis\Accounting\Models\AccountingAccount;
use Abdiaziizaweis\Accounting\ValueObjects\JournalLine;

class JournalLineResolver
{
    /**
     * @param  list<JournalLine>  $lines
     * @return list<array{account_id: int, debit: float, credit: float, memo: ?string}>
     */
    public function resolve(array $lines, string $documentType): array
    {
        if (count($lines) < 2) {
            throw new \InvalidArgumentException(
                "getJournalLines() must return at least 2 lines for document type '{$documentType}'"
            );
        }

        $resolved = [];

        foreach ($lines as $line) {
            if (! $line instanceof JournalLine) {
                throw new \InvalidArgumentException(
                    'getJournalLines() must return JournalLine objects only'
                );
            }

            if ($line->usesKeyName()) {
                $query = AccountingAccount::withoutGlobalScopes()
                    ->where('key_name', $line->accountKey)
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

                $account = $query->first();

                if (! $account) {
                    throw new \RuntimeException(
                        "Account with key_name '{$line->accountKey}' not found in chart of accounts. ".
                        'Run accounting:bootstrap to seed default accounts.'
                    );
                }

                $accountId = (int) $account->id;
            } else {
                $accountId = (int) $line->accountId;
            }

            $resolved[] = [
                'account_id' => $accountId,
                'debit' => round($line->debit, 2),
                'credit' => round($line->credit, 2),
                'memo' => $line->memo,
            ];
        }

        $totalDebits = round(array_sum(array_column($resolved, 'debit')), 4);
        $totalCredits = round(array_sum(array_column($resolved, 'credit')), 4);

        if (abs($totalDebits - $totalCredits) > 0.005) {
            throw UnbalancedJournalLinesException::forLines(
                $totalDebits,
                $totalCredits,
                $documentType
            );
        }

        return $resolved;
    }
}