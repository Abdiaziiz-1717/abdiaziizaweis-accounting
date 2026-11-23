---

## For AI Agents and Coding Assistants

If you are an AI agent integrating this package, read these files 
in this exact order before writing any code:

1. This README (you are here) — installation and quick start
2. Full knowledge base: https://raw.githubusercontent.com/Abdiaziiz-1717/abdiaziizaweis-accounting/main/docs/KNOWLEDGE.md
3. Complete integration example: https://raw.githubusercontent.com/Abdiaziiz-1717/abdiaziizaweis-accounting/main/docs/EXAMPLE_INTEGRATION.md

The KNOWLEDGE.md file contains:
- Full database schema (every table and column)
- Complete HTTP API reference (every endpoint)
- Transaction and journal entry lifecycle
- Mapping system explained
- Chart of accounts key_names list
- Tax and discount splitting rules
- All 6 financial reports with response shapes
- Troubleshooting guide

The EXAMPLE_INTEGRATION.md file contains:
- Complete working example (car sales system)
- Every interface method implemented
- Sale, payment, and deletion flows
- How to build a mapping UI

Do not guess how this package works — read KNOWLEDGE.md first.
Everything you need is documented there.

---

# abdiaziizaweis/accounting

Reusable **double-entry accounting** for Laravel apps: chart of accounts (CoA), operational **transactions** bridged from your invoices/payments, automatic **journal entries** via mappings, inter-account **transfers**, and six **financial reports** over a Sanctum-protected JSON API.

**Namespace:** `Abdiaziizaweis\Accounting`  
**Composer:** `abdiaziizaweis/accounting`

---

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | **8.2+** (`^8.2`) |
| Laravel | **11.x** (`^11.0`) |
| Auth (typical) | Laravel Sanctum (`auth:sanctum` on API routes) |

Host apps need `users`, and—depending on tenancy—`organizations`, `branches`, `payment_methods`, and `expense_categories` tables (names configurable).

---

## What this package does

1. **Chart of accounts** — Hierarchical accounts per org/branch with stable `key_name` identifiers (seeded by bootstrap).
2. **Document bridge** — Host models implement `AccountingDocumentInterface` (or call `AccountingDocumentBridge` manually) to create `accounting_transactions` linked via morph.
3. **Mappings** — Debit/credit account pairs for document types, payment methods, and expense categories.
4. **Journal entries** — Balanced headers + lines; created from mapping, manual API, or transfers. **Void-based** lifecycle (no hard-delete of posted history).
5. **Reports** — Trial balance, P&amp;L, balance sheet, cash flow, account statement, journal activity by month.
6. **HTTP API** — Prefixed by `config('accounting.route_prefix')` (default `api/v1/accounting`).

---

## Installation

### 1. Composer

```bash
composer require abdiaziizaweis/accounting
```

Laravel auto-discovers `Abdiaziizaweis\Accounting\AccountingServiceProvider`.

### 2. Interactive installer (recommended)

```bash
php artisan accounting:install
```

This writes `config/accounting.php`, runs migrations, and runs `accounting:bootstrap` when org/branch IDs are provided.

### 3. Manual install

```bash
php artisan vendor:publish --tag=accounting-config
php artisan migrate
php artisan accounting:bootstrap --organization-id=1 --branch-id=1
```

### 4. Permissions

Routes use middleware alias `permission:…`. If your app has no `permission` middleware yet, the package registers `AccountingPermissionMiddleware` for names such as:

- `view accounting accounts`, `create accounting accounts`, `edit accounting accounts`, `delete accounting accounts`
- `view accounting journal entries`, `create accounting journal entries`, `edit accounting journal entries`, `delete accounting journal entries`
- `view accounting transactions`, `edit accounting transactions`
- `view accounting mappings`, `edit accounting mappings`
- `view accounting transfers`, `create accounting transfers`, `delete accounting transfers`
- `view accounting reports`
- `manage accounting settings`

Wire these into your RBAC (e.g. Spatie Permission) before calling protected endpoints.

---

## Tenancy modes

Set `accounting.tenancy_mode` in config. This controls DB columns, context resolution, and bootstrap requirements.

