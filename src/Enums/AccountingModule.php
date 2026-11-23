<?php

namespace Abdiaziizaweis\Accounting\Enums;

enum AccountingModule: string
{
    case Hotel = 'hotel';
    case Restaurant = 'restaurant';
    case Events = 'events';
    case Purchase = 'purchase';
    case Crm = 'crm';
    case Inventory = 'inventory';
    case Payroll = 'payroll';
    case Expenses = 'expenses';
    case Saas = 'saas';
    case Sales = 'sales';
    case Service = 'service';
    case Parts = 'parts';
}