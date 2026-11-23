## For AI Agents and Coding Assistants

If you are an AI agent integrating this package, read these files 
in this exact order before writing any code:

1. This README (you are here) — installation and quick start
2. Full knowledge base: https://raw.githubusercontent.com/Abdiaziiz-1717/abdiaziizaweis-accounting/main/docs/KNOWLEDGE.md
3. Complete integration example: https://raw.githubusercontent.com/Abdiaziiz-1717/abdiaziizaweis-accounting/main/docs/EXAMPLE_INTEGRATION.md
4. UI structure reference: https://raw.githubusercontent.com/Abdiaziiz-1717/abdiaziizaweis-accounting/main/docs/UI_STRUCTURE.md

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


# abdiaziizaweis/accounting

Reusable **double-entry accounting** for Laravel apps: chart of accounts (CoA), operational **transactions** bridged from your invoices/payments, automatic **journal entries** via mappings, inter-account **transfers**, and six **financial reports** over a JSON API protected by **your** auth middleware.

**Namespace:** `Abdiaziizaweis\Accounting`  
**Composer:** `abdiaziizaweis/accounting`


## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | **8.2+** (`^8.2`) |
| Laravel | **11, 12, or 13** (`^11.0`, `^12.0`, or `^13.0`) |
| Authentication | **Your choice** — set `accounting.middleware` (default `auth`) |

Host apps need `users`, and—depending on tenancy—`organizations`, `branches`, `payment_methods`, and `expense_categories` tables (names configurable).


## Authentication

The package does **not** require Sanctum, Passport, or any specific auth package. Routes load with the middleware stack in `config('accounting.middleware')`.

Default:

```php
'middleware' => [
    'auth',
    \Abdiaziizaweis\Accounting\Http\Middleware\ResolveAccountingContext::class,
],
```

Pick the guard/middleware your app already uses:

| Stack | Example `accounting.middleware` entry |
|-------|--------------------------------------|
| Session / web | `auth` or `auth:web` |
| Laravel Sanctum | `auth:sanctum` |
| Laravel Passport | `auth:api` |
| JWT (tymon/jwt-auth, etc.) | `auth:jwt` or your custom middleware class |
| Custom | Any middleware alias registered in the host app |

**Authorization:** Route abilities use the `permission:…` middleware alias. The package registers `AccountingPermissionMiddleware` only if the host has not already defined a `permission` alias. Checks go through `AccountingAuthInterface::can()` (`DefaultAccountingAuth` uses Laravel `Gate` and the authenticated user’s `can()` — compatible with Gates, Spatie Permission when installed, or a custom `AccountingAuthInterface` binding).


## Usage Patterns

### SPA / API-first (React, Vue, Next.js frontend)

Use the built-in HTTP API under `api/v1/accounting` (or your `route_prefix`). Set `accounting.middleware` to `auth:sanctum`, `auth:api`, or your API guard.

### Traditional Blade app

Inject package services directly into your controllers (`AccountingDocumentBridge`, `AccountingReportService`, `AccountingTransactionService`, etc.). Use session auth (`auth`) on your web routes; Sanctum is not required for server-rendered pages.

See [docs/BLADE_INTEGRATION.md](docs/BLADE_INTEGRATION.md) for examples.

### External app / microservice

Deploy the package inside a Laravel app and call the HTTP API from any client language. See [docs/KNOWLEDGE.md](docs/KNOWLEDGE.md) §2 for architecture.


## What this package does

1. **Chart of accounts** — Hierarchical accounts per org/branch with stable `key_name` identifiers (seeded by bootstrap).
2. **Document bridge** — Host models implement `AccountingDocumentInterface` (or call `AccountingDocumentBridge` manually) to create `accounting_transactions` linked via morph.
3. **Mappings** — Debit/credit account pairs for document types, payment methods, and expense categories.
4. **Journal entries** — Balanced headers + lines; created from mapping, manual API, or transfers. **Void-based** lifecycle (no hard-delete of posted history).
5. **Reports** — Trial balance, P&amp;L, balance sheet, cash flow, account statement, journal activity by month.
6. **HTTP API** — Prefixed by `config('accounting.route_prefix')` (default `api/v1/accounting`); includes **`GET /health`** and **`GET /transactions/unmapped`** for production monitoring.
7. **Core modules** — Built-in `sales`, `service`, and `parts` modules (enum + default CoA/mappings) alongside hotel, restaurant, purchase, inventory, etc.
8. **Period controls** — Close books through `closing_date`; posting, voiding, journals, and transfers are blocked for dates on or before the close (see `AccountingPeriodGuard`).
9. **Pluggable audit logging** — Implement `AccountingAuditInterface` (or use `NullAccountingAudit` / `SpatieAccountingAudit`) so host apps can record audit events for transactions, journals, and transfers.
10. **Extensible reports** — Register custom reports or override built-in ones via `AccountingReportRegistry` without editing package source.
11. **Operation mapping lines** — the host UI saves any number of debit/credit lines per operation key. Amounts come from the document payload (`amount`, `cost`, `tax_amount`, …). `getJournalLines()` is an escape hatch.

