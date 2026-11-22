<?php

namespace Jtech\Accounting\Enums;

enum AccountingAccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';
    case Cash = 'cash';
    case AccountsReceivable = 'accounts_receivable';
    case Bank = 'bank';
    case CurrentAssets = 'current_assets';
    case FixedAssets = 'fixed_assets';
    case AccountsPayable = 'accounts_payable';
    case CreditCard = 'credit_card';
    case CurrentLiabilities = 'current_liabilities';
    case NoncurrentLiabilities = 'noncurrent_liabilities';
    case OwnerEquity = 'owner_equity';
    case OtherIncome = 'other_income';
    case CostOfSales = 'cost_of_sales';
    case Expenses = 'expenses';
    case OtherExpense = 'other_expense';
}