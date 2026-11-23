# abdiaziizaweis/accounting — Complete Reference

**v2 is a breaking release.** The package is a double-entry engine. It does not seed document mappings, does not require industry modules, and does not split tax or discount. Hosts name operation keys and save mapping lines in their own UI. The package does not ship pages — see [UI_STRUCTURE.md](UI_STRUCTURE.md).

For UI implementation guidance see [UI_STRUCTURE.md](UI_STRUCTURE.md).

## 1. What this package is

Composer package **`abdiaziizaweis/accounting`** (`Abdiaziizaweis\Accounting`) for **Laravel 11, 12, or 13** / **PHP 8.2+**. It provides:

- Tenant/branch-scoped **chart of accounts**
- **Accounting transactions** (morph-linked to host documents)
- **Journal entries** + lines (double-entry, void lifecycle)
- **Mappings** (document, payment method, expense category)
- **Transfers**, branch **settings**, **bootstrap** seeding
- **REST API** + **`accounting:install`** / **`accounting:bootstrap`** commands

Host apps integrate by implementing `AccountingDocumentInterface` and registering types in `DocumentRegistry`.

---

## 2. Architecture overview (with ASCII flow diagram)

```
┌─────────────────────┐     saved/deleted      ┌──────────────────────────┐
│ Host Eloquent Model │ ─────────────────────► │ AccountingDocumentBridge │
│ + Interface/Trait   │                      │ record() / voidFor()     │
└──────────┬──────────┘                      └────────────┬─────────────┘
           │                                              │
           │ morph                                          ▼
           │                               ┌──────────────────────────┐
           │                               │ AccountingTransaction    │
           │                               │ not_mapped → mapped      │
           │                               └────────────┬─────────────┘
           │                                              │ tryAutoMap /
           │                                              │ mapWithAccounts
           ▼                                              ▼
┌─────────────────────┐                      ┌──────────────────────────┐
│ DocumentRegistry    │──bootstrap seeds──►  │ AccountingDocumentMapping │
│ (in-memory catalog) │                      │ + PM / expense mappings │
└─────────────────────┘                      └────────────┬─────────────┘
                                                          │
                                                          ▼
                                           ┌──────────────────────────┐
                                           │ AccountingJournalEntry     │
                                           │ status: active | voided    │
                                           │ + lines (debit/credit)     │
                                           └────────────┬─────────────┘
                                                          │
                                                          ▼
                                           ┌──────────────────────────┐
                                           │ AccountingReportService    │
                                           │ trial balance, P&L, …      │
                                           └──────────────────────────┘
```

**Context:** `ResolveAccountingContext` middleware binds `AccountingContextInterface` (org/branch/user) per HTTP request. Global Eloquent scopes filter queries by that context.

---

## 3. Installation and setup

**Supported stack:** PHP `^8.2` with Laravel `^11.0`, `^12.0`, or `^13.0` (see `composer.json`). Laravel 13 applications typically require PHP 8.3+ per the framework; this package remains installable on PHP 8.2 when used with Laravel 11 or 12.

1. `composer require abdiaziizaweis/accounting:^2.0`
2. `php artisan accounting:install` **or** publish config + `migrate` + `accounting:bootstrap`
3. Register `DocumentRegistry::register(...)` entries in `AppServiceProvider::boot()`
4. Re-run `php artisan accounting:bootstrap [--force]` after new registry entries
5. Set `accounting.middleware` in config to your app’s auth middleware (`auth`, `auth:sanctum`, `auth:api`, `auth:jwt`, or custom). The package is **auth-system agnostic** — no Sanctum or Passport dependency.
6. Assign accounting permission names via Gates, `$user->can()`, Spatie (optional), or a custom `AccountingAuthInterface`
7. Optional: override `AccountingContextInterface` / `AccountingAuthInterface` in app `AppServiceProvider::register()`

Published tags: `accounting-config`, `accounting-migrations`.

**MySQL / MariaDB**

Tested on MySQL 8 / MariaDB 10.x. Set `tenancy_mode` in config **before** the first `migrate` (especially `single_tenant`). If a partial migration failed, fix the package version then run `php artisan migrate` again — reconcile-drop and payment-method-side migrations in **1.0.2+** are idempotent for common MySQL FK/index ordering issues.

**`accounting:bootstrap` in CLI**

Does not require login. Optional `owner_user_id` on seeded rows: pass `--user-id=` or rely on `AccountingAuthInterface::userOrNull()` when a web guard session exists. HTTP `POST …/bootstrap` still requires your auth middleware.

**First-host checklist** (no organizations/branches/Spatie)

1. `accounting.tenancy_mode` = `single_tenant` before first migrate  
2. Define Laravel `Gate::define()` (or similar) for accounting permission strings — default is deny without Gates  
3. Register **purchase** invoice/payment in `DocumentRegistry` if you use AP (builtin registry is sales/service/parts only)  
4. `php artisan accounting:bootstrap` (no session); `--user-id=1` only if you want ownership stamped on seeds  
5. Save operation lines in your UI before expecting journals  

**Operation mapping API**

