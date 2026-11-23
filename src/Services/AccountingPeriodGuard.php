<?php

namespace Abdiaziizaweis\Accounting\Services;

use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;
use Abdiaziizaweis\Accounting\Exceptions\AccountingPeriodClosedException;
use Abdiaziizaweis\Accounting\Models\AccountingBranchSetting;
use Illuminate\Support\Carbon;

class AccountingPeriodGuard
{
    public function assertOpen(Carbon|\DateTimeInterface|string $date): void
    {
        if ($this->isOpen($date)) {
            return;
        }

        $settings = $this->branchSettings();
        $closingDate = Carbon::parse($settings?->closing_date)->startOfDay();

        throw AccountingPeriodClosedException::forDate(
            $this->normalizeDate($date),
            $closingDate
        );
    }

    public function isOpen(Carbon|\DateTimeInterface|string $date): bool
    {
        $settings = $this->branchSettings();
        if ($settings === null || ! $settings->close_the_books || $settings->closing_date === null) {
            return true;
        }

        $transactionDay = $this->normalizeDate($date);
        $closingDay = Carbon::parse($settings->closing_date)->startOfDay();

        return $transactionDay->greaterThan($closingDay);
    }

    protected function branchSettings(): ?AccountingBranchSetting
    {
        $context = app(AccountingContextInterface::class);
        $branchId = $context->getBranchId();
        $orgId = $context->getOrganizationId();

        $query = AccountingBranchSetting::withoutGlobalScopes();

        if ($branchId !== null && $branchId > 0) {
            return $query->where('branch_id', $branchId)->first();
        }

        if ($orgId > 0) {
            return $query->where('organization_id', $orgId)->first();
        }

        return null;
    }

    protected function normalizeDate(Carbon|\DateTimeInterface|string $date): Carbon
    {
        if ($date instanceof Carbon) {
            return $date->copy()->startOfDay();
        }

        return Carbon::parse($date)->startOfDay();
    }
}