<?php

namespace Abdiaziizaweis\Accounting\Exceptions;

class UnbalancedJournalLinesException extends \RuntimeException
{
    public static function forLines(
        float $totalDebits,
        float $totalCredits,
        string $documentType
    ): self {
        return new self(
            "Journal lines for '{$documentType}' are not balanced. ".
            "Total debits: {$totalDebits}, Total credits: {$totalCredits}. ".
            'Difference: '.abs($totalDebits - $totalCredits).'. '.
            'Every journal entry must have equal debits and credits.'
        );
    }
}