| Method | Path | Body |
|--------|------|------|
| GET | `{prefix}/registry/documents` | Catalog only. No rows inserted. |
| GET | `{prefix}/operations` | Catalog merged with saved lines |
| GET | `{prefix}/operations/{operationKey}` | One operation. Dots are allowed (`sale.recorded`). |
| PUT | `{prefix}/operations/{operationKey}` | `{ "label", "is_enabled", "lines": [{ "account_key"\|"account_id", "side": "debit"\|"credit", "amount_source", "skip_when_zero" }] }` |

PUT enables the operation only when there are at least two lines and each `amount_source` has the same number of debit and credit lines. Posting reads the document payload. Missing or unbalanced maps leave the transaction `not_mapped` and do not create a journal. `tax_amount` and `discount_amount` are normal payload keys.

**Default middleware stack**

```php
'middleware' => [
    'auth',
    \Abdiaziizaweis\Accounting\Http\Middleware\ResolveAccountingContext::class,
],
```

Include `accounting.context` (alias for `ResolveAccountingContext`) whenever org/branch resolution is required for your tenancy mode.

---

## 4. Tenancy modes

| `accounting.tenancy_mode` | `usesOrganizationId()` | `usesBranchId()` | Default resolver | Host tables required |
|---------------------------|------------------------|------------------|------------------|----------------------|
| `single_tenant` | false | false | `SingleTenantContextResolver` | **None** — no `organizations` / `branches` |
| `multi_tenant` | true | false | `MultiTenantContextResolver` | `organizations` (`id`) |
| `multi_branch` | false | true | `MultiBranchContextResolver` | `branches` (`id`, `organization_id`) |
| `multi_tenant_multi_branch` | true | true | `MultiTenantMultiBranchContextResolver` | Both `organizations` and `branches` |

**Which mode should I choose?**

| Your app | Mode |
|----------|------|
| Single store / single company | `single_tenant` |
| One company, multiple locations | `multi_branch` |
| SaaS — many companies, one location each | `multi_tenant` |
| SaaS — many companies, multiple branches each | `multi_tenant_multi_branch` |

Default config is **`single_tenant`**. Complex tenancy is opt-in.

**`single_tenant` behavior**

- `SingleTenantContextResolver` is fixed: `organization_id = 1`, `branch_id = null`, `user_id = auth()->id() ?? 1` — **no database lookups**.
- `AccountingContextScope` applies **no** org/branch filters.
- `BelongsToAccountingContext` does not stamp or validate org/branch.
- Migrations omit `organization_id` / `branch_id` from all accounting tables (run `migrate` with `tenancy_mode` set before first migration).
- In `single_tenant` mode, the package never queries the `organizations` or `branches` tables. It is safe to use this mode even if those tables do not exist in your database.

Branch resolution in other modes often uses request header (see `MultiBranchContextResolver`). Multi-tenant reads `organization_id` from the authenticated user.

Migrations add `organization_id` / `branch_id` columns only when the mode requires them.

---

## 5. Registering document types (DocumentRegistry)

```php
DocumentRegistry::register(
    string $module,
    string $documentType,
    string $label,
    string $defaultDebit,    // accounting_accounts.key_name
    string $defaultCredit,
    bool $hasTax = false,
    bool $hasDiscount = false,
): void
```

- Keys stored as `strtolower(module):strtolower(document_type)`.
- `AccountingMappingSeeder` creates `accounting_document_mappings` for each `DocumentRegistry::all()` row (skips SaaS module unless org is “master”).
- `DocumentRegistry::clear()` — tests only.

**Builtin modules (package):** `Abdiaziizaweis\Accounting\Registry\BuiltinDocumentRegistry` registers default invoice/payment pairs for **`sales`**, **`service`**, and **`parts`** (enum cases on `AccountingModule`) on provider boot and before mapping seed. Hosts can override by calling `DocumentRegistry::register()` first.

**`AccountingModule` enum:** `hotel`, `restaurant`, `events`, `purchase`, `crm`, `inventory`, `payroll`, `expenses`, `saas`, **`sales`**, **`service`**, **`parts`**.

---

## 6. Implementing AccountingDocumentInterface

All methods are required on the host adapter (signatures on the interface; defaults via trait below).

| Method | Role |
|--------|------|
| `getDocumentId()` | PK |
| `getDocumentType()` | `accounting_transactions.type` |
| `getDocumentModule()` | Mapping module string |
| `getAmount()` | Rounded to 2dp; ≤ 0 voids |
| `getTaxAmount()` | Snapshot + splits |
| `getDiscountAmount()` | Snapshot + splits |
| `getDate()` | `Carbon` → `transaction_date` |
| `getCurrency()` | ISO code |
| `getMorphClass()` | Morph type |
| `getReference()` | Optional ref |
| `getDescription()` | Optional description |
| `getPaymentMethodId()` | Payment-method mapping; **`null`** → skip PM map, use document map |
| `getExpenseCategoryId()` | Expense-category mapping; **`null`** for non-expenses |
| `getOwnerUserId()` | Transaction owner; **`null`** → authenticated user from context |
| `getPaymentSide()` | PM side key (`sales`, `purchase`, `expenses`, …); default = `getDocumentModule()` |
| `getJournalLines()` | **`null`** → mapping mode; **non-null `JournalLine[]`** → custom multi-line JE (see §23) |

