<?php

namespace Abdiaziizaweis\Accounting\Services;

use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;

use Abdiaziizaweis\Accounting\Enums\AccountingAccountType;
use Abdiaziizaweis\Accounting\Models\AccountingAccount;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregate journal lines into standard financial statements.
 *
 * Debit-normal: assets, expenses, COGS, cash/bank/AR subtypes.
 * Credit-normal: liabilities, equity, income.
 */
class AccountingReportService
{
    /** @var list<string> */
    public const ASSET_TYPES = [
        'asset', 'cash', 'accounts_receivable', 'bank', 'current_assets', 'fixed_assets',
    ];

    /** @var list<string> */
    public const LIABILITY_TYPES = [
        'liability', 'accounts_payable', 'credit_card', 'current_liabilities', 'noncurrent_liabilities',
    ];

    /** @var list<string> */
    public const EQUITY_TYPES = [
        'equity', 'owner_equity',
    ];

    /** @var list<string> */
    public const INCOME_TYPES = [
        'income', 'other_income',
    ];

    /** @var list<string> */
    public const COGS_TYPES = [
        'cost_of_sales',
    ];

    /** @var list<string> */
    public const EXPENSE_TYPES = [
        'expense', 'expenses', 'other_expense',
    ];

    /** @var list<string> */
    public const CASH_TYPES = [
        'cash', 'bank',
    ];

    public function isDebitNormal(string $type): bool
    {
        return in_array($type, [
            ...self::ASSET_TYPES,
            ...self::COGS_TYPES,
            ...self::EXPENSE_TYPES,
        ], true);
    }

