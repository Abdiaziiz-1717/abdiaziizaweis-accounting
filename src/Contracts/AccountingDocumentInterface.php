<?php

namespace Jtech\Accounting\Contracts;

use Illuminate\Support\Carbon;

interface AccountingDocumentInterface
{
    public function getDocumentId(): int;

    public function getDocumentType(): string;

    public function getDocumentModule(): string;

    public function getAmount(): float;

    public function getTaxAmount(): float;

    public function getDiscountAmount(): float;

    public function getDate(): Carbon;

    public function getCurrency(): string;

    public function getMorphClass(): string;

    public function getReference(): ?string;

    public function getDescription(): ?string;
}