**`HasDefaultDocumentMethods` trait** — included by **`HasAccountingDocument`**. Defaults: `null` payment/expense/owner, `getPaymentSide()` = `getDocumentModule()`, **`getJournalLines()` = `null`**. Override only what you need.

**`getJournalLines()`** — When you return a balanced array of `Abdiaziizaweis\Accounting\ValueObjects\JournalLine` objects, `AccountingDocumentBridge` resolves `key_name` → account id, validates balance, and calls `mapWithLines()`. Document mapping, payment-method mapping, and automatic tax/discount splitting are **skipped**. When you return `null`, behavior is unchanged (mapping + splits).

---

## 7. HasAccountingDocument trait

- Uses `HasDefaultDocumentMethods` for optional interface methods.
- `bootHasAccountingDocument()` throws if interface missing.
- `saved` → `AccountingDocumentBridge::record()`
- `deleting` → `voidFor(..., strict: true)`

---

## 8. Manual bridge usage

If you are building a Blade app, you can inject services directly into controllers — see [BLADE_INTEGRATION.md](./BLADE_INTEGRATION.md) for complete examples.

`Abdiaziizaweis\Accounting\Services\AccountingDocumentBridge`:

- `record(AccountingDocumentInterface $document)` — try/catch logged unless internal rethrow
- `voidFor($document, bool $strict = false, ?string $reason = null)`
- `tryRemapInvoiceSplit(AccountingTransaction $tx)` — built-in invoice types only

---

## 9. Database schema (every table, every important column)

### `accounting_accounts`
`id`, `organization_id?`, `branch_id?`, `owner_user_id?`, `parent_id?`, `name`, `number?`, `key_name?`, `account_type`, `detail_type?`, `description?`, `is_active`, `is_default`, timestamps, `deleted_at`

### `accounting_branch_settings`
`id`, `organization_id?`, `branch_id?` (unique per branch when branch mode), `first_month_financial_year`, `accounting_method` (`accrual`|`cash`), `close_the_books`, `closing_date?`, `enable_account_numbers`, `tax_payable_account_id?`, `tax_receivable_account_id?`, `sales_discount_account_id?`, `purchase_discount_account_id?`, timestamps

### `accounting_journal_entries`
`id`, `organization_id?`, `branch_id?`, `owner_user_id?`, `number`, `journal_date`, `description?`, `reference?`, `source`, `amount`, `status` (`active`|`voided`), `voided_at?`, `voided_by_user_id?`, `void_reason?`, `accounting_transfer_id?`, timestamps (no soft deletes after void migration)

### `accounting_journal_entry_lines`
`id`, `journal_entry_id`, `account_id`, `debit`, `credit`, `memo?`, timestamps  
*(Reconcile columns removed in later migration; `accounting_reconciles` table dropped.)*

### `accounting_transactions`
`id`, `organization_id?`, `branch_id?`, `owner_user_id?`, `transactionable_type?`, `transactionable_id?`, `type`, `status` (`not_mapped`|`mapped`|`voided`), `amount`, `tax_amount?`, `discount_amount?`, `transaction_date`, `description?`, `reference?`, `journal_entry_id?`, `voided_at?`, `voided_by_user_id?`, `void_reason?`, `superseded_by_transaction_id?`, timestamps (no soft deletes after void migration)

### `accounting_document_mappings`
`id`, `organization_id?`, `branch_id?`, `module`, `document_type`, `debit_account_id`, `credit_account_id`, `is_enabled`, timestamps — unique on `(branch|org, module, document_type)`

### `accounting_payment_method_mappings`
`id`, `organization_id?`, `branch_id?`, `payment_method_id`, `sales_debit_account_id?`, `sales_credit_account_id?`, `purchase_debit_account_id?`, `purchase_credit_account_id?`, `is_enabled`, timestamps — legacy columns kept nullable for backward compatibility.

### `accounting_payment_method_mapping_sides`
`id`, `organization_id?`, `branch_id?`, `payment_method_mapping_id`, `module` (e.g. `sales`, `purchase`, `expenses`, any slug), `debit_account_id?`, `credit_account_id?`, `is_enabled`, timestamps — unique on `(payment_method_mapping_id, module)`.

### `accounting_expense_category_mappings`
`id`, `organization_id?`, `branch_id?`, `expense_category_id`, `debit_account_id`, `credit_account_id`, `is_enabled`, timestamps

### `accounting_transfers`
`id`, `organization_id?`, `branch_id?`, `owner_user_id?`, `from_account_id`, `to_account_id`, `amount`, `transfer_date`, `notes?`, `journal_entry_id?`, timestamps, `deleted_at`

---

## 10. Transaction lifecycle

**Enum:** `AccountingTransactionStatus` — `not_mapped`, `mapped`, `voided` (`isActive()` false only for `voided`).

1. **Insert:** `NotMapped`, morph optional.
2. **Auto-map / map API:** creates/replaces JE → `Mapped`, sets `journal_entry_id`.
3. **Economic change:** void old (+ JE), new row, `superseded_by_transaction_id` on old.
4. **Unmap:** void JE, `journal_entry_id = null`, `NotMapped`.
5. **Void:** permanent; void JE if present.

Active scope excludes `voided` on default API listings.

---

## 11. Journal entry lifecycle