| Mode | Resolver | Org column | Branch column | Context |
|------|----------|------------|---------------|---------|
| `single_tenant` | `SingleTenantContextResolver` | No | No | Minimal |
| `multi_tenant` | `MultiTenantContextResolver` | Yes | No | User’s `organization_id` |
| `multi_branch` | `MultiBranchContextResolver` | No* | Yes | Header e.g. `X-Branch-Id` |
| `multi_tenant_multi_branch` | `MultiTenantMultiBranchContextResolver` | Yes | Yes | User org + branch header |

\*In `multi_branch`, organization may still be stored as `1` internally where needed.

`ResolveAccountingContext` middleware (include in `accounting.middleware`) binds `AccountingContextInterface` per request.

---

## Registering document types (`DocumentRegistry`)

Call from `AppServiceProvider::boot()` **before** `accounting:bootstrap` so default mappings are seeded.

```php
use Abdiaziizaweis\Accounting\Registry\DocumentRegistry;

DocumentRegistry::register(
    string $module,           // e.g. 'sales'
    string $documentType,       // e.g. 'invoice' — unique per module
    string $label,              // UI label
    string $defaultDebit,       // CoA key_name for debit side
    string $defaultCredit,      // CoA key_name for credit side
    bool $hasTax = false,       // UI hint; tax splitting needs branch settings
    bool $hasDiscount = false,  // UI hint; discount splitting needs branch settings
): void
```

Example:

```php
DocumentRegistry::register(
    'sales',
    'invoice',
    'Sales Invoice',
    'accounts_receivable',
    'sales',
    true,
    true,
);
```

After registering, run:

```bash
php artisan accounting:bootstrap --organization-id=1 --branch-id=1
# Re-apply mapping defaults if rows already exist:
php artisan accounting:bootstrap --force --organization-id=1 --branch-id=1
```

Discover registered types (with mapping status) via **GET** `{prefix}/registry/documents` (no extra permission on this route).

---

## `AccountingDocumentInterface`

Implement on your Eloquent model (often with `HasAccountingDocument`).

| Method | Purpose | Example |
|--------|---------|---------|
| `getDocumentId(): int` | Host PK for morph link | `return $this->id;` |
| `getDocumentType(): string` | Stable tx type (mapping / void matching) | `return 'sales_invoice';` |
| `getDocumentModule(): string` | Module for mapping lookup | `return 'sales';` |
| `getAmount(): float` | Economic amount (≤ 0 → void, no new tx) | `return (float) $this->total;` |
| `getTaxAmount(): float` | Tax snapshot / split input | `return (float) $this->tax_total;` |
| `getDiscountAmount(): float` | Discount snapshot / split input | `return (float) $this->discount_total;` |
| `getDate(): Carbon` | `transaction_date` / JE date | `return $this->invoice_date;` |
| `getCurrency(): string` | ISO code | `return 'USD';` |
| `getMorphClass(): string` | Morph type | `return static::class;` |
| `getReference(): ?string` | Short ref on tx/JE | `return $this->number;` |
| `getDescription(): ?string` | Longer text | `return "Invoice {$this->number}";` |

**Optional helpers** (not on interface; bridge reads via `method_exists`):

- `getPaymentMethodId(): ?int` — payment-method mapping on payments
- `getExpenseCategoryId(): ?int` — expense-category mapping
- `getOwnerUserId(): ?int` — override `owner_user_id` on transactions

Full model sketch:

```php
use Abdiaziizaweis\Accounting\Contracts\AccountingDocumentInterface;
use Abdiaziizaweis\Accounting\Concerns\HasAccountingDocument;
use Illuminate\Support\Carbon;

class SalesInvoice extends Model implements AccountingDocumentInterface
{
    use HasAccountingDocument;

    public function getDocumentId(): int { return $this->id; }
    public function getDocumentType(): string { return 'sales_invoice'; }
    public function getDocumentModule(): string { return 'sales'; }
    public function getAmount(): float { return (float) $this->grand_total; }
    public function getTaxAmount(): float { return (float) $this->tax; }
    public function getDiscountAmount(): float { return (float) $this->discount; }
    public function getDate(): Carbon { return $this->invoiced_at; }
    public function getCurrency(): string { return 'USD'; }
    public function getMorphClass(): string { return static::class; }
    public function getReference(): ?string { return $this->invoice_no; }
    public function getDescription(): ?string { return "Sales invoice {$this->invoice_no}"; }
}
```

