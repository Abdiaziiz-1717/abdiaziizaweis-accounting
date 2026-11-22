<?php

namespace Jtech\Accounting\Enums;

enum AccountingJournalSource: string
{
    case Mapping = 'mapping';
    case Manual = 'manual';
    case Transfer = 'transfer';
}