**Enum:** `AccountingJournalEntryStatus` — `active`, `voided`.

- Created via mapping, manual store, transfer, or N-line split.
- `voidEntry()` sets status `voided` + audit fields; lines retained.
- Reports: `status = active` OR `status IS NULL`.
- Update with new `lines` uses `reverseOrReplace` (void old active, new active entry).
- API `DELETE /journal-entries/{id}` → void, not hard delete.

---

## 12. Mapping system (document, payment method, expense category)

**Document:** Resolved by `(organization_id, branch_id, module, document_type)` via `AccountingMappingResolver`.

**Payment method:** One row per `payment_method_id` per branch. **Module sides** live in `accounting_payment_method_mapping_sides` — each side is a `(module → debit/credit)` pair (`sales`, `purchase`, `expenses`, `payroll`, or any custom module string).

**`AccountingMappingResolver::resolve()` priority** (same as `tryAutoMap` after explicit debit/credit hints):

1. **Expense category map** when `getExpenseCategoryId()` / hint is set.
2. **Payment method map — module side** when `getPaymentMethodId()` / hint is set; side key from `getPaymentSide()` (e.g. sales payment → `sales`, purchase payment → `purchase` or `purchases` normalized to `purchase`).
3. **Payment method map — legacy columns** on `accounting_payment_method_mappings` (`sales_*` / `purchase_*` fields) if no side row matches.
4. **Document map** (`module` + `document_type`) — **always the final fallback**.

When **`getPaymentMethodId()` is `null`**, steps 2–3 are skipped. Sales, purchase, and expense **payments** then resolve like purchases today: e.g. `module=sales`, `document_type=payment` → debit cash, credit AR (from document mapping). No payment method ID is required if document mappings exist.

Override **`getPaymentSide()`** when the payment-method side differs from `getDocumentModule()` (example: document module `purchase`, payment side `purchase` for AP payments).

**API — payment method sides** (permission: view/edit accounting mappings):

| Method | Path | Body |
|--------|------|------|
| GET | `{prefix}/mappings/payment-methods/{id}/sides` | — |
| POST | `{prefix}/mappings/payment-methods/{id}/sides` | `module`, `debit_account_id`, `credit_account_id`, `is_enabled?` |
| PUT | `{prefix}/mappings/payment-methods/{id}/sides/{module}` | same fields |
| DELETE | `{prefix}/mappings/payment-methods/{id}/sides/{module}` | — |

**Example — expenses module side**

```http
POST /api/v1/accounting/mappings/payment-methods/3/sides
{
  "module": "expenses",
  "debit_account_id": 15,
  "credit_account_id": 8,
  "is_enabled": true
}
```

When recording an expense payment with `payment_method_id`, pass `payment_side=expenses` (or rely on document module `expenses`).

Legacy **upsert** `POST /payment-method-mappings/upsert` still accepts `sales_*` and `purchase_*` columns; it mirrors `sales` and `purchase` side rows automatically.

`GET /registry/documents` includes `has_payment_method_mapping_side` per document type (true when the branch has at least one enabled payment-method side for that document’s `module`).

**Expense category:** Used when `expense_category_id` on transaction hints (unchanged).

Bootstrap seeds from `DocumentRegistry` + default PM/expense rows when host seeders exist (creates `sales` and `purchase` sides).

Cache: `AccountingMappingResolver::forgetBranch($branchId)` after upsert/bootstrap.

---

## 13. Tax and discount splitting

- There is no tax or discount splitting. Add mapping lines that read `tax_amount` or `discount_amount`.
- Branch settings FKs to tax payable/receivable and sales/purchase discount accounts
- CoA bootstrap wires defaults from `tax_payable`, `tax_receivable`, `sales_discounts`, `purchase_discounts` key_names when unset
- Bridge `applySalesInvoiceSplit` / `applyPurchaseInvoiceSplit` for built-in invoice transaction types
- Custom types: amounts stored; two-line map unless custom logic

---

## 14. Chart of accounts (key_names list)

From `AccountingCoaSeeder` (stable `key_name` values):

**Structure:** `assets`, `current_assets`, `fixed_assets`, `liabilities`, `current_liabilities`, `equity`, `income_parent`, `cost_of_sales_parent`, `expenses_parent`

**Assets:** `cash_and_cash_equivalents`, `bank`, `undeposited_funds`, `accounts_receivable`, `inventory_asset`, `salary_advances`, `tax_receivable`, `prepaid_expenses`, `furniture_and_equipment`

**Liabilities:** `accounts_payable`, `credit_card`, `tax_payable`, `customer_deposits`, `wages_payable`, `inter_branch_due`

**Equity:** `opening_balance_equity`, `retained_earnings`, `owner_equity`

**Income:** `sales`, `sales_revenue`, `service_revenue`, `parts_revenue`, `hotel_revenue`, `restaurant_revenue`, `event_revenue`, `saas_revenue`, `sales_discounts`, `other_income`, `uncategorised_income`

**COGS:** `cost_of_sales`, `purchase_discounts`, `inventory_adjustments`, `waste_and_spoilage`, `purchase_returns`

**Expenses:** `office_expenses`, `payroll_expenses`, `utilities`, `bad_debt_expense`, `rent_expense`, `marketing_expense`, `maintenance_expense`, `transport_expense`, `bank_charges`, `uncategorised_expense`

