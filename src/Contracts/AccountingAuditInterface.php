<?php

namespace Abdiaziizaweis\Accounting\Contracts;

interface AccountingAuditInterface
{
    /**
     * Record an accounting-domain audit event for the host.
     *
     * @param  string  $event  Dotted name, e.g. `transaction.created`, `journal.voided`.
     * @param  string  $entityType  Logical entity, e.g. `AccountingTransaction`.
     * @param  int  $entityId  Primary key of the entity.
     * @param  array<string, mixed>  $context  Extra payload: amounts, account ids, reasons, etc.
     * @param  int|null  $userId  Acting user when known.
     */
    public function log(
        string $event,
        string $entityType,
        int $entityId,
        array $context = [],
        ?int $userId = null,
    ): void;
}