Built-in transaction types (e.g. `hotel_invoice`, `purchase_invoice`) get **automatic** tax/discount N-line splits when branch settings assign tax/discount accounts. Custom types still store `tax_amount` / `discount_amount` but default to **two-line** mapping unless you extend the bridge or map manually.

---

## `HasAccountingDocument` trait

```php
use Abdiaziizaweis\Accounting\Concerns\HasAccountingDocument;
```

**Requirements:** Class must implement `AccountingDocumentInterface`.  
**Context:** `AccountingContextInterface` must resolve org/branch when `record()` runs (HTTP middleware or CLI options).

**Behavior:**

- **`saved`** → `AccountingDocumentBridge::record($model)` (create/update tx; economic edits void old tx and create new).
- **`deleting`** → `voidFor($model, strict: true)` — void failures **block** deletion.

Errors during `record()` are **logged**, not thrown (host UX preserved). Deletes use **strict** void.

---

## Manual bridge

Inject or resolve `Abdiaziizaweis\Accounting\Services\AccountingDocumentBridge`:

```php
$bridge = app(AccountingDocumentBridge::class);

// After saving a document (no trait):
$bridge->record($invoice);

// Void without deleting host row:
$bridge->voidFor($invoice, strict: false, reason: 'Cancelled');

// Strict void (propagates exceptions):
$bridge->voidFor($invoice, true, 'Manual void');
```

Use manual calls when you are not using Eloquent events, batch imports, or non-Eloquent sources.

---

## AppServiceProvider bindings (optional override)

The package binds defaults in `AccountingServiceProvider::register()`. Override in **your** `AppServiceProvider::register()` if needed:

```php
use Abdiaziizaweis\Accounting\Contracts\AccountingContextInterface;
use Abdiaziizaweis\Accounting\Contracts\AccountingAuthInterface;
use Abdiaziizaweis\Accounting\Context\MultiTenantMultiBranchContextResolver;
use Abdiaziizaweis\Accounting\Auth\DefaultAccountingAuth;

public function register(): void
{
    $this->app->bind(AccountingContextInterface::class, MultiTenantMultiBranchContextResolver::class);
    $this->app->bind(AccountingAuthInterface::class, DefaultAccountingAuth::class);
}
```

Implement `AccountingContextInterface` or `AccountingAuthInterface` for custom tenancy/auth. Singletons (`AccountingDocumentBridge`, `AccountingTransactionService`, etc.) are registered by the package—rebind only if you subclass services.

---

## `accounting:bootstrap`

```bash
php artisan accounting:bootstrap
    {--force : Re-apply default document mappings when they already exist}
    {--organization-id= : Organization ID (CLI when HTTP context is not resolved)}
    {--branch-id= : Branch ID (CLI when HTTP context is not resolved)}
```

**Does:**

1. Seeds CoA via `AccountingCoaSeeder::seedDefaultsForBranch` (idempotent `key_name` accounts).
2. Seeds document mappings from `DocumentRegistry::all()`.
3. Seeds payment-method and expense-category mappings when host models expose `seedDefaultsForOrganization`.
4. Clears mapping cache for the branch when branch-scoped.

**Requires:** Valid org ID when `usesOrganizationId()`; valid branch ID when `usesBranchId()`.

HTTP equivalent: **POST** `{prefix}/bootstrap` with optional `force=1` (permission: `manage accounting settings`).

---

## Configuration reference