---

## 15. Financial reports (all 6 with endpoint and response shape)

Prefix: `{accounting.route_prefix}/reports`

| Endpoint | Service method | Response `data` |
|----------|----------------|-----------------|
| GET `/trial-balance` | `trialBalance` | `{ as_of, rows: [{ account_id, number, name, account_type, debit, credit }], totals: { debit, credit } }` |
| GET `/profit-and-loss` | `profitAndLoss` | `{ date_from, date_to, sections: [{ key, title, lines, total, total_label, is_subtotal? }], net_income, net_income_label }` |
| GET `/balance-sheet` | `balanceSheet` | `{ as_of, fy_start, sections: [assets, liabilities, equity], total_liabilities_and_equity, balanced, difference }` |
| GET `/cash-flow` | `cashFlow` | `{ date_from, date_to, opening_cash, closing_cash, sections: [operating, investing, financing], net_change, implied_closing }` |
| GET `/account-statement` | `accountStatement` | `{ date_from, date_to, account: { id, number, name, account_type }, opening_balance, closing_balance, period_debit, period_credit, rows: [{ line_id, journal_entry_id, journal_number, journal_date, description, reference, debit, credit, balance }] }` |
| GET `/journal-activity-by-month` | `journalActivityByMonth` | `{ date_from, date_to, months: [{ month, label, entry_count, line_count, total_debit, total_credit }], peak_month, peak_month_label, peak_entry_count, matching_record_count }` |

Line objects in P&amp;L/BS/CF use `amount` (signed natural balance). Trial balance uses separate debit/credit columns.

---

## 16. HTTP API reference (every endpoint, method, params, response)

Base: `config('accounting.route_prefix')` (default `api/v1/accounting`).  
Standard JSON: `{ success, message?, data?, errors?, status_code?, pagination? }`.

### Registry
| Method | Path | Middleware | Params | Response |
|--------|------|------------|--------|----------|
| GET | `/registry/documents` | package middleware only | — | `data.modules[]` with registry + `is_mapped`, account ids |

### Bootstrap & settings
| POST | `/bootstrap` | `manage accounting settings` | `force` (bool) | counts: accounts, mappings added |
| GET | `/settings` | `manage accounting settings` | — | `AccountingBranchSetting` |
| PATCH | `/settings` | `manage accounting settings` | fiscal, tax/discount account ids, etc. | updated settings |

### Accounts
| GET | `/accounts` | `view accounting accounts` | search, sort, pagination, `account_type`, `is_active`, `parent_id`, `roots_only`, `non_zero_balance` | paginated accounts + `current_balance` |
| POST | `/accounts` | `create accounting accounts` | body: `name`, `account_type`, optional `number`, `parent_id`, … | 201 created |
| GET | `/accounts/trashed/list` | `view accounting accounts` | pagination | soft-deleted |
| GET | `/accounts/{id}` | `view accounting accounts` | — | single |
| PATCH | `/accounts/{id}` | `edit accounting accounts` | partial rules | updated |
| DELETE | `/accounts/{id}` | `delete accounting accounts` | — | soft delete |
| POST | `/accounts/{id}/restore` | `edit accounting accounts` | — | restored |
| DELETE | `/accounts/{id}/force` | `delete accounting accounts` | — | force delete |

### Journal entries
| GET | `/journal-entries` | `view accounting journal entries` | `include_voided`, `scope=audit`, `source`, dates | paginated |
| POST | `/journal-entries` | `create accounting journal entries` | `journal_date`, `lines[]` (min 2, balanced) | 201 |
| GET | `/journal-entries/trashed/list` | view | — | legacy trashed if any |
| GET | `/journal-entries/{id}` | view | — | with lines |
| PATCH | `/journal-entries/{id}` | `edit accounting journal entries` | header fields and/or `lines` | replaced or updated |
| DELETE | `/journal-entries/{id}` | `delete accounting journal entries` | — | voids entry |
| POST | `/journal-entries/{id}/restore` | edit | — | if supported |

### Transactions
| GET | `/transactions` | `view accounting transactions` | `type`, `status`, `module`, dates, `include_voided` | paginated |
| GET | `/transactions/unmapped` | `view accounting transactions` | pagination | monitoring payload |
| GET | `/health` | `view accounting transactions` | — | status summary |
| POST | `/transactions/bulk-map` | `edit accounting transactions` | `ids[]`, optional mapping hints | `{ mapped, failed[] }` |
| GET | `/transactions/{id}` | view | — | full relations |
| POST | `/transactions/{id}/map` | edit | `debit_account_id`, `credit_account_id` | mapped tx |
| POST | `/transactions/{id}/unmap` | edit | — | unmapped tx |

### Mappings
| GET | `/document-mappings` | `view accounting mappings` | filters | list |
| POST | `/document-mappings/upsert` | `edit accounting mappings` | `mappings[]` with module enum, document_type, account ids | saved rows |
| GET/POST | `/payment-method-mappings`, `/expense-category-mappings` | view / edit | upsert arrays | saved rows |

