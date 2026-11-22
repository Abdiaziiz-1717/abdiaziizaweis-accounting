<?php

namespace Jtech\Accounting\Registry;

class DocumentRegistry
{
    /**
     * @var array<string, array{
     *     module: string,
     *     document_type: string,
     *     label: string,
     *     default_debit: string,
     *     default_credit: string,
     *     has_tax: bool,
     *     has_discount: bool
     * }>
     */
    protected static array $documents = [];

    public static function register(
        string $module,
        string $documentType,
        string $label,
        string $defaultDebit,
        string $defaultCredit,
        bool $hasTax = false,
        bool $hasDiscount = false,
    ): void {
        $key = self::key($module, $documentType);

        self::$documents[$key] = [
            'module' => $module,
            'document_type' => $documentType,
            'label' => $label,
            'default_debit' => $defaultDebit,
            'default_credit' => $defaultCredit,
            'has_tax' => $hasTax,
            'has_discount' => $hasDiscount,
        ];
    }

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
    public static function all(): array
    {
        return array_values(self::$documents);
    }

    /**
     * @return array{
     *     module: string,
     *     document_type: string,
     *     label: string,
     *     default_debit: string,
     *     default_credit: string,
     *     has_tax: bool,
     *     has_discount: bool
     * }|null
     */
    public static function find(string $module, string $documentType): ?array
    {
        return self::$documents[self::key($module, $documentType)] ?? null;
    }

    /**
     * @return array<string, list<array{
     *     module: string,
     *     document_type: string,
     *     label: string,
     *     default_debit: string,
     *     default_credit: string,
     *     has_tax: bool,
     *     has_discount: bool
     * }>>
     */
    public static function allGroupedByModule(): array
    {
        $grouped = [];

        foreach (self::$documents as $document) {
            $grouped[$document['module']][] = $document;
        }

        ksort($grouped);

        foreach ($grouped as $module => $documents) {
            usort($grouped[$module], fn (array $a, array $b) => strcmp($a['document_type'], $b['document_type']));
        }

        return $grouped;
    }

    public static function clear(): void
    {
        self::$documents = [];
    }

    protected static function key(string $module, string $documentType): string
    {
        return strtolower($module).':'.strtolower($documentType);
    }
}