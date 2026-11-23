<?php

namespace Abdiaziizaweis\Accounting\Registry;

/**
 * Optional host catalog of operation keys and the payload keys they send.
 * Registration does not insert mapping rows. The mapping UI uses this list
 * for dropdowns. Posting uses saved operation mapping lines only.
 */
class DocumentRegistry
{
    /**
     * @var array<string, array{operation_key: string, label: string, amount_sources: list<string>}>
     */
    protected static array $operations = [];

    /**
     * @param  list<string>  $amountSources
     */
    public static function register(
        string $operationKey,
        string $label,
        array $amountSources = ['amount'],
    ): void {
        self::$operations[$operationKey] = [
            'operation_key' => $operationKey,
            'label' => $label,
            'amount_sources' => array_values(array_unique($amountSources)),
        ];
    }

    /**
     * @return list<array{operation_key: string, label: string, amount_sources: list<string>}>
     */
    public static function all(): array
    {
        return array_values(self::$operations);
    }

    public static function forget(): void
    {
        self::$operations = [];
    }
}
