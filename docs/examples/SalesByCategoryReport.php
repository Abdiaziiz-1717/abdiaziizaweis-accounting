<?php

/**
 * Example custom report for host applications — copy to e.g. app/Reports/SalesByCategoryReport.php.
 *
 * Register in AppServiceProvider::boot():
 * AccountingReportRegistry::register('sales-by-category', 'Sales by Category', SalesByCategoryReport::class);
 */
namespace App\Reports;

use Abdiaziizaweis\Accounting\Contracts\AccountingReportInterface;
use Abdiaziizaweis\Accounting\Models\AccountingAccount;
use Illuminate\Support\Facades\DB;

class SalesByCategoryReport implements AccountingReportInterface
{
    /** @var list<string> */
    private const INCOME_TYPES = ['income', 'other_income'];

    public function label(): string
    {
        return 'Sales by Category';
    }

    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function handle(array $filters): array
    {
        $from = (string) $filters['date_from'];
        $to = (string) $filters['date_to'];

        $accountScope = AccountingAccount::query()->select('id');

        $rows = DB::table('accounting_journal_entry_lines as jel')
          ->join('accounting_journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
          ->join('accounting_accounts as a', 'a.id', '=', 'jel.account_id')
          ->whereIn('a.id', $accountScope)
          ->whereNull('a.deleted_at')
          ->whereIn('a.account_type', self::INCOME_TYPES)
          ->where(function ($q) {
              $q->where('je.status', 'active')->orWhereNull('je.status');
          })
          ->whereBetween('je.journal_date', [$from, $to])
          ->groupBy('a.id', 'a.number', 'a.name', 'a.account_type')
          ->orderBy('a.number')
          ->orderBy('a.name')
          ->get([
              'a.id as account_id',
              'a.number',
              'a.name',
              'a.account_type',
              DB::raw('COALESCE(SUM(jel.credit), 0) - COALESCE(SUM(jel.debit), 0) as amount'),
          ])
          ->map(function ($row) {
              return [
                  'account_id' => (int) $row->account_id,
                  'number' => $row->number,
                  'name' => $row->name,
                  'account_type' => (string) $row->account_type,
                  'amount' => round((float) $row->amount, 2),
              ];
          })
          ->filter(fn (array $row) => abs($row['amount']) >= 0.005)
          ->values()
          ->all();

      $total = round(array_sum(array_column($rows, 'amount')), 2);

      return [
          'date_from' => $from,
          'date_to' => $to,
          'rows' => $rows,
          'total_sales' => $total,
      ];
  }
}