### Transfers
| GET | `/transfers` | `view accounting transfers` | pagination | list |
| POST | `/transfers` | `create accounting transfers` | `from_account_id`, `to_account_id`, `amount`, `transfer_date`, `notes?` | 201 + JE |
| GET | `/transfers/{id}` | view | — | detail |
| DELETE | `/transfers/{id}` | `delete accounting transfers` | — | void/delete transfer |

### Reports (see §15)
All GET under `/reports/...` with `view accounting reports`.

---

## 17. Config reference (every key)

```php
'tenancy_mode' => string,
'currency' => string,
'fy_start' => int 1-12,
'route_prefix' => string,
'middleware' => array<string>, // default ['auth', ResolveAccountingContext::class]; use auth:sanctum, auth:api, auth:jwt, or custom as your app requires
'features' => ['tax_splitting' => bool, 'discount_splitting' => bool],
'auth' => ['guard' => class-string],
'models' => ['user', 'organization', 'branch', 'payment_method', 'expense_category'] => class-string,
'tables' => ['organizations', 'branches', 'users', 'payment_methods', 'expense_categories'] => string,
```

---

## 18. Troubleshooting

- **Bootstrap Unauthenticated (CLI):** Use package **1.0.2+** and `userOrNull()`; pass `--user-id=` optionally. Do not call `user()` from Artisan without a session.
- **MySQL `acct_jel_rec_idx` / FK drop:** Upgrade **1.0.2+** — foreign key `acct_jel_rec_fk` is dropped before indexes.
- **MySQL `permissions` table missing:** Upgrade **1.0.2+** — reconcile permission cleanup is skipped when Spatie is not installed.
- **MySQL FK name too long on payment method sides:** Upgrade **1.0.2+** — uses `acct_pm_side_map_fk`.
- **Wrong COGS `key_name` in `getJournalLines()`:** Seeded account is **`cost_of_sales`** (not `cost_of_goods_sold`). See `AccountingCoaSeeder`.
- **No mappings after bootstrap:** `DocumentRegistry` empty at bootstrap time → register then `--force` bootstrap.
- **CoA key missing:** `default_debit`/`default_credit` must match seeded `key_name`.
- **Context 422:** Branch/org not resolved — headers, user fields, or CLI flags.
- **Permission 403:** Host RBAC missing accounting permission names.
- **Silent no-op on save:** Bridge logs warnings; check Laravel log channel.
- **Strict delete blocked:** Void path threw — inspect linked JE or closed period rules in host app.
- **Report imbalance on balance sheet:** `balanced` flag uses 0.02 tolerance; check voided vs active JEs.
- **Invoice tax not split:** Transaction type must be built-in invoice family or manual N-line map.
- **Stale mappings after upsert:** Resolver cache — bootstrap/upsert calls `forgetBranch`.

---

## 19. Accounting period controls

Branch settings (`accounting_branch_settings`) include:

| Column | Purpose |
|--------|---------|
| `close_the_books` | When `true`, period guard is active for the branch. |
| `closing_date` | Last calendar day of the closed period. Dates **on or before** this day cannot be posted while closed. |

`Abdiaziizaweis\Accounting\Services\AccountingPeriodGuard` loads settings for the current `AccountingContextInterface` branch (or organization when no branch). If no settings row exists, books are treated as **open**.

**Blocked when closed** (`assertOpen` throws `AccountingPeriodClosedException`):

- `AccountingTransactionService::recordTransaction()` — document `transaction_date`
- `AccountingTransactionService::voidTransaction()` — **today** (void is a current-period action)
- `AccountingJournalService::createFromMapping()` / `createManualEntry()` — `journal_date`
- `AccountingJournalService::voidEntry()` — **today**
- `AccountingTransferService::create()` — `transfer_date`

**API**

| Method | Path | Body | Permission |
|--------|------|------|------------|
| PATCH | `{prefix}/settings/close-period` | `{ "closing_date": "YYYY-MM-DD" }` | `manage accounting settings` |
| PATCH | `{prefix}/settings/reopen-period` | (none) | `manage accounting settings` |

`close-period` sets `close_the_books = true` and `closing_date`. `reopen-period` sets `close_the_books = false` (keeps `closing_date` for audit).

To post again into a formerly closed range, call `reopen-period` or PATCH `/settings` with `close_the_books: false`.

---

## 20. Audit logging

Host systems can attach their own audit trail without forking the package. All domain services call a single hook:

`Abdiaziizaweis\Accounting\Contracts\AccountingAuditInterface::log($event, $entityType, $entityId, $context, $userId)`

### Configuration

`config/accounting.php`:

```php
'audit' => [
    'driver' => \Abdiaziizaweis\Accounting\Audit\NullAccountingAudit::class,
],
```

`AccountingServiceProvider` binds `AccountingAuditInterface` to `config('accounting.audit.driver')`.

| Driver | Behavior |
|--------|----------|
| `NullAccountingAudit` | Default — no I/O; package works with zero audit setup. |
| `SpatieAccountingAudit` | Uses Spatie `activity()` when the helper exists; otherwise no-op. |
| Custom class | Any implementation of `AccountingAuditInterface` (see below). |

### Built-in drivers

**`NullAccountingAudit`** — empty `log()` method.

**`SpatieAccountingAudit`** — writes an activity with:

- log name / event = `$event`
- properties = `$context` plus `entity_type`, `entity_id`
- `causedBy` when `$userId` or authenticated user is known

