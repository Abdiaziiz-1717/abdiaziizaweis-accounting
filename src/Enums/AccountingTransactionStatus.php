<?php

namespace Abdiaziizaweis\Accounting\Enums;

enum AccountingTransactionStatus: string
{
    /** Active — not yet linked to a journal entry. */
    case NotMapped = 'not_mapped';

    /** Active — linked to an active journal entry. */
    case Mapped = 'mapped';

    /** Permanently inactive — never reactivated. */
    case Voided = 'voided';

    public function isActive(): bool
    {
        return $this !== self::Voided;
    }
}