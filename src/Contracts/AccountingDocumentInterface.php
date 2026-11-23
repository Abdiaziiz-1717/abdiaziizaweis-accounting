<?php

namespace Abdiaziizaweis\Accounting\Contracts;

use Illuminate\Support\Carbon;

/**
 * Adapter contract between a host business document (invoice, payment, expense)
 * and the accounting engine.
 *
 * Implement this on your Eloquent model (or on a dedicated wrapper). The
 * {@see \Abdiaziizaweis\Accounting\Services\AccountingDocumentBridge} reads these
 * values to create or void {@see \Abdiaziizaweis\Accounting\Models\AccountingTransaction}
 * rows and optionally post journal entries via mappings.
 */
interface AccountingDocumentInterface
{
    /**
     * Primary key of the host record. Used with {@see getMorphClass()} to link
     * accounting_transactions.transactionable_id / transactionable_type.
     */
    public function getDocumentId(): int;

    /**
     * Accounting transaction type string (e.g. `sales_invoice`, `hotel_payment`).
     * Must be stable for the document kind; used for mapping lookup and void matching.
     */
    public function getDocumentType(): string;

    /**
     * Logical module name (e.g. `sales`, `hotel`). Matches DocumentRegistry `module`
     * and document mapping rows. Used when resolving default debit/credit accounts.
     */
    public function getDocumentModule(): string;

    /**
     * Economic amount to post (typically invoice total or payment amount). The bridge
     * rounds to two decimals; amounts ≤ 0 trigger void instead of a new transaction.
     */
    public function getAmount(): float;

    /**
     * Tax portion of the document for snapshot fields and optional N-line invoice splits
     * when branch settings have tax accounts configured.
     */
    public function getTaxAmount(): float;

    /**
     * Discount portion for snapshots and optional sales/purchase discount splits.
     */
    public function getDiscountAmount(): float;

    /**
     * Accounting date (invoice date, payment date). Posted to transaction_date and JEs.
     */
    public function getDate(): Carbon;

    /**
     * ISO currency code for display or future multi-currency support (e.g. `USD`).
     */
    public function getCurrency(): string;

    /**
     * Morph class for the host model, usually `static::class` or `$this->getMorphClass()`
     * so void/record can find transactions for this document instance.
     */
    public function getMorphClass(): string;

    /**
     * Human-readable reference (invoice number, payment ref) stored on the transaction.
     */
    public function getReference(): ?string;

    /**
     * Optional longer description for the transaction / journal entry header.
     */
    public function getDescription(): ?string;
}