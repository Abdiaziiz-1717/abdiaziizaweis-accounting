# abdiaziizaweis/accounting — Complete Reference

## 1. What this package is

Composer package **`abdiaziizaweis/accounting`** (`Abdiaziizaweis\Accounting`) for **Laravel 11** / **PHP 8.2+**. It provides:

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

1. `composer require abdiaziizaweis/accounting`
2. `php artisan accounting:install` **or** publish config + `migrate` + `accounting:bootstrap`
3. Register `DocumentRegistry::register(...)` entries in `AppServiceProvider::boot()`
4. Re-run `php artisan accounting:bootstrap [--force]` after new registry entries
5. Assign API permissions; ensure Sanctum (or adjust `accounting.middleware`)
6. Optional: override `AccountingContextInterface` / `AccountingAuthInterface` in app `AppServiceProvider::register()`

Published tags: `accounting-config`, `accounting-migrations`.

---

## 4. Tenancy modes

| `accounting.tenancy_mode` | `TenancySchemaHelper::usesOrganizationId()` | `usesBranchId()` | Default resolver |
|---------------------------|---------------------------------------------|------------------|------------------|
| `single_tenant` | false | false | `SingleTenantContextResolver` |
| `multi_tenant` | true | false | `MultiTenantContextResolver` |
| `multi_branch` | false | true | `MultiBranchContextResolver` |
| `multi_tenant_multi_branch` | true | true | `MultiTenantMultiBranchContextResolver` |

Branch resolution often uses request header (see `MultiBranchContextResolver`). Multi-tenant reads `organization_id` from authenticated user.

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

---

## 6. Implementing AccountingDocumentInterface

All methods required on the host adapter:

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

Optional: `getPaymentMethodId()`, `getExpenseCategoryId()`, `getOwnerUserId()`.

---

## 7. HasAccountingDocument trait

- `bootHasAccountingDocument()` throws if interface missing.
- `saved` → `AccountingDocumentBridge::record()`
- `deleting` → `voidFor(..., strict: true)`

---

## 8. Manual bridge usage

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
`id`, `organization_id?`, `branch_id?`, `payment_method_id`, `sales_debit_account_id`, `sales_credit_account_id`, `expense_debit_account_id?`, `expense_credit_account_id?`, `is_enabled`, timestamps

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

**Payment method:** Overrides debit/credit for payment transactions when `payment_method_id` hint present; `payment_side` can be `sales` or purchase flow.

**Expense category:** Used when `expense_category_id` on transaction hints.

Bootstrap seeds from `DocumentRegistry` + default PM/expense rows when host seeders exist.

Cache: `AccountingMappingResolver::forgetBranch($branchId)` after upsert/bootstrap.

---

## 13. Tax and discount splitting

- Feature flags: `config('accounting.features.tax_splitting')`, `discount_splitting`
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

**Income:** `sales`, `hotel_revenue`, `restaurant_revenue`, `event_revenue`, `saas_revenue`, `sales_discounts`, `other_income`, `uncategorised_income`

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
'middleware' => array<string>,
'features' => ['tax_splitting' => bool, 'discount_splitting' => bool],
'auth' => ['guard' => class-string],
'models' => ['user', 'organization', 'branch', 'payment_method', 'expense_category'] => class-string,
'tables' => ['organizations', 'branches', 'users', 'payment_methods', 'expense_categories'] => string,
```

---

## 18. Troubleshooting

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

*Package version context: Laravel 11, PHP 8.2+, void-based tx/JE lifecycle, reconcile feature removed in migration `2026_09_09_110000_drop_reconcile`.*