    /**
     * @return Collection<int, object{
     *   account_id: int,
     *   name: string,
     *   number: string|null,
     *   account_type: string,
     *   parent_id: int|null,
     *   debit: float,
     *   credit: float
     * }>
     */
    public function accountActivity(?Carbon $from, Carbon $to): Collection
    {
        $query = DB::table('accounting_journal_entry_lines as jel')
            ->join('accounting_journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('accounting_accounts as a', 'a.id', '=', 'jel.account_id')
            ->where(function ($q) {
                $q->where('je.status', 'active')->orWhereNull('je.status');
            })
            ->whereNull('a.deleted_at')
            ->where('je.journal_date', '<=', $to->toDateString());

        if ($from) {
            $query->where('je.journal_date', '>=', $from->toDateString());
        }

        // Org/branch scopes from Eloquent global scopes — apply manually for query builder.
        $accountScope = AccountingAccount::query()->select('id');
        $query->whereIn('a.id', $accountScope);

        return $query
            ->groupBy('a.id', 'a.name', 'a.number', 'a.account_type', 'a.parent_id')
            ->orderBy('a.number')
            ->orderBy('a.name')
            ->get([
                'a.id as account_id',
                'a.name',
                'a.number',
                'a.account_type',
                'a.parent_id',
                DB::raw('COALESCE(SUM(jel.debit), 0) as debit'),
                DB::raw('COALESCE(SUM(jel.credit), 0) as credit'),
            ])
            ->map(function ($row) {
                $row->debit = (float) $row->debit;
                $row->credit = (float) $row->credit;
                $row->account_type = (string) $row->account_type;

                return $row;
            });
    }

    /**
     * Signed natural balance (positive = normal side).
     */
    public function naturalBalance(string $type, float $debit, float $credit): float
    {
        return $this->isDebitNormal($type)
            ? round($debit - $credit, 2)
            : round($credit - $debit, 2);
    }

    /**
     * Trial balance as of $asOf (cumulative from inception).
     *
     * @return array{as_of: string, rows: list<array<string, mixed>>, totals: array{debit: float, credit: float}}
     */
    public function trialBalance(Carbon $asOf): array
    {
        $rows = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($this->accountActivity(null, $asOf) as $row) {
            $netDebit = max(0, round($row->debit - $row->credit, 2));
            $netCredit = max(0, round($row->credit - $row->debit, 2));
            if ($netDebit == 0.0 && $netCredit == 0.0) {
                continue;
            }
            $totalDebit += $netDebit;
            $totalCredit += $netCredit;
            $rows[] = [
                'account_id' => (int) $row->account_id,
                'number' => $row->number,
                'name' => $row->name,
                'account_type' => $row->account_type,
                'debit' => $netDebit,
                'credit' => $netCredit,
            ];
        }

        return [
            'as_of' => $asOf->toDateString(),
            'rows' => $rows,
            'totals' => [
                'debit' => round($totalDebit, 2),
                'credit' => round($totalCredit, 2),
            ],
        ];
    }

    /**
     * Profit & Loss for a period.
     *
     * @return array<string, mixed>
     */
    public function profitAndLoss(Carbon $from, Carbon $to): array
    {
        $activity = $this->accountActivity($from, $to);

        $income = $this->sectionLines($activity, self::INCOME_TYPES);
        $cogs = $this->sectionLines($activity, self::COGS_TYPES);
        $expenses = $this->sectionLines($activity, self::EXPENSE_TYPES);

        $totalIncome = $this->sumAmounts($income);
        $totalCogs = $this->sumAmounts($cogs);
        $grossProfit = round($totalIncome - $totalCogs, 2);
        $totalExpenses = $this->sumAmounts($expenses);
        $netIncome = round($grossProfit - $totalExpenses, 2);

        return [
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'sections' => [
                [
                    'key' => 'income',
                    'title' => 'Income',
                    'lines' => $income,
                    'total' => $totalIncome,
                    'total_label' => 'Total Income',
                ],
                [
                    'key' => 'cogs',
                    'title' => 'Cost of Sales',
                    'lines' => $cogs,
                    'total' => $totalCogs,
                    'total_label' => 'Total Cost of Sales',
                ],
                [
                    'key' => 'gross_profit',
                    'title' => 'Gross Profit',
                    'lines' => [],
                    'total' => $grossProfit,
                    'total_label' => 'Gross Profit',
                    'is_subtotal' => true,
                ],
                [
                    'key' => 'expenses',
                    'title' => 'Expenses',
                    'lines' => $expenses,
                    'total' => $totalExpenses,
                    'total_label' => 'Total Expenses',
                ],
            ],
            'net_income' => $netIncome,
            'net_income_label' => $netIncome >= 0 ? 'Net Income' : 'Net Loss',
        ];
    }

    /**
     * Balance sheet as of date (includes YTD net income in equity).
     * Lists every active CoA account in asset/liability/equity families,
     * including zero-balance accounts.
     *
     * @return array<string, mixed>
     */
    public function balanceSheet(Carbon $asOf, ?Carbon $fyStart = null): array
    {
        $fyStart = $fyStart ?? $asOf->copy()->startOfYear();
        $cumulative = $this->accountActivity(null, $asOf);
        $ytdPl = $this->profitAndLoss($fyStart, $asOf);
        $netIncome = (float) $ytdPl['net_income'];

        $assets = $this->sectionLinesIncludingZeros($cumulative, self::ASSET_TYPES);
        $liabilities = $this->sectionLinesIncludingZeros($cumulative, self::LIABILITY_TYPES);
        $equity = $this->sectionLinesIncludingZeros($cumulative, self::EQUITY_TYPES);

        // Always show current-period net income line on equity (even when zero).
        $equity[] = [
            'account_id' => null,
            'number' => null,
            'name' => 'Net Income (current period)',
            'account_type' => 'equity',
            'amount' => $netIncome,
            'is_synthetic' => true,
        ];

        $totalAssets = $this->sumAmounts($assets);
        $totalLiabilities = $this->sumAmounts($liabilities);
        $totalEquity = $this->sumAmounts($equity);
        $totalLiabEquity = round($totalLiabilities + $totalEquity, 2);

        return [
            'as_of' => $asOf->toDateString(),
            'fy_start' => $fyStart->toDateString(),
            'sections' => [
                [
                    'key' => 'assets',
                    'title' => 'Assets',
                    'lines' => $assets,
                    'total' => $totalAssets,
                    'total_label' => 'Total Assets',
                ],
                [
                    'key' => 'liabilities',
                    'title' => 'Liabilities',
                    'lines' => $liabilities,
                    'total' => $totalLiabilities,
                    'total_label' => 'Total Liabilities',
                ],
                [
                    'key' => 'equity',
                    'title' => 'Equity',
                    'lines' => $equity,
                    'total' => $totalEquity,
                    'total_label' => 'Total Equity',
                ],
            ],
            'total_liabilities_and_equity' => $totalLiabEquity,
            'balanced' => abs($totalAssets - $totalLiabEquity) < 0.02,
            'difference' => round($totalAssets - $totalLiabEquity, 2),
        ];
    }

    /**
     * Ledger statement for one account over a period.
     *
     * @return array<string, mixed>
     */
    public function accountStatement(int $accountId, Carbon $from, Carbon $to): array
    {
        $account = AccountingAccount::query()->find($accountId);
        if (! $account) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Account not found.');
        }

        $type = $account->account_type instanceof AccountingAccountType
            ? $account->account_type->value
            : (string) $account->account_type;

        $openingAsOf = $from->copy()->subDay()->endOfDay();
        $openingActivity = $this->accountActivity(null, $openingAsOf)
            ->firstWhere('account_id', $accountId);

        $openingDebit = (float) ($openingActivity->debit ?? 0);
        $openingCredit = (float) ($openingActivity->credit ?? 0);
        $openingBalance = $this->naturalBalance($type, $openingDebit, $openingCredit);

        $lines = DB::table('accounting_journal_entry_lines as jel')
            ->join('accounting_journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where(function ($q) {
                $q->where('je.status', 'active')->orWhereNull('je.status');
            })
            ->where('jel.account_id', $accountId)
            ->whereBetween('je.journal_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('je.journal_date')
            ->orderBy('je.id')
            ->orderBy('jel.id')
            ->get([
                'jel.id as line_id',
                'je.id as journal_entry_id',
                'je.number as journal_number',
                'je.journal_date',
                'je.description',
                'je.reference',
                'jel.memo',
                'jel.debit',
                'jel.credit',
            ]);

        $running = $openingBalance;
        $rows = [];
        $periodDebit = 0.0;
        $periodCredit = 0.0;

        foreach ($lines as $line) {
            $debit = (float) $line->debit;
            $credit = (float) $line->credit;
            $periodDebit += $debit;
            $periodCredit += $credit;
            // Running balance follows natural balance side.
            if ($this->isDebitNormal($type)) {
                $running = round($running + $debit - $credit, 2);
            } else {
                $running = round($running + $credit - $debit, 2);
            }

            $rows[] = [
                'line_id' => (int) $line->line_id,
                'journal_entry_id' => (int) $line->journal_entry_id,
                'journal_number' => $line->journal_number,
                'journal_date' => $line->journal_date,
                'description' => $line->description ?: ($line->memo ?: '—'),
                'reference' => $line->reference,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $running,
            ];
        }

        $closingBalance = $running;

        return [
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'account' => [
                'id' => (int) $account->id,
                'number' => $account->number,
                'name' => $account->name,
                'account_type' => $type,
            ],
            'opening_balance' => $openingBalance,
            'closing_balance' => $closingBalance,
            'period_debit' => round($periodDebit, 2),
            'period_credit' => round($periodCredit, 2),
            'rows' => $rows,
        ];
    }

