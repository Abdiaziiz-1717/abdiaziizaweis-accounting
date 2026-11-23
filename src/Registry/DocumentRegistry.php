<?php

namespace Abdiaziizaweis\Accounting\Registry;

/**
 * In-memory catalog of document types the host application can post to accounting.
 *
 * Register entries from {@see \Illuminate\Support\ServiceProvider::boot()} before
 * running {@see \Abdiaziizaweis\Accounting\Console\BootstrapCommand}. The mapping
 * seeder creates {@see \Abdiaziizaweis\Accounting\Models\AccountingDocumentMapping}
 * rows using each entry's default debit/credit {@see key_name} accounts from the CoA.
 */
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

    /**
     * Register a document type for bootstrap seeding and the registry API.
     *
     * @param  string  $module  Module key (e.g. `sales`, `inventory`).
     * @param  string  $documentType  Mapping key within the module (e.g. `invoice`, `payment`).
     * @param  string  $label  Display label for admin UIs.
     * @param  string  $defaultDebit  CoA account `key_name` for the default debit side.
     * @param  string  $defaultCredit  CoA account `key_name` for the default credit side.
     * @param  bool  $hasTax  Hint for UIs; invoice types may use tax splitting when configured.
     * @param  bool  $hasDiscount  Hint for UIs; invoice types may use discount splitting.
     */
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
     * Look up one registered document by module and document type.
     *
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
     * All registrations grouped by module, sorted for stable API output.
     *
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

    /** Clear all registrations (tests only). */
    public static function clear(): void
    {
        self::$documents = [];
    }

    protected static function key(string $module, string $documentType): string
    {
        return strtolower($module).':'.strtolower($documentType);
    }
}