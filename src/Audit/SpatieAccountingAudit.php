<?php

namespace Abdiaziizaweis\Accounting\Audit;

use Abdiaziizaweis\Accounting\Contracts\AccountingAuditInterface;
use Illuminate\Support\Facades\Auth;

class SpatieAccountingAudit implements AccountingAuditInterface
{
    public function __construct(
        protected NullAccountingAudit $nullAudit,
    ) {}

    public function log(
        string $event,
        string $entityType,
        int $entityId,
        array $context = [],
        ?int $userId = null,
    ): void {
        if (! function_exists('activity')) {
            $this->nullAudit->log($event, $entityType, $entityId, $context, $userId);

            return;
        }

        $actor = $userId ?? Auth::id();

        $logger = activity($event)
            ->withProperties(array_merge($context, [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ]));

        if ($actor !== null) {
            $logger->causedBy($actor);
        }

        $logger->event($event)->log("{$entityType} #{$entityId}: {$event}");
    }
}