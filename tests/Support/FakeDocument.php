<?php

namespace Abdiaziizaweis\Accounting\Tests\Support;

use Abdiaziizaweis\Accounting\Concerns\HasDefaultDocumentMethods;
use Abdiaziizaweis\Accounting\Contracts\AccountingDocumentInterface;
use Illuminate\Support\Carbon;

class FakeDocument implements AccountingDocumentInterface
{
    use HasDefaultDocumentMethods;

    public function __construct(
        public string $operationKey,
        public array $payload,
        public int $id = 1,
    ) {}

    public function getDocumentId(): int
    {
        return $this->id;
    }

    public function getOperationKey(): string
    {
        return $this->operationKey;
    }

    public function getAccountingPayload(): array
    {
        return $this->payload;
    }

    public function getDate(): Carbon
    {
        return Carbon::parse('2026-11-23');
    }

    public function getMorphClass(): string
    {
        return 'fake-sale';
    }
}