| Key | Default | Description |
|-----|---------|-------------|
| `tenancy_mode` | `multi_tenant_multi_branch` | `single_tenant` \| `multi_tenant` \| `multi_branch` \| `multi_tenant_multi_branch` |
| `currency` | `USD` | Display/default currency code |
| `fy_start` | `1` | First month of fiscal year (1–12) |
| `route_prefix` | `api/v1/accounting` | API URL prefix |
| `middleware` | `auth:sanctum`, `ResolveAccountingContext` | Route middleware stack |
| `features.tax_splitting` | `true` | Bootstrap wires tax accounts into branch settings when CoA seeds |
| `features.discount_splitting` | `true` | Bootstrap wires discount accounts into branch settings |
| `auth.guard` | `DefaultAccountingAuth::class` | `AccountingAuthInterface` implementation |
| `models.user` | `App\Models\User` | User model FQCN |
| `models.organization` | `App\Models\Organization` | Organization model |
| `models.branch` | `App\Models\Branch` | Branch model |
| `models.payment_method` | `App\Models\PaymentMethod` | Payment method model |
| `models.expense_category` | `App\Models\ExpenseCategory` | Expense category model |
| `tables.organizations` | `organizations` | Organization table name |
| `tables.branches` | `branches` | Branch table name |
| `tables.users` | `users` | Users table name |
| `tables.payment_methods` | `payment_methods` | Payment methods table |
| `tables.expense_categories` | `expense_categories` | Expense categories table |

---

## Financial reports

Base path: `{route_prefix}/reports`  
Permission: `view accounting reports`  
Response envelope: `{ "success": true, "message": "...", "data": { ... } }`

| Report | Method | Path | Query params | `data` shape (high level) |
|--------|--------|------|--------------|---------------------------|
| Trial balance | GET | `/trial-balance` | `as_of` or `date_to` (optional) | `as_of`, `rows[]` (`account_id`, `number`, `name`, `account_type`, `debit`, `credit`), `totals.debit`, `totals.credit` |
| Profit &amp; loss | GET | `/profit-and-loss` | `date_from`, `date_to` | `date_from`, `date_to`, `sections[]` (income, cogs, gross_profit, expenses), `net_income`, `net_income_label` |
| Balance sheet | GET | `/balance-sheet` | `as_of` or `date_to`, optional `fy_start` | `as_of`, `fy_start`, `sections[]` (assets, liabilities, equity), `total_liabilities_and_equity`, `balanced`, `difference` |
| Cash flow | GET | `/cash-flow` | `date_from`, `date_to` | `opening_cash`, `closing_cash`, `sections[]` (operating, investing, financing), `net_change`, `implied_closing` |
| Account statement | GET | `/account-statement` | **required** `account_id`; `date_from`, `date_to` | `account`, `opening_balance`, `closing_balance`, `period_debit`, `period_credit`, `rows[]` |
| Journal activity by month | GET | `/journal-activity-by-month` | `date_from`, `date_to`, `limit` (1–60, default 24) | `months[]`, `peak_month`, `peak_entry_count`, `matching_record_count`, etc. |

Default period: `date_to` = today; `date_from` = start of current month (swapped if inverted).

Only journal entries with `status = active` (or `null` legacy) affect reports.

---

## Mappings

Three mapping tables (branch/org scoped):

1. **Document** — `(module, document_type)` → `debit_account_id`, `credit_account_id`
2. **Payment method** — `payment_method_id` → sales (+ optional purchase) debit/credit pairs
3. **Expense category** — `expense_category_id` → debit/credit

API:

- GET/POST upsert: `{prefix}/document-mappings`, `payment-method-mappings`, `expense-category-mappings`
- UI helper: GET `{prefix}/registry/documents`

`AccountingTransactionService::tryAutoMap()` resolves mappings using transaction type hints, payment method, or expense category.

---

## Transaction lifecycle

Statuses (`AccountingTransactionStatus`):

| Status | Meaning |
|--------|---------|
| `not_mapped` | Active; no active journal entry (or unmapped) |
| `mapped` | Active; linked to an active journal entry |
| `voided` | Permanent; never reactivated |

**Create:** Bridge or API → row with `not_mapped` → optional auto-map → `mapped` + `journal_entry_id`.

**Economic edit** (amount, date, tax, discount): void prior active tx (+ JE), create new tx with `superseded_by` link on the old row.

**Non-economic edit** (description, reference): update in place.

**Unmap:** Void JE, clear `journal_entry_id`, status → `not_mapped`.

**Void:** Status → `voided`, void linked JE, set `voided_at`, `void_reason`, optional `superseded_by_transaction_id`.

List endpoints hide voided rows unless `include_voided=1` or `scope=audit`.

---

## Journal entry lifecycle

Statuses (`AccountingJournalEntryStatus`):

