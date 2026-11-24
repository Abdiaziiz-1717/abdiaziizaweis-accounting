# Changelog

## [2.1.0] - 2026-11-24

Stays on 2.x because `1.0.0`–`1.0.3` are already published with the old design. Packagist will not treat this cleanup as `1.0.0` again.

- Docs rewritten for the current product only.
- API routes no longer require permission Gates. Login middleware only. Hosts can add permissions later.
- Removed PHP journal lines (`getJournalLines`), the document registry, industry module enum, and payment-method / expense-category mapping APIs.
- Fresh installs do not create those old mapping tables. Upgrades drop them if they exist.
- Posting is operation mapping lines plus the document payload only.

## [2.0.0] - 2026-11-23

Breaking:

- Bootstrap seeds the chart of accounts only. No document, payment-method, or expense-category mappings.
- Builtin industry modules are not required. Hosts use any operation key string.
- Posting uses stored multi-line operation mappings and payload amount sources (`amount`, `tax_amount`, `discount_amount`, `cost`, …).
- Automatic tax and discount invoice splitting is removed, including branch-setting tax/discount account columns and `features.tax_splitting` / `features.discount_splitting`.
- `getJournalLines()` remains an explicit escape hatch. The normal path does not need PHP journal code.
- `PUT /operations/{operationKey}` replaces two-account document mapping upserts.

## [1.0.3] - 2026-11-23

- Fix `AccountingPaymentMethodMappingSideController` PHP 8.3 LSP fatals on `route:list` (extend `Controller` + `ApiResponseTrait` instead of overriding `BaseController` CRUD signatures)

## [1.0.2] - 2026-11-23

- CLI `accounting:bootstrap` works without an authenticated user (`userOrNull()`, optional `--user-id=`)
- MySQL-safe `drop_reconcile` migration (drop `acct_jel_rec_fk` before indexes; skip Spatie `permissions` when table missing)
- Short FK name `acct_pm_side_map_fk` on `accounting_payment_method_mapping_sides`
- README / KNOWLEDGE: require `^1.0.1`+, MySQL troubleshooting, first-host checklist, canonical CoA `key_name` for COGS (`cost_of_sales`)

## [1.0.1] - 2026-11-23

- Laravel **13** support (`^11.0|^12.0|^13.0`)
- `single_tenant` mode (no organizations/branches tables required)
- Dynamic multi-line journal entries via `getJournalLines()` and `JournalLine`
- Sales / service / parts builtin registry, health check, unmapped transactions API
- Payment method mapping sides refactor

## [1.0.0] - 2024-01-01

- Initial release (Laravel **11** only — prefer **1.0.1** or newer)
- Double-entry accounting engine
- Multi-tenancy support (4 modes)
- Dynamic document registry
- Chart of accounts bootstrap
- Financial reports: trial balance, P&L, balance sheet, cash flow