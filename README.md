# abdiaziizaweis/accounting

Double-entry accounting for Laravel. The package stores the chart of accounts, transactions, journals, transfers, and reports, and exposes them as a JSON API inside your app. It does not ship pages and it does not know your industry.

**Current line: 2.1.** Tags `1.0.0`–`1.0.3` are an older design. Do not install those. `2.0.0` still contained leftovers removed in `2.1.0`.

```bash
composer require abdiaziizaweis/accounting:^2.1
php artisan accounting:install
```

Set `tenancy_mode` in `config/accounting.php` before the first migrate. `single_tenant` does not need `organizations` or `branches` tables.

## What the package does

- Creates accounting tables and seeds a chart of accounts (`accounts_receivable`, `sales_revenue`, `cost_of_sales`, `tax_payable`, and the rest).
- Does **not** seed mappings. Nothing posts until you save operation lines.
- When a host document is saved, stores an accounting transaction. If that operation has a balanced mapping, it posts the journal from those lines and the document payload. Otherwise the transaction stays `not_mapped`.
- Manual journals, transfers, six reports, health, and close-books.

The API runs in the host process under `api/v1/accounting` (change `route_prefix`). It is not a separate online service. A copy lives in `vendor/` after install. Later edits or removal of Packagist/GitHub do not change hosts who already installed that version. They change only on the next `composer update`.

## What you build

Your UI, on top of the API:

- Chart of accounts
- Transactions (list, unmapped, map, unmap)
- Journal entries
- Transfers
- Mapping setup
- Reports: trial balance, profit and loss, balance sheet, cash flow, account statement, journal activity by month
- Settings: fiscal year, method, close books

Login is required (`accounting.middleware`, default `auth`). Permission Gates are **not** required. Add them later in your own middleware if you want.

## Posting

1. Give each business event an operation key you choose, such as `sale.recorded`.
2. In mapping setup, `PUT /operations/sale.recorded` with a label and lines. Each line is an account, `debit` or `credit`, and an `amount_source` (`amount`, `cost`, `tax_amount`, `discount_amount`, or any key you send).
3. On the model, implement the document adapter and use `HasAccountingDocument`.

`HasAccountingDocument` and `AccountingDocumentBridge::record()` are the same path.

- The trait calls `record()` on Eloquent `saved`, and voids on `deleting`.
- Call `record()` yourself for imports, queues, or when a save should not post.

There is no PHP journal-line escape hatch and no industry module list. Tax, discount, and cost are mapping lines plus payload fields.

Example lines for a sale:

```json
{
  "label": "Sale recorded",
  "is_enabled": true,
  "lines": [
    {"account_key": "accounts_receivable", "side": "debit", "amount_source": "amount"},
    {"account_key": "sales_revenue", "side": "credit", "amount_source": "amount"},
    {"account_key": "cost_of_sales", "side": "debit", "amount_source": "cost", "skip_when_zero": true},
    {"account_key": "inventory_asset", "side": "credit", "amount_source": "cost", "skip_when_zero": true}
  ]
}
```

At least two lines. For each amount source, debit line count must equal credit line count. A missing or unbalanced map leaves the transaction `not_mapped`.

`GET /operations` is the list for the mapping screen (label and amount sources come from saved rows).

## Commands

**`php artisan accounting:install`**  
Writes config, migrates, seeds the chart of accounts.

**`php artisan accounting:bootstrap`**  
Seeds the chart of accounts again. Idempotent. Does not create mappings. No login required. Optional `--user-id=` stamps an owner. `--organization-id` and `--branch-id` apply only outside `single_tenant`.

**`php artisan migrate`**  
Laravel’s migrate. Install runs it. Upgrading to 2.1 drops old unused mapping tables if they exist.

## More detail

- [docs/KNOWLEDGE.md](docs/KNOWLEDGE.md) — API and posting rules
- [docs/EXAMPLE_INTEGRATION.md](docs/EXAMPLE_INTEGRATION.md) — model adapter
- [docs/BLADE_INTEGRATION.md](docs/BLADE_INTEGRATION.md) — trait vs manual `record()`
- [docs/UI_STRUCTURE.md](docs/UI_STRUCTURE.md) — screens to build
