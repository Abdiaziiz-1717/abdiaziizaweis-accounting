<?php

namespace Abdiaziizaweis\Accounting\Contracts;

use Abdiaziizaweis\Accounting\ValueObjects\JournalLine;
use Illuminate\Support\Carbon;

/**
 * Host document adapter. The package does not know industries.
 * The host chooses an operation key and sends numeric payload fields
 * that mapping lines read (amount, tax_amount, cost, …).
 */
interface AccountingDocumentInterface
{
    public function getDocumentId(): int;

    /**
     * Host-defined operation key, e.g. sale.recorded. Not a package enum.
     */
    public function getOperationKey(): string;

    /**
     * Numeric values referenced by mapping lines. Must include amount.
     *
     * @return array<string, float|int|string|null>
     */
    public function getAccountingPayload(): array;

    public function getDate(): Carbon;

    public function getCurrency(): string;

    public function getMorphClass(): string;

    public function getReference(): ?string;

    public function getDescription(): ?string;

    public function getOwnerUserId(): ?int;

    /**
     * Escape hatch. When non-null, these lines are posted instead of stored mapping lines.
     * Hosts should prefer mapping lines for tax, discount, and COGS.
     *
     * @return list<JournalLine>|null
     */
    public function getJournalLines(): ?array;
}
