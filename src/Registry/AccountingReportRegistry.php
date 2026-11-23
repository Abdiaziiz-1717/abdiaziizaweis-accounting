<?php

namespace Abdiaziizaweis\Accounting\Registry;

use Abdiaziizaweis\Accounting\Contracts\AccountingReportInterface;
use InvalidArgumentException;

/**
 * In-memory registry for custom reports and overrides of built-in report handlers.
 */
class AccountingReportRegistry
{
    /** @var list<string> */
    public const BUILT_IN_NAMES = [
        'trial-balance',
        'profit-and-loss',
        'balance-sheet',
        'cash-flow',
        'account-statement',
        'journal-activity-by-month',
    ];

    /**
     * @var array<string, array{name: string, label: string, handler: class-string<AccountingReportInterface>}>
     */
    protected static array $custom = [];

    /**
     * @var array<string, class-string<AccountingReportInterface>>
     */
    protected static array $overrides = [];

    public static function register(string $name, string $label, string $handler): void
    {
        self::assertSlug($name);
        self::assertHandler($handler);

        if (in_array($name, self::BUILT_IN_NAMES, true)) {
            throw new InvalidArgumentException(
                "Report name [{$name}] is reserved for a built-in report. Use override() instead."
            );
        }

        self::$custom[$name] = [
            'name' => $name,
            'label' => $label,
            'handler' => $handler,
        ];
    }

    public static function override(string $name, string $handler): void
    {
        if (! in_array($name, self::BUILT_IN_NAMES, true)) {
            throw new InvalidArgumentException(
                "Unknown built-in report [{$name}]. Valid names: ".implode(', ', self::BUILT_IN_NAMES)
            );
        }

        self::assertHandler($handler);
        self::$overrides[$name] = $handler;
    }

    /**
     * @return list<array{name: string, label: string, handler: class-string<AccountingReportInterface>}>
     */
    public static function allCustom(): array
    {
        return array_values(self::$custom);
    }

    /**
     * @return array{name: string, label: string, handler: class-string<AccountingReportInterface>}|null
     */
    public static function findCustom(string $name): ?array
    {
        return self::$custom[$name] ?? null;
    }

    /**
     * @return class-string<AccountingReportInterface>|null
     */
    public static function getOverride(string $builtInName): ?string
    {
        return self::$overrides[$builtInName] ?? null;
    }

    public static function hasOverride(string $builtInName): bool
    {
        return isset(self::$overrides[$builtInName]);
    }

    /** Clear all registrations and overrides (tests only). */
    public static function clear(): void
    {
        self::$custom = [];
        self::$overrides = [];
    }

    protected static function assertSlug(string $name): void
    {
        if ($name === '' || ! preg_match('/^[a-zA-Z0-9]+(?:-[a-zA-Z0-9]+)*$/', $name)) {
            throw new InvalidArgumentException(
                'Report name must be a slug: letters, numbers, and hyphens only.'
            );
        }
    }

    /**
     * @param  class-string  $handler
     */
    protected static function assertHandler(string $handler): void
    {
        if (! class_exists($handler)) {
            throw new InvalidArgumentException("Report handler class [{$handler}] does not exist.");
        }

        if (! is_subclass_of($handler, AccountingReportInterface::class)) {
            throw new InvalidArgumentException(
                "Report handler [{$handler}] must implement ".AccountingReportInterface::class.'.'
            );
        }
    }
}