### Events emitted by the package

| Event | Entity type | When | Typical context |
|-------|-------------|------|-----------------|
| `transaction.created` | `AccountingTransaction` | `recordTransaction()` creates a new row | `amount`, `journal_entry_id`, `status`, `type` |
| `transaction.superseded` | `AccountingTransaction` | Economic edit voids prior tx and creates successor | above + `superseded_transaction_id` |
| `transaction.voided` | `AccountingTransaction` | `voidTransaction()` | `amount`, `journal_entry_id`, `void_reason`, `status`, `type` |
| `transaction.mapped` | `AccountingTransaction` | `mapWithAccounts()` | `amount`, `journal_entry_id`, `debit_account_id`, `credit_account_id`, … |
| `transaction.unmapped` | `AccountingTransaction` | `unmap()` | `amount`, `journal_entry_id`, … |
| `journal.created` | `AccountingJournalEntry` | `createFromMapping()` (includes manual entries and transfer JEs) | `journal_entry_id`, `amount`, `source` |
| `journal.voided` | `AccountingJournalEntry` | `voidEntry()` | `journal_entry_id`, `void_reason`, `amount` |
| `journal.replaced` | `AccountingJournalEntry` | `reverseOrReplace()` when a prior JE existed | `journal_entry_id`, `prior_journal_entry_id`, `amount` |
| `transfer.created` | `AccountingTransfer` | `AccountingTransferService::create()` | `amount`, `from_account_id`, `to_account_id`, `journal_entry_id` |
| `transfer.deleted` | `AccountingTransfer` | `AccountingTransferService::delete()` | same shape as create |

On economic supersede you will see **`transaction.created`** and **`transaction.superseded`** on the new transaction, and **`transaction.voided`** on the superseded transaction. Remapping a transaction may also emit **`journal.voided`**, **`journal.created`**, and **`journal.replaced`**.

### Custom host audit table

```php
// app/Accounting/CustomAuditLogger.php
use Abdiaziizaweis\Accounting\Contracts\AccountingAuditInterface;
use Illuminate\Support\Facades\DB;

class CustomAuditLogger implements AccountingAuditInterface
{
    public function log(
        string $event,
        string $entityType,
        int $entityId,
        array $context = [],
        ?int $userId = null,
    ): void {
        DB::table('main_audit_log')->insert([
            'event' => $event,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'context' => json_encode($context),
            'user_id' => $userId,
            'created_at' => now(),
        ]);
    }
}
```

Register in `AppServiceProvider::register()`:

```php
$this->app->bind(
    \Abdiaziizaweis\Accounting\Contracts\AccountingAuditInterface::class,
    \App\Accounting\CustomAuditLogger::class,
);
```

Binding in the host overrides the config driver for that application.

---

## 21. Custom and overridden reports

Hosts can add reports to the accounting API and replace built-in statement logic without forking the package.

### `AccountingReportInterface`

| Method | Purpose |
|--------|---------|
| `label(): string` | Human-readable title for UIs and discovery. |
| `rules(): array` | Laravel validation rules for query/body filters. |
| `handle(array $filters): array` | Run the report; return a JSON-serializable array. |

### `AccountingReportRegistry`

| Method | Purpose |
|--------|---------|
| `register($name, $label, $handler)` | Add a **custom** report (`$name` = slug: letters, digits, hyphens). |
| `override($name, $handler)` | Replace a **built-in** handler by slug. |
| `allCustom()` | List registered custom reports (`name`, `label`, `handler`). |
| `getOverride($builtInName)` / `hasOverride($builtInName)` | Inspect overrides. |
| `clear()` | Reset registry (tests only). |

Built-in override names: `trial-balance`, `profit-and-loss`, `balance-sheet`, `cash-flow`, `account-statement`, `journal-activity-by-month`.

`AccountingReportService` checks `hasOverride()` at the start of each built-in method. When set, it resolves the handler from the container and returns `handle($filters)` with normalized filter keys (`as_of`, `date_from`, `date_to`, `account_id`, `fy_start`, `limit`, etc.).

### Register a custom report

In `AppServiceProvider::boot()`:

```php
use Abdiaziizaweis\Accounting\Registry\AccountingReportRegistry;
use App\Reports\SalesByCategoryReport;

AccountingReportRegistry::register(
    name: 'sales-by-category',
    label: 'Sales by Category',
    handler: SalesByCategoryReport::class,
);
```

| Endpoint | Purpose |
|----------|---------|
| `GET {prefix}/reports/custom` | Discovery — all custom reports (`name`, `label`, `handler`). |
| `GET {prefix}/reports/custom/{name}` | Run report; validates query params with `rules()`. |

Example: `GET /api/v1/accounting/reports/custom/sales-by-category?date_from=2026-01-01&date_to=2026-01-31`

Permission: `view accounting reports` (same as built-in reports).

See `docs/examples/SalesByCategoryReport.php` for a full handler example (income accounts by CoA row over a date range).

### Override a built-in report

```php
AccountingReportRegistry::override(
    name: 'profit-and-loss',
    handler: CustomProfitAndLossReport::class,
);
```

`GET {prefix}/reports/profit-and-loss` continues to use the same URL and controller; `AccountingReportService::profitAndLoss()` delegates to your handler. No route changes required.

