<?php

namespace Abdiaziizaweis\Accounting\Concerns;

/**
 * Optional {@see \Abdiaziizaweis\Accounting\Contracts\AccountingDocumentInterface} helpers.
 */
trait HasDefaultDocumentMethods
{
    public function getOwnerUserId(): ?int
    {
        return null;
    }

    public function getCurrency(): string
    {
        return (string) config('accounting.currency', 'USD');
    }

    public function getReference(): ?string
    {
        return null;
    }

    public function getDescription(): ?string
    {
        return null;
    }

    /**
     * @return array<string, float|int|string|null>
     */
    public function getAccountingPayload(): array
    {
        return ['amount' => 0];
    }
}
