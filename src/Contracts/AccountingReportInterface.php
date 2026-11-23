<?php

namespace Abdiaziizaweis\Accounting\Contracts;

interface AccountingReportInterface
{
    /**
     * Run the report and return a structured array payload (same shape conventions as built-in reports).
     *
     * @param  array<string, mixed>  $filters  Validated request / caller filters.
     * @return array<string, mixed>
     */
    public function handle(array $filters): array;

    /**
     * Laravel validation rules for report filters (query params).
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    public function label(): string;
}