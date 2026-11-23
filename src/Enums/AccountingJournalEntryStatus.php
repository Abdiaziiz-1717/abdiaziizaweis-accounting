<?php

namespace Abdiaziizaweis\Accounting\Enums;

enum AccountingJournalEntryStatus: string
{
    case Active = 'active';
    case Voided = 'voided';

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}