    /**
     * Cash flow statement (classified by contra account type on cash/bank lines).
     *
     * @return array<string, mixed>
     */
    public function cashFlow(Carbon $from, Carbon $to): array
    {
        $openingAsOf = $from->copy()->subDay();
        $openingCash = $this->cashBalanceAsOf($openingAsOf);
        $closingCash = $this->cashBalanceAsOf($to);

        $movements = $this->cashMovements($from, $to);

        $operating = [];
        $investing = [];
        $financing = [];

        foreach ($movements as $m) {
            $bucket = $this->cashFlowBucket($m['contra_type']);
            $line = [
                'account_id' => $m['contra_account_id'],
                'number' => $m['contra_number'],
                'name' => $m['contra_name'],
                'account_type' => $m['contra_type'],
                'amount' => $m['amount'],
            ];
            if ($bucket === 'investing') {
                $investing[] = $line;
            } elseif ($bucket === 'financing') {
                $financing[] = $line;
            } else {
                $operating[] = $line;
            }
        }

        $operating = $this->mergeLinesByAccount($operating);
        $investing = $this->mergeLinesByAccount($investing);
        $financing = $this->mergeLinesByAccount($financing);

        $totalOperating = $this->sumAmounts($operating);
        $totalInvesting = $this->sumAmounts($investing);
        $totalFinancing = $this->sumAmounts($financing);
        $netChange = round($totalOperating + $totalInvesting + $totalFinancing, 2);

        return [
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'opening_cash' => $openingCash,
            'closing_cash' => $closingCash,
            'sections' => [
                [
                    'key' => 'operating',
                    'title' => 'Cash flows from operating activities',
                    'lines' => $operating,
                    'total' => $totalOperating,
                    'total_label' => 'Net cash from operating activities',
                ],
                [
                    'key' => 'investing',
                    'title' => 'Cash flows from investing activities',
                    'lines' => $investing,
                    'total' => $totalInvesting,
                    'total_label' => 'Net cash from investing activities',
                ],
                [
                    'key' => 'financing',
                    'title' => 'Cash flows from financing activities',
                    'lines' => $financing,
                    'total' => $totalFinancing,
                    'total_label' => 'Net cash from financing activities',
                ],
            ],
            'net_change' => $netChange,
            'implied_closing' => round($openingCash + $netChange, 2),
        ];
    }