**Payment methods are optional** on all document types — when `getPaymentMethodId()` is null, auto-map falls back to **document mapping** (`module` + `document_type`).

```php
use Abdiaziizaweis\Accounting\ValueObjects\JournalLine;

public function getJournalLines(): ?array
{
    return [
        JournalLine::debit('accounts_receivable', $this->total),
        JournalLine::credit('sales_revenue', $this->subtotal),
        JournalLine::credit('tax_payable', $this->tax),
    ];
}
```


## Installation

### 1. Composer

```bash
composer require abdiaziizaweis/accounting:^2.0
```

**2.0** does not seed mappings and does not auto-split tax or discount. Configure operation lines in your UI. 1.x tags keep the old two-account maps.

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
php artisan accounting:bootstrap
```

For `multi_tenant` / `multi_branch` modes, pass `--organization-id` and/or `--branch-id` as required.

### 4. Permissions

Routes use middleware alias `permission:…`. If your app has no `permission` middleware yet, the package registers `AccountingPermissionMiddleware` for names such as:

- `view accounting accounts`, `create accounting accounts`, `edit accounting accounts`, `delete accounting accounts`
- `view accounting journal entries`, `create accounting journal entries`, `edit accounting journal entries`, `delete accounting journal entries`
- `view accounting transactions`, `edit accounting transactions`
- `view accounting mappings`, `edit accounting mappings`
- `view accounting transfers`, `create accounting transfers`, `delete accounting transfers`
- `view accounting reports`
- `manage accounting settings`

Wire these into your RBAC (Gates, policies, Spatie Permission if installed, or a custom `AccountingAuthInterface`) before calling protected endpoints.


## Tenancy modes

Set `accounting.tenancy_mode` in config (**default `single_tenant`**). This controls DB columns, context resolution, and bootstrap requirements. Set the mode **before** your first `migrate` so the schema matches.

**Which mode should I choose?**

| Your app | Mode |
|----------|------|
| Single store / single company | `single_tenant` — no `organizations` or `branches` tables |
| One company, multiple locations | `multi_branch` |
| SaaS, many companies, one location each | `multi_tenant` |
| SaaS, many companies, multiple branches | `multi_tenant_multi_branch` |

| Mode | Resolver | Org column | Branch column | Context |
|------|----------|------------|---------------|---------|
| `single_tenant` | `SingleTenantContextResolver` | No | No | Fixed context, no DB |
| `multi_tenant` | `MultiTenantContextResolver` | Yes | No | User’s `organization_id` |
| `multi_branch` | `MultiBranchContextResolver` | No* | Yes | Header e.g. `X-Branch-Id` |
| `multi_tenant_multi_branch` | `MultiTenantMultiBranchContextResolver` | Yes | Yes | User org + branch header |

\*In `multi_branch`, organization may still be stored as `1` internally where needed.

`ResolveAccountingContext` middleware (include in `accounting.middleware`) binds `AccountingContextInterface` per request.


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


## `accounting:bootstrap`

```bash
php artisan accounting:bootstrap
    {--force : Re-apply default document mappings when they already exist}
    {--organization-id= : Organization ID (CLI when HTTP context is not resolved)}
    {--branch-id= : Branch ID (CLI when HTTP context is not resolved)}
    {--user-id= : Optional owner user ID for seeded rows (CLI; no login required)}