| Status | Meaning |
|--------|---------|
| `active` | Counts toward balances and reports |
| `voided` | Audit-only; excluded from aggregates |

**Sources:** `mapping`, `manual`, `transfer`, `reconcile_adjustment` (legacy value).

**Create:** Manual API or mapping/transfer services; lines must balance.

**Update with lines:** `reverseOrReplace` voids old entry and creates a replacement (same business rule as remap).

**Delete API:** Calls `voidEntry` — does not hard-delete.

---

## Tax and discount splitting

1. **Config:** `features.tax_splitting` / `features.discount_splitting` — when true, bootstrap links CoA `tax_payable`, `tax_receivable`, `sales_discounts`, `purchase_discounts` on `accounting_branch_settings`.
2. **Settings API:** PATCH `{prefix}/settings` can set `tax_payable_account_id`, `tax_receivable_account_id`, `sales_discount_account_id`, `purchase_discount_account_id`.
3. **Snapshots:** `tax_amount` and `discount_amount` on `accounting_transactions`.
4. **N-line JEs:** Built-in invoice types (`hotel_invoice`, `restaurant_invoice`, `event_invoice`, `purchase_invoice`) split AR/sales or inventory/AP when settings accounts exist. Otherwise plain two-line map.

Sales split (simplified): DR AR (total); CR sales (net); CR tax payable; DR sales discounts.

Purchase split: DR inventory (net); DR tax receivable; CR purchase discounts; CR AP (total).

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Transactions stay `not_mapped` | No mapping row or disabled mapping | Register `DocumentRegistry` + bootstrap; upsert mappings; check `is_enabled` |
| Bootstrap “Organization/Branch required” | CLI without context | Pass `--organization-id` / `--branch-id` |
| 401/422 on API | Missing Sanctum or org/branch | Auth user; set branch header; check `tenancy_mode` |
| 403 on endpoints | Missing permission | Assign Spatie (or custom) permissions listed above |
| Delete host model fails | Strict void error | Resolve linked JE issues; void tx manually first |
| Record silently does nothing | Bridge swallows errors | Check `storage/logs` for `AccountingDocumentBridge failed` |
| Reports don’t match | Voided JEs or wrong dates | Use `include_voided` for audit; confirm `journal_date` range |
| Tax split not applied | Custom `getDocumentType()` | Use built-in invoice types or custom `mapWithLines` |
| `Missing CoA account key_name` on bootstrap | Wrong `default_debit`/`default_credit` in registry | Use valid `key_name` from seeded CoA (see `AccountingCoaSeeder`) |

---

## Documentation

| Document | Description |
|---|---|
| [README](https://github.com/Abdiaziiz-1717/abdiaziizaweis-accounting/blob/main/README.md) | Installation and quick start |
| [KNOWLEDGE.md](https://github.com/Abdiaziiz-1717/abdiaziizaweis-accounting/blob/main/docs/KNOWLEDGE.md) | Complete technical reference for developers and AI agents |
| [EXAMPLE_INTEGRATION.md](https://github.com/Abdiaziiz-1717/abdiaziizaweis-accounting/blob/main/docs/EXAMPLE_INTEGRATION.md) | Full working integration example |

## Repository Structure

```
abdiaziizaweis-accounting/
├── config/accounting.php       # Default package config (merged on register)
├── database/migrations/        # Accounting tables (auto-loaded)
├── docs/
│   ├── KNOWLEDGE.md            # Full technical + API reference
│   └── EXAMPLE_INTEGRATION.md  # End-to-end host app example
├── routes/api.php              # HTTP routes (prefix + middleware from config)
├── stubs/accounting.php.stub   # Template written by accounting:install
└── src/
    ├── AccountingServiceProvider.php
    ├── Auth/                   # DefaultAccountingAuth
    ├── Concerns/               # HasAccountingDocument
    ├── Console/                # accounting:install, accounting:bootstrap
    ├── Context/                # Tenancy resolvers
    ├── Contracts/              # Context, auth, document interfaces
    ├── Enums/
    ├── Exceptions/
    ├── Http/                   # Controllers, middleware, traits
    ├── Models/
    ├── Registry/               # DocumentRegistry
    └── Services/                 # Bridge, journal, transaction, reports, …
```

**License:** MIT