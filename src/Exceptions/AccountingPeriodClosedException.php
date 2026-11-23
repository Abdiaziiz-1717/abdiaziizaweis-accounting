<?php

namespace Abdiaziizaweis\Accounting\Exceptions;

use Illuminate\Support\Carbon;
use RuntimeException;

class AccountingPeriodClosedException extends RuntimeException
{
    public static function forDate(Carbon $date, Carbon $closingDate): self
    {
        return new self(
            "Cannot post to a closed accounting period. 
                Transaction date {$date->toDateString()} is on or before 
                the closing date {$closingDate->toDateString()}. 
                Reopen the books before posting."
        );
    }
}