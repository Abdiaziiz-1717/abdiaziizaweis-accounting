<?php

namespace Abdiaziizaweis\Accounting\Http\Controllers;

use Abdiaziizaweis\Accounting\Database\Migration\TenancySchemaHelper;
use Abdiaziizaweis\Accounting\Enums\AccountingTransactionStatus;
use Abdiaziizaweis\Accounting\Models\AccountingAccount;
use Abdiaziizaweis\Accounting\Models\AccountingBranchSetting;
use Abdiaziizaweis\Accounting\Models\AccountingDocumentMapping;
use Abdiaziizaweis\Accounting\Models\AccountingExpenseCategoryMapping;
use Abdiaziizaweis\Accounting\Models\AccountingJournalEntry;
use Abdiaziizaweis\Accounting\Models\AccountingPaymentMethodMapping;
use Abdiaziizaweis\Accounting\Models\AccountingTransaction;
use Abdiaziizaweis\Accounting\Http\Traits\ApiResponseTrait;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

class AccountingHealthController extends Controller
{
    use ApiResponseTrait;

    public function index()
    {
        $unmappedQuery = AccountingTransaction::withoutGlobalScopes()
            ->where('status', AccountingTransactionStatus::NotMapped);

        $unmappedCount = (int) $unmappedQuery->count();

        $oldestUnmapped = (clone $unmappedQuery)
            ->orderBy('created_at')
            ->value('created_at');

        $oldestUnmappedDays = null;
        if ($oldestUnmapped !== null) {
            $oldestUnmappedDays = (int) Carbon::parse($oldestUnmapped)->diffInDays(now());
        }

        $lastJournalDate = AccountingJournalEntry::withoutGlobalScopes()
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 'active');
            })
            ->max('journal_date');

        $settings = AccountingBranchSetting::withoutGlobalScopes()
            ->when(
                TenancySchemaHelper::isSingleTenant(),
                fn ($q) => $q->orderBy('id'),
                fn ($q) => $q
            )
            ->first();

        $disabledMappings = (int) AccountingDocumentMapping::withoutGlobalScopes()
            ->where('is_enabled', false)
            ->count();
        $disabledMappings += (int) AccountingPaymentMethodMapping::withoutGlobalScopes()
            ->where('is_enabled', false)
            ->count();
        $disabledMappings += (int) AccountingExpenseCategoryMapping::withoutGlobalScopes()
            ->where('is_enabled', false)
            ->count();

        $totalAccounts = (int) AccountingAccount::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->count();

        $status = $this->deriveStatus($unmappedCount, $oldestUnmappedDays, $disabledMappings);

        return $this->successResponse([
            'status' => $status,
            'unmapped_transactions' => $unmappedCount,
            'oldest_unmapped_days' => $oldestUnmappedDays,
            'last_journal_entry_date' => $lastJournalDate
                ? Carbon::parse($lastJournalDate)->toDateString()
                : null,
            'books_closed' => (bool) ($settings?->close_the_books),
            'closing_date' => $settings?->closing_date
                ? Carbon::parse($settings->closing_date)->toDateString()
                : null,
            'total_accounts' => $totalAccounts,
            'disabled_mappings' => $disabledMappings,
        ], 'Accounting health summary');
    }

    protected function deriveStatus(int $unmappedCount, ?int $oldestUnmappedDays, int $disabledMappings): string
    {
        if ($unmappedCount >= 10 || ($oldestUnmappedDays !== null && $oldestUnmappedDays > 7)) {
            return 'critical';
        }

        if ($unmappedCount > 0 || $disabledMappings > 0) {
            return 'warning';
        }

        return 'ok';
    }
}