```

**CLI auth:** Bootstrap does **not** require a logged-in user. `owner_user_id` on seeded rows is null unless you pass `--user-id=` or run bootstrap from an authenticated HTTP request.

**Does:**

1. Seeds CoA via `AccountingCoaSeeder::seedDefaultsForBranch` (idempotent `key_name` accounts).
2. Does not seed operation mappings. The host saves those in the mapping UI.
3. Does not seed payment-method or expense-category mappings.

**Requires:** Valid org ID when `usesOrganizationId()`; valid branch ID when `usesBranchId()`.

HTTP equivalent: **POST** `{prefix}/bootstrap` with optional `force=1` (permission: `manage accounting settings`).


## Configuration reference

| Key | Default | Description |
|-----|---------|-------------|
| `tenancy_mode` | `single_tenant` | `single_tenant` \| `multi_tenant` \| `multi_branch` \| `multi_tenant_multi_branch` |
| `currency` | `USD` | Display/default currency code |
| `fy_start` | `1` | First month of fiscal year (1–12) |
| `route_prefix` | `api/v1/accounting` | API URL prefix |
| `middleware` | `auth`, `ResolveAccountingContext` | Route middleware stack (use `auth:sanctum`, `auth:api`, etc. as needed) |
| Operation mappings | none seeded | Host `PUT /operations/{key}` after bootstrap |
| `auth.guard` | `DefaultAccountingAuth::class` | `AccountingAuthInterface` implementation |
| `audit.driver` | `NullAccountingAudit::class` | `AccountingAuditInterface` implementation (`SpatieAccountingAudit` or a custom class) |
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
| Custom reports (list) | GET | `/reports/custom` | — | `data[]` with `name`, `label`, `handler` per registered report |
| Custom report (run) | GET | `/reports/custom/{name}` | Per handler `rules()` | Handler-defined `data` object |

Default period: `date_to` = today; `date_from` = start of current month (swapped if inverted).

Only journal entries with `status = active` (or `null` legacy) affect reports.


## Mappings

Three mapping tables (branch/org scoped):

1. **Document** — `(module, document_type)` → `debit_account_id`, `credit_account_id`
2. **Payment method** — `payment_method_id` → debit/credit per **module side** (`sales`, `purchase`, `expenses`, or any custom module)
3. **Expense category** — `expense_category_id` → debit/credit

API:

- GET/POST upsert: `{prefix}/document-mappings`, `payment-method-mappings`, `expense-category-mappings`
- UI helper: GET `{prefix}/registry/documents`

`AccountingTransactionService::tryAutoMap()` resolves mappings using transaction type hints, payment method, or expense category.


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


## Operation mappings (v2)

Bootstrap creates accounts only. Posting uses lines you save:

`PUT {prefix}/operations/{operationKey}` with `lines[]` of `account_key` or `account_id`, `side` (`debit`|`credit`), `amount_source` (`amount`, `tax_amount`, `discount_amount`, `cost`, …), optional `skip_when_zero`.

At least two lines. Each amount source needs the same number of debit and credit lines. Otherwise the save is rejected, or a later unbalanced payload stays `not_mapped`.

`GET {prefix}/operations` and `GET {prefix}/registry/documents` feed the mapping screen. The registry does not insert rows.

`getJournalLines()` is an escape hatch. Tax, discount, and COGS belong in mapping lines plus payload keys. Settings cover fiscal year and close books only.


## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Transactions stay `not_mapped` | No mapping row or disabled mapping | Register `DocumentRegistry` + bootstrap; upsert mappings; check `is_enabled` |
| Bootstrap **Unauthenticated** in CLI | Old package called `user()` without session | Upgrade to **1.0.2+**; optional `--user-id=` |
| Migration FK/index on `drop_reconcile` | Index dropped before FK (MySQL) | Upgrade to **1.0.2+** (fixed migration order) |
| Migration `permissions` table missing | Host without Spatie | Upgrade to **1.0.2+** (migration skips optional tables) |
| Migration identifier too long (PM sides) | MySQL 64-char FK names | Upgrade to **1.0.2+** (`acct_pm_side_map_fk`) |
| Bootstrap “Organization/Branch required” | CLI without context | Pass `--organization-id` / `--branch-id` |
| 401/422 on API | Auth middleware or org/branch missing | Match `accounting.middleware` to your guard; set branch header; check `tenancy_mode` |
| 403 on endpoints | Missing permission | Define Gates / `$user->can()` / custom `AccountingAuthInterface` for permission names above |
| Delete host model fails | Strict void error | Resolve linked JE issues; void tx manually first |
| Record silently does nothing | Bridge swallows errors | Check `storage/logs` for `AccountingDocumentBridge failed` |
| Reports don’t match | Voided JEs or wrong dates | Use `include_voided` for audit; confirm `journal_date` range |
| Tax split not applied | Custom `getDocumentType()` | Use built-in invoice types or custom `mapWithLines` |
| `Missing CoA account key_name` on bootstrap | Wrong `default_debit`/`default_credit` in registry | Use valid `key_name` from seeded CoA (see `AccountingCoaSeeder`) |


## Documentation

| Document | Description |
|---|---|
| [README](https://github.com/Abdiaziiz-1717/abdiaziizaweis-accounting/blob/main/README.md) | Installation and quick start |
| [KNOWLEDGE.md](https://github.com/Abdiaziiz-1717/abdiaziizaweis-accounting/blob/main/docs/KNOWLEDGE.md) | Complete technical reference for developers and AI agents |
| [EXAMPLE_INTEGRATION.md](https://github.com/Abdiaziiz-1717/abdiaziizaweis-accounting/blob/main/docs/EXAMPLE_INTEGRATION.md) | Full working integration example |
| [BLADE_INTEGRATION.md](https://github.com/Abdiaziiz-1717/abdiaziizaweis-accounting/blob/main/docs/BLADE_INTEGRATION.md) | Blade / direct service injection (no API required) |
| [UI_STRUCTURE.md](https://github.com/Abdiaziiz-1717/abdiaziizaweis-accounting/blob/main/docs/UI_STRUCTURE.md) | UI pages reference and framework examples |

## Repository Structure

```
abdiaziizaweis-accounting/
├── config/accounting.php       # Default package config (merged on register)
├── database/migrations/        # Accounting tables (auto-loaded)
├── docs/
│   ├── KNOWLEDGE.md            # Full technical + API reference
│   ├── EXAMPLE_INTEGRATION.md  # End-to-end host app example
│   └── BLADE_INTEGRATION.md    # Blade / direct service usage
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

**Author:** Abdiaziiz Aweis Bashiir — [cabdicaziiz1717@gmail.com](mailto:cabdicaziiz1717@gmail.com)
