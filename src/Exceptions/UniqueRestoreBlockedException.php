<?php

namespace Jtech\Accounting\Exceptions;

use Exception;

class UniqueRestoreBlockedException extends Exception
{
    public function __construct(
        public readonly string $column,
        public readonly string $originalValue,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : sprintf(
            'Cannot restore: %s "%s" is already used by another record. Change or permanently delete the live record first.',
            $column,
            $originalValue
        ));
    }

    public static function forColumn(string $column, string $originalValue): self
    {
        return new self($column, $originalValue);
    }
}