### Implementing `AccountingReportInterface`

```php
namespace App\Reports;

use Abdiaziizaweis\Accounting\Contracts\AccountingReportInterface;

class CustomProfitAndLossReport implements AccountingReportInterface
{
    public function label(): string
    {
        return 'Profit & Loss (custom)';
    }

    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ];
    }

    public function handle(array $filters): array
    {
        // Query ledger, shape sections, return array payload.
        return [
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'sections' => [],
            'net_income' => 0.0,
        ];
    }
}
```

`handle()` must return an `array`. Custom report controllers wrap the result in the standard `{ success, message, data }` envelope.

---

## 23. Multi-line journal entries

### Two posting modes

**Mode 1 — Automatic mapping (default)**  
`getJournalLines()` returns `null`. Debit/credit come from the mapping table (and payment method / expense category when applicable). Tax/discount splitting applies when configured. Best for simple documents and admin-configured mappings.

**Mode 2 — Custom lines (full control)**  
`getJournalLines()` returns at least two `JournalLine` instances. The package validates balance, resolves `accountKey` (`key_name`) via `JournalLineResolver`, and posts via `AccountingTransactionService::mapWithLines()`. Tax/discount splitting and mapping lookup are **not** used — the document owns the full entry.

### `JournalLine` helpers

```php
use Abdiaziizaweis\Accounting\ValueObjects\JournalLine;

JournalLine::debit('accounts_receivable', 100.00, 'Invoice total');
JournalLine::credit('sales_revenue', 80.00);
JournalLine::debitById(42, 50.00);
JournalLine::creditById(43, 50.00);
```

### Examples

**Sale with COGS and tax (5 lines):**

```php
public function getJournalLines(): ?array
{
    return [
        JournalLine::debit('accounts_receivable', $this->total_with_tax),
        JournalLine::credit('sales_revenue', $this->subtotal),
        JournalLine::credit('tax_payable', $this->tax_amount),
        JournalLine::debit('cost_of_sales', $this->cost),
        JournalLine::credit('inventory_asset', $this->cost),
    ];
}
```

**Payroll with deductions (6 lines):**

```php
public function getJournalLines(): ?array
{
    return [
        JournalLine::debit('payroll_expenses', $this->gross),
        JournalLine::credit('cash_and_cash_equivalents', $this->net),
        JournalLine::credit('tax_payable', $this->tax),
        JournalLine::credit('pension_payable', $this->pension),
        JournalLine::credit('health_insurance_payable', $this->health),
        JournalLine::credit('union_dues_payable', $this->union),
    ];
}
```

**Simple mapping mode:**

```php
public function getJournalLines(): ?array
{
    return null;
}
```

### Balance validation

`UnbalancedJournalLinesException` is thrown when debits ≠ credits (tolerance 0.005) after resolution. Message includes document type, totals, and difference. Fix amounts in `getJournalLines()` or add missing offsetting lines.

### Common `key_name` values

`accounts_receivable`, `accounts_payable`, `cash_and_cash_equivalents`, `bank`, `sales_revenue`, `service_revenue`, `parts_revenue`, `inventory_asset`, `cost_of_sales` (COGS), `purchase_discounts`, `tax_payable`, `tax_receivable`, `payroll_expenses`, `salary_advances`, `customer_deposits`, `inter_branch_due`.

Alias note: use **`cost_of_sales`** — that is the canonical `key_name` from bootstrap. `cost_of_goods_sold` is not seeded.

### Registry flag

`DocumentRegistry::register(..., usesCustomLines: true)` sets `posting_mode: custom_lines` on `GET /registry/documents` so mapping UIs can show **Managed in code** (no debit/credit dropdowns).

### Manual API

`POST {prefix}/journal-entries` already accepts `lines` with `min:2` and balance validation via `AccountingJournalService::assertBalanced()`.

---

## 22. Production monitoring

### Health check

`GET {prefix}/health` (permission: `view accounting transactions`)

Call from a monitoring dashboard or uptime service. Response `data` includes:

- `status`: `ok` | `warning` | `critical`
- `unmapped_transactions`, `oldest_unmapped_days`
- `last_journal_entry_date`, `books_closed`, `closing_date`
- `total_accounts`, `disabled_mappings`

Rules: `critical` if ≥10 unmapped or oldest unmapped &gt; 7 days; `warning` if any unmapped or disabled mappings; else `ok`.

### Unmapped transactions

`GET {prefix}/transactions/unmapped` — convenience list of `not_mapped` transactions with morph trace, `created_at`, and humanized `unmapped_for`.

Common causes: missing/disabled document mapping, bootstrap not run, period closed (check logs), bridge exception.

### Log monitoring

Watch for `AccountingDocumentBridge failed` in `storage/logs` — silent posting failure.

### Recommended monitoring setup

- Poll `GET {prefix}/health` every 5 minutes; alert when `status` ≠ `ok`.
- Review `/transactions/unmapped` daily during the first week after go-live.
- Weekly notification if `unmapped_transactions` &gt; 0.

---

*Package version context: Laravel 11–13, PHP 8.2+, void-based tx/JE lifecycle, reconcile feature removed in migration `2026_09_09_110000_drop_reconcile`.*