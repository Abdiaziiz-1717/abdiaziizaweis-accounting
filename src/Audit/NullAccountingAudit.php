<?php

namespace Abdiaziizaweis\Accounting\Audit;

use Abdiaziizaweis\Accounting\Contracts\AccountingAuditInterface;

class NullAccountingAudit implements AccountingAuditInterface
{
    public function log(
        string $event,
        string $entityType,
        int $entityId,
        array $context = [],
        ?int $userId = null,
    ): void {
        // no-op
    }
}