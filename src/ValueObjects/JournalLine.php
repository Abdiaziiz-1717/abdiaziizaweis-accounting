<?php

namespace Abdiaziizaweis\Accounting\ValueObjects;

final class JournalLine
{
    private function __construct(
        public readonly ?string $accountKey,
        public readonly ?int $accountId,
        public readonly float $debit,
        public readonly float $credit,
        public readonly ?string $memo = null,
    ) {
        if ($accountKey === null && $accountId === null) {
            throw new \InvalidArgumentException(
                'JournalLine requires either accountKey or accountId'
            );
        }
        if ($debit < 0 || $credit < 0) {
            throw new \InvalidArgumentException(
                'JournalLine debit and credit must be non-negative'
            );
        }
        if ($debit > 0 && $credit > 0) {
            throw new \InvalidArgumentException(
                'JournalLine cannot have both debit and credit — use separate lines'
            );
        }
    }

    public static function debit(string $accountKey, float $amount, ?string $memo = null): self
    {
        return new self(
            accountKey: $accountKey,
            accountId: null,
            debit: $amount,
            credit: 0,
            memo: $memo,
        );
    }

    public static function credit(string $accountKey, float $amount, ?string $memo = null): self
    {
        return new self(
            accountKey: $accountKey,
            accountId: null,
            debit: 0,
            credit: $amount,
            memo: $memo,
        );
    }

    public static function debitById(int $accountId, float $amount, ?string $memo = null): self
    {
        return new self(
            accountKey: null,
            accountId: $accountId,
            debit: $amount,
            credit: 0,
            memo: $memo,
        );
    }

    public static function creditById(int $accountId, float $amount, ?string $memo = null): self
    {
        return new self(
            accountKey: null,
            accountId: $accountId,
            debit: 0,
            credit: $amount,
            memo: $memo,
        );
    }

    public function isDebit(): bool
    {
        return $this->debit > 0;
    }

    public function isCredit(): bool
    {
        return $this->credit > 0;
    }

    public function amount(): float
    {
        return $this->debit + $this->credit;
    }

    public function usesKeyName(): bool
    {
        return $this->accountKey !== null;
    }

    public function usesAccountId(): bool
    {
        return $this->accountId !== null;
    }
}