    /**
     * @param  Collection<int, object>  $activity
     * @param  list<string>  $types
     * @return list<array<string, mixed>>
     */
    private function sectionLines(Collection $activity, array $types): array
    {
        $lines = [];
        foreach ($activity as $row) {
            if (! in_array($row->account_type, $types, true)) {
                continue;
            }
            $amount = $this->naturalBalance($row->account_type, $row->debit, $row->credit);
            if (abs($amount) < 0.0001) {
                continue;
            }
            $lines[] = [
                'account_id' => (int) $row->account_id,
                'number' => $row->number,
                'name' => $row->name,
                'account_type' => $row->account_type,
                'amount' => $amount,
            ];
        }

        return $lines;
    }

    /**
     * Balance-sheet sections: every active CoA account of the given types,
     * including zeros (merged with journal activity).
     *
     * @param  Collection<int, object>  $activity
     * @param  list<string>  $types
     * @return list<array<string, mixed>>
     */
    private function sectionLinesIncludingZeros(Collection $activity, array $types): array
    {
        $byId = [];
        foreach ($activity as $row) {
            if (! in_array($row->account_type, $types, true)) {
                continue;
            }
            $byId[(int) $row->account_id] = $this->naturalBalance(
                $row->account_type,
                $row->debit,
                $row->credit
            );
        }

        $accounts = AccountingAccount::query()
            ->where('is_active', true)
            ->whereIn('account_type', $types)
            ->orderBy('number')
            ->orderBy('name')
            ->get(['id', 'name', 'number', 'account_type']);

        $lines = [];
        foreach ($accounts as $account) {
            $type = $account->account_type instanceof AccountingAccountType
                ? $account->account_type->value
                : (string) $account->account_type;
            $lines[] = [
                'account_id' => (int) $account->id,
                'number' => $account->number,
                'name' => $account->name,
                'account_type' => $type,
                'amount' => round((float) ($byId[(int) $account->id] ?? 0), 2),
            ];
        }

        return $lines;
    }

    /**
     * @param  list<array{amount: float}>  $lines
     */
    private function sumAmounts(array $lines): float
    {
        return round(array_sum(array_map(fn ($l) => (float) ($l['amount'] ?? 0), $lines)), 2);
    }

    private function cashBalanceAsOf(Carbon $asOf): float
    {
        $sum = 0.0;
        foreach ($this->accountActivity(null, $asOf) as $row) {
            if (! in_array($row->account_type, self::CASH_TYPES, true)) {
                continue;
            }
            $sum += $this->naturalBalance($row->account_type, $row->debit, $row->credit);
        }

        return round($sum, 2);
    }

    /**
     * Net cash effect per contra account for the period.
     * Positive amount = cash in; negative = cash out.
     *
     * @return list<array<string, mixed>>
     */
    private function cashMovements(Carbon $from, Carbon $to): array
    {
        $cashAccountIds = AccountingAccount::query()
            ->whereIn('account_type', [
                AccountingAccountType::Cash->value,
                AccountingAccountType::Bank->value,
            ])
            ->pluck('id')
            ->all();

        if ($cashAccountIds === []) {
            return [];
        }

        $jeIds = DB::table('accounting_journal_entry_lines as jel')
            ->join('accounting_journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where(function ($q) {
                $q->where('je.status', 'active')->orWhereNull('je.status');
            })
            ->whereIn('jel.account_id', $cashAccountIds)
            ->whereBetween('je.journal_date', [$from->toDateString(), $to->toDateString()])
            ->distinct()
            ->pluck('je.id');

        if ($jeIds->isEmpty()) {
            return [];
        }

        $lines = DB::table('accounting_journal_entry_lines as jel')
            ->join('accounting_accounts as a', 'a.id', '=', 'jel.account_id')
            ->whereIn('jel.journal_entry_id', $jeIds)
            ->whereNull('a.deleted_at')
            ->get([
                'jel.journal_entry_id',
                'jel.account_id',
                'jel.debit',
                'jel.credit',
                'a.name',
                'a.number',
                'a.account_type',
            ]);

        $byJe = $lines->groupBy('journal_entry_id');
        $out = [];

