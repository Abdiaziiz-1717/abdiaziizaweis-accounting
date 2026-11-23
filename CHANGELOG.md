# Changelog

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