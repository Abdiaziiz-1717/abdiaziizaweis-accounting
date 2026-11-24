# abdiaziizaweis/accounting

Version **2.1**. The package is a double-entry engine and a JSON API inside the host app. It does not ship UI. It does not seed mappings. It does not split tax or discount by itself.

`1.0.x` tags are an older package. Use `^2.1`.

## Install

```bash
composer require abdiaziizaweis/accounting:^2.1
php artisan accounting:install
```

Set `accounting.tenancy_mode` before the first migrate.

| Mode | Host tables |
|------|-------------|
| `single_tenant` | none |
| `multi_tenant` | `organizations` |
| `multi_branch` | `branches` |
| `multi_tenant_multi_branch` | both |

`accounting:bootstrap` seeds accounts only. No login required in CLI. Optional `--user-id=`. Pass `--organization-id` / `--branch-id` when the mode needs them.

Routes use `config('accounting.middleware')` (default `auth` plus context). They do **not** check permission names. Add Gates later only if you want them.

## Two ways to post

Same method: `AccountingDocumentBridge::record()`.

- **`HasAccountingDocument`** on an Eloquent model calls `record()` after `saved`, and `voidFor(strict: true)` on `deleting`.
- **Call `record($document)` yourself** when there is no model event you want to use (import, queue, or a save that must not post).

Void of a document voids the linked journal. The host row is not deleted by the package.

## Document adapter

```php
getDocumentId(): int
getOperationKey(): string          // you choose, e.g. sale.recorded
getAccountingPayload(): array      // must include amount
getDate(): Carbon
getCurrency(): string
getMorphClass(): string
getReference(): ?string
getDescription(): ?string
getOwnerUserId(): ?int
```

`HasDefaultDocumentMethods` fills currency, reference, description, owner, and a zero amount. Override the payload and the operation key.

Payload keys are whatever your mapping lines name: `amount`, `cost`, `tax_amount`, `discount_amount`, or your own.

## Mapping

`PUT /operations/{operationKey}` replaces the lines. The key may contain dots (`sale.recorded`).

Each line: `account_key` or `account_id`, `side` (`debit` or `credit`), `amount_source`, optional `skip_when_zero`.

Save is rejected unless there are at least two lines and each amount source has the same number of debit and credit lines.

`GET /operations` returns saved operations with `label`, `lines`, and the amount sources already used. That is the list for the mapping screen. Nothing else has to be registered in PHP.

If the operation is missing, disabled, or the payload does not balance after skip-when-zero, the transaction stays `not_mapped` and no journal is created.

COGS account key from the seeder is `cost_of_sales`.

## API

Prefix default: `api/v1/accounting`.

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/health` | Unmapped count. `ok` until you enable a mapping. |
| POST | `/bootstrap` | Seed chart of accounts |
| GET/PATCH | `/settings` | Fiscal year, method, account numbers |
| PATCH | `/settings/close-period` | `{ "closing_date": "YYYY-MM-DD" }` |
| PATCH | `/settings/reopen-period` | Open the books |
| * | `/accounts` | Chart of accounts CRUD |
| GET | `/transactions` | List |
| GET | `/transactions/unmapped` | Not mapped |
| GET | `/transactions/{id}` | One |
| POST | `/transactions/{id}/map` | Manual debit/credit pair |
| POST | `/transactions/{id}/unmap` | Void journal, keep transaction |
| POST | `/transactions/bulk-map` | Re-try operation mappings |
| * | `/journal-entries` | List and manual balanced entry |
| GET/PUT | `/operations` and `/operations/{operationKey}` | Mapping setup |
| * | `/transfers` | Move money between accounts |
| GET | `/reports/trial-balance` | |
| GET | `/reports/profit-and-loss` | |
| GET | `/reports/balance-sheet` | |
| GET | `/reports/cash-flow` | |
| GET | `/reports/account-statement` | |
| GET | `/reports/journal-activity-by-month` | |

Dates on or before `closing_date` cannot be posted while books are closed.

## Where the code lives

After `composer require`, the package is in the host `vendor/` directory. The API is your Laravel app. Deleting the GitHub repo later does not remove an already installed copy. `composer update` is what fetches a newer copy.