        foreach ($byJe as $jeLines) {
            $cashNet = 0.0; // debit - credit on cash (cash in positive)
            $contras = [];
            foreach ($jeLines as $line) {
                $type = (string) $line->account_type;
                if (in_array($type, self::CASH_TYPES, true)) {
                    $cashNet += (float) $line->debit - (float) $line->credit;
                } else {
                    $contras[] = $line;
                }
            }
            if (abs($cashNet) < 0.0001 || $contras === []) {
                continue;
            }

            $contraWeight = array_sum(array_map(
                fn ($c) => abs((float) $c->debit - (float) $c->credit),
                $contras
            ));
            if ($contraWeight < 0.0001) {
                $share = $cashNet / count($contras);
                foreach ($contras as $c) {
                    $out[] = [
                        'contra_account_id' => (int) $c->account_id,
                        'contra_number' => $c->number,
                        'contra_name' => $c->name,
                        'contra_type' => (string) $c->account_type,
                        'amount' => round($share, 2),
                    ];
                }
            } else {
                foreach ($contras as $c) {
                    $w = abs((float) $c->debit - (float) $c->credit) / $contraWeight;
                    $out[] = [
                        'contra_account_id' => (int) $c->account_id,
                        'contra_number' => $c->number,
                        'contra_name' => $c->name,
                        'contra_type' => (string) $c->account_type,
                        'amount' => round($cashNet * $w, 2),
                    ];
                }
            }
        }

        return $out;
    }

    private function cashFlowBucket(string $type): string
    {
        if (in_array($type, ['fixed_assets'], true)) {
            return 'investing';
        }
        if (in_array($type, [...self::EQUITY_TYPES, 'noncurrent_liabilities'], true)) {
            return 'financing';
        }

        return 'operating';
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function mergeLinesByAccount(array $lines): array
    {
        $map = [];
        foreach ($lines as $line) {
            $key = (string) ($line['account_id'] ?? $line['name']);
            if (! isset($map[$key])) {
                $map[$key] = $line;
            } else {
                $map[$key]['amount'] = round(
                    (float) $map[$key]['amount'] + (float) $line['amount'],
                    2
                );
            }
        }

        return array_values(array_filter(
            $map,
            fn ($l) => abs((float) $l['amount']) >= 0.005
        ));
    }

    /**
     * Journal entry volume grouped by calendar month (read-only analytics for AI / reports).
     *
     * @return array{
     *   date_from: string|null,
     *   date_to: string,
     *   months: list<array{
     *     month: string,
     *     label: string,
     *     entry_count: int,
     *     line_count: int,
     *     total_debit: float,
     *     total_credit: float
     *   }>,
     *   peak_month: string|null,
     *   peak_entry_count: int,
     *   matching_record_count: int
     * }
     */
    public function journalActivityByMonth(?Carbon $from, Carbon $to, int $limit = 24): array
    {
        $limit = max(1, min(60, $limit));

        $entryScope = \Abdiaziizaweis\Accounting\Models\AccountingJournalEntry::query()->select('id');

        $query = DB::table('accounting_journal_entries as je')
            ->join('accounting_journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
            ->whereIn('je.id', $entryScope)
            ->where(function ($q) {
                $q->where('je.status', 'active')->orWhereNull('je.status');
            })
            ->where('je.journal_date', '<=', $to->toDateString());

        if ($from !== null) {
            $query->where('je.journal_date', '>=', $from->toDateString());
        }

        $aggregates = $query
            ->selectRaw("DATE_FORMAT(je.journal_date, '%Y-%m') as month")
            ->selectRaw('COUNT(DISTINCT je.id) as entry_count')
            ->selectRaw('COUNT(jel.id) as line_count')
            ->selectRaw('COALESCE(SUM(jel.debit), 0) as total_debit')
            ->selectRaw('COALESCE(SUM(jel.credit), 0) as total_credit')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $months = [];
        foreach ($aggregates as $row) {
            $month = (string) $row->month;
            if ($month === '') {
                continue;
            }
            try {
                $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
            } catch (\Throwable) {
                continue;
            }
            $months[] = [
                'month' => $month,
                'label' => $start->format('F Y'),
                'entry_count' => (int) $row->entry_count,
                'line_count' => (int) $row->line_count,
                'total_debit' => round((float) $row->total_debit, 2),
                'total_credit' => round((float) $row->total_credit, 2),
            ];
        }

        $byVolume = $months;
        usort($byVolume, fn (array $a, array $b) => $b['entry_count'] <=> $a['entry_count']
            ?: strcmp($b['month'], $a['month']));

        $peak = $byVolume[0] ?? null;
        $display = count($months) <= $limit ? $months : array_slice($months, -$limit);

        return [
            'date_from' => $from?->toDateString(),
            'date_to' => $to->toDateString(),
            'months' => $display,
            'peak_month' => $peak['month'] ?? null,
            'peak_month_label' => $peak['label'] ?? null,
            'peak_entry_count' => (int) ($peak['entry_count'] ?? 0),
            'matching_record_count' => count($months),
        ];
    }
}
