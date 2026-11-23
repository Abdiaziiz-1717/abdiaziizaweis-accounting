<?php

namespace Abdiaziizaweis\Accounting\Registry;

/**
 * Package-default document types seeded on bootstrap when hosts have not
 * registered their own entries for these module/type pairs.
 */
class BuiltinDocumentRegistry
{
    /**
     * @return list<array{
     *     module: string,
     *     document_type: string,
     *     label: string,
     *     default_debit: string,
     *     default_credit: string,
     *     has_tax: bool,
     *     has_discount: bool
     * }>
     */
    public static function entries(): array
    {
        return [
            [
                'module' => 'sales',
                'document_type' => 'invoice',
                'label' => 'Sales invoice',
                'default_debit' => 'accounts_receivable',
                'default_credit' => 'sales_revenue',
                'has_tax' => true,
                'has_discount' => true,
            ],
            [
                'module' => 'sales',
                'document_type' => 'payment',
                'label' => 'Sales payment',
                'default_debit' => 'cash_and_cash_equivalents',
                'default_credit' => 'accounts_receivable',
                'has_tax' => false,
                'has_discount' => false,
            ],
            [
                'module' => 'service',
                'document_type' => 'invoice',
                'label' => 'Service invoice',
                'default_debit' => 'accounts_receivable',
                'default_credit' => 'service_revenue',
                'has_tax' => true,
                'has_discount' => false,
            ],
            [
                'module' => 'service',
                'document_type' => 'payment',
                'label' => 'Service payment',
                'default_debit' => 'cash_and_cash_equivalents',
                'default_credit' => 'accounts_receivable',
                'has_tax' => false,
                'has_discount' => false,
            ],
            [
                'module' => 'parts',
                'document_type' => 'invoice',
                'label' => 'Parts invoice',
                'default_debit' => 'accounts_receivable',
                'default_credit' => 'parts_revenue',
                'has_tax' => true,
                'has_discount' => true,
            ],
            [
                'module' => 'parts',
                'document_type' => 'payment',
                'label' => 'Parts payment',
                'default_debit' => 'cash_and_cash_equivalents',
                'default_credit' => 'accounts_receivable',
                'has_tax' => false,
                'has_discount' => false,
            ],
        ];
    }

    public static function register(): void
    {
        foreach (self::entries() as $row) {
            if (DocumentRegistry::find($row['module'], $row['document_type']) !== null) {
                continue;
            }

            DocumentRegistry::register(
                $row['module'],
                $row['document_type'],
                $row['label'],
                $row['default_debit'],
                $row['default_credit'],
                $row['has_tax'],
                $row['has_discount'],
            );
        }
    }
}